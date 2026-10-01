<?php

namespace App\Models;

use App\Models\Campaign;
use App\Models\CareerRank;
use App\Traits\HasInventory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\CharacterJournal;
use App\Models\Achievement;
use App\Models\Property;


class Character extends Model
{
    use HasFactory, HasInventory, SoftDeletes;

    protected $fillable = [
        "user_id",
        "display_name",
        "gender",
        "city_id",
        "home_city_id",
        "career_id",
        "career_rank",
        "career_xp",
        "total_character_exp",
        "health",
        "max_health",
        "cash_on_hand",
        "cash_in_bank",
        "dirty_cash",
        "property_id",
        "property_condition",
        "corporation_id",
        "corporation_position",
        "corporation_reports_to_id",
        "custom_avatar_url",
        "glow_color",
        "biography",
        "additional_info",
        "death_cause",
        "deleted_at",
        "death_reason",
        "degrees",
        "total_earns",
        "active_talent",
    ];

    protected $casts = [
        "health" => "integer",
        "max_health" => "integer",
        "cash_on_hand" => "integer",
        "cash_in_bank" => "integer",
        "dirty_cash" => "integer",
        "career_rank" => "integer",
        "career_xp" => "integer",
        "total_character_exp" => "integer",
        "deleted_at" => "datetime",
        "is_banned" => "boolean",
        "degrees" => "array",
        "total_earns" => "integer",
    ];

    public const MAX_ON_HAND_ITEMS = 2;

