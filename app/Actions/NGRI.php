<?php

namespace App\Actions;

use App\Models\Career;
use App\Models\Character;
use App\Models\CrimeRecord;
use App\Models\City;
use App\Models\CareerRank;
use App\Services\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
//TODO fix to prevent infinite retries

//TODO block if record includes themselves as char or participant
class NGRI extends Action
{
    public function getId(): string          { return 'ngri'; }
    public function getIcon(): string        { return 'Scales'; }
    public function getButtonLabel(): string { return 'File NGRI Plea'; }

    public function getShape(Character $character): array
    {
        
        $targets = [];
        if ($character->home_city_id) {
         
            $cases = CrimeRecord::where('city_id', $character->home_city_id)
                ->where('status', CrimeRecord::STATUS_SENTENCED)
                ->where('character_id', '!=', $character->id)
                ->whereRaw("(data->'participants' IS NULL OR NOT (data->'participants' @> ?::jsonb))", [json_encode([$character->id])])
                ->whereRaw("COALESCE((sentence->>'jail_seconds')::int, 0) > 0")
                ->with(['perpetrator:id,display_name'])
                ->whereHas('perpetrator', fn ($q) => $q->whereNull('deleted_at'))
                ->select('id', 'type', 'severity', 'character_id', 'sentence', 'sentenced_at')
                ->orderByDesc('sentenced_at')
                ->limit(25)
                ->get();

            $severityLabels = [
                CrimeRecord::SEV_MISDEMEANOR => 'Misdemeanour',
                CrimeRecord::SEV_FELONY      => 'Felony',
                CrimeRecord::SEV_CAPITAL     => 'Capital',
            ];

            $targets = $cases->map(function (CrimeRecord $r) use ($severityLabels) {
                $defendantName = $r->perpetrator?->display_name ?? 'Unknown';

                $fine     = (int) ($r->sentence['fine']         ?? 0);
                $jailSecs = (int) ($r->sentence['jail_seconds'] ?? 0);

                $parts = [];
                if ($fine > 0) {
                    $parts[] = '$' . number_format($fine) . ' fine';
                }
                if ($jailSecs > 0) {
                    $hours   = intdiv($jailSecs, 3600);
                    $mins    = intdiv($jailSecs % 3600, 60);
                    $parts[] = $hours > 0 ? "{$hours}h jail" : "{$mins}m jail";
                }
                $sentenceSummary = $parts ? implode(' + ', $parts) : 'No active penalty';

                return [
                    'id'      => encrypt($r->id),
                    'name'    => $defendantName . ' — ' . $r->typeLabel(),
                    'subtitle' => $sentenceSummary . ' · sentenced ' . $r->sentenced_at?->diffForHumans(),
                    'badge'   => $severityLabels[$r->severity] ?? ucfirst($r->severity),
                ];
            })->all();
        }

        return [
            'id'               => $this->getId(),
            'title'            => 'NGRI',
          
            'category'         => 'Healthcare',
            'description'      => 'Leverage your medical credentials to argue that the defendant was suffering from a severe psychiatric disorder at the time of the offence, rendering them not criminally responsible. Success depends on an experienced professional, your strength, and the type of crime committed.',
            'image_url'        => 'https://images.thedirector.app/actions/NGRI.jpg',
            'icon'             => $this->getIcon(),
            'button_label'     => $this->getButtonLabel(),
            'group_init_label' => null,
            'execute_route'    => route('career.healthcare.ngri'),
            'cancel_route'     => null,
            'is_group_action'  => false,
            'available'        => true,
            'blocker'          => null,
            'is_waiting'       => false,
            'is_ready'         => false,
            'active_members'   => null,
            'has_amount_input' => false,
            'amount_label'     => null,
            'pick_label'       => 'Select a sentenced case',
            'target_icon'      => 'scales',
            'targets'          => $targets,
            'accomplices'      => null,
        ];
    }

    public function canExecute(Character $character): array
    {
        if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You must wait before filing another NGRI plea.'];
        }

        if (! $this->isAvailable($character)) {
            return ['valid' => false, 'error' => 'You are currently unavailable (hospitalised or jailed).'];
        }

        $isHealthcare = strtolower($character->career?->code ?? '') === 'healthcare';
        
