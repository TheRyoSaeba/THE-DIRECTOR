<?php

namespace App\Http\Controllers;

use App\Models\Career;
use App\Models\CareerRank;
use App\Models\Character;
use App\Models\City;
use App\Models\CrimeRecord;
use App\Models\DefenseOffer;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;


class LawController extends Controller
{
    public function index(Request $request)
    {
        $character = $request->user()->character;
        $rank = $character->career_rank;
        $cityId = $character->home_city_id;

        $this->processStaleReferredCases($cityId);

        $referredRecords = $rank === 2
            ? CrimeRecord::inCity($cityId)
                ->where('status', CrimeRecord::STATUS_REFERRED)
                ->latest('referred_at')
                ->limit(100)
                ->get()
            : collect();

        // NOTE: whereNull OR != is required — a plain != excludes NULL rows in SQL.

        $chargedRecords = $rank === 2
            ? CrimeRecord::query()
                ->where('status', CrimeRecord::STATUS_CHARGED)
                ->where(fn($q) => $q
                    ->whereNull('defense_id')
                    ->orWhere('defense_id', $character->id)
                )
                ->whereRaw("(data->>'auto_charged_reason') IS NULL")
                ->where(
                    fn($q) => $q
                        ->whereNull('prosecutor_id')
                        ->orWhere('prosecutor_id', '!=', $character->id)
                )
                ->latest('charged_at')
                ->limit(100)
                ->get()
            : collect();

        $convictedRecords = $rank >= 3
            ? CrimeRecord::inCity($cityId)
                ->where(
                    fn($q) => $q
                        ->where(
                            fn($q2) => $q2
                                ->where('status', CrimeRecord::STATUS_CHARGED)


                                ->where(
                                    fn($q4) => $q4
                                        ->whereRaw("(data->>'auto_charged_reason') IS NOT NULL")
                                        ->orWhere('charged_at', '<=', now()->subHour())
                                )
                                ->where(
                                    fn($q3) => $q3
                                        ->whereNull('prosecutor_id')
                                        ->orWhere('prosecutor_id', '!=', $character->id)
                                )
                        )
                        ->orWhere(
                            fn($q2) => $q2
                                ->where('status', CrimeRecord::STATUS_CONVICTED)
                                ->where(fn($q3) => $q3->whereNull('prosecutor_id')->orWhere('prosecutor_id', '!=', $character->id))
                                ->where(fn($q3) => $q3->whereNull('defense_id')->orWhere('defense_id', '!=', $character->id))
                        )
                )
                ->latest('updated_at')
                ->limit(100)
                ->get()
            : collect();

        $appealedRecords = $rank >= 4
            ? CrimeRecord::inCity($cityId)
                ->where('status', CrimeRecord::STATUS_APPEALED)
                ->where(fn($q) => $q->whereNull('judge_id')->orWhere('judge_id', '!=', $character->id))
                ->latest('appealed_at')
                ->limit(50)
                ->get()
            : collect();

        $activeCaseIds = $referredRecords->pluck('id')
            ->merge($chargedRecords->pluck('id'))
            ->merge($convictedRecords->pluck('id'))
            ->merge($appealedRecords->pluck('id'))
            ->filter()
            ->unique()
            ->values();

        $myRecords = CrimeRecord::query()
            ->where(
                fn($q) => $q
                    ->where('prosecutor_id', $character->id)
                    ->orWhere('defense_id', $character->id)
                    ->orWhere('judge_id', $character->id)
            )
            ->when($activeCaseIds->isNotEmpty(), fn($q) => $q->whereNotIn('id', $activeCaseIds))
            ->latest('updated_at')
            ->limit(50)
            ->get();

        $this->hydrateCaseRelations(
            $referredRecords
                ->concat($chargedRecords)
                ->concat($convictedRecords)
                ->concat($appealedRecords)
                ->concat($myRecords)
        );

        $referredCases = $referredRecords->map(fn($r) => $this->formatCaseForLaw($r, $character->id));
        $chargedCases = $chargedRecords->map(fn($r) => $this->formatCaseForLaw($r, $character->id));
        $convictedCases = $convictedRecords->map(fn($r) => $this->formatCaseForLaw($r, $character->id));
        $appealedCases = $appealedRecords->map(fn($r) => $this->formatCaseForLaw($r, $character->id));
        $myCases = $myRecords->map(fn($r) => $this->formatCaseForLaw($r, $character->id));

        $rankLabel = $character->current_rank?->rank_name ?? match ($rank) {
            2 => 'Attorney',
            3 => 'District Judge',
            4 => 'Chief Justice',
            default => 'Law Clerk',
        };

        $lawCareerId = Career::findByCode('law')?->id;
        $isChiefJustice = $lawCareerId
            && $character->career_id === $lawCareerId
            && (int) $character->career_rank === 4;

        $dismissableMembers = [];
        if ($isChiefJustice && $lawCareerId) {
            $careerRanks = CareerRank::getRanksForCareer($lawCareerId)->keyBy('rank_level');
            $dismissableMembers = Character::where('career_id', $lawCareerId)
                ->where('home_city_id', $character->home_city_id)
                ->where('id', '!=', $character->id)
                ->where('career_rank', '<', 4)
                ->alive()
                ->orderByDesc('career_rank')
                ->orderBy('display_name')
                ->get()
                ->map(fn($c) => [
                    'id' => $c->id,
                    'displayName' => $c->display_name,
                    'avatarUrl' => $c->custom_avatar_url ?: ($careerRanks->get($c->career_rank)?->avatar_url ?? null),
                    'rankName' => $careerRanks->get($c->career_rank)?->rank_name ?? 'Law Clerk',
                    'rankNumber' => $c->career_rank ?? 1,
                ])
                ->values()
                ->all();
        }

        $stepDownBlocker = $this->getStepDownBlocker($character, $lawCareerId);

        return Inertia::render('Careers/Law', [
            'rank' => $rank,
            'rank_label' => $rankLabel,
            'referred_cases' => $referredCases,
            'charged_cases' => $chargedCases,
            'convicted_cases' => $convictedCases,
            'appealed_cases' => $appealedCases,
            'my_cases' => $myCases,
            'is_chief_justice' => $isChiefJustice,
            'dismissable_members' => $dismissableMembers,
            'can_step_down' => $stepDownBlocker === null,
            'step_down_blocker' => $stepDownBlocker,
        ]);
    }