    public const MAX_ON_HAND_VEHICLES = 1;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->custom_avatar_url ?:
            $this->current_rank?->avatar_url ?? null;
    }

    public function getAchievementsAttribute()
    {
        return $this->user->achievements ?? collect();
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public static function findByName(string $name): ?self
    {
        //! why did i use trashed
        return static::withTrashed()
            ->whereRaw("LOWER(display_name) = ?", [strtolower($name)])
            ->first();
    }

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public function corporationReportsTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'corporation_reports_to_id');
    }

    public function corporationDirectReports(): HasMany
    {
        return $this->hasMany(self::class, 'corporation_reports_to_id');
    }

    public function homeCity(): BelongsTo
    {
        return $this->belongsTo(City::class, "home_city_id");
    }

    protected static function booted(): void
    {
        static::updated(function (self $character) {
            if ($character->isDirty("total_earns")) {
                $user = (auth()->id() === $character->user_id) ? auth()->user() : $character->user;
                $user?->checkAchievementThresholds(
                    "earns",
                    (int) $character->total_earns,
                    $character
                );
            }

            if ($character->isDirty("degrees")) {
                $rawOriginal = $character->getOriginal("degrees");
                $old = is_array($rawOriginal)
                    ? $rawOriginal
                    : json_decode($rawOriginal ?? "null", true) ?? [];
                $new = $character->degrees ?? [];

                $newCount = count(
                    array_filter($new, fn($d) => !empty($d["completed_at"])),
                );
                $oldCount = count(
                    array_filter($old, fn($d) => !empty($d["completed_at"])),
                );

                if ($newCount > $oldCount) {
                    $user = (auth()->id() === $character->user_id) ? auth()->user() : $character->user;
                    $user?->checkAchievementThresholds(
                        "degrees",
                        $newCount,
                        $character
                    );
                }
            }




        });
    }


    private function works24hKey(): string
    {
        return "works_24h_{$this->id}";
    }


    public function incrementWorks24h(): void
    {
        try {
            $key = $this->works24hKey();
            $redis = \Illuminate\Support\Facades\Redis::connection();
            $count = $redis->incr($key);

            if ($count === 1) {
                $redis->expire($key, 86400);
            }
        } catch (\Throwable) {

        }
    }


    public function getWorks24h(): int
    {
        try {
            $val = \Illuminate\Support\Facades\Redis::connection()->get($this->works24hKey());
            return max(0, (int) $val);
        } catch (\Throwable) {
            return 0;
        }
    }

    protected ?CareerRank $memoCurrentRank = null;

    public function resetRankMemo(): void
    {
        $this->memoCurrentRank = null;
    }

    public function getCurrentRankAttribute(): ?CareerRank
    {
        if ($this->career_id === null) {
            return null;
        }

        return $this->memoCurrentRank ??= CareerRank::findForCharacter(
            $this->career_id,
            $this->career_rank,
        );
    }

    public function getNextRankAttribute(): ?CareerRank
    {
        if ($this->career_id === null) {
            return null;
        }

        return CareerRank::getNextForCharacter(
            $this->career_id,
            $this->career_rank,
        );
    }

    public function getRankRequirementsAttribute(): array
    {
        return $this->current_rank
            ? $this->current_rank->getRankRequirements($this->career_xp)
            : [
                "progress" => 0,
                "xp_needed" => 0,
                "ready" => false,
                "max_rank" => false,
            ];
    }

    public function getCanPromoteAttribute(): bool
    {
        // The character IS ready — they just need to found via the boardroom.
        return $this->getPromotionChecker() === null;
    }
    public function getPromotionChecker(): ?string
    {
        return $this->resolvePromotionChecker();
    }

    private function resolvePromotionChecker(): ?string
    {
        if (!$this->rank_requirements['ready']) {
            return 'You are not eligible for a promotion yet.';
        }

        $isCorporation = strcasecmp($this->career?->code, 'corporation') === 0;

        if ($isCorporation) {
            $corporation = $this->relationLoaded('corporation')
                ? $this->getRelation('corporation')
                : $this->corporation;

            if ($this->career_rank >= 7) {
                return 'You cannot advance further yet.';
            }

            if ($this->career_rank === 6) {
                if ($corporation && $corporation->is_holding_company) {
                    return $this->corporationTrustVotePromotionBlocker($corporation);
                }

                return 'The board must complete a Director vote before you can advance.';
            }

            if ($this->career_rank === 5) {
                if (
                    !$corporation
                    || !$corporation->is_holding_company
                    || !in_array($this->corporation_position, [
                        Corporation::POSITION_GROUP_PRESIDENT,
                        Corporation::POSITION_CHAIRMAN,
                    ], true)
                ) {
                    return 'You need a holding-company board seat before you can be promoted.';
                }

                return null;
            }

            if ($this->career_rank === 4) {
                if (
                    $corporation
                    && (
                        $this->hasPendingCorporationMergerHandoff($corporation)
                        || $this->hasPendingCorporationBoardPromotionHandoff($corporation)
                    )
                ) {
                    return null;
                }

                return 'Your company has reached its zenith. A synergistic merger is required to advance further.';
            }

            if ($this->career_rank === 3) {
                if (!$corporation || (int) $corporation->ceo_id !== (int) $this->id) {
                    return 'You need to start a corporation from the boardroom before you can be promoted.';
                }
            }

            return null;

            // Rank 3 → 4 is the CEO rank — standard promote() is blocked.
            // which calls found() → promote() as part of the founding flow.
        }



        if (($this->career_rank + 1) >= 4) {
            $isLaw = strcasecmp($this->career?->code, 'law') === 0;
            $limit = $isLaw ? 3 : 1;

            $existingCount = self::where('career_id', $this->career_id)
                ->where('career_rank', '>=', 4)
                ->where('id', '!=', $this->id)
                ->where('home_city_id', $this->home_city_id)
                ->count();

            if ($existingCount >= $limit) {
                return $isLaw
                    ? 'All Chief Justice seats in your city are currently filled. A seat must open before you can be elevated.'
                    : 'This position is currently being held by your boss.';
            }
        }

        return null;
    }

    private function hasPendingCorporationMergerHandoff(Corporation $corporation): bool
    {
        if (
            $corporation->is_holding_company
            || $corporation->parent_trust_id === null
            || (int) $corporation->ceo_id !== (int) $this->id
        ) {
            return false;
        }

        return CorporationMergerRequest::where('status', CorporationMergerRequest::STATUS_COMPLETED)
            ->where(function ($query) use ($corporation) {
                $query->where(function ($query) use ($corporation) {
                    $query->where('requester_id', $this->id)
                        ->where('requester_corporation_id', $corporation->id)
                        ->whereNull('requester_handoff_completed_at');
                })->orWhere(function ($query) use ($corporation) {
                    $query->where('target_id', $this->id)
                        ->where('target_corporation_id', $corporation->id)
                        ->whereNull('target_handoff_completed_at');
                });
            })
            ->exists();
    }

    private function hasPendingCorporationBoardPromotionHandoff(Corporation $corporation): bool
    {
        if (
            $corporation->is_holding_company
            || $corporation->parent_trust_id === null
            || (int) $corporation->ceo_id !== (int) $this->id
        ) {
            return false;
        }

        return CorporationBoardPromotion::pending()
            ->where('promoted_ceo_id', $this->id)
            ->where('subsidiary_id', $corporation->id)
            ->where('holding_company_id', $corporation->parent_trust_id)
            ->exists();
    }

    private function corporationTrustVotePromotionBlocker(Corporation $corporation): ?string
    {
        if (
            !$corporation->is_holding_company
            || (int) $this->corporation_id !== (int) $corporation->id
        ) {
            return 'The board must complete a Director vote before you can advance.';
        }

        $voteQuery = CorporationTrustVote::promotionPending()
            ->where('holding_company_id', $corporation->id)
            ->where('winner_id', $this->id);

        if (DB::transactionLevel() > 0) {
            $voteQuery->lockForUpdate();
        }

        $vote = $voteQuery->first();
        if (!$vote) {
            return 'The board must complete a Director vote before you can advance.';
        }

        $boardMembers = DB::transactionLevel() > 0
            ? $corporation->lockTrustVoteState()
            : $corporation->trustVoteBoardMembers();

        $blocker = $corporation->directorPromotionBlocker(
            $this,
            $boardMembers,
            winnerAlreadyPromoted: false,
            lockForUpdate: DB::transactionLevel() > 0,
        );

        if ($blocker) {
            $vote->update(['status' => CorporationTrustVote::STATUS_CANCELLED]);
            return $blocker;
        }

        return null;
    }

    public function promote(): void
    {
        if ($this->career_id === null || $this->current_rank === null) {
            throw new \Exception('Cannot promote character without a valid career');
        }

        DB::transaction(function () {
            $freshRow = self::where('id', $this->id)->lockForUpdate()->first();
            $this->setRawAttributes($freshRow->getAttributes(), true);
            $this->resetRankMemo();

            if ($blocker = $this->getPromotionChecker()) {
                throw new \Exception($blocker);
            }

            $oldRank = $this->current_rank->rank_name;

            $this->career_rank++;
            $this->memoCurrentRank = null;
            $this->save();

            $newRank = $this->current_rank->rank_name;
            \App\Services\JournalService::promotion($this->id, $oldRank, $newRank);

            // ── Achievement: managing director ────────────────────────────
            if (strtolower($newRank) === 'managing director') {
                try {
                    $achievement = Achievement::where('slug', 'managing_director')->first();
                    $user = $this->user;
                    if ($achievement && $user && !$user->hasAchievement('managing_director')) {
                        $user->achievements()->attach($achievement->id, ['unlocked_at' => now()]);
                        $user->notifyAchievement($achievement, $this);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[Achievement] managing_director failed silently.', [
                        'character_id' => $this->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            CharacterHistory::appendCareerEvent($this, [
                'type' => 'promoted',
                'career_name' => $this->career?->name,
                'career_code' => $this->career?->code,
                'old_rank' => $oldRank,
                'new_rank' => $newRank,
            ]);
        });
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function corporation()
    {
        return $this->belongsTo(Corporation::class);
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, "owner_id");
    }

    public function hasBusinessCapacity(): bool
    {
        return $this->businesses()->count() < 3;
    }

    public function stats(): HasOne
    {
        return $this->hasOne(CharacterStats::class);
    }

    public function timers(): HasOne
    {
        return $this->hasOne(CharacterTimers::class);
    }

    public function journals(): HasMany
    {
        return $this->hasMany(CharacterJournal::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CharacterItem::class);
    }

    public function crimeRecords(): HasMany
    {
        return $this->hasMany(\App\Models\CrimeRecord::class);
    }

    public function characterHistory(): HasOne
    {
        return $this->hasOne(\App\Models\CharacterHistory::class);
    }


    public function convictionCount(?string $type = null): int
    {
        return \App\Models\CrimeRecord::convictionCount($this->id, $type);
    }

    public function scopeAlive(Builder $query): Builder
    {
        return $query->whereNull("deleted_at");
    }

    public function scopeDead(Builder $query): Builder
    {
        return $query->whereNotNull("deleted_at");
    }

    public function scopeInCity(Builder $query, int $cityId): Builder
    {
        return $query->where("city_id", $cityId);
    }

    public function scopeJailed(Builder $query): Builder
    {
        return $query->whereHas('timers', function ($q) {
            $q->where('jail_until', '>', now());
        });
    }

    public function scopeJailedInCity(Builder $query, int $cityId): Builder
    {
        return $query->jailed()->where("city_id", $cityId);
    }

    public function scopeOnline(Builder $query): Builder
    {



        return $query->whereExists(function ($q) {
            $q->select(DB::raw(1))
                ->from('sessions')
                ->whereColumn('sessions.user_id', 'characters.user_id')
                ->whereNotNull('sessions.user_id');
        });
    }

    public function scopeOnlineInCity(Builder $query, int $cityId): Builder
    {


        return $query->online()->where("city_id", $cityId);
    }


    /**
     * Global online list (most recently active first, max 100). Each row
     * carries its current cityId so the client can derive the per-city tab
     * itself — one shared cache entry instead of one per city, and the
     * payload no longer ships a second (city) copy of the same rows.
     */
    public static function getOnlinePlayersOptimized(): array
    {

        $recentDeathCutoff = now()->subMinutes(35);

        $globalQuery = DB::table('characters')
            ->select([
                'characters.id',
                'characters.display_name',
                'characters.custom_avatar_url',
                'characters.glow_color',
                'characters.career_id',
                'characters.career_rank',
                'characters.corporation_position',
                'characters.user_id',
                'characters.city_id',
                'characters.deleted_at',
                'careers.name as career_name',
                'home_cities.name as home_city_name',
                'corporations.name as corporation_name',
                'corporations.image_url as corporation_image_url',
                'corporations.ceo_id as corporation_ceo_id',
                'corporations.founder_id as corporation_founder_id',
                'corporations.is_holding_company as corporation_is_holding_company',
                'corporations.parent_trust_id as corporation_parent_trust_id',
                'parent_trusts.name as corporation_parent_trust_name',
                'parent_trusts.image_url as corporation_parent_trust_image_url',
                'character_timers.jail_until',
                'character_timers.hospital_until',
                DB::raw('COALESCE(MAX(sessions.last_activity), FLOOR(EXTRACT(EPOCH FROM characters.deleted_at))) as last_activity'),
            ])
            ->leftJoin('sessions', function ($join) {
                $join->on('characters.user_id', '=', 'sessions.user_id')
                    ->whereNotNull('sessions.user_id');
            })
            ->join('careers', 'characters.career_id', '=', 'careers.id')
            ->join('cities as home_cities', 'characters.home_city_id', '=', 'home_cities.id')
            ->leftJoin('corporations', 'characters.corporation_id', '=', 'corporations.id')
            ->leftJoin('corporations as parent_trusts', 'corporations.parent_trust_id', '=', 'parent_trusts.id')
            ->leftJoin('character_timers', 'characters.id', '=', 'character_timers.character_id')
            ->where(function ($query) use ($recentDeathCutoff) {
                $query->whereNotNull('sessions.user_id')
                    ->orWhere(function ($query) use ($recentDeathCutoff) {
                        $query->whereNotNull('characters.deleted_at')
                            ->where('characters.deleted_at', '>=', $recentDeathCutoff);
                    });
            })
            ->groupBy([
                'characters.id',
                'characters.display_name',
                'characters.custom_avatar_url',
                'characters.glow_color',
                'characters.career_id',
                'characters.career_rank',
                'characters.corporation_position',
                'characters.user_id',
                'characters.city_id',
                'characters.deleted_at',
                'careers.name',
                'home_cities.name',
                'corporations.name',
                'corporations.image_url',
                'corporations.ceo_id',
                'corporations.founder_id',
                'corporations.is_holding_company',
                'corporations.parent_trust_id',
                'parent_trusts.name',
                'parent_trusts.image_url',
                'character_timers.jail_until',
                'character_timers.hospital_until',
            ])
            ->orderBy('last_activity', 'desc')
            ->limit(100);

        $globalResults = $globalQuery->get();

        $careerIds = $globalResults->pluck("career_id")->unique()->toArray();
        $rankLevels = $globalResults->pluck("career_rank")->unique()->toArray();
        $ranks = CareerRank::bulkLoadForCharacters($careerIds, $rankLevels);

        $deadCharacterIds = $globalResults
            ->filter(fn($row) => ! is_null($row->deleted_at))
            ->pluck('id')
            ->unique()
            ->values();

        $deadCareerSnapshots = $deadCharacterIds->isEmpty()
            ? collect()
            : DB::table('character_histories')
                ->whereIn('character_id', $deadCharacterIds->all())
                ->pluck('career_timeline', 'character_id')
                ->map(fn($timeline) => CharacterHistory::finalCareerAndRankFromTimeline($timeline));

        $mapPlayer = function ($row) use ($ranks, $deadCareerSnapshots) {
            $rankKey = "{$row->career_id}_{$row->career_rank}";
            $rank = $ranks[$rankKey] ?? null;
            $isDead = ! is_null($row->deleted_at);
            $deadCareerSnapshot = $isDead ? ($deadCareerSnapshots->get($row->id) ?? []) : [];
            $rankName = $isDead
                ? ($deadCareerSnapshot['rank'] ?? $rank["rank_name"] ?? "Entry Level")
                : ($rank["rank_name"] ?? "Entry Level");
            $careerName = $isDead
                ? ($deadCareerSnapshot['career'] ?? $row->career_name ?? "Unknown")
                : ($row->career_name ?? "Unknown");

            return [
                "displayName" => $row->display_name,
                "cityId" => (int) $row->city_id,
                "avatarUrl" =>
                    $row->custom_avatar_url ?: $rank["avatar_url"] ?? null,
                "rank" => $rankName,
                "career" => $careerName,
                "homeCity" => $row->home_city_name ?? "Unknown",
                "glowColor" => $row->glow_color ?? "cyan",
                "lastActivity" => $row->last_activity ?? now()->timestamp,
                "corporationName" => $row->corporation_name ?? null,
                "corporationImageUrl" => $row->corporation_image_url ?? null,
                "corporationPosition" => $row->corporation_position ?? null,
                "corporationIsHoldingCompany" => (bool) ($row->corporation_is_holding_company ?? false),
                "corporationParentTrustId" => $row->corporation_parent_trust_id ?? null,
                "corporationParentTrustName" => $row->corporation_parent_trust_name ?? null,
                "corporationParentTrustImageUrl" => $row->corporation_parent_trust_image_url ?? null,
                "isCeo" => !(bool) ($row->corporation_is_holding_company ?? false)
                    && isset($row->corporation_ceo_id)
                    && $row->corporation_ceo_id === $row->id,
                "isFounder" => isset($row->corporation_founder_id) && $row->corporation_founder_id === $row->id,
                "is_jailed" => isset($row->jail_until) && $row->jail_until > now()->timestamp,
                "is_hospitalized" => isset($row->hospital_until) && $row->hospital_until > now()->timestamp,
                "is_dead" => $isDead,
            ];
        };

        return [
            "globalList" => $globalResults->map($mapPlayer)->values()->toArray(),
        ];
    }

    public function scopeRich(Builder $query, int $threshold = 1000000): Builder
    {
        return $query->whereRaw("(cash_on_hand + cash_in_bank) >= ?", [
            $threshold,
        ]);
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        return $query->where("display_name", "ILIKE", "%{$search}%");
    }

    public function isAlive(): bool
    {
        //! this is weirdly worded
        return is_null($this->deleted_at) && $this->health > 0;
    }

    public function isInHomeCity(): bool
    {
        return $this->city_id === $this->home_city_id;
    }

    public function isMayor(): bool
    {
        return City::where('mayor_id', $this->id)->exists();
    }

    public function isOnline(): bool
    {


        return DB::table('sessions')
            ->where('user_id', $this->user_id)
            ->whereNotNull('user_id')
            ->exists();
    }

    public function isAdmin(): bool
    {
        return $this->user?->is_admin === true;
    }

    public function canAfford(int $amount): bool
    {
        return $this->cash_on_hand >= $amount;
    }

    public function isProtected(): bool
    {
        return $this->timers?->protection_until?->isFuture() ?? false;
    }

    public function isHospitalized(): bool
    {
        return $this->timers?->hospital_until?->isFuture() ?? false;
    }

    public function isJailed(): bool
    {
        return $this->timers?->jail_until?->isFuture() ?? false;
    }

    public function canFight(?Character $target = null): array
    {
        if (!$this->isAlive()) {
            return ["valid" => false, "error" => "You are dead"];
        }

        if ($this->isAdmin()) {
            return ["valid" => false, "error" => "You cannot fight!"];
        }

        if ($this->isHospitalized() || $this->isJailed()) {
            return ["valid" => false, "error" => "You are Hospitalized or Jailed."];
        }

        $nextConflict = $this->timers?->next_conflict_at;
        if ($nextConflict && $nextConflict->isFuture()) {
            return [
                "valid" => false,
                "error" => "You must wait before attacking again",
                "remaining" => $nextConflict->diffInSeconds(now()),
            ];
        }

        if (!$target) {
            return ["valid" => false, "error" => "Player not found"];
        }

        if ($this->id === $target->id) {
            return ["valid" => false, "error" => "You cannot attack yourself"];
        }

        if (!$target->isAlive()) {
            return ["valid" => false, "error" => "Target player is dead"];
        }

        if ($this->city_id !== $target->city_id) {
            return ["valid" => false, "error" => "Target is not in your city"];
        }

        if ($target->isProtected()) {
            return [
                "valid" => false,
                "error" => "This player cannot be attacked at this moment!",
            ];
        }

        if ($target->isHospitalized() || $target->isJailed()) {
            return ["valid" => false, "error" => "Target is in the hospital or in prison."];
        }

        if ($target->isAdmin()) {
            return [
                "valid" => false,
                "error" => "This player cannot be attacked!",
            ];
        }

        if (!$target->isOnline()) {
            $lastLogin = $target->user?->last_login_at;
            $inactiveForTwoWeeks = $lastLogin && $lastLogin->lte(now()->subWeeks(2));

            if (!$inactiveForTwoWeeks && (!$lastLogin || $lastLogin->lt(now()->subMinutes(35)))) {
                return [
                    "valid" => false,
                    "error" => "Target has not been online recently",
                ];
            }
        }

        return ["valid" => true];
    }

    public function hospitalize(int $seconds, ?string $reason = null): void
    {
        $this->timers?->update([
            "hospital_until" => now()->addSeconds($seconds),
            "hospital_reason" => $reason,
        ]);
    }

    public function jail(int $seconds, ?string $reason = null): void
    {
        $this->timers?->update([
            'jail_until' => now()->addSeconds($seconds),
        ]);
    }

    public function setCombatCooldown(int $seconds): void
    {
        $this->timers?->update([
            "next_conflict_at" => now()->addSeconds($seconds),
        ]);
    }

    public function setProtection(int $seconds): void
    {
        $this->timers?->update([
            "protection_until" => now()->addSeconds($seconds),
        ]);
    }


    public function halveProtection(): void
    {
        if (!$this->isProtected()) {
            return;
        }
        $remaining = $this->timers->protection_until->getTimestamp() - now()->getTimestamp();
        $this->setProtection((int) floor($remaining / 2));
    }

    public function kill(string $cause, ?string $reason = null): void
    {
        DB::transaction(function () use ($cause, $reason) {
            $character = self::where("id", $this->id)->lockForUpdate()->first();

            if (!$character) {
                throw new \Exception(
                    "Character not found during death processing",
                );
            }

            \App\Models\Leaderboard::snapshotOnDeath($character->id);


            if ($character->corporation_id) {
                $corp = Corporation::lockForUpdate()->find($character->corporation_id);
                if ($corp) {
                    if ($corp->ceo_id === $character->id) {
                        $corp->removeMember($character);
                        $corp->succeedCeo();
                    } else {
                        $wasHoldingBoardMember = $corp->isBoardMember($character);
                        $corp->removeMember($character);
                        if ($wasHoldingBoardMember) {
                            $corp->reconcileHoldingLifecycle();
                        }
                    }
                }
            }

            if ($character->homeCity && $character->homeCity->mayor_id === $character->id) {
                $term = \App\Models\MayorTerm::activeForCity($character->homeCity->id);
                if ($term) {
                    \App\Services\MayorService::removeMayor($term, $character->homeCity, \App\Models\MayorTerm::END_REMOVED);
                } else {
                    $character->homeCity->removeMayor();
                    \App\Models\Election::startForCity($character->homeCity);
                }
            } else {
                $character->quitCareer();
            }

            Campaign::where('candidate_id', $character->id)
                ->where('status', 'active')
                ->update(['status' => 'withdrawn']);

            $character->releasePlantedBombs();
            $character->items()->delete();

            $character->property_id = null;

            Business::where("owner_id", $character->id)->update([
                "owner_id" => null,
            ]);

            $character->update([
                "health" => 0,
                "death_cause" => $cause,
                "death_reason" => $reason,
                'biography' => null,
            ]);

            $character->delete();


        });
    }

    public function takeDamage(int $damage): void
    {
        $newHealth = max(0, $this->health - $damage);
        $this->update(["health" => $newHealth]);

        if ($newHealth <= 0) {
            $this->kill("combat", "Died in combat");
        }
    }

    public function heal(int $amount): void
    {
        $newHealth = min($this->max_health, $this->health + $amount);
        $this->update(["health" => $newHealth]);
    }



    public function addCash(int $amount, bool $asDirtyCash = false, bool $save = true): void
    {
        $column = $asDirtyCash ? 'dirty_cash' : 'cash_on_hand';

        if (!$save) {

            if ($asDirtyCash) {
                $this->dirty_cash += $amount;
            } else {
                $this->cash_on_hand += $amount;
            }
            return;
        }




        DB::table('characters')->where('id', $this->id)->increment($column, $amount);


        if ($asDirtyCash) {
            $this->dirty_cash += $amount;
        } else {
            $this->cash_on_hand += $amount;
        }
    }

    public function removeCash(int $amount, bool $save = true): bool
    {
        if (!$save) {

            if ($this->cash_on_hand < $amount) {
                return false;
            }
            $this->cash_on_hand -= $amount;
            return true;
        }





        $affected = DB::table('characters')
            ->where('id', $this->id)
            ->where('cash_on_hand', '>=', $amount)
            ->decrement('cash_on_hand', $amount);

        if (!$affected) {
            return false;
        }

        $this->cash_on_hand -= $amount;
        return true;
    }

    public function depositToBank(int $amount): bool
    {
        return DB::transaction(function () use ($amount) {
            $lock = DB::table("characters")
                ->where("id", $this->id)
                ->lockForUpdate()
                ->first();

            if ((int) $lock->cash_on_hand < $amount) {
                return false;
            }

            $this->cash_on_hand = (int) $lock->cash_on_hand - $amount;
            $this->cash_in_bank = (int) $lock->cash_in_bank + $amount;
            $this->save();

            \App\Models\BankTransaction::record(
                characterId: $this->id,
                type: \App\Models\BankTransaction::TYPE_DEPOSIT,
                amount: $amount,
                balanceAfter: $this->cash_in_bank,
            );

            return true;
        });
    }

    public function withdrawFromBank(int $amount): bool
    {
        return DB::transaction(function () use ($amount) {
            $lock = DB::table("characters")
                ->where("id", $this->id)
                ->lockForUpdate()
                ->first();

            if ((int) $lock->cash_in_bank < $amount) {
                return false;
            }

            $this->cash_in_bank = (int) $lock->cash_in_bank - $amount;
            $this->cash_on_hand = (int) $lock->cash_on_hand + $amount;
            $this->save();

            \App\Models\BankTransaction::record(
                characterId: $this->id,
                type: \App\Models\BankTransaction::TYPE_WITHDRAW,
                amount: $amount,
                balanceAfter: $this->cash_in_bank,
            );

            return true;
        });
    }

    public function quitCareer(bool $preserveExp = false): bool
    {
        $unemployed = Career::findByCode("unemployed");
        if (!$unemployed) {
            return false;
        }


        $policeCareerId = Career::findByCode('police')?->id;
        if ($policeCareerId && $this->career_id === $policeCareerId && $this->career_rank >= 4) {
            Business::where('owner_id', $this->id)
                ->where('city_id', $this->home_city_id)
                ->where('code', 'police')
                ->update(['owner_id' => null, 'is_purchasable' => true]);
        }


        Business::where('owner_id', $this->id)
            ->where('city_id', $this->home_city_id)
            ->where('code', 'city-hall')
            ->update(['owner_id' => null, 'is_purchasable' => true]);



        \App\Models\CrimeRecord::where('detective_id', $this->id)
            ->where('status', \App\Models\CrimeRecord::STATUS_INVESTIGATING)
            ->update([
                'detective_id' => null,
                'status' => \App\Models\CrimeRecord::STATUS_OPEN,
            ]);

        $data = [
            "career_id" => $unemployed->id,
            "career_rank" => 1,
            "career_xp" => 0,
        ];

        if (!$preserveExp) {
            $data["total_character_exp"] =
                (int) ($this->total_character_exp * 0.85);
        }

        $this->update($data);
        $this->memoCurrentRank = null;

        return true;
    }

    public function startCareer(string $careerCode): bool
    {
        $career = Career::findByCode($careerCode);
        if (!$career) {
            return false;
        }



        //! THIS SHOULD ALWAYS BE TRUE.
        $this->quitCareer(preserveExp: true);

        $this->update([
            'career_id' => $career->id,
            'career_rank' => 1,
            'career_xp' => 50,
        ]);

        $this->memoCurrentRank = null;

        CharacterHistory::appendCareerEvent($this, [
            'type' => 'career_started',
            'career_name' => $career->name,
            'career_code' => $career->code,
            'rank_name' => $this->current_rank?->rank_name,
        ]);

        return true;
    }

    public function getDegree(string $code): ?array
    {
        $degrees = $this->degrees ?? [];
        $code = strtolower($code);

        if (isset($degrees[$code])) {
            return $degrees[$code];
        }

        foreach ($degrees as $degreeCode => $degree) {
            if (strtolower((string) $degreeCode) === $code) {
                return $degree;
            }
        }

        return null;
    }

    public function enrollDegree(string $code, int $cityId): void
    {
        $code = strtolower($code);
        $degrees = $this->degrees ?? [];
        $degrees[$code] = [
            "city_id" => $cityId,
            "cycles" => 0,
            "completed_at" => null,
        ];
        $this->update(["degrees" => $degrees]);
    }

    public function studyDegree(string $code): bool
    {
        $code = strtolower($code);
        $degrees = $this->degrees ?? [];
        if (!isset($degrees[$code])) {
            return false;
        }

        $degrees[$code]["cycles"]++;

        $required = config("timers.degree_cycles." . $code, 999);
        $completed = $degrees[$code]["cycles"] >= $required;

        if ($completed) {
            $degrees[$code]["completed_at"] = now()->toIso8601String();
        }

        $this->update(["degrees" => $degrees]);
        return $completed;
    }

    public function quitDegree(string $code): bool
    {
        $code = strtolower($code);
        $degrees = $this->degrees ?? [];

        $matchingKeys = array_filter(
            array_keys($degrees),
            fn($degreeCode) => strtolower((string) $degreeCode) === $code
        );

        if (!$matchingKeys) {
            return false;
        }

        foreach ($matchingKeys as $degreeCode) {
            unset($degrees[$degreeCode]);
        }

        $this->update(["degrees" => $degrees]);
        return true;
    }

    public function hasAllDegrees(): bool
    {
        $required = array_keys(config("timers.degree_cycles", []));
        $degrees = $this->degrees ?? [];

        $required = array_filter($required, fn($code) => in_array(strtolower($code), [
            'finance',
            'law',
            'medicine',
            'engineering',
            'business',
        ]));

        foreach ($required as $code) {
            if (empty($degrees[$code]["completed_at"])) {
                return false;
            }
        }

        return count($required) > 0;
    }

    public function hasDefenseInDepthRequirements(): bool
    {
        //? Requires police rank 3 , 10 cases investigated, 5 closes and 1 arrest.
        $h = DB::table('character_histories')->where('character_id', $this->id)->first();
        $casesInvestigated = $h->cases_investigated ?? 0;
        $casesClosed = $h->cases_closed ?? 0;
        $arrests = $h->arrests_made ?? 0;
        return $this->career?->code === "police" &&
            $this->career_rank >= 3 &&
            $casesInvestigated >= 10 &&
            $casesClosed >= 5 &&
            $arrests >= 1;
    }

    public function hasGoalOfAllLifeRequirements(): bool
    {
        if ($this->total_character_exp < 50000) {
            return false;
        }

        $h = DB::table('character_histories')->where('character_id', $this->id)->value('times_hit');
        return (int) $h >= 3;
    }

    public function hasLazarusConnectionRequirements(): bool
    {
        if ($this->career?->code !== 'healthcare') {
            return false;
        }

        if ($this->career_xp < 60_000) {
            return false;
        }

        $h = DB::table('character_histories')->where('character_id', $this->id)->first();
        return (int) ($h?->surgeries_performed ?? 0) >= 3
            && (int) ($h?->gender_reassignments_performed ?? 0) >= 1;
    }


    public function hasTalentActive(?string $talentId = null): bool
    {
        if (!$this->active_talent) {
            return false;
        }

        [$id, $expiresAt] = array_pad(explode(':', $this->active_talent, 2), 2, 0);

        if (now()->timestamp >= (int) $expiresAt) {

            $this->update(['active_talent' => null]);
            return false;
        }

        return $talentId ? $id === $talentId : true;
    }


    public function canActivateTalent(): bool
    {
        return !$this->hasTalentActive()
            && !$this->timers?->next_talents_at?->isFuture();
    }


    public function getEquippedWeaponTemplate(): ?\App\Models\GameItem
    {
        $item = $this->items
            ->first(fn($i) => $i->equipped_slot === 'weapon' && $i->is_equipped);

        return $item?->template;
    }


    public function getEquippedWeaponName(): string
    {
        return $this->getEquippedWeaponTemplate()?->name ?? 'bare hands';
    }

    public function addXp(int $xp): void
    {



        DB::table('characters')
            ->where('id', $this->id)
            ->update([
                'career_xp' => DB::raw("career_xp + {$xp}"),
                'total_character_exp' => DB::raw("total_character_exp + {$xp}"),
            ]);

        $this->career_xp += $xp;
        $this->total_character_exp += $xp;
    }
}
