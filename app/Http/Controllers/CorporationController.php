<?php

namespace App\Http\Controllers;

use App\Actions\InvestmentFraud;
use App\Models\Career;
use App\Models\CareerRank;
use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CorporationBoardPromotion;
use App\Models\CorporationMergerRequest;
use App\Models\CorporationProperty;
use App\Models\CorporationSubsidiaryInvite;
use App\Models\CorporationTrustVote;
use App\Models\MayorTerm;
use App\Services\JournalService;
use App\Services\ProxyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

//! YOU CAN JOIN CORPORATION OUTSIDE THEIR HOME CITY
//! corporations will make and sell things to reduce timers or boost skills (reduce action/study/travel timers, boost strength)
//! replace one of the starting with customs ( inspects travel and corporation items without proper auth) some medicine corps sell require medical to allow it, some require banking etc
//! corporations get approval then sell only in their home city where they have it.
//! basically 3 choices of what a corporation can make, drugs for boosting skills or timers( like oxycotin), investment vehicles (stocks, bonds, etc), or gadgets )
//! each one will require the appropriate business and interact with an existing career. corporation will also have two other business one for laundering and another for .
//! since apart from hq corporations can own properties and businesses that can produce items companies can sell. like a medical biopharmaceutical ,  where corp members with medical degrees can use action timers to produce medicine using the property or business
//! there is also a laundering businesses that only the cfo can operate to launder large amounts.
//corp headquarters and other properties  can only be bought by the ceo and requires clean cash this incentivizes laundering properties also require upkeep costs and taxes
//similar to property_condition corporation properties have condition CONSTRUCTED, BOMBED, DESTROYED, SEIZED, DEFAULTED (when they cant pay upkeep cost)
//? SOLO corp members negatively affects by stat reduction, cannot do company actions or corporate actions, no access to slush funds all the things companies can do

class CorporationController extends Controller
{
    public const FOUNDING_COST = 1_000_000;
    public const MERGER_COST = 5_000_000;
    public const MERGER_MIN_MEMBERS = 3;
    public const TRUST_VOTE_MIN_BOARD_MEMBERS = 3;
    public const TRUST_VOTE_MIN_SUBSIDIARIES = 2;
    private const MERGER_REQUEST_TTL_HOURS = 1;
    private const SUBSIDIARY_INVITE_TTL_HOURS = 1;