        if (!($isHealthcare && (int) $character->career_rank >= 2)) {
            return ['valid' => false, 'error' => 'NGRI requires you to at least be a Physician.'];
        }

        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): RedirectResponse
    {
        $check = $this->canExecute($character);
        if (! $check['valid']) {
            return $this->error($check['error']);
        }

        $rawTarget = $params['target_id'] ?? null;
        if (! $rawTarget) {
            return $this->error('You must select a case to file the NGRI plea against.');
        }

        try {
            $caseId = (int) decrypt($rawTarget);
        } catch (\Throwable) {
            return $this->error('Invalid case reference.');
        }

        $careerId = $character->career?->id;
        $city     = $character->homeCity ?? City::find($character->home_city_id);

        $rankName = CareerRank::getRanksForCareer($careerId)
            ->where('rank_level', (int) $character->career_rank)
            ->first()
            ?->rank_name ?? 'Physician';

        $strength = $this->getStrength($character);

        try {
            return DB::transaction(function () use ($character, $caseId, $careerId, $city, $rankName, $strength) {
                DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
                $character->refresh();
                $character->load('timers');

                if (! $character->isAlive() || $character->isHospitalized() || $character->isJailed()) {
                    return $this->error('You cannot file a plea in your current state.');
                }

                if ($character->career_id !== $careerId || (int) $character->career_rank < 2) {
                    return $this->error('NGRI requires Healthcare rank 2 or above.');
                }

                if ($this->isOnCooldown($character)) {
                    return $this->error('You must wait before filing another NGRI plea.');
                }

                $record = CrimeRecord::lockForUpdate()->find($caseId);

                if (! $record) {
                    return $this->error('Case not found.');
                }

                if ($record->status !== CrimeRecord::STATUS_SENTENCED) {
                    return $this->error('This case is no longer at the sentencing stage.');
                }

                if ((int) $record->city_id !== (int) $character->home_city_id) {
                    return $this->error('You can only file pleas for cases within your home city.');
                }


                $participants = array_map('intval', $record->data['participants'] ?? []);
                if ((int) $record->character_id === $character->id || in_array($character->id, $participants, true)) {
                    return $this->error('You cannot file an NGRI plea on a case you are named in.');
                }

                $jailSeconds = (int) ($record->sentence['jail_seconds'] ?? 0);
                if ($jailSeconds <= 0) {
                    return $this->error('NGRI only applies to cases whose sentence involves jail time. This case carries no jail term.');
                }

                $defendant = Character::lockForUpdate()->find($record->character_id);

                if (! $defendant || ! $defendant->isAlive()) {
                    return $this->error('The defendant is no longer available.');
                }

                $chance = $this->chance($character, $record, $strength, $city);
                $roll   = mt_rand(1, 100);
                $hit    = $roll <= $chance;

                Log::info('[NGRI] Attempt.', [
                    'physician' => $character->id,
                    'case'      => $caseId,
                    'severity'  => $record->severity,
                    'strength'  => $strength,
                    'chance'    => $chance,
                    'roll'      => $roll,
                    'outcome'   => $hit ? 'SUCCESS' : 'FAILURE',
                ]);

                $this->setCooldownHours($character, 2);
                $this->resetStrength($character);

                if (! $hit) {
                    return $this->error(
                        'Despite your expert testimony the court rejected the insanity plea. '
                        . 'The sentence stands. You may try again in 2 hours.'
                    );
                }

                
                $record->status      = CrimeRecord::STATUS_CLOSED;
                $record->resolved_at = now()->utc();
                $record->save();

                $xpReward = match ($record->severity) {
                    CrimeRecord::SEV_CAPITAL => 120,
                    CrimeRecord::SEV_FELONY  => 70,
                    default                  => 35,
                };
                $character->addXp($xpReward);
                

                \App\Models\CharacterHistory::addHistory($character, 'ngri_successes');

                if ($record->detective_id) {
                    $detective = Character::find($record->detective_id);
                    if ($detective) {
                        \App\Models\CharacterHistory::addHistory($detective, 'cases_closed');
                    }
                }

                $crimeLabel = $record->typeLabel();
                JournalService::custom($defendant->id, 'ngri_success', [
                    'case_id'     => $record->id,
                    'crime_label' => $crimeLabel,
                    'doctor_name' => $character->display_name,
                    'doctor_rank' => $rankName,
                    'message'     => "{$rankName} {$character->display_name} has successfully managed to argue "
                        . "your case away by proving you were non compos mentis at the time of the offence. "
                        . "He also amazingly avoided you being committed to a psychiatric hospital. "
                        . "The {$crimeLabel} charge has been dismissed and the case is closed.",
                ]);

                return $this->success(
                    "Your NGRI plea for the {$crimeLabel} case was accepted by the court. "
                    . "The case has been closed and the defendant's sentence has been vacated. Abraham Halpern would be angry if he knew what was going on!"
                );
            });
        } catch (\Throwable $e) {
            Log::error('[NGRI] Transaction failed.', [
                'physician' => $character->id,
                'case'      => $caseId,
                'error'     => $e->getMessage(),
            ]);
            return $this->error('The plea could not be filed at this time. Please try again.');
        }
    }

    private function chance(Character $character, CrimeRecord $record, float $strength, ?City $city): int
{
    $xpFactor = log10(max(1, (int) $character->career_xp)) * 3;

    $severityBase = match ($record->severity) {
        CrimeRecord::SEV_MISDEMEANOR => 30,
        CrimeRecord::SEV_FELONY      => -10,
        CrimeRecord::SEV_CAPITAL     => -20,
        default                      => -5,
    };

    $ngriSuccesses = (int) DB::table('character_histories')
        ->where('character_id', $character->id)
        ->value('ngri_successes');

    $ngriBonus = log10($ngriSuccesses + 1) * 3;

    $crimeBonus = $this->crimeRateBonus($city, 0.15);

    $rawChance = $severityBase + $xpFactor + $ngriBonus + $crimeBonus;
    $strengthMultiplier = max(0.15, sqrt($strength / 100));

    $final = $rawChance * $strengthMultiplier;
 
    $saturated = 100 - 100 * exp(-$final / 50);

    return (int) max(3, round($saturated));
}
}
