<?php

namespace App\Models;

use App\Services\JournalService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class Corporation extends Model
{
    use SoftDeletes;

    public const POSITION_CFO = 'cfo';
    public const POSITION_CTO = 'cto';
    public const POSITION_VP = 'vp';
    public const POSITION_MEMBER = 'member';
    public const POSITION_GROUP_PRESIDENT = 'group_president';
    public const POSITION_CHAIRMAN = 'chairman';
    public const POSITION_DIRECTOR_OF_BOARD = 'director_of_board';

    public const BOARD_POSITIONS = [
        self::POSITION_GROUP_PRESIDENT,
        self::POSITION_CHAIRMAN,
        self::POSITION_DIRECTOR_OF_BOARD,
    ];
    public const MAX_SUBSIDIARIES = 4;

    // ── HQ relocation (cache-only state, no table) ─────────────────────────
    // Phase-1 ops can file a request to move home_city_id; a customs officer
    // (rank 2+) in the destination city approves or denies. Fee is escrowed
    // from cash_reserves at request time; refunded on cancel/deny.
    public const MOVE_HQ_FEE = 100_000;
    public const MOVE_HQ_AGENT_PAYOUT = 10_000;
    public const MOVE_HQ_REVIEW_WINDOW_SECONDS = 3600;

    public const ASSIGNABLE_POSITIONS = [
        self::POSITION_CFO,
        self::POSITION_CTO,
        self::POSITION_VP,
        self::POSITION_MEMBER,
    ];

    public const C_SUITE_POSITIONS = [
        self::POSITION_CFO,
        self::POSITION_CTO,
    ];

    public const REPORTABLE_POSITIONS = [
        self::POSITION_VP,
        self::POSITION_MEMBER,
    ];

    protected $fillable = [
        'name',
        'home_city_id',
        'founder_id',
        'ceo_id',
        'slush_fund',
        'cash_reserves',
        'hq_tier',
        'total_profits',
        'is_holding_company',
        'parent_trust_id',
        'image_url',
        'board_notes',
    ];

    protected $casts = [
        'slush_fund' => 'integer',
        'cash_reserves' => 'integer',
        'hq_tier' => 'integer',
        'total_profits' => 'integer',
        'is_holding_company' => 'boolean',
    ];


    //! max people per group is 20.  7 from one company 7 from another then six
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'home_city_id');
    }

    public function founder(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'founder_id');
    }

    public function ceo(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'ceo_id');
    }



    public function members(): HasMany
    {
        return $this->hasMany(Character::class, 'corporation_id');
    }

    public function parentTrust(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_trust_id');
    }

    public function subsidiaries(): HasMany
    {
        return $this->hasMany(self::class, 'parent_trust_id');
    }

    public function activeSubsidiaries(): HasMany
    {
        return $this->hasMany(self::class, 'parent_trust_id')
            ->whereNull('deleted_at');
    }

    public function boardMembers(): HasMany
    {
        return $this->hasMany(Character::class, 'corporation_id')
            ->whereIn('corporation_position', self::BOARD_POSITIONS)
            ->whereNull('deleted_at')
            ->where('health', '>', 0);
    }

    public function directorOfBoardMember(): HasOne
    {
        return $this->hasOne(Character::class, 'corporation_id')
            ->where('corporation_position', self::POSITION_DIRECTOR_OF_BOARD)
            ->where('career_rank', '>=', 7)
            ->whereNull('deleted_at')
            ->where('health', '>', 0);
    }

    public function directorOfBoard(): ?Character
    {
        if (!$this->is_holding_company) {
            return null;
        }

        if ($this->relationLoaded('directorOfBoardMember')) {
            return $this->getRelation('directorOfBoardMember');
        }

        if ($this->relationLoaded('boardMembers')) {
            return $this->boardMembers->first(
                fn(Character $member) => $member->corporation_position === self::POSITION_DIRECTOR_OF_BOARD
                && (int) ($member->career_rank ?? 0) >= 7
                && $member->isAlive()
            );
        }

        if ($this->relationLoaded('members')) {
            return $this->members->first(
                fn(Character $member) => $member->corporation_position === self::POSITION_DIRECTOR_OF_BOARD
                && (int) ($member->career_rank ?? 0) >= 7
                && $member->isAlive()
            );
        }

        return $this->directorOfBoardMember()->with('career')->first();
    }

    public static function activeDirectorOfBoard(?int $excludeCharacterId = null, bool $lockForUpdate = false): ?Character
    {
        $query = Character::where('corporation_position', self::POSITION_DIRECTOR_OF_BOARD)
            ->where('career_rank', '>=', 7)
            ->whereNull('deleted_at')
            ->where('health', '>', 0);

        if ($excludeCharacterId !== null) {
            $query->where('id', '!=', $excludeCharacterId);
        }

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function properties(): HasMany
    {
        return $this->hasMany(CorporationProperty::class);
    }

    public function trustVotes(): HasMany
    {
        return $this->hasMany(CorporationTrustVote::class, 'holding_company_id');
    }

    public function medicalProperties(): HasMany
    {
        return $this->properties()->where('type', CorporationProperty::TYPE_MEDICAL);
    }

    public function launderingProperties(): HasMany
    {
        return $this->properties()->where('type', CorporationProperty::TYPE_LAUNDERING);
    }

    public function medicalProperty(bool $operationalOnly = false): ?CorporationProperty
    {
        if ($this->relationLoaded('properties')) {
            $properties = $this->properties->where('type', CorporationProperty::TYPE_MEDICAL);

            return $operationalOnly
                ? $properties->first(fn(CorporationProperty $property) => $property->isOperational())
                : $properties->first();
        }

        $query = $this->medicalProperties();

        if ($operationalOnly) {
            $query->whereIn('condition', CorporationProperty::OPERATIONAL_CONDITIONS);
        }

        return $query->first();
    }

    public function lockedMedicalProperty(): ?CorporationProperty
    {
        return $this->medicalProperties()
            ->lockForUpdate()
            ->first();
    }

    public function lockedLaunderingProperty(): ?CorporationProperty
    {
        return $this->launderingProperties()
            ->lockForUpdate()
            ->first();
    }

    public function getMaxMemberSlotsAttribute(): int
    {
        // Driven by hq_tier — denormalized from the HQ corporation_properties row
        // so isFull() never needs to query that table on every check.
        // 0 = no HQ purchased, only founder present.
        return match ($this->hq_tier ?? 0) {
            1 => 3,
            2 => 5,
            3 => 7,
            default => 1,
        };
    }

    public function getMemberCountAttribute(): int
    {
        return $this->relationLoaded('members')
            ? $this->members->count()
            : $this->members()->count();
    }

    public static function CorporationStrength(Collection $members): int
    {
        return (int) $members->sum(function (Character $member) {
            $effective = $member->stats?->effectiveStats() ?? [];
            $statSum = ($effective['intelligence'] ?? 0)
                + ($effective['offense'] ?? 0)
                + ($effective['defense'] ?? 0)
                + ($effective['luck'] ?? 0);
            $statContribution = $statSum / 1000;
            $xpContribution = (($member->total_character_exp ?? 0) + ($member->career_xp ?? 0)) / 10000;
            return $statContribution + $xpContribution;
        });
    }

    public function getStrengthAttribute(): int
    {
        $members = $this->relationLoaded('members')
            ? $this->members->loadMissing(['stats', 'property', 'items.template'])
            : $this->members()->with(['stats', 'property', 'items.template'])->get();

        return (int) $members->sum(function (Character $member) {
            $effective = $member->stats?->effectiveStats() ?? [];
            $statSum = ($effective['intelligence'] ?? 0)
                + ($effective['offense'] ?? 0)
                + ($effective['defense'] ?? 0)
                + ($effective['luck'] ?? 0);
            $statContribution = $statSum / 1000;
            $xpContribution = (($member->total_character_exp ?? 0) + ($member->career_xp ?? 0)) / 10000;

            return $statContribution + $xpContribution;
        });
    }

    public function scopeInCity($query, int $cityId)
    {
        return $query->where('home_city_id', $cityId);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    public function scopeOperating($query)
    {
        return $query
            ->where('is_holding_company', false);
    }

    public function isOperatingCompany(): bool
    {
        return !$this->is_holding_company;
    }

    public function isFull(): bool
    {
        return $this->member_count >= $this->max_member_slots;
    }

    public function isCeo(Character $character): bool
    {
        return (int) $this->ceo_id === (int) $character->id;
    }

    public function hasWorkingHQ(): bool
    {
        // A corp may briefly own two HQ rows (the operational one plus a
        // PENDING upgrade); only the CONSTRUCTED row counts as "working".
        return $this->properties()
            ->where('type', CorporationProperty::TYPE_HQ)
            ->whereIn('condition', CorporationProperty::OPERATIONAL_CONDITIONS)
            ->exists();
    }

    public function hasDefaultedHQ(): bool
    {
        if ($this->relationLoaded('properties')) {
            return $this->properties->contains(
                fn(CorporationProperty $property) => $property->type === CorporationProperty::TYPE_HQ
                && $property->condition === CorporationProperty::CONDITION_DEFAULTED
            );
        }

        return $this->properties()
            ->where('type', CorporationProperty::TYPE_HQ)
            ->where('condition', CorporationProperty::CONDITION_DEFAULTED)
            ->exists();
    }

    public function hasConstructedHqTier(int $tier): bool
    {
        if ($this->relationLoaded('properties')) {
            return $this->properties->contains(
                fn(CorporationProperty $property) => $property->type === CorporationProperty::TYPE_HQ
                && (int) $property->tier >= $tier
                && in_array($property->condition, CorporationProperty::OPERATIONAL_CONDITIONS, true)
            );
        }

        return $this->properties()
            ->where('type', CorporationProperty::TYPE_HQ)
            ->where('tier', '>=', $tier)
            ->whereIn('condition', CorporationProperty::OPERATIONAL_CONDITIONS)
            ->exists();
    }

    public function isFounder(Character $character): bool
    {
        return (int) $this->founder_id === (int) $character->id;
    }

    public function roleFor(Character $character): string
    {
        if ($this->isCeo($character)) {
            return 'ceo';
        }

        return $character->corporation_position ?: self::POSITION_MEMBER;
    }

    public function isCSuite(Character $character): bool
    {
        return in_array($this->roleFor($character), self::C_SUITE_POSITIONS, true);
    }

    public function isVicePresident(Character $character): bool
    {
        return $this->roleFor($character) === self::POSITION_VP;
    }

    public function isBoardMember(Character $character): bool
    {
        return $this->is_holding_company
            && (int) $character->corporation_id === (int) $this->id
            && in_array($character->corporation_position, self::BOARD_POSITIONS, true)
            && in_array((int) ($character->career_rank ?? 0), [5, 6, 7], true)
            && $character->isAlive();
    }

    public function trustVoteBoardMembers(): Collection
    {
        if ($this->relationLoaded('boardMembers')) {
            return $this->boardMembers->values();
        }

        if ($this->relationLoaded('members')) {
            return $this->members
                ->filter(fn(Character $member) => in_array($member->corporation_position, self::BOARD_POSITIONS, true)
                    && $member->deleted_at === null
                    && $member->isAlive())
                ->values();
        }

        return $this->boardMembers()->get()->values();
    }

    public function isEligibleTrustVoter(Character $member): bool
    {
        if (!$this->isBoardMember($member) || (int) ($member->career_rank ?? 0) !== 6) {
            return false;
        }

        $corporationCareerId = Career::findByCode('corporation')?->id;

        if (!$corporationCareerId || (int) $member->career_id !== (int) $corporationCareerId) {
            return false;
        }

        $rank = CareerRank::findForCharacter((int) $member->career_id, 6);

        return (bool) ($rank?->getRankRequirements((int) $member->career_xp)['ready'] ?? false);
    }

    public function hasActiveTrustDirector(?Collection $boardMembers = null): bool
    {
        $boardMembers ??= $this->trustVoteBoardMembers();

        return $boardMembers->contains(
            fn(Character $member) => $member->corporation_position === self::POSITION_DIRECTOR_OF_BOARD
            && (int) ($member->career_rank ?? 0) >= 7
            && $member->isAlive()
        );
    }

    public function trustVoteReadinessBlocker(?Collection $boardMembers = null): ?string
    {
        if ($this->trashed() || !$this->is_holding_company) {
            return 'Only a holding company can open a Director vote.';
        }

        if ($this->hasActiveTrustDirector($boardMembers)) {
            return 'This holding company already has a Director of the Board.';
        }

        if (self::activeDirectorOfBoard()) {
            return 'The Director of the Board seat is already occupied.';
        }

        if ($this->created_at && $this->created_at->gt(now()->subDay())) {
            return 'The holding company is too new to appoint a Director.';
        }

        $activeHoldingCount = $this->getAttribute('locked_active_holding_count');
        if ($activeHoldingCount === null) {
            $activeHoldingCount = self::where('is_holding_company', true)
                ->whereNull('deleted_at')
                ->get(['id'])
                ->count();
        }

        if ($activeHoldingCount !== 1) {
            return 'Only the final holding company can appoint a Director of the Board.';
        }

        $subsidiaryCount = $this->activeSubsidiaryCount();

        if ($subsidiaryCount < 2) {
            return 'The holding company requires at least 2 active subsidiaries.';
        }

        if (!$this->hasConstructedHqTier(3)) {
            return 'The holding company needs a completed headquarters.';
        }

        $boardMembers ??= $this->trustVoteBoardMembers();

        if ($boardMembers->count() < 3) {
            return 'The board is not established enough.';
        }

        if ($boardMembers->contains(fn(Character $member) => !$this->isEligibleTrustVoter($member))) {
            return 'Both the Voter and the Candidate must be ready for rank 7';
        }

        return null;
    }

    public function lockTrustVoteState(): Collection
    {
        $activeHoldingIds = self::where('is_holding_company', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        $activeSubsidiaries = $this->activeSubsidiaries()
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        $hqProperties = $this->properties()
            ->where('type', CorporationProperty::TYPE_HQ)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'corporation_id', 'type', 'tier', 'condition']);

        $boardMembers = Character::where('corporation_id', $this->id)
            ->whereIn('corporation_position', self::BOARD_POSITIONS)
            ->whereNull('deleted_at')
            ->where('health', '>', 0)
            ->with('career')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->setAttribute('locked_active_holding_count', $activeHoldingIds->count());
        $this->setRelation('activeSubsidiaries', $activeSubsidiaries);
        $this->setRelation('properties', $hqProperties);
        $this->setRelation('boardMembers', $boardMembers);

        return $boardMembers;
    }

    public function directorPromotionBlocker(
        Character $winner,
        ?Collection $boardMembers = null,
        bool $winnerAlreadyPromoted = false,
        bool $lockForUpdate = false,
    ): ?string {
        if ($this->trashed() || !$this->is_holding_company) {
            return 'The holding company can no longer form a trust.';
        }

        if ($this->hasActiveTrustDirector($boardMembers)) {
            return 'This holding company already has a Director of the Board.';
        }

        if (!$this->isBoardMember($winner) || !$winner->isAlive()) {
            return 'The elected Director is no longer eligible.';
        }

        if ($winnerAlreadyPromoted) {
            if ((int) ($winner->career_rank ?? 0) !== 7) {
                return 'The elected Director is no longer eligible.';
            }
        } elseif (!$this->isEligibleTrustVoter($winner)) {
            return 'The elected Director is no longer eligible.';
        }

        if (self::activeDirectorOfBoard($winnerAlreadyPromoted ? (int) $winner->id : null, $lockForUpdate)) {
            return 'The Director of the Board seat is already occupied.';
        }

        $activeHoldingCount = $this->getAttribute('locked_active_holding_count');
        if ($activeHoldingCount === null) {
            $query = self::where('is_holding_company', true)
                ->whereNull('deleted_at');

            $activeHoldingCount = $lockForUpdate
                ? $query->lockForUpdate()->get(['id'])->count()
                : $query->get(['id'])->count();
        }

        if ($activeHoldingCount !== 1) {
            return 'Only the final holding company can appoint a Director of the Board.';
        }

        if ($this->created_at && $this->created_at->gt(now()->subDay())) {
            return 'The holding company is too new to appoint a Director.';
        }

        if ($this->activeSubsidiaryCount() < 2) {
            return 'The holding company requires at least 2 active subsidiaries.';
        }

        if (!$this->hasConstructedHqTier(3)) {
            return 'The holding company needs a completed headquarters.';
        }

        $boardMembers ??= $this->trustVoteBoardMembers();

        if ($boardMembers->count() < 3) {
            return 'The board is not established enough.';
        }

        $invalidBoardMember = $boardMembers->first(function (Character $member) use ($winner) {
            if ((int) $member->id === (int) $winner->id) {
                return false;
            }

            return !$this->isEligibleTrustVoter($member);
        });

        if ($invalidBoardMember) {
            return 'The board is no longer ready for a Director vote.';
        }

        return null;
    }

    public function boardCapacity(): int
    {
        if (!$this->is_holding_company) {
            return 0;
        }

        return min($this->activeSubsidiaryCount(), self::MAX_SUBSIDIARIES) + 1;
    }

    public function hasSubsidiaryCapacity(): bool
    {
        if (!$this->is_holding_company) {
            return false;
        }
        return $this->activeSubsidiaryCount() < self::MAX_SUBSIDIARIES;
    }

    public function activeSubsidiaryCount(): int
    {
        if (!$this->is_holding_company) {
            return 0;
        }

        if ($this->relationLoaded('activeSubsidiaries')) {
            return $this->activeSubsidiaries->count();
        }

        if ($this->relationLoaded('subsidiaries')) {
            return $this->subsidiaries->count();
        }

        return $this->activeSubsidiaries()->count();
    }

    public function activeSubsidiaryIds(): array
    {
        if (!$this->is_holding_company) {
            return [];
        }

        if ($this->relationLoaded('activeSubsidiaries')) {
            return $this->activeSubsidiaries->pluck('id')->map(fn($id) => (int) $id)->all();
        }

        if ($this->relationLoaded('subsidiaries')) {
            return $this->subsidiaries->pluck('id')->map(fn($id) => (int) $id)->all();
        }

        return $this->activeSubsidiaries()->pluck('id')->map(fn($id) => (int) $id)->all();
    }

    public function boardMemberCount(): int
    {
        if (!$this->is_holding_company) {
            return 0;
        }

        if ($this->relationLoaded('boardMembers')) {
            return $this->boardMembers->count();
        }

        if ($this->relationLoaded('members')) {
            return $this->members
                ->filter(fn(Character $member) => in_array($member->corporation_position, self::BOARD_POSITIONS, true)
                    && $member->isAlive())
                ->count();
        }

        return $this->boardMembers()->count();
    }

    public function pendingBoardIntakeCount(?int $excludeBoardPromotionId = null): int
    {
        if (!$this->is_holding_company) {
            return 0;
        }

        $subsidiaryIds = $this->activeSubsidiaryIds();
        if (!$subsidiaryIds) {
            return 0;
        }

        $boardPromotions = CorporationBoardPromotion::pending()
            ->with(['promotedCeo', 'successor', 'subsidiary'])
            ->where('holding_company_id', $this->id)
            ->when($excludeBoardPromotionId, fn($query) => $query->where('id', '!=', $excludeBoardPromotionId))
            ->get()
            ->filter(function (CorporationBoardPromotion $promotion) use ($subsidiaryIds) {
                return in_array((int) $promotion->subsidiary_id, $subsidiaryIds, true)
                    && (int) $promotion->subsidiary?->parent_trust_id === (int) $this->id
                    && $promotion->promotedCeo?->isAlive()
                    && $promotion->successor?->isAlive();
            })
            ->count();

        $mergerIntakes = CorporationMergerRequest::where('status', CorporationMergerRequest::STATUS_COMPLETED)
            ->with(['requester', 'target'])
            ->where(function ($query) use ($subsidiaryIds) {
                $query->where(function ($query) use ($subsidiaryIds) {
                    $query->whereIn('requester_corporation_id', $subsidiaryIds)
                        ->whereNull('requester_handoff_completed_at');
                })->orWhere(function ($query) use ($subsidiaryIds) {
                    $query->whereIn('target_corporation_id', $subsidiaryIds)
                        ->whereNull('target_handoff_completed_at');
                });
            })
            ->get()
            ->sum(function (CorporationMergerRequest $request) use ($subsidiaryIds) {
                $count = 0;

                if (
                    in_array((int) $request->requester_corporation_id, $subsidiaryIds, true)
                    && $request->requester_handoff_completed_at === null
                    && $request->requester?->isAlive()
                ) {
                    $count++;
                }

                if (
                    in_array((int) $request->target_corporation_id, $subsidiaryIds, true)
                    && $request->target_handoff_completed_at === null
                    && $request->target?->isAlive()
                ) {
                    $count++;
                }

                return $count;
            });

        return $boardPromotions + $mergerIntakes;
    }

    public function availableBoardSlots(?int $excludeBoardPromotionId = null): int
    {
        return max(
            0,
            $this->boardCapacity() - $this->boardMemberCount() - $this->pendingBoardIntakeCount($excludeBoardPromotionId)
        );
    }

    public function hasBoardIntakeCapacity(?int $excludeBoardPromotionId = null): bool
    {
        return $this->availableBoardSlots($excludeBoardPromotionId) > 0;
    }

    public function cancelPendingBoardPromotions(?Corporation $subsidiary = null, ?Character $member = null): void
    {
        $query = CorporationBoardPromotion::pending()
            ->where(function ($query) {
                $query->where('holding_company_id', $this->id)
                    ->orWhere('subsidiary_id', $this->id);
            });

        if ($subsidiary) {
            $query->where('subsidiary_id', $subsidiary->id);
        }

        if ($member) {
            $query->where(function ($query) use ($member) {
                $query->where('promoted_ceo_id', $member->id)
                    ->orWhere('successor_id', $member->id);
            });
        }

        $query->update(['status' => CorporationBoardPromotion::STATUS_CANCELLED]);
    }

    public function cancelPendingTrustVotes(): void
    {
        CorporationTrustVote::where('holding_company_id', $this->id)
            ->where(function ($query) {
                $query->where('status', CorporationTrustVote::STATUS_PENDING)
                    ->orWhere(function ($query) {
                        $query->where('status', CorporationTrustVote::STATUS_COMPLETED)
                            ->whereNull('promotion_completed_at');
                    });
            })
            ->update(['status' => CorporationTrustVote::STATUS_CANCELLED]);
    }

    public function closePendingMergerHandoffsForSubsidiary(Corporation $subsidiary): void
    {
        CorporationMergerRequest::where('status', CorporationMergerRequest::STATUS_COMPLETED)
            ->where('requester_corporation_id', $subsidiary->id)
            ->whereNull('requester_handoff_completed_at')
            ->update(['requester_handoff_completed_at' => now()]);

        CorporationMergerRequest::where('status', CorporationMergerRequest::STATUS_COMPLETED)
            ->where('target_corporation_id', $subsidiary->id)
            ->whereNull('target_handoff_completed_at')
            ->update(['target_handoff_completed_at' => now()]);
    }

    public function kickOutSubsidiary(Corporation $subsidiary): void
    {
        if (!$this->is_holding_company || (int) $subsidiary->parent_trust_id !== (int) $this->id) {
            throw new \InvalidArgumentException('That company is not a subsidiary of this holding company.');
        }

        $activeSubsidiaryCount = self::where('parent_trust_id', $this->id)
            ->lockForUpdate()
            ->get(['id'])
            ->count();

        if ($activeSubsidiaryCount <= 1) {
            throw new \InvalidArgumentException('You cannot kick out your last subsidiary, what would you be holding?!');
        }

        $this->cancelPendingBoardPromotions($subsidiary);
        $this->cancelPendingTrustVotes();
        $this->closePendingMergerHandoffsForSubsidiary($subsidiary);
        $subsidiary->update(['parent_trust_id' => null]);
        if ($subsidiary->ceo_id) {
            Character::where('id', $subsidiary->ceo_id)->update(['corporation_reports_to_id' => null]);
        }
        $this->reconcileHoldingLifecycle();
    }

    public function reconcileHoldingLifecycle(): void
    {
        if (!$this->is_holding_company || $this->trashed()) {
            return;
        }

        $holding = self::where('id', $this->id)->lockForUpdate()->first();
        if (!$holding || !$holding->is_holding_company || $holding->trashed()) {
            return;
        }

        $subsidiaryCount = self::where('parent_trust_id', $holding->id)
            ->lockForUpdate()
            ->get(['id'])
            ->count();

        if ($subsidiaryCount === 0) {
            $holding->cancelPendingTrustVotes();
            $holding->collapseHoldingCompany(false);
            return;
        }

        $boardCount = Character::where('corporation_id', $holding->id)
            ->whereIn('corporation_position', self::BOARD_POSITIONS)
            ->whereNull('deleted_at')
            ->where('health', '>', 0)
            ->lockForUpdate()
            ->get(['id'])
            ->count();

        if ($boardCount === 0 && $holding->pendingBoardIntakeCount() === 0) {
            $holding->cancelPendingTrustVotes();
            $holding->collapseHoldingCompany(true);
            return;
        }

        $hasActiveDirectorVote = CorporationTrustVote::where('holding_company_id', $holding->id)
            ->where(function ($query) {
                $query->where('status', CorporationTrustVote::STATUS_PENDING)
                    ->orWhere(function ($query) {
                        $query->where('status', CorporationTrustVote::STATUS_COMPLETED)
                            ->whereNull('promotion_completed_at');
                    });
            })
            ->exists();

        if ($hasActiveDirectorVote && $holding->trustVoteReadinessBlocker() !== null) {
            $holding->cancelPendingTrustVotes();
        }
    }

    public function collapseHoldingCompany(bool $freeSubsidiaries): void
    {
        if (!$this->is_holding_company) {
            return;
        }

        $holding = self::where('id', $this->id)->lockForUpdate()->first();
        if (!$holding || !$holding->is_holding_company || $holding->trashed()) {
            return;
        }

        $subsidiaries = self::where('parent_trust_id', $holding->id)
            ->lockForUpdate()
            ->get();

        foreach ($subsidiaries as $subsidiary) {
            $holding->cancelPendingBoardPromotions($subsidiary);
            $holding->closePendingMergerHandoffsForSubsidiary($subsidiary);
        }

        if ($freeSubsidiaries) {
            self::whereIn('id', $subsidiaries->pluck('id')->all())
                ->update(['parent_trust_id' => null]);

            $freedCeoIds = $subsidiaries->pluck('ceo_id')->filter()->all();
            if ($freedCeoIds) {
                Character::whereIn('id', $freedCeoIds)
                    ->update(['corporation_reports_to_id' => null]);
            }
        }

        $holding->cancelPendingBoardPromotions();
        $holding->cancelPendingTrustVotes();

        $members = Character::where('corporation_id', $holding->id)
            ->lockForUpdate()
            ->get();
        $memberIds = $members->pluck('id')->all();

        foreach ($members as $member) {
            $data = [
                'corporation_id' => null,
                'corporation_position' => null,
                'corporation_reports_to_id' => null,
            ];

            if (
                strcasecmp($member->career?->code ?? '', 'corporation') === 0
                && ($member->career_rank ?? 0) >= 4
            ) {
                $rank3 = $member->career_id
                    ? CareerRank::findForCharacter($member->career_id, 3)
                    : null;

                $data['career_rank'] = 3;
                $data['career_xp'] = $rank3?->xp_required ?? $member->career_xp;
            }

            $member->update($data);
            $member->resetRankMemo();
        }

        foreach ($memberIds as $memberId) {
            JournalService::custom($memberId, 'corporation_holding_collapsed', [
                'holding_name' => $holding->name,
                'subsidiaries_freed' => $freeSubsidiaries,
            ]);
        }

        $holding->delete();
    }

    public function isLineManager(Character $character): bool
    {
        return $this->isCSuite($character) || $this->isVicePresident($character);
    }

    public function canManageMember(Character $manager, Character $member): bool
    {
        if ((int) $manager->id === (int) $member->id) {
            return false;
        }

        if ((int) $manager->corporation_id !== (int) $this->id || (int) $member->corporation_id !== (int) $this->id) {
            return false;
        }

        if ($this->isCeo($member)) {
            return false;
        }

        if ($this->isCeo($manager)) {
            return true;
        }

        if ((int) $member->corporation_reports_to_id !== (int) $manager->id) {
            return false;
        }

        if ($this->isCSuite($manager)) {
            return in_array($this->roleFor($member), self::REPORTABLE_POSITIONS, true);
        }

        return $this->isVicePresident($manager)
            && $this->roleFor($member) === self::POSITION_MEMBER;
    }

    public function attachMember(Character $member, ?string $position = null, ?Character $reportsTo = null): void
    {
        $position = $this->normalizePosition($position);

        if ($this->isCeo($member)) {
            $position = self::POSITION_MEMBER;
            $reportsTo = null;
        } elseif ($member->career?->code === 'corporation' && ($member->career_rank ?? 0) >= 4) {
            throw new \InvalidArgumentException('Managing Directors cannot join another corporation.');
        } else {
            $reportsTo ??= $this->defaultReportsToFor($position, $member);
        }

        $member->update([
            'corporation_id' => $this->id,
            'corporation_position' => $position === self::POSITION_MEMBER ? null : $position,
            'corporation_reports_to_id' => $this->validReportsToId($member, $position, $reportsTo, false),
        ]);
    }

    public function removeMember(Character $member): void
    {
        $this->clearReportsFor($member);
        $this->cancelPendingBoardPromotions(null, $member);
        $this->cancelPendingTrustVotes();

        $member->update([
            'corporation_id' => null,
            'corporation_position' => null,
            'corporation_reports_to_id' => null,
        ]);
    }

    public function assignMemberPosition(Character $member, string $position, ?Character $reportsTo = null): void
    {
        if ((int) $member->corporation_id !== (int) $this->id) {
            throw new \InvalidArgumentException('Member is not in this corporation.');
        }

        if ($this->isCeo($member)) {
            throw new \InvalidArgumentException('Cannot assign the CEO through member position assignment.');
        }

        $position = $this->normalizePosition($position);

        if (in_array($position, [self::POSITION_CFO, self::POSITION_CTO, self::POSITION_VP], true) && ($member->career_rank ?? 0) < 3) {
            throw new \InvalidArgumentException('That member must be at least Rank 3 (Department Head) to hold that position.');
        }

        if (in_array($position, self::C_SUITE_POSITIONS, true)) {
            $existing = $this->members()
                ->where('corporation_position', $position)
                ->where('id', '!=', $member->id)
                ->exists();

            if ($existing) {
                throw new \InvalidArgumentException(strtoupper($position) . ' position is already filled.');
            }
        }

        if (
            in_array($this->roleFor($member), [self::POSITION_CFO, self::POSITION_CTO, self::POSITION_VP], true)
            && $this->roleFor($member) !== $position
        ) {
            $this->clearReportsFor($member);
        }

        $reportsTo ??= $this->defaultReportsToFor($position, $member);

        $member->update([
            'corporation_position' => $position === self::POSITION_MEMBER ? null : $position,
            'corporation_reports_to_id' => $this->validReportsToId($member, $position, $reportsTo, true),
        ]);
    }

    public function demoteMemberRank(Character $member, Character $demoter): CareerRank
    {
        if ((int) $member->corporation_id !== (int) $this->id || (int) $demoter->corporation_id !== (int) $this->id) {
            throw new \InvalidArgumentException('Member is not in this corporation.');
        }

        if ((int) $member->id === (int) $demoter->id) {
            throw new \InvalidArgumentException('You cannot demote yourself.');
        }

        if ($this->isCeo($member)) {
            throw new \InvalidArgumentException('The CEO cannot be demoted through member discipline.');
        }

        if (!$this->canManageMember($demoter, $member)) {
            throw new \InvalidArgumentException('You can only demote members assigned to you.');
        }

        if (($member->career_rank ?? 0) <= 1) {
            throw new \InvalidArgumentException('That member cannot be demoted any further.');
        }

        if (!$member->career_id || strcasecmp($member->career?->code ?? '', 'corporation') !== 0) {
            throw new \InvalidArgumentException('Only corporation career members can be demoted.');
        }

        $newRankLevel = (int) $member->career_rank - 1;
        $newRank = CareerRank::findForCharacter($member->career_id, $newRankLevel);

        if (!$newRank) {
            throw new \InvalidArgumentException('Could not verify the lower rank.');
        }

        $formerReportIds = $this->reassignReportsForDemotion($member, $demoter);
        $newPosition = $newRankLevel >= 3 ? $this->roleFor($member) : self::POSITION_MEMBER;
        $reportsTo = null;

        if (in_array($newPosition, self::REPORTABLE_POSITIONS, true)) {
            $currentReportsTo = $member->corporation_reports_to_id
                ? Character::where('id', $member->corporation_reports_to_id)->lockForUpdate()->first()
                : null;

            $reportsTo = $this->canReportToPosition($member, $newPosition, $currentReportsTo, $formerReportIds)
                ? $currentReportsTo
                : $this->bestReportsToFor($member, $newPosition, $demoter, $formerReportIds);
        }

        $member->update([
            'career_rank' => $newRankLevel,
            'career_xp' => $newRank->xp_required,
            'corporation_position' => $newPosition === self::POSITION_MEMBER ? null : $newPosition,
            'corporation_reports_to_id' => $reportsTo?->id,
        ]);
        $member->resetRankMemo();

        return $newRank;
    }

    public function clearReportsFor(Character $manager): void
    {
        Character::where('corporation_id', $this->id)
            ->where('corporation_reports_to_id', $manager->id)
            ->update(['corporation_reports_to_id' => null]);
    }

    public function defaultReportsToFor(string $position, ?Character $member = null, array $excludeIds = []): ?Character
    {
        $position = $this->normalizePosition($position);
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', [
            ...$excludeIds,
            $member?->id,
        ]))));

        if ($position === self::POSITION_VP) {
            $query = $this->members()
                ->whereIn('corporation_position', self::C_SUITE_POSITIONS);

            if ($excludeIds) {
                $query->whereNotIn('id', $excludeIds);
            }

            return $query
                ->orderByRaw("CASE corporation_position WHEN 'cfo' THEN 0 WHEN 'cto' THEN 1 ELSE 2 END")
                ->orderBy('id')
                ->first();
        }

        if ($position === self::POSITION_MEMBER) {
            $query = $this->members()
                ->where(function ($query) {
                    $query->where('corporation_position', self::POSITION_VP)
                        ->orWhereIn('corporation_position', self::C_SUITE_POSITIONS);
                });

            if ($excludeIds) {
                $query->whereNotIn('id', $excludeIds);
            }

            return $query
                ->orderByRaw("CASE corporation_position WHEN 'vp' THEN 0 WHEN 'cfo' THEN 1 WHEN 'cto' THEN 2 ELSE 3 END")
                ->orderBy('id')
                ->first();
        }

        return null;
    }

    private function normalizePosition(?string $position): string
    {
        $position = strtolower((string) ($position ?: self::POSITION_MEMBER));

        if (!in_array($position, self::ASSIGNABLE_POSITIONS, true)) {
            throw new \InvalidArgumentException('Invalid corporation position.');
        }

        return $position;
    }

    private function validReportsToId(Character $member, string $position, ?Character $reportsTo, bool $required): ?int
    {
        if (!in_array($position, self::REPORTABLE_POSITIONS, true)) {
            return null;
        }

        if (!$reportsTo) {
            if (!$required) {
                return null;
            }

            throw new \InvalidArgumentException(
                $position === self::POSITION_VP
                ? 'Vice Presidents must report to a C-suite member.'
                : 'Members must report to a VP or C-suite member.'
            );
        }

        if ((int) $reportsTo->corporation_id !== (int) $this->id || (int) $reportsTo->id === (int) $member->id) {
            throw new \InvalidArgumentException('Reports-to must be a manager in this corporation.');
        }

        if ($position === self::POSITION_VP && !$this->isCSuite($reportsTo)) {
            throw new \InvalidArgumentException('Vice Presidents must report to a C-suite member.');
        }

        if ($position === self::POSITION_MEMBER && !$this->isCSuite($reportsTo) && !$this->isVicePresident($reportsTo)) {
            throw new \InvalidArgumentException('Members must report to a VP or C-suite member.');
        }

        return $reportsTo->id;
    }

    private function reassignReportsForDemotion(Character $manager, Character $demoter): array
    {
        $reports = Character::where('corporation_id', $this->id)
            ->where('corporation_reports_to_id', $manager->id)
            ->orderByRaw("CASE corporation_position WHEN 'vp' THEN 0 WHEN 'cfo' THEN 1 WHEN 'cto' THEN 2 ELSE 3 END")
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($reports as $report) {
            $position = $this->roleFor($report);

            if (!in_array($position, self::REPORTABLE_POSITIONS, true)) {
                $report->update(['corporation_reports_to_id' => null]);
                continue;
            }

            $replacement = $this->bestReportsToFor($report, $position, $demoter, [$manager->id]);
            $report->update(['corporation_reports_to_id' => $replacement?->id]);
        }

        return $reports->pluck('id')->all();
    }

    private function bestReportsToFor(Character $member, string $position, ?Character $preferred, array $excludeIds = []): ?Character
    {
        if ($this->canReportToPosition($member, $position, $preferred, $excludeIds)) {
            return $preferred;
        }

        return $this->defaultReportsToFor($position, $member, $excludeIds);
    }

    private function canReportToPosition(Character $member, string $position, ?Character $reportsTo, array $excludeIds = []): bool
    {
        if (!$reportsTo || in_array((int) $reportsTo->id, array_map('intval', $excludeIds), true)) {
            return false;
        }

        if ((int) $reportsTo->corporation_id !== (int) $this->id || (int) $reportsTo->id === (int) $member->id) {
            return false;
        }

        if ($position === self::POSITION_VP) {
            return $this->isCSuite($reportsTo);
        }

        if ($position === self::POSITION_MEMBER) {
            return $this->isCSuite($reportsTo) || $this->isVicePresident($reportsTo);
        }

        return false;
    }



    public function transferCeo(Character $newCeo): void
    {
        $newCeo = Character::where('id', $newCeo->id)->lockForUpdate()->first();
        if (!$newCeo) {
            return;
        }

        $oldCeo = $this->relationLoaded('ceo') ? $this->ceo : $this->ceo()->first();

        $oldRankName = $newCeo->current_rank?->rank_name ?? 'Department Head';

        $this->update(['ceo_id' => $newCeo->id]);

        $this->clearReportsFor($newCeo);
        $newCeo->update([
            'career_rank' => 4,
            'corporation_position' => null,
            'corporation_reports_to_id' => null,
        ]);
        $newCeo->resetRankMemo();

        \App\Models\CharacterHistory::appendCareerEvent($newCeo, [
            'type' => 'promoted',
            'career_name' => 'Corporation',
            'career_code' => 'corporation',
            'old_rank' => $oldRankName,
            'new_rank' => 'Managing Director',
        ]);

        if ($oldCeo && (int) $oldCeo->id !== (int) $newCeo->id && $oldCeo->isAlive()) {
            $demotedRank = $oldCeo->career_id
                ? CareerRank::findForCharacter($oldCeo->career_id, 3)
                : null;
            $reportsTo = $this->defaultReportsToFor(self::POSITION_MEMBER, $oldCeo);

            $oldCeo->update([
                'career_rank' => 3,
                'career_xp' => $demotedRank?->xp_required ?? $oldCeo->career_xp,
                'corporation_position' => null,
                'corporation_reports_to_id' => $this->validReportsToId($oldCeo, self::POSITION_MEMBER, $reportsTo, false),
            ]);
            $oldCeo->resetRankMemo();
        }
    }

    public function succeedCeo(): bool
    {
        $parentHoldingId = $this->parent_trust_id;
        $oldCeoId = $this->ceo_id;

        $candidates = $this->members()
            ->where('id', '!=', $this->ceo_id)
            ->where('career_rank', 3)
            ->orderByDesc('career_xp')
            ->lockForUpdate()
            ->get();

        $successor = $candidates->first(function (Character $candidate) {
            $rank3Row = $candidate->career_id
                ? CareerRank::findForCharacter($candidate->career_id, 3)
                : null;

            return (bool) ($rank3Row?->getRankRequirements((int) $candidate->career_xp)['ready'] ?? false);
        });

        if (!$successor) {
            $this->cancelPendingBoardPromotions();
            $this->closePendingMergerHandoffsForSubsidiary($this);

            Character::where('corporation_id', $this->id)->update([
                'corporation_id' => null,
                'corporation_position' => null,
                'corporation_reports_to_id' => null,
            ]);
            $this->delete();

            if ($parentHoldingId) {
                self::where('id', $parentHoldingId)
                    ->lockForUpdate()
                    ->first()
                        ?->reconcileHoldingLifecycle();
            }

            return false;
        }
        $oldRankName = $successor->current_rank?->rank_name ?? 'Staff';

        CorporationBoardPromotion::pending()
            ->where('subsidiary_id', $this->id)
            ->where('promoted_ceo_id', $oldCeoId)
            ->update(['status' => CorporationBoardPromotion::STATUS_CANCELLED]);

        $this->closePendingMergerHandoffsForSubsidiary($this);

        $this->update(['ceo_id' => $successor->id]);
        $this->clearReportsFor($successor);
        $successor->update([
            'career_rank' => 4,
            'corporation_position' => null,
            'corporation_reports_to_id' => null,
        ]);
        $successor->resetRankMemo();
        //TODO should send a journal to the new ceo

        \App\Models\CharacterHistory::appendCareerEvent($successor, [
            'type' => 'promoted',
            'career_name' => 'Corporation',
            'career_code' => 'corporation',
            'old_rank' => $oldRankName,
            'new_rank' => 'Managing Director',
        ]);

        if ($parentHoldingId) {
            self::where('id', $parentHoldingId)
                ->lockForUpdate()
                ->first()
                    ?->reconcileHoldingLifecycle();
        }

        return true;
    }


    public static function moveRequestKey(int $corpId): string
    {
        return "corp_move_request:{$corpId}";
    }

    public static function moveRequestsCityKey(int $cityId): string
    {
        return "corp_move_requests_in_city:{$cityId}";
    }

    public function pendingMoveRequest(): ?array
    {
        $payload = Cache::get(self::moveRequestKey((int) $this->id));
        return is_array($payload) ? $payload : null;
    }

    public function hasPendingMoveRequest(): bool
    {
        return Cache::has(self::moveRequestKey((int) $this->id));
    }


    public static function writeMoveCityIndex(int $cityId, int $corpId, array $payload): void
    {
        if ($cityId <= 0 || $corpId <= 0) {
            return;
        }
        $key = self::moveRequestsCityKey($cityId);
        Cache::lock($key . ':lock', 5)->block(5, function () use ($key, $corpId, $payload) {
            $list = Cache::get($key, []);
            $list[$corpId] = $payload;
            Cache::forever($key, $list);
        });
    }

    public static function removeFromMoveCityIndex(int $cityId, int $corpId): void
    {
        if ($cityId <= 0 || $corpId <= 0) {
            return;
        }
        $key = self::moveRequestsCityKey($cityId);
        Cache::lock($key . ':lock', 5)->block(5, function () use ($key, $corpId) {
            $list = Cache::get($key, []);
            if (isset($list[$corpId])) {
                unset($list[$corpId]);
                if (empty($list)) {
                    Cache::forget($key);
                } else {
                    Cache::forever($key, $list);
                }
            }
        });
    }
}