    public function stepDown(Request $request): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;
        $lawCareerId = Career::findByCode('law')?->id;

        if (!$lawCareerId || $character->career_id !== $lawCareerId) {
            return back()->with('error', 'You are not a member of the judiciary.');
        }

        $blocker = $this->getStepDownBlocker($character, $lawCareerId);
        if ($blocker) {
            return back()->with('error', $blocker);
        }

        try {
            return DB::transaction(function () use ($character) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
                $character->refresh();

                $lawCareerId = Career::findByCode('law')?->id;
                if (!$lawCareerId || $character->career_id !== $lawCareerId) {
                    return back()->with('error', 'Your career has changed.');
                }

                $blocker = $this->getStepDownBlocker($character, $lawCareerId);
                if ($blocker) {
                    return back()->with('error', $blocker);
                }

                $rankName = $character->current_rank?->rank_name ?? 'Counsel';
                $character->quitCareer(preserveExp: true);

                JournalService::custom($character->id, 'career_step_down', [
                    'message' => "You have stepped down from your position as {$rankName} in the Judiciary.",
                ]);

                Log::info('[Law] Member stepped down.', [
                    'character' => $character->id,
                    'rank' => $rankName,
                ]);

                return redirect()->route('dashboard')
                    ->with('success', "You have stepped down as {$rankName}. The city thanks you for having served them.");
            });
        } catch (\Throwable $e) {
            Log::error('[Law] Step-down failed', ['character' => $character->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Step-down failed. Please try again.');
        }
    }





    public function dismissMember(Request $request)
    {
        $character = $request->user()->character;
        $lawCareerId = Career::findByCode('law')?->id;

        $isChiefJustice = $lawCareerId
            && $character->career_id === $lawCareerId
            && $character->home_city_id !== null
            && (int) $character->career_rank === 4;

        if (!$isChiefJustice) {
            return back()->with('error', 'Only the Chief Justice can dismiss members of the judiciary.');
        }

        if (!$character->isAlive()) {
            return back()->with('error', 'You cannot perform this action in your current state.');
        }
        if ($character->isHospitalized() || $character->isJailed()) {
            return back()->with('error', 'You cannot dismiss members while incapacitated.');
        }

        $data = $request->validate([
            'character_id' => 'required|integer|exists:characters,id',
        ]);

        if ((int) $data['character_id'] === $character->id) {
            return back()->with('error', 'You cannot dismiss yourself, Chief Justice.');
        }

        try {
            return DB::transaction(function () use ($character, $data, $lawCareerId) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
                $character->refresh();

                if ($character->career_id !== $lawCareerId || (int) $character->career_rank !== 4) {
                    return back()->with('error', 'Your Chief Justice status has changed.');
                }

                $target = Character::alive()
                    ->where('id', $data['character_id'])
                    ->lockForUpdate()
                    ->first();

                if (!$target) {
                    return back()->with('error', 'Member not found or no longer active.');
                }
                if ($target->career_id !== $lawCareerId) {
                    return back()->with('error', 'That character is not a member of the judiciary.');
                }
                if ($target->home_city_id !== $character->home_city_id) {
                    return back()->with('error', 'That member does not serve in your jurisdiction.');
                }
                if ((int) $target->career_rank === 4) {
                    return back()->with('error', 'You cannot dismiss another Chief Justice.');
                }




                if ((int) $target->career_rank === 3) {
                    $otherCJs = Character::where('career_id', $lawCareerId)
                        ->where('home_city_id', $character->home_city_id)
                        ->where('career_rank', 4)
                        ->where('id', '!=', $character->id)
                        ->alive()
                        ->count();

                    if ($otherCJs < 2) {
                        $rank4 = \App\Models\CareerRank::findForCharacter($lawCareerId, 4);
                        $isOnlySuccessor = $rank4
                            && $target->career_xp >= $rank4->xp_required
                            && !Character::where('career_id', $lawCareerId)
                                ->where('home_city_id', $character->home_city_id)
                                ->where('career_rank', 3)
                                ->where('id', '!=', $target->id)
                                ->where('career_xp', '>=', $rank4->xp_required)
                                ->alive()
                                ->exists();

                        if ($isOnlySuccessor) {
                            return back()->with(
                                'error',
                                "{$target->display_name} is the only judge in this city qualified for elevation. "
                                . 'Dismissing them would leave the judiciary without a path to succession. '
                                . 'wait for  another  district judge to be promoted first, or wait until a second candidate is ready.'
                            );
                        }
                    }
                }

                $chiefJusticeRankName = $character->current_rank?->rank_name ?? 'Chief Justice';
                $target->quitCareer(preserveExp: true);

                JournalService::careerDismissed(
                    $target->id,
                    $character->display_name,
                    $chiefJusticeRankName,
                    'Judiciary'
                );

                Log::info('[Law] Chief Justice dismissed member.', [
                    'chief_justice' => $character->id,
                    'dismissed' => $target->id,
                ]);

                return back()->with('success', "{$target->display_name} has been removed from the practice.");
            });
        } catch (\Throwable $e) {
            Log::error('[Law] Dismiss failed', ['chief_justice' => $character->id, 'error' => $e->getMessage()]);
            return back()->with('error', 'Dismissal failed. Please try again.');
        }
    }





    private function getStepDownBlocker(Character $character, ?int $careerId): ?string
    {
        if (!$careerId) {
            return 'Career data is unavailable.';
        }
        if (!$character->isInHomeCity()) {
            return 'You must be in your home city to step down.';
        }




        if ((int) $character->career_rank < 4) {
            return null;
        }



        $otherCJsCount = Character::where('career_id', $careerId)
            ->where('home_city_id', $character->home_city_id)
            ->where('career_rank', 4)
            ->where('id', '!=', $character->id)
            ->alive()
            ->count();

        if ($otherCJsCount >= 2) {
            return null;
        }


        $rank4 = CareerRank::findForCharacter($careerId, 4);
        if (!$rank4) {
            return 'Career rank data is unavailable.';
        }

        $successorReady = Character::where('career_id', $careerId)
            ->where('home_city_id', $character->home_city_id)
            ->where('career_rank', 3)
            ->where('id', '!=', $character->id)
            ->where('career_xp', '>=', $rank4->xp_required)
            ->alive()
            ->exists();

        if (!$successorReady) {
            return 'The city must retain at least one Chief Justice capable of resolving appeals. '
                . 'You cannot step down until a qualified rank-3 judge is ready for elevation, '
                . 'or until a second Chief Justice is serving alongside you.';
        }

        return null;
    }


    private function processStaleReferredCases(int $cityId): void
    {
        $staleIds = CrimeRecord::inCity($cityId)
            ->where('status', CrimeRecord::STATUS_REFERRED)
            ->where('referred_at', '<', now()->subHour())
            ->pluck('id');

        if ($staleIds->isEmpty()) {
            return;
        }

        foreach ($staleIds as $recordId) {
            DB::transaction(function () use ($recordId, $cityId) {
                $record = CrimeRecord::lockForUpdate()->find($recordId);
                if (
                    !$record
                    || $record->city_id !== $cityId
                    || $record->status !== CrimeRecord::STATUS_REFERRED
                    || !$record->referred_at
                    || $record->referred_at->isAfter(now()->subHour())
                ) {
                    return;
                }

                if (!$record->prepareChargeTargets()) {
                    Log::warning('[Law] Stale referred case could not be auto-charged because suspects no longer resolve.', [
                        'case' => $record->id,
                    ]);
                    return;
                }

                $record->status = CrimeRecord::STATUS_CHARGED;
                $record->charged_at = now()->utc();
                $record->data = array_merge($record->data ?? [], [
                    'auto_charged_reason' => 'No prosecutor action within 1 hour.',
                ]);
                $record->save();

                $message = "You have been formally auto-charged for {$record->typeLabel()}. "
                    . 'The case has been forwarded directly to a District Judge for review.';

                $notified = [];

                if ($record->detective_id) {
                    JournalService::custom($record->detective_id, 'case_auto_charged', [
                        'case_id' => $record->id,
                        'message' => "Your referral for {$record->typeLabel()} in {$record->city->name} was not picked up by a prosecutor "
                            . 'within the legal window. The case has been forwarded directly to a District Judge.',
                    ]);
                    $notified[] = $record->detective_id;
                }

                foreach ($record->partyIds() as $suspectId) {
                    if (!in_array($suspectId, $notified, true)) {
                        JournalService::custom($suspectId, 'case_auto_charged', [
                            'case_id' => $record->id,
                            'message' => $message,
                        ]);
                        $notified[] = $suspectId;
                    }
                }

                Log::info('[Law] Case auto-charged (referred > 1 hour idle).', [
                    'case' => $record->id,
                    'referred_at' => $record->referred_at?->toIso8601String(),
                    'city' => $cityId,
                ]);
            });
        }
    }


    private function hydrateCaseRelations($records): void
    {
        $records = $records->filter();
        if ($records->isEmpty()) {
            return;
        }

        $cityIds = $records->pluck('city_id')->filter()->unique()->values();
        $cities = $cityIds->isEmpty()
            ? collect()
            : City::query()
                ->select('id', 'name')
                ->whereIn('id', $cityIds)
                ->get()
                ->keyBy('id');

        $recordIds = $records->pluck('id')->filter()->unique()->values();
        $offers = $recordIds->isEmpty()
            ? collect()
            : DefenseOffer::query()
                ->active()
                ->whereIn('crime_record_id', $recordIds)
                ->latest()
                ->get()
                ->groupBy('crime_record_id');

        $characterIds = $records
            ->flatMap(fn($record) => [
                $record->detective_id,
                $record->prosecutor_id,
                $record->defense_id,
                $record->judge_id,
            ])
            ->merge($offers->flatten(1)->pluck('attorney_id'))
            ->filter()
            ->unique()
            ->values();
        $characters = $characterIds->isEmpty()
            ? collect()
            : Character::query()
                ->select('id', 'display_name')
                ->whereIn('id', $characterIds)
                ->get()
                ->keyBy('id');

        $offers->flatten(1)->each(
            fn($offer) => $offer->setRelation('attorney', $characters->get($offer->attorney_id))
        );

        foreach ($records as $record) {
            $record->setRelation('city', $cities->get($record->city_id));
            $record->setRelation('detective', $characters->get($record->detective_id));
            $record->setRelation('prosecutor', $characters->get($record->prosecutor_id));
            $record->setRelation('defense', $characters->get($record->defense_id));
            $record->setRelation('judge', $characters->get($record->judge_id));
            $record->setRelation('defenseOffers', $offers->get($record->id, collect())->values());
        }
    }


    private function formatCaseForLaw(CrimeRecord $r, int $myCharacterId): array
    {
        $data = $r->data ?? [];

        $isAppealJustice = isset($data['appeal']['chief_justice_id'])
            && (int) $data['appeal']['chief_justice_id'] === $myCharacterId;

        return [
            'id' => $r->id,
            'type' => $r->type,
            'type_label' => $r->typeLabel(),
            'severity' => $r->severity,
            'status' => $r->status,
            'city_name' => $r->city?->name,
            'committed_at_utc' => $r->committed_at ? $r->committed_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'victim_name' => $data['victim_name'] ?? null,
            'amount' => $data['amount'] ?? null,
            'suspect_names' => $data['suspect_names'] ?? [],
            'participant_count' => count($data['participants'] ?? []),
            'investigation_notes' => $data['investigation_notes'] ?? null,
            'field_intel' => $data['field_intel'] ?? null,
            'detective_name' => $r->detective?->display_name,
            'prosecutor_name' => $r->prosecutor?->display_name,
            'defense_name' => $r->defense?->display_name,
            'judge_name' => $r->judge?->display_name,
            'has_defense' => $r->defense_id !== null,
            'defense_pending' => (bool) ($data['defense_pending'] ?? false) || $this->activeDefenseOffer($r)?->status === DefenseOffer::STATUS_PENDING,
            'defense_offer' => $this->formatDefenseOffer($r, $myCharacterId),
            'auto_charged' => isset($data['auto_charged_reason']),
            'is_my_prosecutor' => $r->prosecutor_id === $myCharacterId,
            'is_my_defense' => $r->defense_id === $myCharacterId,
            'is_my_judge' => $r->judge_id === $myCharacterId,
            'referred_at_utc' => $r->referred_at ? $r->referred_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'charged_at_utc' => $r->charged_at ? $r->charged_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'charged_at_ts' => $r->charged_at?->utc()->getTimestamp(),
            'resolved_at_utc' => $r->resolved_at ? $r->resolved_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'sentenced_at_utc' => $r->sentenced_at ? $r->sentenced_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'appealed_at_utc' => $r->appealed_at ? $r->appealed_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'sentence' => $r->sentence,
            'appeal_data' => $isAppealJustice ? ($data['appeal'] ?? null) : null,
        ];
    }

    private function activeDefenseOffer(CrimeRecord $record): ?DefenseOffer
    {
        if ($record->relationLoaded('defenseOffers')) {
            return $record->defenseOffers->first();
        }

        return $record->defenseOffers()->active()->latest()->first();
    }

    private function formatDefenseOffer(CrimeRecord $record, int $myCharacterId): ?array
    {
        $offer = $this->activeDefenseOffer($record);
        if (! $offer) {
            return null;
        }

        $isMyOffer = (int) $offer->attorney_id === $myCharacterId;

        return [
            'id' => $isMyOffer ? $offer->id : null,
            'status' => $offer->status,
            'fee' => $isMyOffer ? (int) $offer->fee : null,
            'attorney_name' => $isMyOffer
                ? 'You'
                : ($offer->attorney?->display_name ?? 'Another attorney'),
            'is_mine' => $isMyOffer,
        ];
    }
}
