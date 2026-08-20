<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Career;
use App\Models\CareerRank;
use App\Models\Character;
use App\Models\CrimeRecord;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class PoliceController extends Controller
{


    public function index(Request $request)
    {
        $character = $request->user()->character;
        $rank = $character->career_rank;
        $cityId = $character->home_city_id;

        $allowedSeverities = match (true) {
            $rank >= 3 => [CrimeRecord::SEV_MISDEMEANOR, CrimeRecord::SEV_FELONY, CrimeRecord::SEV_CAPITAL],
            $rank >= 2 => [CrimeRecord::SEV_MISDEMEANOR, CrimeRecord::SEV_FELONY],
            default => [CrimeRecord::SEV_MISDEMEANOR],
        };

        $cityName = \App\Models\City::where('id', $cityId)->value('name') ?? '';


        $openCaseRecords = CrimeRecord::inCity($cityId)
            ->where('status', CrimeRecord::STATUS_OPEN)
            ->whereIn('severity', $allowedSeverities)
            ->whereNull('detective_id')
            ->latest('committed_at')
            ->limit(100)
            ->get();


        $activeCase = CrimeRecord::where('detective_id', $character->id)
            ->whereIn('status', [CrimeRecord::STATUS_INVESTIGATING])
            ->first();


        $myCaseRecords = CrimeRecord::where('detective_id', $character->id)
            ->whereNotIn('status', [CrimeRecord::STATUS_INVESTIGATING])
            ->latest('committed_at')
            ->get();

        // Crime Log = live dispatch chatter, cached per city by CrimeService.
        // Entries auto-expire after 15 minutes (per-entry, not per-cache).
        // No DB hit — it's a single Redis read.
        $crimeLog = collect($this->loadCrimeDispatch($cityId));

        $caseActorIds = $openCaseRecords
            ->concat($activeCase ? [$activeCase] : [])
            ->concat($myCaseRecords)
            ->flatMap(fn(CrimeRecord $case) => [
                $case->detective_id,
                $case->prosecutor_id,
                $case->defense_id,
                $case->judge_id,
            ])
            ->filter()
            ->unique()
            ->values();

        $caseActorNames = $caseActorIds->isEmpty()
            ? []
            : Character::withTrashed()
                ->whereIn('id', $caseActorIds)
                ->pluck('display_name', 'id')
                ->all();

        $openCases = $openCaseRecords
            ->map(fn($r) => $this->formatCaseForPolice($r, $character->id, $cityName, $caseActorNames));

        $currentCase = $activeCase
            ? $this->formatCaseForPolice($activeCase, $character->id, $cityName, $caseActorNames)
            : null;

        $myCases = $myCaseRecords
            ->map(fn($r) => $this->formatCaseForPolice($r, $character->id, $cityName, $caseActorNames));

        $rankLabel = $character->current_rank?->rank_name ?? match ($rank) {
            1 => 'Sergeant',
            2 => 'Inspector',
            3 => 'Superintendent',
            default => 'Commissioner-General',
        };

        $policeCareerId = Career::findByCode('police')?->id;
        $isCommissioner = $policeCareerId
            && $character->career_id === $policeCareerId
            && (int) $character->career_rank === 4;

        $dismissableOfficers = [];
        if ($isCommissioner && $policeCareerId) {
            $careerRanks = CareerRank::getRanksForCareer($policeCareerId)->keyBy('rank_level');
            $dismissableOfficers = Character::where('career_id', $policeCareerId)
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
                    'rankName' => $careerRanks->get($c->career_rank)?->rank_name ?? 'Officer',
                    'rankNumber' => $c->career_rank ?? 1,
                ])
                ->values()
                ->all();
        }

        $stepDownBlocker = $this->getStepDownBlocker($character, $policeCareerId);


        $policeHqImage = Business::where('city_id', $character->home_city_id)
            ->whereRaw('LOWER(code) = ?', ['police'])
            ->value('image_url');

        return Inertia::render('Careers/Police', [
            'rank' => $rank,
            'rank_label' => $rankLabel,
            'open_cases' => $openCases,
            'current_case' => $currentCase,
            'my_cases' => $myCases,
            'crime_log' => $crimeLog,
            'is_commissioner' => $isCommissioner,
            'dismissable_officers' => $dismissableOfficers,
            'can_step_down' => $stepDownBlocker === null,
            'step_down_blocker' => $stepDownBlocker,
            'police_hq_image' => $policeHqImage,
        ]);
    }





    public function stepDown(Request $request): \Illuminate\Http\RedirectResponse
    {
        $character = $request->user()->character;
        $policeCareerId = Career::findByCode('police')?->id;

        if (!$policeCareerId || $character->career_id !== $policeCareerId) {
            return back()->with('error', 'You are not a police officer.');
        }

        $blocker = $this->getStepDownBlocker($character, $policeCareerId);
        if ($blocker) {
            return back()->with('error', $blocker);
        }

        try {
            return DB::transaction(function () use ($character) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
                $character->refresh();

                $policeCareerId = Career::findByCode('police')?->id;
                if (!$policeCareerId || $character->career_id !== $policeCareerId) {
                    return back()->with('error', 'Your career has changed.');
                }

                $blocker = $this->getStepDownBlocker($character, $policeCareerId);
                if ($blocker) {
                    return back()->with('error', $blocker);
                }

                $rankName = $character->current_rank?->rank_name ?? 'Officer';

                $character->quitCareer(preserveExp: true);

                JournalService::custom($character->id, 'career_step_down', [
                    'message' => "You have stepped down from your position as {$rankName} in the Police Department.",
                ]);

                Log::info('[Police] Officer stepped down.', [
                    'character' => $character->id,
                    'rank' => $rankName,
                ]);

                return redirect()->route('dashboard')
                    ->with('success', "You have stepped down as {$rankName}. The city thanks you for having served them.");
            });
        } catch (\Throwable $e) {
            Log::error('[Police] Step-down failed', [
                'character' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Step-down failed. Please try again.');
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

        if ((int) $character->career_rank !== 4) {
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
            return 'There is no rank-3 officer in your home precinct who is ready to step up. '
                . 'You cannot step down without penalty until a superintendent is ready for the work of a commisioner!';
        }

        return null;
    }




    public function takeCase(Request $request, int $id)
    {
        $character = $request->user()->character;

        return DB::transaction(function () use ($character, $id) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if (!$character->isAlive()) {
                return back()->with('error', 'You cannot work in your current state.');
            }
            if ($character->isHospitalized() || $character->isJailed()) {
                return back()->with('error', 'You cannot work while hospitalised.');
            }


            $hasActiveCase = CrimeRecord::where('detective_id', $character->id)
                ->whereIn('status', [CrimeRecord::STATUS_INVESTIGATING])
                ->exists();
            if ($hasActiveCase) {
                return back()->with('error', 'You are already investigating a case. Finish or abandon it first.');
            }

            $record = CrimeRecord::lockForUpdate()->find($id);

            if (!$record) {
                return back()->with('error', 'Case not found.');
            }
            if ($record->city_id !== $character->home_city_id) {
                return back()->with('error', 'You can only investigate cases in your home city.');
            }
            if (!in_array($record->status, [CrimeRecord::STATUS_OPEN, CrimeRecord::STATUS_INVESTIGATING])) {
                return back()->with('error', 'This case is no longer available.');
            }
            if (!$record->isAccessibleAtPoliceRank($character->career_rank)) {
                return back()->with('error', 'Your rank clearance does not cover this case severity.');
            }
            if ($record->detective_id) {
                return back()->with('error', 'This case is already assigned to another detective.');
            }
            if ($record->isConflicted($character)) {
                return back()->with('error', 'You have a conflict of interest and cannot work this case.');
            }


            $record->detective_id = $character->id;
            $record->status = CrimeRecord::STATUS_INVESTIGATING;
            $record->save();

            Log::info('[Police] Officer took case.', [
                'officer' => $character->id,
                'case' => $id,
            ]);

            return redirect()->route('career.police')->with('success', "You are now investigating case #{$id}.");
        });
    }


  public function abandonCase(Request $request)
    {
        $character = $request->user()->character;

        return DB::transaction(function () use ($character) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if (!$character->isAlive()) {
                return back()->with('error', 'You cannot work in your current state.');
            }
            if ($character->isHospitalized() || $character->isJailed()) {
                return back()->with('error', 'You cannot work while hospitalised.');
            }


            if ($character->timers?->next_action_at?->isFuture()) {
                return back()->with('error', 'You must wait before performing another action.');
            }

            $activeCase = CrimeRecord::where('detective_id', $character->id)
                ->whereIn('status', [CrimeRecord::STATUS_INVESTIGATING])
                ->lockForUpdate()
                ->first();

            if (!$activeCase) {
                return back()->with('error', 'You are not investigating any case.');
            }

            $isCapital = $activeCase->severity === CrimeRecord::SEV_CAPITAL;
            if ($isCapital) {
                $activeCase->detective_id = null;
                $activeCase->status = CrimeRecord::STATUS_OPEN;
                $activeCase->save();
                $message = "You managed to get yourself off the case, but you couldn't shelve it since it was too hot!";
            } else {
                $activeCase->delete();
                $message = 'You have abandoned this case and managed to shelve it somewhere no one can find it.';
            }


            $character->timers()->update([
                'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
            ]);

            Log::info('[Police] Officer abandoned case.', [
                'officer' => $character->id,
                'case' => $activeCase->id,
                'capital' => $isCapital,
            ]);

            return back()->with('success', $message);
        });
    }


    public function investigate(Request $request, int $id)
    {
        $clueType = in_array($request->input('clue_type'), ['witness', 'stat', 'item'])
            ? $request->input('clue_type')
            : 'witness';

        $character = $request->user()->character;

        return DB::transaction(function () use ($character, $id, $clueType) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if (!$character->isAlive()) {
                return back()->with('error', 'You cannot work in your current state.');
            }
            if ($character->isHospitalized() || $character->isJailed()) {
                return back()->with('error', 'You cannot work while hospitalised.');
            }


            $record = CrimeRecord::lockForUpdate()->find($id);

            if (!$record) {
                return back()->with('error', 'Case not found.');
            }
            if ($record->city_id !== $character->home_city_id) {
                return back()->with('error', 'You can only investigate cases in your home city.');
            }
            if ($record->detective_id !== $character->id) {
                return back()->with('error', 'You are not assigned to this case.');
            }
            if (!in_array($record->status, [CrimeRecord::STATUS_OPEN, CrimeRecord::STATUS_INVESTIGATING])) {
                return back()->with('error', 'This case is no longer available for investigation.');
            }
            if (!$record->isAccessibleAtPoliceRank($character->career_rank)) {
                return back()->with('error', 'Your rank clearance does not cover this case severity.');
            }
            if ($record->isConflicted($character)) {
                return back()->with('error', 'You have a conflict of interest and cannot work this case.');
            }

            $data = $record->data ?? [];


            if (isset($data['clues'][$clueType])) {
                return back()->with('info', $data['clues'][$clueType]);
            }

            $character->loadMissing(['stats', 'items.template', 'property', 'corporation']);

            $perp = $record->character_id
                ? Character::withTrashed()->find($record->character_id)
                : null;

            if ($perp) {
                $perp->loadMissing(['stats', 'items.template', 'property', 'corporation', 'homeCity']);
                $perp->stats?->setRelation('character', $perp);
            }

            $stats = $character->stats;
            if ($stats) {
                $stats->setRelation('character', $character);
                $effective = $stats->effectiveStats();
            } else {
                $effective = [];
            }

            $xpFactor = min(15.0, $character->career_xp / 15000);
            $rankLabel = $character->current_rank?->rank_name ?? 'Officer';
            $detName = $character->display_name;
            $tier = 0;
            $gainedBase = 0;



            $clueRoll = function (int $stat) use ($xpFactor): int {
                $goodPct = min(75, $xpFactor * 3 + log10(max(1, $stat)) * 5);
                $midPct = min(90, max(50, $goodPct + 15));
                $roll = random_int(1, 100);
                return $roll <= (int) $goodPct ? 2 : ($roll <= (int) $midPct ? 1 : 0);
            };


            $computeBase = function (int $stat) use ($xpFactor): int {
                $statBonus = log10(max(1, $stat)) * 1.5;
                $noise = random_int(-3, 5);
                return max(2, min(25, (int) round(5 + $xpFactor + $statBonus + $noise)));
            };

            switch ($clueType) {


                case 'witness':
                default:
                    $intel = (int) ($effective['intelligence'] ?? 1);

                    if ($perp) {
                        $tier = $clueRoll($intel);
                        $gainedBase = $computeBase($intel);
                        $gender = $perp->gender === 'Male' ? 'he' : 'she';
                        $city = $perp->homeCity?->name ?? 'somewhere';
                        $initial = substr($perp->display_name, 0, 3);

                        $clue = match ($tier) {
                            2 => "was certain the suspect had a name starting with \"{$initial}\" and that {$gender} came from {$city}.",
                            1 => "vaguely recalled someone around that time whose name might have started with \"{$initial}\".",
                            default => 'gave conflicting and unusable descriptions — no actionable lead.',
                        };
                    } else {
                        $clue = 'gave conflicting and unusable descriptions — no actionable lead.';
                    }
                    $note = "{$rankLabel} {$detName} interviewed a witness who {$clue}";
                    break;


                case 'stat':
                    $statVal = (int) max(1, (($effective['offense'] ?? 1) + ($effective['defense'] ?? 1)) / 2);

                    if ($perp && $perp->stats) {
                        $tier = $clueRoll($statVal);
                        $gainedBase = $computeBase($statVal);
                        $perpStats = $perp->stats->effectiveStats();
                        $ratio = ($perpStats['offense'] ?? 0) / max(1, $perpStats['defense'] ?? 1);
                        $gender = $perp->gender === 'Male' ? 'he' : 'she';

                        if ($tier === 2) {
                            $build = $ratio > 1.5
                                ? "{$gender} is exceptionally strong and physically dominant"
                                : ($ratio < 0.7
                                    ? "{$gender} is remarkably agile and quick — built for evasion"
                                    : "{$gender} has a balanced, athletic build");
                            $clue = "a full criminal profile emerged from the scene: {$build}.";
                        } elseif ($tier === 1) {
                            $build = $ratio > 1.5 ? 'physically imposing' : ($ratio < 0.7 ? 'quick and evasive' : 'of average build');
                            $clue = "a partial profile suggests the suspect is likely {$build}.";
                        } else {
                            $clue = 'the scene yielded nothing useful — the profile could not be built.';
                        }
                    } else {
                        $clue = 'the scene yielded nothing useful — the profile could not be built.';
                    }
                    $note = "{$rankLabel} {$detName} combed the entire scene to build a criminal profile — {$clue}";
                    break;


                case 'item':
                    $luck = (int) ($effective['luck'] ?? 1);

                    if ($perp) {
                        $tier = $clueRoll($luck);
                        $gainedBase = $computeBase($luck);
                        $gender = $perp->gender === 'Male' ? 'he' : 'she';
                        $items = $perp->getOnHandItems()->filter(
                            fn($i) => in_array($i->template?->type, ['weapon', 'gadget', 'armor'])
                        );

                        if ($items->isNotEmpty()) {
                            $itemName = $items->random()->template?->name ?? 'an unknown object';
                            if ($tier === 2) {
                                $clue = "a source confirmed {$gender} had recently obtained a {$itemName}.";
                            } elseif ($tier === 1) {
                                $clue = "unverified intelligence suggested someone matching the profile was seen with a {$itemName}.";
                            } else {
                                $clue = 'despite the effort, no personal effects or useful digital trail could be found.';
                            }
                        } else {

                            $tier = 0;
                            $clue = 'despite the effort, no personal effects or useful digital trail could be found.';
                        }
                    } else {
                        $tier = 0;
                        $clue = 'despite the effort, no personal effects or useful digital trail could be found.';
                    }
                    $note = "{$rankLabel} {$detName} worked with the cybersecurity and Foreign Intelligence division — {$clue}";
                    break;
            }



            $actualGained = match ($tier) {
                2 => $gainedBase,
                1 => max(1, (int) floor($gainedBase * 0.55)),
                default => 0,
            };

            if ($tier > 0) {
                $data['clues'][$clueType] = $note;
                $record->data = $data;
                $record->addEvidence($actualGained, $note);
                $character->addXp((int) round(10 + ($actualGained * 4)));
            } else {
                $data['clues'][$clueType] = $note;
                $record->data = $data;
                $record->addEvidence(0, $note);
            }


            \App\Models\CharacterHistory::addHistory($character, 'cases_investigated');



            Log::info('[Police] Investigation completed.', [
                'detective' => $character->id,
                'case' => $id,
                'clue_type' => $clueType,
                'xp_factor' => round($xpFactor, 2),
                'tier' => $tier,
                'evidence_gained' => $actualGained,
                'evidence_total' => $record->evidence_level,
            ]);

            if ($tier === 0) {
                return back()->with(
                    'error',
                    'The investigation turned up nothing useful. You seem to have wasted your time following this lead.'
                );
            }
            $clueTypeLabel = match ($clueType) {
                'stat' => 'criminal profile',
                'item' => 'intelligence lead',
                default => 'witness lead',
            };
            $qualityLabel = $tier === 2 ? 'strong' : 'partial';
            return back()->with('success', "You managed to get a {$qualityLabel} {$clueTypeLabel} and added it to your case file.");
        });
    }
    public function refer(Request $request, int $id)
    {
        $request->validate([
            'suspect_names' => 'required|array|min:1|max:5',
            'suspect_names.*' => 'required|string|max:100|min:2',
        ]);

        $character = $request->user()->character;

        return DB::transaction(function () use ($character, $request, $id) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if (!$character->isAlive()) {
                return back()->with('error', 'You cannot work in your current state.');
            }
            if ($character->isHospitalized() || $character->isJailed()) {
                return back()->with('error', 'You cannot work while hospitalised.');
            }

            if ($character->timers?->next_action_at?->isFuture()) {
                return back()->with('error', 'You must wait before performing another action.');
            }

            $record = CrimeRecord::lockForUpdate()->find($id);

            if (!$record) {
                return back()->with('error', 'Case not found.');
            }
            if ($record->status !== CrimeRecord::STATUS_INVESTIGATING) {
                return back()->with('error', 'You can only refer a case that is actively under investigation.');
            }
            if ($record->detective_id !== $character->id) {
                return back()->with('error', 'Only the assigned detective may refer this case.');
            }
            if (!$record->isAccessibleAtPoliceRank($character->career_rank)) {
                return back()->with('error', 'Your rank clearance does not cover this case severity.');
            }


            if ($record->city_id !== $character->home_city_id) {
                return back()->with('error', 'You can only investigate cases that take place in your home city');

            }


            if ($record->isConflicted($character)) {
                return back()->with('error', 'You have a conflict of interest and cannot refer this case.');
            }



            $suspectNames = array_values(array_unique(array_filter(
                array_map('trim', $request->suspect_names),
                fn($n) => strlen($n) >= 2
            )));

            if (empty($suspectNames)) {
                return back()->with('error', 'At least one valid suspect name is required.');
            }


            $normalizedInputs = array_map('strtolower', $suspectNames);
            $foundCharacters = Character::whereIn(DB::raw('LOWER(display_name)'), $normalizedInputs)
                ->whereNotNull('user_id')
                ->withTrashed()
                ->get(['id', 'display_name'])
                ->keyBy(fn(Character $suspect) => strtolower($suspect->display_name));

            $invalidNames = array_filter($suspectNames, fn($n) => !$foundCharacters->has(strtolower($n)));

            if (!empty($invalidNames)) {
                return back()->with('error', 'You must enter the name of an existing player suspect!');
            }


            $validatedNames = [];
            $validatedIds = [];
            foreach ($suspectNames as $n) {
                $found = $foundCharacters->get(strtolower($n));
                if ($found) {
                    $validatedNames[] = $found->display_name;
                    $validatedIds[] = (int) $found->id;
                }
            }
            $suspectNames = array_values(array_unique($validatedNames));
            $validatedIds = array_values(array_unique($validatedIds));

            if (in_array($character->display_name, $suspectNames, true)) {
                return back()->with('error', 'You cannot name yourself as a suspect.');
            }

            $victimId = isset($record->data['victim_id']) ? (int) $record->data['victim_id'] : null;
            if ($victimId) {
                $victimName = Character::withTrashed()->find($victimId)?->display_name;
                if ($victimName && in_array($victimName, $suspectNames, true)) {
                    return back()->with('error', 'The victim of a crime cannot be listed as a suspect.');
                }
            }

            if (!$record->refer($character, $suspectNames)) {
                return back()->with('error', 'This case cannot be referred in its current state.');
            }

            $data = $record->data ?? [];
            $data['suspect_ids'] = $validatedIds;
            $record->data = $data;
            $record->save();

            $character->timers()->update([
                'next_action_at' => now()->addSeconds(config('timers.action'))->getTimestamp(),
            ]);

            $character->addXp(50);
            $character->increment('cash_on_hand', 500);

            \App\Models\CharacterHistory::addHistoryCaseType($character, $record->type);

            Log::info('[Police] Case referred to Law.', [
                'detective' => $character->id,
                'case' => $id,
                'suspect_count' => count($suspectNames),
            ]);

            return back()->with(
                'success',
                'You have successfully sent the case to the office of the prosecutor and have been paid for a good day\'s work!'
            );
        });
    }

    public function dismissOfficer(Request $request)
    {
        $character = $request->user()->character;

        $policeCareerId = Career::findByCode('police')?->id;

        $isCommissioner = $policeCareerId
            && $character->career_id === $policeCareerId
            && $character->home_city_id !== null
            && (int) $character->career_rank === 4;

        if (!$isCommissioner) {
            return back()->with('error', 'Only the Commissioner can dismiss officers.');
        }

        $data = $request->validate([
            'character_id' => 'required|integer|exists:characters,id',
        ]);

        if ((int) $data['character_id'] === $character->id) {
            return back()->with('error', 'You cannot dismiss yourself, Commissioner.');
        }

        try {
            return DB::transaction(function () use ($character, $data, $policeCareerId) {
                $target = Character::lockForUpdate()->find($data['character_id']);

                if (!$target) {
                    return back()->with('error', 'Officer not found.');
                }

                if ($target->career_id !== $policeCareerId || $target->home_city_id !== $character->home_city_id) {
                    return back()->with('error', 'That officer does not serve under your precinct.');
                }

                if ((int) $target->career_rank === 4) {
                    return back()->with('error', 'You cannot dismiss another Commissioner.');
                }


                $commisionerRankName = $character->current_rank?->rank_name ?? 'Commisioner-General';


                $target->quitCareer(true);

                JournalService::careerDismissed(
                    $target->id,
                    $character->display_name,
                    $commisionerRankName,
                    'Police Department'

                );

                return back()->with('success', "{$target->display_name} has been dismissed from the force.");
            });
        } catch (\Throwable $e) {
            Log::error('[Police] Dismiss failed', [
                'commissioner' => $character->id,
                'error' => $e->getMessage(),
            ]);
            return back()->with('error', 'Dismissal failed. Please try again.');
        }
    }

    public function fieldIntel(Request $request, int $id)
    {
        $request->validate([
            'target_name' => 'required|string|min:2|max:50',
        ]);

        $character = $request->user()->character;
        $targetName = $request->input('target_name');

        return DB::transaction(function () use ($character, $id, $targetName) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();

            if (!$character->isAlive() || $character->isHospitalized() || $character->isJailed()) {
                return back()->with('error', 'You cannot work in your current state.');
            }


            $record = CrimeRecord::where('id', $id)
                ->where('status', CrimeRecord::STATUS_INVESTIGATING)
                ->where('detective_id', $character->id)
                ->lockForUpdate()
                ->first();

            if (!$record) {
                return back()->with('error', 'Case not found or not active.');
            }

            $data = $record->data ?? [];


            if (isset($data['field_intel'])) {
                return back()->with('error', 'Field intelligence has already been logged for this case.');
            }


            $target = Character::whereRaw('LOWER(display_name) = ?', [strtolower($targetName)])
                ->whereNotNull('user_id')
                ->inCity($character->home_city_id)
                ->alive()
                ->first();

            if (!$target) {

                return back()->with('error', "$targetName not found in your jurisdiction.");
            }
            //!block surveillance on victim


            $items = $target->getOnHandItems()->filter(function ($i) {
                return in_array($i->template?->type, ['weapon', 'armor', 'gadget']);
            });


            $data['field_intel'][] = [
                'target_name' => $target->display_name,
                'officer_name' => collect(explode(' ', $character->display_name))->last(),
                'logged_at' => now()->utc()->format('d/m/y H:i:s') . ' UTC',
                'items' => $items->map(function ($i) {
                    return [
                        'name' => $i->template->name,
                        'image_url' => $i->template->image_url,
                        'type' => $i->template->type,
                    ];
                })->values()->all(),
            ];

            $record->data = $data;
            $record->save();
            //! just flat.
            $character->addXp(25);



            Log::info('[Police] Field Intel logged.', [
                'detective' => $character->id,
                'case' => $id,
                'target' => $target->id,
                'items_num' => $items->count(),
            ]);

            return back()->with('success', "Field Intelligence Log updated for $targetName.");
        });
    }

    /**
     * Live crime dispatch for a city. Reads the Redis cache list written
     * by CrimeService::pushDispatch and filters out anything older than
     * 15 minutes (per-entry expiry independent of cache TTL refreshes).
     * Returns plain arrays already shaped for the frontend.
     */
    private function loadCrimeDispatch(int $cityId): array
    {
        $list = \Illuminate\Support\Facades\Cache::get('crime_log:' . $cityId, []);
        if (!is_array($list)) {
            return [];
        }

        $cutoff = now()->subMinutes(15);
        $out = [];

        foreach ($list as $entry) {
            $at = $entry['at'] ?? null;
            if (!$at) {
                continue;
            }
            try {
                $when = \Carbon\Carbon::parse($at);
            } catch (\Throwable $e) {
                continue;
            }
            if ($when->lt($cutoff)) {
                continue;
            }

            $type = $entry['type'] ?? '';
            $out[] = [
                'at_utc' => $when->utc()->format('d/m/y H:i:s') . ' UTC',
                'type' => $type,
                'type_label' => CrimeRecord::TYPE_LABELS[$type] ?? $type,
                'severity' => $entry['severity'] ?? 'felony',
                'name' => $entry['name'] ?? null,
            ];
        }

        return $out;
    }

    private function formatCaseForPolice(
        CrimeRecord $r,
        int $myCharacterId,
        string $cityName = '',
        array $caseActorNames = [],
    ): array {
        $data = $r->data ?? [];
        $isMineCase = $r->detective_id === $myCharacterId;
        $actorName = fn($id) => $id ? ($caseActorNames[$id] ?? null) : null;

        if (!$isMineCase) {
            return [
                'id' => $r->id,
                'type' => $r->type,
                'type_label' => $r->typeLabel(),
                'severity' => $r->severity,
                'status' => $r->status,
                'victim_name' => $data['victim_name'] ?? null,
                'is_mine' => false,
                'committed_at_utc' => $r->committed_at ? $r->committed_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
                'detective_name' => $actorName($r->detective_id),
                'prosecutor_name' => $actorName($r->prosecutor_id),
                'defense_name' => $actorName($r->defense_id),
                'judge_name' => $actorName($r->judge_id),
            ];
        }

        return [
            'id' => $r->id,
            'type' => $r->type,
            'type_label' => $r->typeLabel(),
            'severity' => $r->severity,
            'status' => $r->status,
            'city_name' => $cityName,
            'committed_at_utc' => $r->committed_at ? $r->committed_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'victim_name' => $data['victim_name'] ?? null,
            'amount' => $data['amount'] ?? null,
            'participant_count' => isset($data['participants']) ? count($data['participants']) : 0,
            'suspect_names' => ($isMineCase && in_array($r->status, [
                CrimeRecord::STATUS_REFERRED,
                CrimeRecord::STATUS_CHARGED,
                CrimeRecord::STATUS_CONVICTED,
                CrimeRecord::STATUS_SENTENCED,
                CrimeRecord::STATUS_APPEALED,
            ])) ? ($data['suspect_names'] ?? []) : [],
            'investigation_notes' => $isMineCase ? ($data['investigation_notes'] ?? []) : [],
            'field_intel' => $isMineCase ? ($data['field_intel'] ?? null) : null,
            'detective_name' => $actorName($r->detective_id),
            'prosecutor_name' => $actorName($r->prosecutor_id),
            'defense_name' => $actorName($r->defense_id),
            'judge_name' => $actorName($r->judge_id),
            'referred_at_utc' => $r->referred_at ? $r->referred_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'charged_at_utc' => $r->charged_at ? $r->charged_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'sentenced_at_utc' => $r->sentenced_at ? $r->sentenced_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'resolved_at_utc' => $r->resolved_at ? $r->resolved_at->utc()->format('d/m/y H:i:s') . ' UTC' : null,
            'sentence' => $r->sentence,
        ];
    }
}