    public function found(Request $request)
    {
        $request->validate([
            'name' => [
                'required',
                'string',
                'min:5',
                'max:30',
                function ($attribute, $value, $fail) {
                    if (Corporation::whereRaw('LOWER(name) = ?', [strtolower($value)])->exists()) {
                        $fail('That corporation name is already taken.');
                    }
                },
            ],
            'image_url' => 'nullable|url|max:255',
        ], [
            'name.required' => 'Corporation name is required.',
            'name.min' => 'Corporation name must be at least 5 characters.',
            'name.max' => 'Corporation name cannot exceed 30 characters.',
            'image_url.url' => 'Logo URL must be a valid URL.',
            'image_url.max' => 'Logo URL cannot exceed 255 characters.',
        ]);

        $character = $request->user()->character;
        $resolvedImageUrl = $request->filled('image_url')
            ? ProxyService::resolveDirectUrl(trim((string) $request->image_url))
            : null;

        try {
            return DB::transaction(function () use ($character, $request, $resolvedImageUrl) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();

                $blocker = self::getFoundingBlocker($character);
                if ($blocker) {
                    return back()->with('error', $blocker);
                }

                $needsPromotion = self::foundingCreatesPromotionOpportunity($character);

                self::deductFoundingCost($character);

                $corp = Corporation::create([
                    'name' => $request->name,
                    'home_city_id' => $character->home_city_id,
                    'founder_id' => $character->id,
                    'ceo_id' => $character->id,
                    'image_url' => $resolvedImageUrl,
                ]);

                CorporationProperty::createStartingHeadquarters($corp);
                $corp->attachMember($character);

                JournalService::custom($character->id, 'corporation_founded', [
                    'corporation_name' => $corp->name,
                ]);

                Log::info('[Corporation] Founded.', [
                    'character_id' => $character->id,
                    'corp_id' => $corp->id,
                    'corp_name' => $corp->name,
                    'needs_promotion' => $needsPromotion,
                ]);

                if ($needsPromotion) {
                    return redirect()
                        ->route('career.promote')
                        ->with('success', "{$corp->name} has been incorporated. Choose a path to complete your promotion and become the CEO.");
                }

                return redirect()
                    ->route('career.corporate')
                    ->with('success', "Congratulations! {$corp->name} has been incorporated.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Founding failed.', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to incorporate. You probably tried to use the name of a corporation that once existed. Please try another name.');
        }
    }

    public static function getFoundingBlocker(Character $character): ?string
    {
        if ($character->career?->code !== 'corporation') {
            return 'You must be in the Corporation career to start a corporation.';
        }

        if ($character->isHospitalized() || $character->isJailed()) {
            return 'You cannot start a corporation while hospitalized or jailed.';
        }

        if ($character->corporation_id) {
            return 'You are already part of a corporation.';
        }

        if (!$character->isInHomeCity()) {
            return 'You must be in your home city to start a corporation.';
        }

        if ($character->isMayor()) {
            return 'A sitting mayor cannot start a corporation.';
        }

        if (!self::isFoundingRankEligible($character)) {
            return 'You are not ready to step into the boardroom yet.';
        }

        if ($character->cash_on_hand < self::FOUNDING_COST) {
            return 'You need $1,000,000 to incorporate.';
        }

        return null;
    }

    public static function meetsFoundingRequirements(Character $character): bool
    {
        return self::getFoundingBlocker($character) === null;
    }

    public function invite(Request $request)
    {
        $request->validate(['target' => 'required|string']);

        $character = $request->user()->character;
        $corp = $character->corporation;

        if (!$corp || !$corp->isCeo($character)) {
            return back()->with('error', 'Only the CEO can invite members.');
        }

        if (!$corp->hasWorkingHQ()) {
            return back()->with('error', 'Your corporation needs a working Headquarters before you can invite members.');
        }

        if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
            return back()->with('error', $blocker);
        }

        if ($corp->isFull()) {
            return back()->with('error', 'Your corporation is at maximum capacity.');
        }

        $target = Character::findByName($request->target);
        if (!$target) {
            return back()->with('error', 'Player not found.');
        }

        if ($target->isMayor()) {
            return back()->with('error', 'You cannot invite a mayor.');
        }

        $restrictedCareerIds = array_values(array_filter([
            Career::findByCode('police')?->id,
            Career::findByCode('law')?->id,
        ]));

        if ($target->career_id && in_array($target->career_id, $restrictedCareerIds, true)) {
            return back()->with('error', 'Police officers and Law career members cannot be invited to a corporation.');
        }

        if ($target->career?->code === 'corporation' && ($target->career_rank ?? 0) >= 4) {
            return back()->with('error', 'Managing Directors cannot join another corporation.');
        }





        if ((int) $target->id === (int) $character->id) {
            return back()->with('error', 'You cannot invite yourself.');
        }

        if ($target->corporation_id) {
            return back()->with('error', 'That player is already in a corporation.');
        }

        if (!$target->isAlive()) {
            return back()->with('error', 'That player is deceased.');
        }

        if ($target->created_at->diffInDays(now()) < 1) {
            return back()->with('error', 'That player\'s character is too new to join a corporation.');
        }

        JournalService::custom($target->id, 'corporation_invite_request', [
            'inviter_name' => $character->display_name,
            'inviter_id' => $character->id,
            'corporation_name' => $corp->name,
            'corporation_id' => $corp->id,
        ]);

        return back()->with('success', "Invitation sent to {$target->display_name}.");
    }

    public function acceptInviteFromJournal(Request $request, CharacterJournal $journal)
    {
        $character = $request->user()->character;
        $corpId = $journal->data['corporation_id'] ?? null;

        if (!$corpId) {
            return ['status' => 'error', 'message' => 'Invalid invitation data.'];
        }

        try {
            return DB::transaction(function () use ($character, $corpId, $journal) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();

                if ($character->isHospitalized() || $character->isJailed()) {
                    return ['status' => 'error', 'message' => 'You cannot accept invites while hospitalized or jailed.'];
                }

                if (!$character->isAlive()) {
                    return ['status' => 'error', 'message' => 'You cannot accept invites while dead.'];
                }

                if ($character->corporation_id) {
                    return ['status' => 'error', 'message' => 'You are already in a corporation.'];
                }

                if ($character->isMayor()) {
                    return ['status' => 'error', 'message' => 'A sitting mayor cannot join a corporation.'];
                }

                if ($character->created_at->diffInDays(now()) < 1) {
                    return ['status' => 'error', 'message' => 'Your account must be at least a day old to join a corporation.'];
                }

                $restrictedCareerIds = array_values(array_filter([
                    Career::findByCode('police')?->id,
                    Career::findByCode('law')?->id,
                ]));

                if ($character->career_id && in_array($character->career_id, $restrictedCareerIds, true)) {
                    return ['status' => 'error', 'message' => 'Police officers and Law career members cannot join a corporation.'];
                }

                if ($character->career?->code === 'corporation' && ($character->career_rank ?? 0) >= 4) {
                    return ['status' => 'error', 'message' => 'Managing Directors cannot join another corporation.'];
                }

                $corp = Corporation::lockForUpdate()->find($corpId);
                if (!$corp || $corp->trashed()) {
                    return ['status' => 'error', 'message' => 'Corporation no longer exists.'];
                }

                if ($corp->isFull()) {
                    return ['status' => 'error', 'message' => 'Corporation is at full capacity.'];
                }

                if (!$corp->hasWorkingHQ()) {
                    return ['status' => 'error', 'message' => ' You cannot accept this invitation until The corporation has a working headquarters.'];
                }

                if ($character->career?->code !== 'corporation') {
                    $character->startCareer('corporation');
                }

                $corp->attachMember($character);

                // Defensive: a holding can't issue invites (no CEO), but if a CEO
                // dies between invite and accept, ceo_id may be transiently null.
                if ($corp->ceo_id) {
                    JournalService::custom($corp->ceo_id, 'corporation_member_joined', [
                        'member_name' => $character->display_name,
                        'corporation_name' => $corp->name,
                    ]);
                }

                JournalService::custom($character->id, 'corporation_joined', [
                    'corporation_name' => $corp->name,
                ]);

                $journal->delete();
                CharacterJournal::where('character_id', $character->id)
                    ->where('type', 'corporation_invite_request')
                    ->delete();

                return ['status' => 'success', 'message' => "You have joined {$corp->name}."];
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Invite acceptance failed.', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'corporation_id' => $corpId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ['status' => 'error', 'message' => 'Failed to join corporation. Please try again.'];
        }
    }

    public function kick(Request $request)
    {
        $request->validate(['member_id' => 'required|integer']);

        $character = $request->user()->character;
        if (!$character) {
            return back()->with('error', 'No active character found.');
        }

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = $character->corporation;

                if (!$corp || (!$corp->isCeo($character) && !$corp->isLineManager($character))) {
                    return back()->with('error', 'Only leadership can remove members.');
                }

                $corp = Corporation::where('id', $corp->id)->lockForUpdate()->first();
                if (!$corp || (!$corp->isCeo($character) && !$corp->isLineManager($character))) {
                    return back()->with('error', 'Corporation leadership changed. Please try again.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                $member = Character::where('id', $request->member_id)->lockForUpdate()->first();
                if (!$member || (int) $member->corporation_id !== (int) $corp->id) {
                    return back()->with('error', 'Member not found in your corporation.');
                }

                if ((int) $member->id === (int) $character->id) {
                    return back()->with('error', 'You cannot kick yourself.');
                }

                if (!$corp->canManageMember($character, $member)) {
                    return back()->with('error', 'You can only remove members assigned to you.');
                }

                $memberName = $member->display_name;

                $this->detachFromCorporation($member, false, $corp);

                JournalService::custom($member->id, 'corporation_kicked', [
                    'corporation_name' => $corp->name,
                ]);

                return back()->with('success', "{$memberName} has been removed from the corporation.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Kick failed.', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'member_id' => $request->member_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to remove member. Please try again.');
        }
    }

    public function assignPosition(Request $request)
    {
        $request->validate([
            'member_id' => 'required|integer',
            'position' => 'required|string|in:cfo,cto,vp,member',
            'reports_to_id' => 'nullable|integer',
        ]);

        $character = $request->user()->character;
        $corp = $character->corporation;

        if (!$corp || (!$corp->isCeo($character) && !$corp->isLineManager($character))) {
            return back()->with('error', 'Only leadership can assign positions.');
        }

        if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
            return back()->with('error', $blocker);
        }

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = Corporation::where('id', $character->corporation_id)->lockForUpdate()->first();

                if (!$corp || (!$corp->isCeo($character) && !$corp->isLineManager($character))) {
                    return back()->with('error', 'Only leadership can assign positions.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                $member = Character::where('id', $request->member_id)->lockForUpdate()->first();
                if (!$member || (int) $member->corporation_id !== (int) $corp->id) {
                    return back()->with('error', 'Member not found in your corporation.');
                }

                if ($corp->isCeo($member)) {
                    return back()->with('error', 'Cannot change the CEO position through assignment.');
                }

                if (!$corp->canManageMember($character, $member)) {
                    return back()->with('error', 'You can only manage members assigned to you.');
                }

                $reportsTo = null;
                if ($corp->isCeo($character) && $request->filled('reports_to_id')) {
                    $reportsTo = Character::where('id', $request->reports_to_id)->lockForUpdate()->first();
                    if (!$reportsTo || (int) $reportsTo->corporation_id !== (int) $corp->id) {
                        return back()->with('error', 'Reporting manager not found in your corporation.');
                    }
                } elseif ($corp->isLineManager($character)) {
                    if ($request->position !== Corporation::POSITION_MEMBER) {
                        return back()->with('error', 'Line managers can only demote their direct reports.');
                    }

                    $reportsTo = $character;
                }

                try {
                    $corp->assignMemberPosition($member, $request->position, $reportsTo);
                } catch (\InvalidArgumentException $e) {
                    return back()->with('error', $e->getMessage());
                }

                JournalService::custom($member->id, 'corporation_position_assigned', [
                    'position' => strtoupper($request->position),
                    'corporation_name' => $corp->name,
                ]);

                return back()->with('success', "{$member->display_name} is now a {$request->position}.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Position assignment failed.', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'member_id' => $request->member_id,
                'position' => $request->position,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to assign position. Please try again.');
        }
    }

    public function demoteRank(Request $request)
    {
        $request->validate(['member_id' => 'required|integer']);

        $character = $request->user()->character;
        if (!$character) {
            return back()->with('error', 'No active character found.');
        }

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                if (!$character) {
                    return back()->with('error', 'No active character found.');
                }

                $corp = $character->corporation_id
                    ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
                    : null;

                if (!$corp || (!$corp->isCeo($character) && !$corp->isLineManager($character))) {
                    return back()->with('error', 'Only leadership can demote ranks.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                $member = Character::where('id', $request->member_id)->lockForUpdate()->first();
                if (!$member || (int) $member->corporation_id !== (int) $corp->id) {
                    return back()->with('error', 'Member not found in your corporation.');
                }

                $previousReportsToId = $member->corporation_reports_to_id;
                $memberName = $member->display_name;
                $demoterLabel = $this->corporationPositionLabel($corp, $character);

                try {
                    $newRank = $corp->demoteMemberRank($member, $character);
                } catch (\InvalidArgumentException $e) {
                    return back()->with('error', $e->getMessage());
                }

                $stats = $character->stats()->lockForUpdate()->first()
                    ?? $character->stats()->create([]);
                $stats->removeInfluence(10);

                JournalService::custom($member->id, 'demoted', [
                    'audience' => 'target',
                    'actor_id' => $character->id,
                    'actor_name' => $character->display_name,
                    'actor_position' => $demoterLabel,
                    'target_name' => $memberName,
                    'rank_name' => $newRank->rank_name,
                    'corporation_name' => $corp->name,
                ]);

                $oversightRecipients = collect([$previousReportsToId, $character->corporation_reports_to_id, $corp->ceo_id])
                    ->filter()
                    ->map(fn($id) => (int) $id)
                    ->unique()
                    ->reject(fn($id) => in_array($id, [(int) $character->id, (int) $member->id], true));

                foreach ($oversightRecipients as $recipientId) {
                    JournalService::custom($recipientId, 'demoted', [
                        'audience' => 'manager',
                        'actor_id' => $character->id,
                        'actor_name' => $character->display_name,
                        'actor_position' => $demoterLabel,
                        'target_name' => $memberName,
                        'rank_name' => $newRank->rank_name,
                        'corporation_name' => $corp->name,
                    ]);
                }

                return back()->with('success', "{$memberName} has been demoted.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Rank demotion failed.', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'member_id' => $request->member_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to demote rank. Please try again.');
        }
    }

    public function deposit(Request $request)
    {
        $request->validate(['amount' => 'required|integer|min:1']);

        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = $character->corporation;

                if (!$corp) {
                    return back()->with('error', 'You are not in a corporation.');
                }

                if ($character->isHospitalized() || $character->isJailed()) {
                    return back()->with('error', 'You cannot deposit funds while hospitalized or jailed.');
                }

                $corp = Corporation::where('id', $corp->id)->lockForUpdate()->first();

                if ($character->dirty_cash < $request->amount) {
                    return back()->with('error', 'Insufficient funds for deposit.');
                }

                $character->decrement('dirty_cash', $request->amount);
                $corp->increment('slush_fund', $request->amount);

                $this->notifyOversightOfDeposit($corp, $character, 'corporation_deposit', $request->amount);

                return back()->with('success', '$' . number_format($request->amount) . ' deposited to corporate slush fund.');
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Deposit failed.', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'amount' => $request->amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Deposit failed. Please try again.');
        }
    }

    public function depositReserves(Request $request)
    {
        $request->validate(['amount' => 'required|integer|min:1']);

        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = $character->corporation;

                if (!$corp) {
                    return back()->with('error', 'You are not in a corporation.');
                }

                if ($character->isHospitalized() || $character->isJailed()) {
                    return back()->with('error', 'You cannot deposit funds while hospitalized or jailed.');
                }

                $corp = Corporation::where('id', $corp->id)->lockForUpdate()->first();

                if ($character->cash_on_hand < $request->amount) {
                    return back()->with('error', 'Insufficient clean cash on hand.');
                }

                $character->decrement('cash_on_hand', $request->amount);
                $corp->increment('cash_reserves', $request->amount);

                $this->notifyOversightOfDeposit($corp, $character, 'corporation_reserve_deposit', $request->amount);

                return back()->with('success', '$' . number_format($request->amount) . ' deposited to corporate reserves.');
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Reserve deposit failed.', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'amount' => $request->amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Reserve deposit failed. Please try again.');
        }
    }

    public function distribute(Request $request)
    {
        $request->validate([
            'member_id' => 'required|integer',
            'amount' => 'required|integer|min:1',
        ]);

        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = $character->corporation;

                if (!$corp) {
                    return back()->with('error', 'You are not in a corporation.');
                }

                $corp = Corporation::where('id', $corp->id)->lockForUpdate()->first();

                // Holding companies have no CEO or CFO. The board governs the
                // network; distribution drains the consolidated slush pot
                // (own + subsidiaries) and only flows between board members.
                if ($corp->is_holding_company) {
                    return $this->distributeFromHolding(
                        actor: $character,
                        holding: $corp,
                        targetId: (int) $request->member_id,
                        amount: (int) $request->amount,
                        sourceColumn: 'slush_fund',
                        targetColumn: 'dirty_cash',
                        journalType: 'corporation_distribution',
                    );
                }

                if (!$corp->isCeo($character) && $character->corporation_position !== 'cfo') {
                    return back()->with('error', 'Only the CEO or CFO can distribute funds.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                if ((int) $request->member_id === (int) $character->id) {
                    return back()->with('error', 'You cannot distribute funds to yourself.');
                }

                $member = Character::where('id', $request->member_id)->lockForUpdate()->first();

                if (!$member || (int) $member->corporation_id !== (int) $corp->id) {
                    return back()->with('error', 'Member not found in your corporation.');
                }

                $canReceiveDistribution = $corp->isCeo($member)
                    || in_array($member->corporation_position, ['cfo', 'cto', 'vp'], true);

                if (!$canReceiveDistribution) {
                    return back()->with('error', 'Funds can only be distributed to C-suite and Vice Presidents.');
                }

                if ($corp->slush_fund < $request->amount) {
                    return back()->with('error', 'Insufficient slush funds.');
                }

                $corp->decrement('slush_fund', $request->amount);
                $member->increment('dirty_cash', $request->amount);

                JournalService::custom($member->id, 'corporation_distribution', [
                    'distributor_name' => $character->display_name,
                    'amount' => $request->amount,
                    'corporation_name' => $corp->name,
                ]);

                return back()->with('success', '$' . number_format($request->amount) . " has been  distributed to {$member->display_name}.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Distribution failed.', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'member_id' => $request->member_id,
                'amount' => $request->amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Distribution failed. Please try again.');
        }
    }

    public function distributeReserves(Request $request)
    {
        $request->validate([
            'member_id' => 'required|integer',
            'amount' => 'required|integer|min:1',
        ]);

        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = $character->corporation;

                if (!$corp) {
                    return back()->with('error', 'You are not in a corporation.');
                }

                $corp = Corporation::where('id', $corp->id)->lockForUpdate()->first();

                if ($corp->is_holding_company) {
                    return $this->distributeFromHolding(
                        actor: $character,
                        holding: $corp,
                        targetId: (int) $request->member_id,
                        amount: (int) $request->amount,
                        sourceColumn: 'cash_reserves',
                        targetColumn: 'cash_on_hand',
                        journalType: 'corporation_reserve_distribution',
                    );
                }

                if (!$corp->isCeo($character) && $character->corporation_position !== 'cfo') {
                    return back()->with('error', 'Only the CEO or CFO can distribute funds.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                if ((int) $request->member_id === (int) $character->id) {
                    return back()->with('error', 'You cannot distribute funds to yourself.');
                }

                $member = Character::where('id', $request->member_id)->lockForUpdate()->first();

                if (!$member || (int) $member->corporation_id !== (int) $corp->id) {
                    return back()->with('error', 'Member not found in your corporation.');
                }

                $canReceiveDistribution = $corp->isCeo($member)
                    || in_array($member->corporation_position, ['cfo', 'cto', 'vp'], true);

                if (!$canReceiveDistribution) {
                    return back()->with('error', 'Funds can only be distributed to C-suite and Vice Presidents.');
                }

                if ($corp->cash_reserves < $request->amount) {
                    return back()->with('error', 'Insufficient cash reserves.');
                }

                $corp->decrement('cash_reserves', $request->amount);
                $member->increment('cash_on_hand', $request->amount);

                JournalService::custom($member->id, 'corporation_reserve_distribution', [
                    'distributor_name' => $character->display_name,
                    'amount' => $request->amount,
                    'corporation_name' => $corp->name,
                ]);

                return back()->with('success', '$' . number_format($request->amount) . " distributed to {$member->display_name}.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Reserve distribution failed.', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'member_id' => $request->member_id,
                'amount' => $request->amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Reserve distribution failed. Please try again.');
        }
    }

    public function transferCeo(Request $request)
    {
        $request->validate(['member_id' => 'required|integer']);

        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = $character->corporation;

                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Only the CEO can transfer leadership.');
                }

                $corp = Corporation::where('id', $corp->id)->lockForUpdate()->first();
                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Corporation leadership changed. Please try again.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                $newCeo = Character::where('id', $request->member_id)->lockForUpdate()->first();

                if (!$newCeo || (int) $newCeo->corporation_id !== (int) $corp->id) {
                    return back()->with('error', 'Member not found in your corporation.');
                }

                if ((int) $newCeo->id === (int) $character->id) {
                    return back()->with('error', 'You are already the CEO.');
                }

                if (($newCeo->career_rank ?? 0) !== 3) {
                    return back()->with('error', 'The incoming CEO must be Rank 3 (Department Head) and promotion-ready.');
                }

                $corpCareer = $newCeo->career;
                $rank3Row = $corpCareer
                    ? CareerRank::findForCharacter($corpCareer->id, 3)
                    : null;

                if (!$rank3Row) {
                    return back()->with('error', 'Could not verify promotion eligibility. Please try again.');
                }

                $readiness = $rank3Row->getRankRequirements((int) $newCeo->career_xp);
                if (!$readiness['ready']) {
                    return back()->with('error', 'The incoming CEO is not yet promotion-ready.');
                }

                $corp->transferCeo($newCeo);

                JournalService::custom($newCeo->id, 'corporation_ceo_transfer', [
                    'previous_ceo' => $character->display_name,
                    'corporation_name' => $corp->name,
                ]);

                JournalService::custom($character->id, 'corporation_ceo_transferred', [
                    'new_ceo' => $newCeo->display_name,
                    'corporation_name' => $corp->name,
                ]);

                Log::info('[Corporation] CEO transferred.', [
                    'corp_id' => $corp->id,
                    'old_ceo_id' => $character->id,
                    'new_ceo_id' => $newCeo->id,
                ]);

                return back()->with('success', "Leadership transferred to {$newCeo->display_name}.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] CEO transfer failed.', [
                'user_id' => $request->user()->id,
                'character_id' => $character->id ?? null,
                'member_id' => $request->member_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'CEO transfer failed. Please try again.');
        }
    }

    public function updateBoardNotes(Request $request)
    {
        $request->validate([
            'board_notes' => 'nullable|string|max:500',
        ]);

        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = Corporation::where('id', $character->corporation_id)->lockForUpdate()->first();

                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Only the CEO can edit board notes.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                $corp->update([
                    'board_notes' => trim((string) $request->board_notes) ?: null,
                ]);

                return back()->with('success', 'Board notes updated.');
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Board notes update failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Board notes update failed. Please try again.');
        }
    }

    public function updateBanner(Request $request)
    {
        $request->validate([
            'image_url' => 'nullable|url|max:255',
        ], [
            'image_url.url' => 'Banner URL must be a valid URL.',
            'image_url.max' => 'Banner URL cannot exceed 255 characters.',
        ]);

        $character = $request->user()->character;
        $imageUrl = trim((string) $request->image_url);
        $resolvedImageUrl = $imageUrl ? ProxyService::resolveDirectUrl($imageUrl) : null;

        try {
            return DB::transaction(function () use ($character, $resolvedImageUrl) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = Corporation::where('id', $character->corporation_id)->lockForUpdate()->first();

                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Only the CEO can update the banner.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                $corp->update([
                    'image_url' => $resolvedImageUrl,
                ]);

                return back()->with('success', 'Corporation banner updated.');
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Banner update failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Banner update failed. Please try again.');
        }
    }

    public function purchaseProperty(Request $request)
    {
        $request->validate([
            'property_id' => 'required|integer|exists:corporation_properties,id',
        ]);

        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = $character?->corporation_id
                    ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
                    : null;

                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Only the CEO can buy corporation property.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                if ((int) $character->city_id !== (int) $corp->home_city_id) {
                    return back()->with('error', 'You need to be in the corporation home city to do this action!');
                }

                if ($corp->is_holding_company) {
                    return back()->with('error', 'Holding company property purchases are not available yet.');
                }

                $template = CorporationProperty::query()
                    ->templates()
                    ->where('id', (int) $request->property_id)
                    ->lockForUpdate()
                    ->first();

                if (!$template) {
                    return back()->with('error', 'Choose a valid corporation property.');
                }

                $availableTemplateIds = CorporationProperty::purchasableTemplatesFor($corp)
                    ->pluck('id')
                    ->map(fn($id) => (int) $id)
                    ->all();

                if (!in_array((int) $template->id, $availableTemplateIds, true)) {
                    return back()->with('error', 'You cannot downgrade your corporation\'s properties, build a new one while still waiting for  construction to finish on the previous one or build a new one while defaulted');
                }

                $activeTerm = MayorTerm::activeForCity($corp->home_city_id);
                $taxRate = ($activeTerm && $activeTerm->corpRegUnlocked())
                    ? max(0, (int) ($activeTerm->getPolicies()['corporate_tax_rate'] ?? 0))
                    : 0;
                $quote = CorporationProperty::quoteFor($template, $taxRate);

                if ((int) $corp->cash_reserves < $quote['total']) {
                    return back()->with('error', 'Your corporation does not have the required cash reserves to purchase this property.');
                }

                try {
                    $property = CorporationProperty::purchaseTemplate($corp, $template);
                } catch (\RuntimeException $e) {
                    return back()->with('error', $e->getMessage());
                }

                $corp->cash_reserves = (int) $corp->cash_reserves - $quote['total'];

                $corp->save();

                if ($activeTerm && $quote['tax'] > 0) {
                    $activeTerm->addFunds((int) floor($quote['tax'] * $activeTerm->servicesMultiplier()), 'corptax');
                }

                return back()->with('success', " You've successfully purchased the land for the {$property->name} construction — now  awaiting an engineer to finish constructing the building.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Property purchase failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'property_id' => $request->property_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Property purchase failed. Please try again.');
        }
    }

    public function proposeMerger(Request $request)
    {
        $request->validate([
            'target_corporation_id' => 'required|integer|exists:corporations,id',
            'requester_successor_id' => 'required|integer|exists:characters,id',
            'holding_name' => [
                'required',
                'string',
                'min:5',
                'max:30',
                function ($attribute, $value, $fail) {
                    if (Corporation::whereRaw('LOWER(name) = ?', [strtolower($value)])->exists()) {
                        $fail('That company name is already taken.');
                    }
                },
            ],
            'holding_image_url' => 'nullable|url|max:255',
        ], [
            'holding_name.required' => 'Holding company name is required.',
            'holding_name.min' => 'Holding company name must be at least 5 characters.',
            'holding_name.max' => 'Holding company name cannot exceed 30 characters.',
            'holding_image_url.url' => 'Banner URL must be a valid URL.',
            'holding_image_url.max' => 'Banner URL cannot exceed 255 characters.',
        ]);

        $character = $request->user()->character;
        $holdingName = trim((string) $request->holding_name);
        $resolvedImageUrl = $request->filled('holding_image_url')
            ? ProxyService::resolveDirectUrl(trim((string) $request->holding_image_url))
            : null;

        try {
            return DB::transaction(function () use ($character, $request, $holdingName, $resolvedImageUrl) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $targetId = (int) $request->target_corporation_id;
                $corpIds = collect([$character?->corporation_id, $targetId])
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                $corporations = Corporation::whereIn('id', $corpIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $corp = $character?->corporation_id ? $corporations->get($character->corporation_id) : null;
                $target = $corporations->get($targetId);

                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Only the CEO can propose a merger.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                if (!$target || (int) $target->id === (int) $corp->id) {
                    return back()->with('error', 'Choose a different company.');
                }

                $successor = Character::where('id', $request->requester_successor_id)->lockForUpdate()->first();

                if ($blocker = self::getMergerProposalBlocker($character, $corp, $target, $successor)) {
                    return back()->with('error', $blocker);
                }

                if (Corporation::whereRaw('LOWER(name) = ?', [strtolower($holdingName)])->exists()) {
                    return back()->with('error', 'That company name is already taken.');
                }

                if (self::hasPendingMergerForCorporations([$corp->id, $target->id])) {
                    return back()->with('error', 'One of these companies already has a pending merger.');
                }

                $targetCeo = $target->ceo_id
                    ? Character::where('id', $target->ceo_id)->lockForUpdate()->first()
                    : null;

                if (!$targetCeo || !$targetCeo->isAlive()) {
                    return back()->with('error', 'That company is not ready to merge.');
                }

                $mergerRequest = CorporationMergerRequest::create([
                    'requester_id' => $character->id,
                    'target_id' => $targetCeo->id,
                    'requester_corporation_id' => $corp->id,
                    'target_corporation_id' => $target->id,
                    'requester_successor_id' => $successor->id,
                    'holding_name' => $holdingName,
                    'holding_image_url' => $resolvedImageUrl,
                    'status' => CorporationMergerRequest::STATUS_PENDING,
                    'expires_at' => now()->addHours(self::MERGER_REQUEST_TTL_HOURS),
                ]);

                JournalService::custom($targetCeo->id, 'corporation_merger_proposed', [
                    'requester_name' => $character->display_name,
                    'requester_corporation_name' => $corp->name,
                    'target_corporation_name' => $target->name,
                    'holding_name' => $holdingName,
                    'merger_request_id' => $mergerRequest->id,
                ]);

                return back()->with('success', "Merger proposal sent to {$target->name}.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Merger proposal failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'target_corporation_id' => $request->target_corporation_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Merger proposal failed. Please try again.');
        }
    }

    public function acceptMerger(Request $request, CorporationMergerRequest $mergerRequest)
    {
        $request->validate([
            'target_successor_id' => 'required|integer|exists:characters,id',
        ]);

        $actorId = $request->user()->character?->id;

        try {
            return DB::transaction(function () use ($request, $mergerRequest, $actorId) {
                $actor = Character::where('id', $actorId)->lockForUpdate()->first();

                if ($actor->isHospitalized() || $actor->isJailed()) {
                    return back()->with('error', 'You cannot accept mergers while hospitalized or jailed.');
                }

                $mergerRequest = CorporationMergerRequest::where('id', $mergerRequest->id)
                    ->lockForUpdate()
                    ->first();

                if (!$mergerRequest || $mergerRequest->status !== CorporationMergerRequest::STATUS_PENDING) {
                    return back()->with('error', 'That merger request is no longer active.');
                }

                if ($mergerRequest->expires_at && $mergerRequest->expires_at->isPast()) {
                    $mergerRequest->update(['status' => CorporationMergerRequest::STATUS_EXPIRED]);
                    return back()->with('error', 'That merger request has expired.');
                }

                if ((int) $mergerRequest->target_id !== (int) $actorId) {
                    return back()->with('error', 'Only the invited CEO can accept this merger.');
                }

                $corpIds = collect([
                    $mergerRequest->requester_corporation_id,
                    $mergerRequest->target_corporation_id,
                ])->sort()->values()->all();

                $corporations = Corporation::whereIn('id', $corpIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $requesterCorp = $corporations->get($mergerRequest->requester_corporation_id);
                $targetCorp = $corporations->get($mergerRequest->target_corporation_id);

                if (!$requesterCorp || !$targetCorp) {
                    return back()->with('error', 'One of the companies is no longer available.');
                }

                $city = City::where('id', $requesterCorp->home_city_id)->lockForUpdate()->first();
                if (!$city) {
                    return back()->with('error', 'Could not verify headquarters city.');
                }

                $targetSuccessorId = (int) $request->target_successor_id;
                $characterIds = collect([
                    $mergerRequest->requester_id,
                    $mergerRequest->target_id,
                    $mergerRequest->requester_successor_id,
                    $targetSuccessorId,
                ])->unique()->sort()->values()->all();

                $characters = Character::whereIn('id', $characterIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $requester = $characters->get($mergerRequest->requester_id);
                $target = $characters->get($mergerRequest->target_id);
                $requesterSuccessor = $characters->get($mergerRequest->requester_successor_id);
                $targetSuccessor = $characters->get($targetSuccessorId);

                $members = Character::whereIn('corporation_id', [$requesterCorp->id, $targetCorp->id])
                    ->lockForUpdate()
                    ->get()
                    ->groupBy('corporation_id');

                $blocker = self::getMergerAcceptanceBlocker(
                    $requester,
                    $target,
                    $requesterCorp,
                    $targetCorp,
                    $requesterSuccessor,
                    $targetSuccessor,
                    $members
                );

                if ($blocker) {
                    return back()->with('error', $blocker);
                }

                if (self::hasHoldingCompanyInCity((int) $city->id)) {
                    return back()->with('error', 'That headquarters city already has a holding company.');
                }

                if (Corporation::whereRaw('LOWER(name) = ?', [strtolower($mergerRequest->holding_name)])->exists()) {
                    return back()->with('error', 'That company name is already taken.');
                }

                if (
                    !CareerRank::findForCharacter($requesterSuccessor->career_id, 4)
                    || !CareerRank::findForCharacter($requester->career_id, 5)
                ) {
                    return back()->with('error', 'The merger board could not verify the promotion path.');
                }

                $requester->decrement('cash_on_hand', self::MERGER_COST);

                $holding = Corporation::create([
                    'name' => $mergerRequest->holding_name,
                    'home_city_id' => $requesterCorp->home_city_id,
                    'founder_id' => $requester->id,
                    'ceo_id' => null,
                    'is_holding_company' => true,
                    'image_url' => $mergerRequest->holding_image_url,
                ]);

                // Founding gives a Phase 1 corp a tier-1 HQ; the merger that
                // creates a Phase 2 holding gives it a tier-3 HQ on the spot
                // (one-time, not purchasable). Per design doc §13.
                CorporationProperty::createStartingHeadquarters($holding, 3);

                $requesterCorp->update(['parent_trust_id' => $holding->id]);
                $targetCorp->update(['parent_trust_id' => $holding->id]);
                $requester->update(['corporation_reports_to_id' => null]);
                $target->update(['corporation_reports_to_id' => null]);

                $mergerRequest->update([
                    'target_successor_id' => $targetSuccessor->id,
                    'status' => CorporationMergerRequest::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                foreach ([$requester, $target, $requesterSuccessor, $targetSuccessor] as $recipient) {
                    JournalService::custom($recipient->id, 'corporation_merger_completed', [
                        'holding_name' => $holding->name,
                        'requester_corporation_name' => $requesterCorp->name,
                        'target_corporation_name' => $targetCorp->name,
                    ]);
                }

                Log::info('[Corporation] Merger completed.', [
                    'holding_id' => $holding->id,
                    'requester_corporation_id' => $requesterCorp->id,
                    'target_corporation_id' => $targetCorp->id,
                    'requester_id' => $requester->id,
                    'target_id' => $target->id,
                ]);

                return redirect()
                    ->route('career.corporate')
                    ->with('success', "{$holding->name} has been formed.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Merger acceptance failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $actorId,
                'merger_request_id' => $mergerRequest->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Merger failed. Please try again.');
        }
    }

    public function cancelMerger(Request $request, CorporationMergerRequest $mergerRequest)
    {
        $actorId = $request->user()->character?->id;

        try {
            return DB::transaction(function () use ($actorId, $mergerRequest) {
                $actor = Character::where('id', $actorId)->lockForUpdate()->first();

                if ($actor->isHospitalized() || $actor->isJailed()) {
                    return back()->with('error', 'You cannot manage mergers while hospitalized or jailed.');
                }

                $mergerRequest = CorporationMergerRequest::where('id', $mergerRequest->id)
                    ->lockForUpdate()
                    ->first();

                if (!$mergerRequest || $mergerRequest->status !== CorporationMergerRequest::STATUS_PENDING) {
                    return back()->with('error', 'That merger request is no longer active.');
                }

                if ($mergerRequest->expires_at && $mergerRequest->expires_at->isPast()) {
                    $mergerRequest->update(['status' => CorporationMergerRequest::STATUS_EXPIRED]);
                    return back()->with('error', 'That merger request has expired.');
                }

                if ((int) $actorId === (int) $mergerRequest->requester_id) {
                    $mergerRequest->update(['status' => CorporationMergerRequest::STATUS_CANCELLED]);
                    return back()->with('success', 'Merger proposal cancelled.');
                }

                if ((int) $actorId === (int) $mergerRequest->target_id) {
                    $mergerRequest->update(['status' => CorporationMergerRequest::STATUS_DECLINED]);

                    JournalService::custom($mergerRequest->requester_id, 'corporation_merger_cancelled', [
                        'holding_name' => $mergerRequest->holding_name,
                    ]);

                    return back()->with('success', 'Merger proposal declined.');
                }

                return back()->with('error', 'You cannot change that merger request.');
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Merger cancellation failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $actorId,
                'merger_request_id' => $mergerRequest->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Could not update that merger request.');
        }
    }

    public function inviteSubsidiary(Request $request)
    {
        $request->validate([
            'target_corporation_id' => 'required|integer|exists:corporations,id',
        ]);

        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = $character?->id
                    ? Character::where('id', $character->id)->lockForUpdate()->first()
                    : null;

                if (!$character) {
                    return back()->with('error', 'No active character found.');
                }

                if ($character->isHospitalized() || $character->isJailed()) {
                    return back()->with('error', 'You cannot invite subsidiaries while hospitalized or jailed.');
                }

                $holding = $character?->corporation_id
                    ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
                    : null;

                if (!$character || !$holding || !$holding->isBoardMember($character)) {
                    return back()->with('error', 'Only holding-company board members can invite subsidiaries.');
                }

                $target = Corporation::where('id', $request->target_corporation_id)->lockForUpdate()->first();
                if (!$target || (int) $target->id === (int) $holding->id) {
                    return back()->with('error', 'Choose a valid company.');
                }

                $targetCeo = $target->ceo_id
                    ? Character::where('id', $target->ceo_id)->lockForUpdate()->first()
                    : null;

                if ($blocker = self::getSubsidiaryInviteBlocker($character, $holding, $target, $targetCeo)) {
                    return back()->with('error', $blocker);
                }

                if (self::hasPendingSubsidiaryInviteForCorporation($target->id)) {
                    return back()->with('error', 'That company already has a pending subsidiary invitation.');
                }

                $invite = CorporationSubsidiaryInvite::create([
                    'holding_company_id' => $holding->id,
                    'target_corporation_id' => $target->id,
                    'requester_id' => $character->id,
                    'target_ceo_id' => $targetCeo->id,
                    'status' => CorporationSubsidiaryInvite::STATUS_PENDING,
                    'expires_at' => now()->addHours(self::SUBSIDIARY_INVITE_TTL_HOURS),
                ]);

                JournalService::custom($targetCeo->id, 'corporation_subsidiary_invite', [
                    'holding_name' => $holding->name,
                    'requester_name' => $character->display_name,
                    'target_corporation_name' => $target->name,
                    'invite_id' => $invite->id,
                ]);

                return back()->with('success', "Subsidiary invitation sent to {$target->name}.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Subsidiary invitation failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'target_corporation_id' => $request->target_corporation_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Subsidiary invitation failed. Please try again.');
        }
    }

    public function acceptSubsidiaryInvite(Request $request, CorporationSubsidiaryInvite $subsidiaryInvite)
    {
        $request->validate([
            'successor_id' => 'required|integer|exists:characters,id',
        ]);

        $actorId = $request->user()->character?->id;

        try {
            return DB::transaction(function () use ($request, $subsidiaryInvite, $actorId) {
                $actor = Character::where('id', $actorId)->lockForUpdate()->first();

                if (!$actor) {
                    return back()->with('error', 'No active character found.');
                }

                if ($actor->isHospitalized() || $actor->isJailed()) {
                    return back()->with('error', 'You cannot accept subsidiary invitations while hospitalized or jailed.');
                }

                $subsidiaryInvite = CorporationSubsidiaryInvite::where('id', $subsidiaryInvite->id)
                    ->lockForUpdate()
                    ->first();

                if (!$subsidiaryInvite || $subsidiaryInvite->status !== CorporationSubsidiaryInvite::STATUS_PENDING) {
                    return back()->with('error', 'That subsidiary invitation is no longer active.');
                }

                if ($subsidiaryInvite->expires_at && $subsidiaryInvite->expires_at->isPast()) {
                    $subsidiaryInvite->update(['status' => CorporationSubsidiaryInvite::STATUS_EXPIRED]);
                    return back()->with('error', 'That subsidiary invitation has expired.');
                }

                if ((int) $subsidiaryInvite->target_ceo_id !== (int) $actorId) {
                    return back()->with('error', 'Only the invited CEO can accept this subsidiary invitation.');
                }

                $corporations = Corporation::whereIn('id', [
                    $subsidiaryInvite->holding_company_id,
                    $subsidiaryInvite->target_corporation_id,
                ])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $holding = $corporations->get($subsidiaryInvite->holding_company_id);
                $target = $corporations->get($subsidiaryInvite->target_corporation_id);
                $successor = Character::where('id', $request->successor_id)->lockForUpdate()->first();

                if (!$holding || !$target) {
                    return back()->with('error', 'That subsidiary invitation is no longer valid.');
                }

                $targetCeo = Character::where('id', $subsidiaryInvite->target_ceo_id)->lockForUpdate()->first();

                if ($blocker = self::getSubsidiaryInviteAcceptanceBlocker($targetCeo, $holding, $target, $successor)) {
                    return back()->with('error', $blocker);
                }

                $target->update(['parent_trust_id' => $holding->id]);
                $targetCeo->update(['corporation_reports_to_id' => null]);

                $promotion = CorporationBoardPromotion::create([
                    'holding_company_id' => $holding->id,
                    'subsidiary_id' => $target->id,
                    'promoted_by_id' => $subsidiaryInvite->requester_id,
                    'promoted_ceo_id' => $targetCeo->id,
                    'successor_id' => $successor->id,
                    'status' => CorporationBoardPromotion::STATUS_PENDING,
                ]);

                $subsidiaryInvite->update([
                    'status' => CorporationSubsidiaryInvite::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);

                JournalService::custom($targetCeo->id, 'corporation_board_promotion_pending', [
                    'holding_name' => $holding->name,
                    'subsidiary_name' => $target->name,
                    'successor_name' => $successor->display_name,
                    'promotion_id' => $promotion->id,
                ]);

                JournalService::custom($successor->id, 'corporation_board_successor_named', [
                    'holding_name' => $holding->name,
                    'subsidiary_name' => $target->name,
                    'ceo_name' => $targetCeo->display_name,
                ]);

                JournalService::custom($subsidiaryInvite->requester_id, 'corporation_subsidiary_invite_accepted', [
                    'holding_name' => $holding->name,
                    'target_corporation_name' => $target->name,
                    'target_ceo_name' => $targetCeo->display_name,
                ]);

                return redirect()
                    ->route('career.corporate')
                    ->with('success', "{$target->name} has joined {$holding->name}.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Subsidiary invitation acceptance failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $actorId,
                'invite_id' => $subsidiaryInvite->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Subsidiary invitation failed. Please try again.');
        }
    }

    public function cancelSubsidiaryInvite(Request $request, CorporationSubsidiaryInvite $subsidiaryInvite)
    {
        $actorId = $request->user()->character?->id;

        try {
            return DB::transaction(function () use ($actorId, $subsidiaryInvite) {
                $actor = Character::where('id', $actorId)->lockForUpdate()->first();

                if (!$actor) {
                    return back()->with('error', 'No active character found.');
                }

                if ($actor->isHospitalized() || $actor->isJailed()) {
                    return back()->with('error', 'You cannot manage subsidiary invitations while hospitalized or jailed.');
                }

                $subsidiaryInvite = CorporationSubsidiaryInvite::where('id', $subsidiaryInvite->id)
                    ->lockForUpdate()
                    ->first();

                if (!$subsidiaryInvite || $subsidiaryInvite->status !== CorporationSubsidiaryInvite::STATUS_PENDING) {
                    return back()->with('error', 'That subsidiary invitation is no longer active.');
                }

                if ($subsidiaryInvite->expires_at && $subsidiaryInvite->expires_at->isPast()) {
                    $subsidiaryInvite->update(['status' => CorporationSubsidiaryInvite::STATUS_EXPIRED]);
                    return back()->with('error', 'That subsidiary invitation has expired.');
                }

                if ((int) $actorId === (int) $subsidiaryInvite->requester_id) {
                    $subsidiaryInvite->update(['status' => CorporationSubsidiaryInvite::STATUS_CANCELLED]);
                    return back()->with('success', 'Subsidiary invitation cancelled.');
                }

                if ((int) $actorId === (int) $subsidiaryInvite->target_ceo_id) {
                    $subsidiaryInvite->update(['status' => CorporationSubsidiaryInvite::STATUS_DECLINED]);

                    JournalService::custom($subsidiaryInvite->requester_id, 'corporation_subsidiary_invite_declined', [
                        'target_corporation_name' => $subsidiaryInvite->targetCorporation?->name ?? 'the company',
                    ]);

                    return back()->with('success', 'Subsidiary invitation declined.');
                }

                return back()->with('error', 'You cannot change that subsidiary invitation.');
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Subsidiary invitation cancellation failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $actorId,
                'invite_id' => $subsidiaryInvite->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Could not update that subsidiary invitation.');
        }
    }

    public function promoteSubsidiaryCeoToBoard(Request $request)
    {
        $request->validate([
            'subsidiary_id' => 'required|integer|exists:corporations,id',
            'successor_id' => 'required|integer|exists:characters,id',
        ]);

        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();

                if ($character->isHospitalized() || $character->isJailed()) {
                    return back()->with('error', 'You cannot promote members while hospitalized or jailed.');
                }

                $holding = $character?->corporation_id
                    ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
                    : null;

                if (!$character || !$holding || !$holding->isBoardMember($character)) {
                    return back()->with('error', 'Only holding-company board members can promote a subsidiary CEO.');
                }

                $subsidiary = Corporation::where('id', $request->subsidiary_id)->lockForUpdate()->first();
                if (
                    !$subsidiary
                    || $subsidiary->is_holding_company
                    || (int) $subsidiary->parent_trust_id !== (int) $holding->id
                ) {
                    return back()->with('error', 'Choose a valid subsidiary.');
                }

                if (!$holding->hasBoardIntakeCapacity()) {
                    return back()->with('error', 'The holding board has no open seats.');
                }

                $promotedCeo = $subsidiary->ceo_id
                    ? Character::where('id', $subsidiary->ceo_id)->lockForUpdate()->first()
                    : null;
                $successor = Character::where('id', $request->successor_id)->lockForUpdate()->first();

                if (!$promotedCeo || !$subsidiary->isCeo($promotedCeo) || !self::isReadyForCorporationRank($promotedCeo, 4)) {
                    return back()->with('error', 'That subsidiary CEO is not ready for the board.');
                }

                if (self::hasPendingMergerHandoffFor($promotedCeo, $subsidiary)) {
                    return back()->with('error', 'That CEO already has a board handoff pending.');
                }

                if (!self::isEligibleMergerSuccessor($subsidiary, $successor)) {
                    return back()->with('error', 'Choose an eligible successor from that subsidiary.');
                }

                $hasPending = CorporationBoardPromotion::pending()
                    ->where('holding_company_id', $holding->id)
                    ->where(function ($query) use ($subsidiary, $promotedCeo, $successor) {
                        $query->where('subsidiary_id', $subsidiary->id)
                            ->orWhere('promoted_ceo_id', $promotedCeo->id)
                            ->orWhere('successor_id', $successor->id);
                    })
                    ->exists();

                if ($hasPending) {
                    return back()->with('error', 'That subsidiary already has a pending board handoff.');
                }

                $promotion = CorporationBoardPromotion::create([
                    'holding_company_id' => $holding->id,
                    'subsidiary_id' => $subsidiary->id,
                    'promoted_by_id' => $character->id,
                    'promoted_ceo_id' => $promotedCeo->id,
                    'successor_id' => $successor->id,
                    'status' => CorporationBoardPromotion::STATUS_PENDING,
                ]);

                JournalService::custom($promotedCeo->id, 'corporation_board_promotion_pending', [
                    'holding_name' => $holding->name,
                    'subsidiary_name' => $subsidiary->name,
                    'successor_name' => $successor->display_name,
                    'promotion_id' => $promotion->id,
                ]);

                JournalService::custom($successor->id, 'corporation_board_successor_named', [
                    'holding_name' => $holding->name,
                    'subsidiary_name' => $subsidiary->name,
                    'ceo_name' => $promotedCeo->display_name,
                ]);

                return back()->with('success', "{$promotedCeo->display_name} has been nominated for the holding board.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Board promotion failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'subsidiary_id' => $request->subsidiary_id,
                'successor_id' => $request->successor_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Board promotion failed. Please try again.');
        }
    }

    public function kickSubsidiary(Request $request)
    {
        $request->validate([
            'subsidiary_id' => 'required|integer|exists:corporations,id',
        ]);

        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character, $request) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();

                if ($character->isHospitalized() || $character->isJailed()) {
                    return back()->with('error', 'You cannot remove subsidiaries while hospitalized or jailed.');
                }

                $holding = $character?->corporation_id
                    ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
                    : null;

                if (!$character || !$holding || !$holding->isBoardMember($character)) {
                    return back()->with('error', 'Only holding-company board members can remove a subsidiary.');
                }

                $subsidiary = Corporation::where('id', $request->subsidiary_id)->lockForUpdate()->first();
                if (
                    !$subsidiary
                    || $subsidiary->is_holding_company
                    || (int) $subsidiary->parent_trust_id !== (int) $holding->id
                ) {
                    return back()->with('error', 'Choose a valid subsidiary.');
                }

                $activeSubsidiaryCount = Corporation::where('parent_trust_id', $holding->id)
                    ->lockForUpdate()
                    ->get(['id'])
                    ->count();

                if ($activeSubsidiaryCount <= 1) {
                    return back()->with('error', 'You cannot kick out your last subsidiary, what would you be holding?!');
                }

                $subsidiaryName = $subsidiary->name;
                $subsidiaryCeoId = $subsidiary->ceo_id;
                $boardMemberIds = $holding->boardMembers()->pluck('id')->all();

                $holding->kickOutSubsidiary($subsidiary);

                if ($subsidiaryCeoId) {
                    JournalService::custom($subsidiaryCeoId, 'corporation_subsidiary_kicked', [
                        'holding_name' => $holding->name,
                        'subsidiary_name' => $subsidiaryName,
                    ]);
                }

                foreach ($boardMemberIds as $boardMemberId) {
                    if ((int) $boardMemberId === (int) $character->id) {
                        continue;
                    }

                    JournalService::custom($boardMemberId, 'corporation_subsidiary_kicked', [
                        'holding_name' => $holding->name,
                        'subsidiary_name' => $subsidiaryName,
                    ]);
                }

                return redirect()
                    ->route('career.corporate')
                    ->with('success', "{$subsidiaryName} has been removed from the holding company.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Subsidiary removal failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'subsidiary_id' => $request->subsidiary_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Subsidiary removal failed. Please try again.');
        }
    }

    public function requestMoveHeadquarters(Request $request)
    {
        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                if (!$character) {
                    return back()->with('error', 'No active character found.');
                }

                if (!$character->isAlive()) {
                    return back()->with('error', 'You cannot move a corporation while dead.');
                }

                if ($character->timers?->next_action_at?->isFuture()) {
                    return back()->with('error', 'You need to wait before performing another action.');
                }

                $corp = $character->corporation_id
                    ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
                    : null;

                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Only the CEO can move the corporation.');
                }

                if (!self::isPhaseOneOperatingCompany($corp)) {
                    return back()->with('error', 'Subsidiary companies cannot relocate their headquarters.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                if ((int) $character->city_id === (int) $corp->home_city_id) {
                    return back()->with('error', 'Your corporation\'s headquarters is already located in this city. Travel to another one to move your headquarters.');
                }

                if (self::hasPendingMergerForCorporations([$corp->id])) {
                    return back()->with('error', 'Resolve your pending merger before relocating.');
                }

                if (
                    CorporationSubsidiaryInvite::pending()
                        ->where('target_corporation_id', $corp->id)
                        ->exists()
                ) {
                    return back()->with('error', 'Resolve your pending subsidiary invitation before relocating.');
                }

                if ($corp->properties()->where('condition', CorporationProperty::CONDITION_PENDING)->exists()) {
                    return back()->with('error', 'Finish your pending construction before relocating.');
                }

                $fraudCacheKey = InvestmentFraud::cacheKey($corp);
                $fraudData = Cache::get($fraudCacheKey);
                if ($fraudData && now()->timestamp <= ($fraudData['expires_at'] ?? 0)) {
                    return back()->with('error', 'Cancel your pending investment fraud action before relocating.');
                }

                if ($corp->hasPendingMoveRequest()) {
                    return back()->with('error', 'You already have a relocation request awaiting customs approval.');
                }

                if ((int) $corp->cash_reserves < Corporation::MOVE_HQ_FEE) {
                    return back()->with(
                        'error',
                        'Your cash reserves are short of the $' . number_format(Corporation::MOVE_HQ_FEE) . ' relocation fee.'
                    );
                }

                $destinationCity = City::where('id', $character->city_id)->first();
                if (!$destinationCity) {
                    return back()->with('error', 'Destination city not found.');
                }

                $homeCity = $corp->city;

                $corp->decrement('cash_reserves', Corporation::MOVE_HQ_FEE);

                $payload = [
                    'corporation_id' => (int) $corp->id,
                    'corporation_name' => $corp->name,
                    'corporation_image_url' => $corp->image_url,
                    'requested_by_id' => (int) $character->id,
                    'requested_by_name' => $character->display_name,
                    'from_city_id' => (int) $corp->home_city_id,
                    'from_city_name' => $homeCity?->name ?? 'Unknown',
                    'to_city_id' => (int) $destinationCity->id,
                    'to_city_name' => $destinationCity->name,
                    'fee_escrowed' => Corporation::MOVE_HQ_FEE,
                    'requested_at' => now()->utc()->toIso8601String(),
                    'expires_at' => now()->addSeconds(Corporation::MOVE_HQ_REVIEW_WINDOW_SECONDS)->utc()->toIso8601String(),
                ];

                Cache::forever(Corporation::moveRequestKey((int) $corp->id), $payload);
                Corporation::writeMoveCityIndex((int) $destinationCity->id, (int) $corp->id, $payload);

                $character->timers()->update([
                    'next_action_at' => now()->addHours(2)->getTimestamp(),
                ]);

                Log::info('[Corporation] Move requested.', [
                    'corp_id' => $corp->id,
                    'corp_name' => $corp->name,
                    'ceo_id' => $character->id,
                    'from_city_id' => $corp->home_city_id,
                    'to_city_id' => $destinationCity->id,
                    'fee' => Corporation::MOVE_HQ_FEE,
                ]);

                return back()->with(
                    'success',
                    "You have successfully paid $100,000 from the cash reserves and filed a request with the customs officials of {$destinationCity->name} to approve your Company's relocation."
                );
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Move request failed.', [
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Could not file relocation request. Please try again.');
        }
    }

    /**
     * CEO cancels their own pending move request — refunds the escrowed fee
     * to cash_reserves and clears the cache entries.
     */
    public function cancelMoveHeadquarters(Request $request)
    {
        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = $character?->corporation_id
                    ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
                    : null;

                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Only the CEO can cancel a relocation request.');
                }

                $payload = $corp->pendingMoveRequest();
                if (!$payload) {
                    return back()->with('error', 'No active relocation request to cancel.');
                }

                $refund = (int) ($payload['fee_escrowed'] ?? Corporation::MOVE_HQ_FEE);
                if ($refund > 0) {
                    $corp->increment('cash_reserves', $refund);
                }

                Cache::forget(Corporation::moveRequestKey((int) $corp->id));
                Corporation::removeFromMoveCityIndex((int) ($payload['to_city_id'] ?? 0), (int) $corp->id);

                Log::info('[Corporation] Move cancelled.', [
                    'corp_id' => $corp->id,
                    'ceo_id' => $character->id,
                    'refund' => $refund,
                ]);

                return back()->with(
                    'success',
                    'You have successfully canceled your Company\'s relocation request and have been refunded the full cost.'
                );
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Move cancel failed.', [
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Could not cancel relocation request.');
        }
    }

    public function dissolve(Request $request)
    {
        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = $character->corporation;

                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Only the CEO can dissolve the corporation.');
                }

                if ($blocker = $this->getCeoAuthorityBlocker($character, $corp)) {
                    return back()->with('error', $blocker);
                }

                $corp = Corporation::where('id', $corp->id)->lockForUpdate()->first();
                if (!$corp || !$corp->isCeo($character)) {
                    return back()->with('error', 'Corporation leadership changed. Please try again.');
                }

                if ($corp->parent_trust_id !== null) {
                    return back()->with(
                        'error',
                        'Subsidiaries cannot be dissolved while attached to a holding company. '
                        . 'Ask the holding board to release this subsidiary, or transfer the CEO seat first.'
                    );
                }

                if ($corp->member_count > 1) {
                    return back()->with('error', 'Cannot dissolve - remove all members first.');
                }

                $corpName = $corp->name;
                $slushLost = (int) $corp->slush_fund;
                $reservesLost = (int) $corp->cash_reserves;
                $totalLost = $slushLost + $reservesLost;

                $cacheKey = InvestmentFraud::cacheKey($corp);
                $cacheData = Cache::get($cacheKey);
                if ($cacheData && now()->timestamp <= ($cacheData['expires_at'] ?? 0)) {
                    InvestmentFraud::fail(
                        $corp,
                        $cacheData['required_members'] ?? [],
                        $cacheData['accepted_by'] ?? [],
                        'corp_dissolved'
                    );
                } elseif ($cacheData) {
                    Cache::forget($cacheKey);
                }

                $pendingMove = $corp->pendingMoveRequest();
                if ($pendingMove) {
                    Cache::forget(Corporation::moveRequestKey((int) $corp->id));
                    Corporation::removeFromMoveCityIndex(
                        (int) ($pendingMove['to_city_id'] ?? 0),
                        (int) $corp->id
                    );
                }

                $this->detachFromCorporation($character, false, $corp);
                $character->quitCareer();
                $corp->delete();

                JournalService::custom($character->id, 'corporation_dissolved', [
                    'corporation_name' => $corpName,
                    'slush_forfeited' => $slushLost,
                    'reserves_forfeited' => $reservesLost,
                ]);

                $message = "{$corpName} has been dissolved.";
                if ($totalLost > 0) {
                    $parts = [];
                    if ($slushLost > 0) {
                        $parts[] = '$' . number_format($slushLost) . ' in slush funds';
                    }
                    if ($reservesLost > 0) {
                        $parts[] = '$' . number_format($reservesLost) . ' in cash reserves';
                    }
                    $message .= ' ' . implode(' and ', $parts) . ' has been permanently forfeited.';
                }

                Log::info('[Corporation] Dissolved.', [
                    'character_id' => $character->id,
                    'corp_name' => $corpName,
                    'slush_forfeited' => $slushLost,
                    'reserves_forfeited' => $reservesLost,
                ]);

                return back()->with('success', $message);
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Dissolution failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Dissolution failed. Please try again.');
        }
    }

    public function quit(Request $request)
    {
        $character = $request->user()->character;

        try {
            return DB::transaction(function () use ($character) {
                $character = Character::where('id', $character->id)->lockForUpdate()->first();
                $corp = $character->corporation;

                if (!$corp) {
                    return back()->with('error', 'You are not in a corporation.');
                }

                if ($corp->isCeo($character)) {
                    return back()->with('error', 'The CEO cannot quit. Transfer leadership first, or dissolve the corporation.');
                }


                if ($corp->is_holding_company && $corp->isBoardMember($character)) {
                    return back()->with('error', 'Board members cannot resign');
                }

                if ($corp->parent_trust_id !== null) {
                    return back()->with(
                        'error',
                        'You cannot leave the corporation once part of a holding company.'
                    );
                }

                $corpName = $corp->name;
                $ceoId = $corp->ceo_id;

                $this->detachFromCorporation($character, false, $corp);

                if ($ceoId) {
                    JournalService::custom($ceoId, 'corporation_member_quit', [
                        'member_name' => $character->display_name,
                        'corporation_name' => $corpName,
                    ]);
                }

                JournalService::custom($character->id, 'corporation_quit', [
                    'corporation_name' => $corpName,
                ]);

                return back()->with('success', "You have left {$corpName}.");
            });
        } catch (\Throwable $e) {
            Log::error('[Corporation] Quit failed.', [
                'user_id' => $request->user()->id ?? null,
                'character_id' => $character->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->with('error', 'Failed to leave corporation. Please try again.');
        }
    }

    public static function isFoundingRankEligible(Character $character): bool
    {
        if (($character->career_rank ?? 0) >= 4) {
            return true;
        }

        if (($character->career_rank ?? 0) !== 3) {
            return false;
        }

        $rank3Row = CareerRank::findForCharacter($character->career_id, 3);
        if (!$rank3Row) {
            return false;
        }

        return (bool) ($rank3Row->getRankRequirements((int) $character->career_xp)['ready'] ?? false);
    }

    private static function foundingCreatesPromotionOpportunity(Character $character): bool
    {
        return $character->career?->code === 'corporation' && ($character->career_rank ?? 0) === 3;
    }

    private static function deductFoundingCost(Character $character): void
    {
        $character->decrement('cash_on_hand', self::FOUNDING_COST);
    }

    public static function propertyTaxRateFor(Corporation $corp): int
    {
        $term = MayorTerm::activeForCity((int) $corp->home_city_id);
        if (!$term || !$term->corpRegUnlocked()) {
            return 0;
        }

        return max(0, (int) ($term->getPolicies()['corporate_tax_rate'] ?? 0));
    }

    public static function isPhaseOneOperatingCompany(Corporation $corp): bool
    {
        return !$corp->trashed()
            && !$corp->is_holding_company
            && $corp->parent_trust_id === null;
    }

    public static function activeMemberCount(Corporation $corp): int
    {
        return $corp->members()
            ->alive()
            ->where('health', '>', 0)
            ->count();
    }

    public static function isReadyForCorporationRank(Character $character, int $currentRank): bool
    {
        $corporationCareerId = Career::findByCode('corporation')?->id;

        if (
            !$character->career_id
            || !$corporationCareerId
            || (int) $character->career_id !== (int) $corporationCareerId
            || (int) ($character->career_rank ?? 0) !== $currentRank
        ) {
            return false;
        }

        if (!$character->isAlive()) {
            return false;
        }



        $rank = CareerRank::findForCharacter($character->career_id, $currentRank);

        return (bool) ($rank?->getRankRequirements((int) $character->career_xp)['ready'] ?? false);
    }

    public static function isEligibleMergerSuccessor(Corporation $corp, ?Character $candidate): bool
    {
        if (!$candidate || (int) $candidate->corporation_id !== (int) $corp->id || $corp->isCeo($candidate)) {
            return false;
        }

        return self::isReadyForCorporationRank($candidate, 3);
    }

    public static function hasEligibleMergerSuccessor(Corporation $corp): bool
    {
        return $corp->members()
            ->with('career')
            ->where('id', '!=', $corp->ceo_id)
            ->where('career_rank', 3)
            ->get()
            ->contains(fn(Character $member) => self::isEligibleMergerSuccessor($corp, $member));
    }

    public static function hasHoldingCompanyInCity(int $cityId): bool
    {
        return Corporation::where('home_city_id', $cityId)
            ->where('is_holding_company', true)
            ->exists();
    }

    public static function hasPendingMergerForCorporations(array $corporationIds): bool
    {
        $corporationIds = array_values(array_unique(array_filter(array_map('intval', $corporationIds))));

        if (!$corporationIds) {
            return false;
        }

        return CorporationMergerRequest::pending()
            ->where(function ($query) use ($corporationIds) {
                $query->whereIn('requester_corporation_id', $corporationIds)
                    ->orWhereIn('target_corporation_id', $corporationIds);
            })
            ->exists();
    }

    public static function hasPendingSubsidiaryInviteForCorporation(int $corporationId): bool
    {
        return CorporationSubsidiaryInvite::pending()
            ->where('target_corporation_id', $corporationId)
            ->exists();
    }

    public static function hasPendingMergerHandoffFor(Character $character, Corporation $company): bool
    {
        return CorporationMergerRequest::where('status', CorporationMergerRequest::STATUS_COMPLETED)
            ->where(function ($query) use ($character, $company) {
                $query->where(function ($query) use ($character, $company) {
                    $query->where('requester_id', $character->id)
                        ->where('requester_corporation_id', $company->id)
                        ->whereNull('requester_handoff_completed_at');
                })->orWhere(function ($query) use ($character, $company) {
                    $query->where('target_id', $character->id)
                        ->where('target_corporation_id', $company->id)
                        ->whereNull('target_handoff_completed_at');
                });
            })
            ->exists();
    }

    public static function getMergerProposalBlocker(
        Character $requester,
        Corporation $requesterCorp,
        Corporation $targetCorp,
        ?Character $requesterSuccessor
    ): ?string {
        if (!self::isPhaseOneOperatingCompany($requesterCorp)) {
            return 'Your company is not eligible for a merger.';
        }

        if (!self::isPhaseOneOperatingCompany($targetCorp)) {
            return 'That company is not eligible for a merger.';
        }

        if ((int) $requesterCorp->home_city_id !== (int) $targetCorp->home_city_id) {
            return 'Companies must share the same headquarters city.';
        }

        if ((int) $requester->city_id !== (int) $requesterCorp->home_city_id) {
            return 'You need to be in the corporation home city to propose a merger!';
        }

        if (self::hasHoldingCompanyInCity((int) $requesterCorp->home_city_id)) {
            return 'That headquarters city already has a holding company.';
        }

        if (!$requesterCorp->isCeo($requester) || !self::isReadyForCorporationRank($requester, 4)) {
            return 'You need to be the ceo or ready for rank 5 to start a merger.';
        }

        if ($requester->cash_on_hand < self::MERGER_COST) {
            return 'You do not have the merger setup funds on hand.';
        }

        if (self::activeMemberCount($requesterCorp) < self::MERGER_MIN_MEMBERS) {
            return 'Your company is not established enough to be talking about a merger with that corporation.';
        }

        if (!self::isEligibleMergerSuccessor($requesterCorp, $requesterSuccessor)) {
            return 'Your company needs a valid successor.';
        }

        return null;
    }

    private static function getMergerAcceptanceBlocker(
        ?Character $requester,
        ?Character $target,
        Corporation $requesterCorp,
        Corporation $targetCorp,
        ?Character $requesterSuccessor,
        ?Character $targetSuccessor,
        $membersByCorporation
    ): ?string {
        if (!$requester || !$target || !$requester->isAlive() || !$target->isAlive()) {
            return 'One of the CEOs is no longer available.';
        }

        if (!self::isPhaseOneOperatingCompany($requesterCorp) || !self::isPhaseOneOperatingCompany($targetCorp)) {
            return 'One of the companies is no longer eligible for a merger.';
        }

        if ((int) $requesterCorp->home_city_id !== (int) $targetCorp->home_city_id) {
            return 'Companies must share the same headquarters city.';
        }

        if (!$requesterCorp->isCeo($requester) || !$targetCorp->isCeo($target)) {
            return 'Company leadership changed. Start a new merger request.';
        }

        if (!self::isReadyForCorporationRank($requester, 4) || !self::isReadyForCorporationRank($target, 4)) {
            return 'One of the CEOs is not ready for rank 5 yet.';
        }

        if ($requester->cash_on_hand < self::MERGER_COST) {
            return 'The proposing CEO no longer has the merger setup funds on hand.';
        }

        $requesterMembers = $membersByCorporation->get($requesterCorp->id, collect());
        $targetMembers = $membersByCorporation->get($targetCorp->id, collect());

        $requesterLiving = $requesterMembers->filter(fn(Character $member) => $member->isAlive())->count();
        $targetLiving = $targetMembers->filter(fn(Character $member) => $member->isAlive())->count();

        if ($requesterLiving < self::MERGER_MIN_MEMBERS || $targetLiving < self::MERGER_MIN_MEMBERS) {
            return 'Both companies need a real operating roster before merging.';
        }

        if (!self::isEligibleMergerSuccessor($requesterCorp, $requesterSuccessor)) {
            return 'The proposing company needs a valid successor.';
        }

        if (!self::isEligibleMergerSuccessor($targetCorp, $targetSuccessor)) {
            return 'Choose an eligible successor from your company.';
        }

        return null;
    }

    private static function getSubsidiaryInviteBlocker(
        Character $requester,
        Corporation $holding,
        Corporation $targetCorp,
        ?Character $targetCeo
    ): ?string {
        if (!$holding->is_holding_company || !$holding->isBoardMember($requester)) {
            return 'Only holding-company board members can invite subsidiaries.';
        }

        if (!$holding->hasSubsidiaryCapacity()) {
            return 'The holding company has no open subsidiary slot.';
        }

        if (!self::isPhaseOneOperatingCompany($targetCorp)) {
            return 'That company is not eligible to become a subsidiary.';
        }

        if ((int) $holding->home_city_id !== (int) $targetCorp->home_city_id) {
            return 'Companies must share the same headquarters city.';
        }

        if (!$targetCeo || !$targetCeo->isAlive() || !$targetCorp->isCeo($targetCeo)) {
            return 'That company does not have a valid CEO.';
        }



        if (self::activeMemberCount($targetCorp) < self::MERGER_MIN_MEMBERS) {
            return 'That company is not established enough to join as your subsidiary.';
        }

        if (!self::holdingHasBoardCapacityAfterSubsidiaryGain($holding)) {
            return 'The holding board has no open seat.';
        }

        return null;
    }

    private static function getSubsidiaryInviteAcceptanceBlocker(
        ?Character $targetCeo,
        Corporation $holding,
        Corporation $targetCorp,
        ?Character $successor
    ): ?string {
        if (!$holding->is_holding_company || !$holding->hasSubsidiaryCapacity()) {
            return 'The holding company has no open subsidiary slot.';
        }

        if (!self::isPhaseOneOperatingCompany($targetCorp)) {
            return 'Your company is no longer eligible to become a subsidiary.';
        }

        if ((int) $holding->home_city_id !== (int) $targetCorp->home_city_id) {
            return 'Companies must share the same headquarters city.';
        }

        if (!$targetCeo || !$targetCeo->isAlive() || !$targetCorp->isCeo($targetCeo)) {
            return 'Company leadership changed. Start a new subsidiary invitation.';
        }

        if (!self::isReadyForCorporationRank($targetCeo, 4)) {
            return 'The CEO is no longer ready for a board handoff.';
        }

        if (self::activeMemberCount($targetCorp) < self::MERGER_MIN_MEMBERS) {
            return 'Your company is no longer established enough to join as a subsidiary.';
        }

        if (!self::isEligibleMergerSuccessor($targetCorp, $successor)) {
            return 'Choose an eligible successor from your company.';
        }

        if (!self::holdingHasBoardCapacityAfterSubsidiaryGain($holding)) {
            return 'The holding board has no open seat.';
        }

        return null;
    }

    private static function holdingHasBoardCapacityAfterSubsidiaryGain(Corporation $holding): bool
    {
        if (!$holding->is_holding_company || !$holding->hasSubsidiaryCapacity()) {
            return false;
        }

        return ($holding->boardMemberCount() + $holding->pendingBoardIntakeCount()) < ($holding->boardCapacity() + 1);
    }

    public static function lockTrustVoteState(Corporation $holding): \Illuminate\Support\Collection
    {
        return $holding->lockTrustVoteState();
    }

    public static function completeRankFiveCorporationHandoff(Character $character): void
    {
        $company = $character->corporation_id
            ? Corporation::where('id', $character->corporation_id)->first()
            : null;

        if ($company && self::hasPendingMergerHandoffFor($character, $company)) {
            self::completeMergerPromotionHandoff($character);
            return;
        }

        self::completeBoardPromotionHandoff($character);
    }

    public static function completeMergerPromotionHandoff(Character $character): void
    {
        $character = Character::where('id', $character->id)->lockForUpdate()->first();
        $company = $character?->corporation_id
            ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
            : null;

        if (!$character || !$company || !$company->parent_trust_id || !$company->isCeo($character)) {
            throw new \InvalidArgumentException('The merger handoff is no longer valid.');
        }

        $mergerRequest = CorporationMergerRequest::where('status', CorporationMergerRequest::STATUS_COMPLETED)
            ->where(function ($query) use ($character, $company) {
                $query->where(function ($query) use ($character, $company) {
                    $query->where('requester_id', $character->id)
                        ->where('requester_corporation_id', $company->id)
                        ->whereNull('requester_handoff_completed_at');
                })->orWhere(function ($query) use ($character, $company) {
                    $query->where('target_id', $character->id)
                        ->where('target_corporation_id', $company->id)
                        ->whereNull('target_handoff_completed_at');
                });
            })
            ->lockForUpdate()
            ->first();

        if (!$mergerRequest) {
            throw new \InvalidArgumentException('The merger handoff is no longer available.');
        }

        $side = (int) $mergerRequest->requester_id === (int) $character->id
            ? 'requester'
            : 'target';

        $successorId = $side === 'requester'
            ? $mergerRequest->requester_successor_id
            : $mergerRequest->target_successor_id;

        $holding = Corporation::where('id', $company->parent_trust_id)->lockForUpdate()->first();
        $successor = Character::where('id', $successorId)->lockForUpdate()->first();

        if (!$holding || !$holding->is_holding_company || !self::isEligibleMergerSuccessor($company, $successor)) {
            throw new \InvalidArgumentException('The merger successor is no longer valid.');
        }

        $company->clearReportsFor($character);
        $company->clearReportsFor($successor);
        $company->update(['ceo_id' => $successor->id]);

        $successor->update([
            'corporation_position' => null,
            'corporation_reports_to_id' => null,
        ]);

        $character->update([
            'corporation_id' => $holding->id,
            'corporation_position' => Corporation::POSITION_GROUP_PRESIDENT,
            'corporation_reports_to_id' => null,
        ]);

        $mergerRequest->update([
            "{$side}_handoff_completed_at" => now(),
        ]);
    }

    public static function completeBoardPromotionHandoff(Character $character): void
    {
        $character = Character::where('id', $character->id)->lockForUpdate()->first();
        $company = $character?->corporation_id
            ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
            : null;

        if (!$character || !$company || !$company->parent_trust_id || !$company->isCeo($character)) {
            throw new \InvalidArgumentException('The board promotion handoff is no longer valid.');
        }

        $promotion = CorporationBoardPromotion::pending()
            ->where('promoted_ceo_id', $character->id)
            ->where('subsidiary_id', $company->id)
            ->where('holding_company_id', $company->parent_trust_id)
            ->lockForUpdate()
            ->first();

        if (!$promotion) {
            throw new \InvalidArgumentException('The board promotion handoff is no longer available.');
        }

        $holding = Corporation::where('id', $promotion->holding_company_id)->lockForUpdate()->first();
        $successor = Character::where('id', $promotion->successor_id)->lockForUpdate()->first();

        if (
            !$holding
            || !$holding->is_holding_company
            || (int) $company->parent_trust_id !== (int) $holding->id
            || !$holding->hasBoardIntakeCapacity($promotion->id)
        ) {
            throw new \InvalidArgumentException('The holding board no longer has room for that promotion.');
        }

        if (!self::isEligibleMergerSuccessor($company, $successor)) {
            throw new \InvalidArgumentException('The nominated successor is no longer valid.');
        }

        $company->clearReportsFor($character);
        $company->clearReportsFor($successor);
        $company->update(['ceo_id' => $successor->id]);

        $successor->update([
            'corporation_position' => null,
            'corporation_reports_to_id' => null,
        ]);

        $character->update([
            'corporation_id' => $holding->id,
            'corporation_position' => Corporation::POSITION_GROUP_PRESIDENT,
            'corporation_reports_to_id' => null,
        ]);

        $promotion->update([
            'status' => CorporationBoardPromotion::STATUS_COMPLETED,
            'completed_at' => now(),
            'handoff_completed_at' => now(),
        ]);

        JournalService::custom($successor->id, 'corporation_board_successor_installed', [
            'holding_name' => $holding->name,
            'subsidiary_name' => $company->name,
            'ceo_name' => $character->display_name,
        ]);
    }

    public static function updateBoardPositionAfterPromotion(Character $character): void
    {
        $character = Character::where('id', $character->id)->lockForUpdate()->first();
        $holding = $character?->corporation_id
            ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
            : null;

        if (
            $character
            && $holding?->is_holding_company
            && $character->corporation_position === Corporation::POSITION_GROUP_PRESIDENT
        ) {
            $character->update([
                'corporation_position' => Corporation::POSITION_CHAIRMAN,
                'corporation_reports_to_id' => null,
            ]);
        }
    }

    public static function completeDirectorOfBoardPromotion(Character $character): void
    {
        $character = Character::where('id', $character->id)->lockForUpdate()->first();
        $holding = $character?->corporation_id
            ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
            : null;

        if (!$character || !$holding || !$holding->is_holding_company || (int) ($character->career_rank ?? 0) !== 7) {
            throw new \InvalidArgumentException('The Director vote is no longer valid.');
        }

        $vote = CorporationTrustVote::promotionPending()
            ->where('holding_company_id', $holding->id)
            ->where('winner_id', $character->id)
            ->lockForUpdate()
            ->first();

        if (!$vote) {
            throw new \InvalidArgumentException('The Director vote is no longer available.');
        }

        $boardMembers = self::lockTrustVoteState($holding);
        if ($blocker = self::directorPromotionBlocker($holding, $character, $boardMembers)) {
            $vote->update(['status' => CorporationTrustVote::STATUS_CANCELLED]);
            throw new \InvalidArgumentException($blocker);
        }

        $character->update([
            'corporation_position' => Corporation::POSITION_DIRECTOR_OF_BOARD,
            'corporation_reports_to_id' => null,
        ]);

        $vote->update(['promotion_completed_at' => now()]);

        foreach ($boardMembers as $boardMember) {
            JournalService::custom($boardMember->id, 'corporation_trust_created', [
                'holding_name' => $holding->name,
                'director_name' => $character->display_name,
            ]);
        }
    }

    private static function directorPromotionBlocker(
        Corporation $holding,
        Character $winner,
        \Illuminate\Support\Collection $boardMembers
    ): ?string {
        return $holding->directorPromotionBlocker(
            $winner,
            $boardMembers,
            winnerAlreadyPromoted: true,
            lockForUpdate: true,
        );
    }

    private function distributeFromHolding(
        Character $actor,
        Corporation $holding,
        int $targetId,
        int $amount,
        string $sourceColumn,
        string $targetColumn,
        string $journalType,
    ): \Illuminate\Http\RedirectResponse {
        if (!$holding->isBoardMember($actor)) {
            return back()->with('error', 'Only board members can distribute holding funds.');
        }

        if ($targetId === (int) $actor->id) {
            return back()->with('error', 'You cannot distribute funds to yourself.');
        }

        $target = Character::where('id', $targetId)->lockForUpdate()->first();
        if (!$target || !$holding->isBoardMember($target)) {
            return back()->with('error', 'Target must be another board member of this holding company.');
        }

        $subsidiaries = $holding->subsidiaries()->lockForUpdate()->orderBy('id')->get();
        $consolidated = (int) $holding->{$sourceColumn} + (int) $subsidiaries->sum($sourceColumn);

        if ($amount > $consolidated) {
            return back()->with('error', 'Insufficient consolidated funds.');
        }

        // Drain the holding's own pot first, then each subsidiary in id
        // order until the request is satisfied.
        $remaining = $amount;
        $fromHolding = min($remaining, (int) $holding->{$sourceColumn});
        if ($fromHolding > 0) {
            $holding->decrement($sourceColumn, $fromHolding);
            $remaining -= $fromHolding;
        }
        foreach ($subsidiaries as $subsidiary) {
            if ($remaining <= 0)
                break;
            $fromSub = min($remaining, (int) $subsidiary->{$sourceColumn});
            if ($fromSub > 0) {
                $subsidiary->decrement($sourceColumn, $fromSub);
                $remaining -= $fromSub;
            }
        }

        $target->increment($targetColumn, $amount);

        JournalService::custom($target->id, $journalType, [
            'distributor_name' => $actor->display_name,
            'amount' => $amount,
            'corporation_name' => $holding->name,
        ]);

        return back()->with('success', '$' . number_format($amount) . " distributed to {$target->display_name}.");
    }

    private function notifyOversightOfDeposit(Corporation $corp, Character $depositor, string $type, int $amount): void
    {
        $payload = [
            'depositor_name' => $depositor->display_name,
            'amount' => $amount,
            'corporation_name' => $corp->name,
        ];

        if ($corp->ceo_id) {
            if ((int) $corp->ceo_id !== (int) $depositor->id) {
                JournalService::custom($corp->ceo_id, $type, $payload);
            }
            return;
        }

        if (!$corp->is_holding_company) {
            return;
        }

        $corp->loadMissing('boardMembers');
        foreach ($corp->boardMembers as $boardMember) {
            if ((int) $boardMember->id === (int) $depositor->id) {
                continue;
            }
            JournalService::custom($boardMember->id, $type, $payload);
        }
    }

    private function detachFromCorporation(Character $character, bool $demoteManagingDirector = false, ?Corporation $corp = null): void
    {
        $corp ??= $character->corporation_id
            ? Corporation::where('id', $character->corporation_id)->lockForUpdate()->first()
            : null;

        $wasHoldingBoardMember = $corp?->isBoardMember($character) ?? false;

        if ($corp) {
            $corp->removeMember($character);
        } else {
            $character->update([
                'corporation_id' => null,
                'corporation_position' => null,
                'corporation_reports_to_id' => null,
            ]);
        }

        if (
            $character->career?->code === 'corporation'
            && (($character->career_rank ?? 0) >= 4 || $demoteManagingDirector)
        ) {
            $rank3 = $character->career_id
                ? CareerRank::findForCharacter($character->career_id, 3)
                : null;

            $character->update([
                'career_rank' => 3,
                'career_xp' => $rank3?->xp_required ?? $character->career_xp,
            ]);
            $character->resetRankMemo();
        }

        CharacterJournal::where('character_id', $character->id)
            ->where('type', 'corporation_invite_request')
            ->delete();

        if ($wasHoldingBoardMember) {
            $corp->reconcileHoldingLifecycle();
        }
    }

    private function getCeoAuthorityBlocker(Character $character, ?Corporation $corp): ?string
    {
        if ($character->isHospitalized() || $character->isJailed()) {
            return 'You cannot perform leadership actions while hospitalized or jailed.';
        }

        if (!$corp || !$corp->isCeo($character)) {
            return null;
        }

        if (($character->career_rank ?? 0) < 4) {
            return 'You need to complete your promotion before you can run the corporation.';
        }

        if (
            strcasecmp($character->career?->code ?? '', 'corporation') === 0
            && ($character->rank_requirements['ready'] ?? false)
            && $character->getPromotionChecker() === null
        ) {
            return 'You need to complete your promotion before you can run the corporation.';
        }

        return null;
    }

    private function corporationPositionLabel(Corporation $corp, Character $character): string
    {
        if ($corp->isCeo($character)) {
            return 'CEO';
        }

        return strtoupper($character->corporation_position ?? 'Member');
    }
}
