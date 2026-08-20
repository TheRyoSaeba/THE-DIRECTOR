<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\City;
use App\Models\CrimeRecord;
use App\Services\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class Arrest extends Action
{
    public function getId(): string        { return 'arrest'; }
    public function getIcon(): string      { return 'Shield'; }
    public function getButtonLabel(): string { return 'Execute Warrant'; }

    public function getShape(Character $character): array
    {

        $targets = $this->arrestableTargets($character);

        return [
            'id'               => $this->getId(),
            'title'            => 'Arrest',
            
            'category'         => 'Police',
            'description'      => 'Execute a warrant on a sentenced criminal. The warrant must be issued from your home city and the criminal must be online and available.',
            'image_url'        => 'https://images.thedirector.app/actions/arrest.jpg',
            'icon'             => $this->getIcon(),
            'button_label'     => $this->getButtonLabel(),
            'group_init_label' => null,
            'execute_route'    => route('career.police.actions.arrest'),
            'cancel_route'     => null,
            'is_group_action'  => false,
            'available'        => true,
            'blocker'          => null,
            'is_waiting'       => false,
            'is_ready'         => false,
            'active_members'   => null,
            'targets'          => $targets,
            'accomplices'      => null,
            'has_amount_input' => false,
            'amount_label'     => null,
            'pick_label'       => 'Select a suspect',
            'target_icon'      => 'user',
        ];
    }

    public function canExecute(Character $character): array
    {
        $isPolice = strtolower($character->career?->code ?? '') === 'police';
        $isRank2  = (int) $character->career_rank >= 2;

        if (!($isPolice && $isRank2)) {
            return ['valid' => false, 'error' => 'You need to be police rank 2 or above to make an arrest.'];
        }



        if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You need to wait before performing another action.'];
        }

        if (! $this->isAvailable($character)) {
            return ['valid' => false, 'error' => 'You cannot make an arrest from the hospital or a jail cell.'];
        }
        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): RedirectResponse
    {
        $check = $this->canExecute($character);
        if (! $check['valid']) {
            return $this->error($check['error']);
        }

        $rawTargetId = (string) ($params['target_id'] ?? '');
        $parts       = explode(':', $rawTargetId, 2);

        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return $this->error('You must select a valid arrest target.');
        }

        [$recordId, $targetCharId] = [(int) $parts[0], (int) $parts[1]];

        $record = CrimeRecord::where('id', '=', $recordId, 'and')
            ->where('city_id', '=', $character->home_city_id, 'and')
            ->where('status', '=', CrimeRecord::STATUS_SENTENCED, 'and')
            ->first();

        if (! $record) {
            return $this->error('This warrant is invalid, has been appealed, or is not yet actionable.');
        }

        $allParties = $record->partyIds();

        if (! in_array($targetCharId, $allParties, true)) {
            return $this->error('That individual is not named on this warrant.');
        }

        $alreadyArrested = array_map('intval', $record->data['arrested_parties'] ?? []);
        if (in_array($targetCharId, $alreadyArrested, true)) {
            return $this->error('This suspect has already been arrested on this warrant.');
        }

        $target = Character::with(['timers', 'stats'])
            ->where('id', '=', $targetCharId)
            ->where('city_id', '=', $character->city_id)
            ->whereNull('deleted_at')
            ->first();

        if (! $target)                    return $this->error('The suspect is not present in your city.');
        if (! $target->isOnline())        return $this->error('The suspect must be online to be arrested.');
        if ($target->isHospitalized())    return $this->error('You cannot arrest a hospitalised suspect.');
        if ($target->isJailed())          return $this->error('This suspect is already in custody.');

        if ($this->isTargetOnActionCooldown($target)) {
            return $this->error("{$target->display_name} has recently had an action committed against them and is on high alert. Try again later.");
        }

        $strength = $this->getStrength($character);

        $this->setTargetActionCooldown($target, 15 * 60, 30 * 60);

        $city   = City::find($character->home_city_id);
        $chance = $this->chance($character, $target, $strength, $city);
        $roll   = mt_rand(1, 100);

        $this->logAttempt($character, $target, $record, $chance, $roll);

        $this->setCooldown($character, 15 * 60);
        $this->resetStrength($character);

        $rankName   = $character->current_rank?->rank_name ?? 'Officer';
        $crimeLabel = $record->typeLabel();

        if ($roll > $chance) {
            JournalService::custom($target->id, 'arrested', [
                'status'       => 'failure',
                'case_id'      => $record->id,
                'crime_label'  => $crimeLabel,
                'officer_name' => $character->display_name,
                'message'      => "{$rankName} {$character->display_name} attempted to arrest you for the crime of {$crimeLabel} but you managed to evade them.",
            ]);
            return $this->error("{$target->display_name} slipped through your grasp. They are now aware you are pursuing them.");
        }

        [$fine, $jailSecs] = DB::transaction(function () use ($character, $target, $record, $crimeLabel, $targetCharId, $rankName) {
            $target = Character::lockForUpdate()->find($target->id);
            $target->refresh();

            $fresh = CrimeRecord::lockForUpdate()->find($record->id);

            $allParties = $fresh->partyIds();

            $arrested   = array_map('intval', $fresh->data['arrested_parties'] ?? []);
            $arrested[] = $targetCharId;
            $arrested   = array_values(array_unique($arrested));

            $allArrested = count($arrested) >= count($allParties);

            $fresh->applySentenceToCharacter($target);

            if ($allArrested && $fresh->detective_id) {
                $detective = Character::find($fresh->detective_id);
                if ($detective) {
                    \App\Models\CharacterHistory::addHistory($detective, 'cases_closed');
                }
            }
            //TODO being arrested gives a 10% chance of being fired if not in a corporation

            $fresh->update([
                'status'      => $allArrested ? CrimeRecord::STATUS_CLOSED : $fresh->status,
                'resolved_at' => $allArrested ? now()->utc() : $fresh->resolved_at,
                'data'        => array_merge($fresh->data ?? [], [
                    'arrested_parties' => $arrested,
                    'arrested_by'      => array_merge($fresh->data['arrested_by'] ?? [], [
                        $targetCharId => ['officer_id' => $character->id, 'at' => now()->utc()->toIso8601String()],
                    ]),
                ]),
            ]);

            $xp = match ($fresh->severity) {
                CrimeRecord::SEV_CAPITAL => mt_rand(200, 350),
                CrimeRecord::SEV_FELONY  => mt_rand(100, 190),
                default                  => mt_rand(25, 75),
            };

            $character->stats?->addDefense(mt_rand(25, 50), true);
            $character->addXp($xp);
            City::decreaseCrimeRateById($character->home_city_id, 2.0);

            $fine     = (int) ($fresh->sentence['fine']         ?? 0);
            $jailSecs = (int) ($fresh->sentence['jail_seconds'] ?? 0);

            $fineStr = $fine > 0 ? " fined $" . number_format($fine) : "";
            $jailStr = '';
            if ($jailSecs > 0) {
                $h = intdiv($jailSecs, 3600);
                $jailStr = $h > 0 ? " sentenced to {$h} hours in custody" : " sentenced to custody";
            }
            $andStr      = ($fine > 0 && $jailSecs > 0) ? " and" : "";
            $sentenceMsg = ($fine > 0 || $jailSecs > 0) ? " You were{$fineStr}{$andStr}{$jailStr}." : "";

            JournalService::custom($target->id, 'arrested', [
                'status'       => 'success',
                'case_id'      => $fresh->id,
                'message'      => "You were arrested by {$rankName} {$character->display_name} for the crime of {$crimeLabel}.{$sentenceMsg}",
                'crime_label'  => $crimeLabel,
                'officer_name' => $character->display_name,
                'fine'         => $fine,
                'jail_seconds' => $jailSecs,
            ]);

            return [$fine, $jailSecs];
        });

        \App\Models\CharacterHistory::addHistory($character, 'arrests_made');
        \App\Models\CharacterHistory::addHistoryCaseType($character, $record->type);

        $msg = "You have successfully arrested {$target->display_name} for {$crimeLabel}.";
        if ($fine > 0) {
            $msg .= ' They have received a fine of $' . number_format($fine) . '.';
        }
        if ($jailSecs > 0) {
            $h = intdiv($jailSecs, 3600);
            $msg .= $h > 0 ? " They are now serving {$h} hours in jail." : " They are now serving time in jail.";
        }

        return $this->success($msg);
    }

    private function arrestableTargets(Character $officer): array
    {
        $records = CrimeRecord::where('city_id', '=', $officer->home_city_id, 'and')
            ->where('status', '=', CrimeRecord::STATUS_SENTENCED, 'and')
            ->get();

        $targets = [];
        foreach ($records as $record) {
            $alreadyArrested = array_map('intval', $record->data['arrested_parties'] ?? []);
            $allParties = $record->partyIds();

            foreach ($allParties as $charId) {
                if (in_array($charId, $alreadyArrested, true)) continue;

                $char = Character::with('timers')
                    ->where('id', '=', $charId, 'and')
                    ->where('city_id', '=', $officer->city_id, 'and')
                    ->whereNull('deleted_at')
                    ->first();

                if (! $char)                                      continue;
                if ($char->id === $officer->id)                   continue;
                if (! $char->isOnline())                          continue;
                if ($char->isHospitalized() || $char->isJailed()) continue;

                $isLead = $charId === (int) $record->character_id;
                $targets[] = [
                    'id'          => "{$record->id}:{$charId}",
                    'name'        => $record->typeLabel() . ' — ' . $char->display_name . ($isLead ? '' : ' (co-conspirator)'),
                    'crime_label' => $record->typeLabel(),
                    'severity'    => $record->severity,
                ];
            }
        }
        return $targets;
    }

    private function chance(Character $officer, Character $target, float $strength, ?City $city): int
    {
        $officer->loadMissing('stats');
        $target->loadMissing('stats');

        $oStats = $officer->stats?->effectiveStats() ?? [];
        $tStats = $target->stats?->effectiveStats()  ?? [];

        $officerPower = ($oStats['intelligence'] ?? 0) + ($oStats['defense'] ?? 0);
        $targetPower  = ($tStats['intelligence'] ?? 0) + ($tStats['luck']    ?? 0);
        $ratio        = $officerPower / max(1, $targetPower);
        $statFactor   = log($ratio, 2) * 8;

        $crimePenalty = $this->crimeRateBonus($city, -0.10);

        $statChance         = 70 + $statFactor + $crimePenalty;
        $strengthMultiplier = max(0.15, sqrt($strength / 100));

        return (int) max(25, min(95, (int) round($statChance * $strengthMultiplier)));
    }

    private function logAttempt(Character $officer, Character $target, CrimeRecord $record, int $chance, int $roll): void
    {
        Log::info('[Arrest] Attempt', [
            'officer'  => $officer->id,
            'target'   => $target->id,
            'record'   => $record->id,
            'severity' => $record->severity,
            'chance'   => $chance,
            'roll'     => $roll,
            'outcome'  => $roll <= $chance ? 'SUCCESS' : 'FAILURE',
        ]);
    }
}
