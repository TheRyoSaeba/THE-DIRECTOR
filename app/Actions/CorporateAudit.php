<?php

namespace App\Actions;
use App\Models\City;
use App\Models\Character;
use App\Models\Corporation;
use App\Models\MayorTerm;
use App\Services\CrimeService;
use App\Services\JournalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


//TODO fix audit should be police group action
class CorporateAudit extends Action
{
    private const COOLDOWN_MINUTES  = 180;
    private const REQUIRED_RANK     = 4;

    public function getId(): string          { return 'corporate_audit'; }
    public function getIcon(): string        { return 'Files'; }
    public function getButtonLabel(): string { return 'Execute Audit'; }

    public function getShape(Character $character): array
    {
        return [
            'id'               => $this->getId(),
            'title'            => 'Corporate Audit',
   
            'category'         => 'Police',
            'description'      => 'Execute a mayoral audit order. Investigate the targeted corporation\'s financial records and recover undeclared funds for the city treasury.',
            'image_url'        => 'https://images.thedirector.app/actions/audit.jpeg',
            'icon'             => $this->getIcon(),
            'button_label'     => $this->getButtonLabel(),
            'group_init_label' => null,
            'execute_route'    => route('career.police.actions.corporate-audit'),
            'cancel_route'     => null,
            'is_group_action'  => false,
            'available'        => true,
            'blocker'          => null,
            'is_waiting'       => false,
            'is_ready'         => false,
            'active_members'   => null,
            'targets'          => null,
            'accomplices'      => null,
            'has_amount_input' => false,
            'amount_label'     => null,
            'pick_label'       => null,
            'target_icon'      => null,
        ];
    }

    public function canExecute(Character $character): array
    {
        if ($this->isOnCooldown($character)) {
            return ['valid' => false, 'error' => 'You must wait before conducting another audit.'];
        }
        if (! $this->isAvailable($character)) {
            return ['valid' => false, 'error' => 'You cannot conduct an audit from hospital or jail.'];
        }
        if ((int) $character->career_rank < self::REQUIRED_RANK) {
            return ['valid' => false, 'error' => 'Only the Police Commissioner (rank 4) can execute a corporate audit.'];
        }

        $term = $this->activeTerm($character);
        if (! $term) {
            return ['valid' => false, 'error' => 'No active mayoral term found for your city.'];
        }
        if (! $term->auditInProgress()) {
            return ['valid' => false, 'error' => 'No audit has been ordered by the Mayor.'];
        }
        if (! $term->auditActionUnlocked()) {
            return ['valid' => false, 'error' => 'Your police budget is not high enough to conduct an audit.'];
        }

        return ['valid' => true, 'error' => null];
    }

    public function execute(Character $character, array $params): RedirectResponse
    {
        $check = $this->canExecute($character);
        if (! $check['valid']) {
            return $this->error($check['error']);
        }

        return DB::transaction(function () use ($character) {
            DB::table('characters')->where('id', $character->id)->lockForUpdate()->first();
            $character->refresh();

            $recheck = $this->canExecute($character);
            if (! $recheck['valid']) {
                return $this->error($recheck['error']);
            }

            $term = $this->activeTerm($character);
            if (! $term) {
                return $this->error('No active mayoral term found.');
            }


            $corp = Corporation::where('home_city_id', $character->home_city_id)
                ->where('is_holding_company', false)
                ->where('slush_fund', '>', 0)
                ->lockForUpdate()
                ->orderByDesc('slush_fund')
                ->first();

            if (! $corp) {
                $this->setCooldownMinutes($character, self::COOLDOWN_MINUTES);
                $term->resolveAudit('failed', ['reason' => 'no_targets', 'resolved_period' => $term->period]);
                return $this->error('No corporation with reportable funds was found in the city. The audit has been closed.');
            }

            $chance  = $this->chance($character, $corp);
            $roll    = mt_rand(1, 100);
            $success = $roll <= $chance;

            $this->setCooldownMinutes($character, self::COOLDOWN_MINUTES);
            $this->resetStrength($character);

            Log::info('[CorporateAudit] Roll.', [
                'officer'  => $character->id,
                'corp'     => $corp->id,
                'chance'   => $chance,
                'roll'     => $roll,
                'success'  => $success,
            ]);

            $ceoName = $corp->ceo?->display_name ?? 'Unknown';

            $record = CrimeService::taxEvasion(
                perpetrator:      $corp->ceo,
                corp:             $corp,
                cityId:           $character->home_city_id,
                cityName:         $character->homeCity?->name ?? 'the City',
                success:          $success,
                slushFundAtAudit: $corp->slush_fund,
                auditOrderNote:   "Corporate audit ordered by the Mayor. Officer {$character->display_name} executed the warrant against {$corp->name}.",
            );

          
            if (! $record->refer($character, [$ceoName])) {
                Log::warning('[CorporateAudit] Could not refer audit case to Law.', [
                    'record'  => $record->id,
                    'officer' => $character->id,
                    'corp'    => $corp->id,
                ]);
            }

            if ($success) {
                $pct      = mt_rand(5, 15) / 100;
                $recovery = (int) floor($corp->slush_fund * $pct);

                if ($recovery > 0) {
                    $corp->decrement('slush_fund', $recovery);
                    $term->addFunds($recovery);
                }

                $character->addXp(mt_rand(150, 250));
                City::decreaseCrimeRateById($character->home_city_id, 10.0);

                $term->resolveAudit('success', [
                    'resolved_period' => $term->period,
                    'recovery'        => $recovery,
                    'corp_id'         => $corp->id,
                    'corp_name'       => $corp->name,
                    'crime_record_id' => $record->id,
                ]);

                JournalService::custom($character->id, 'audit_success', [
                    'message' => "Your audit of {$corp->name} recovered $" . number_format($recovery)
                        . " for the city treasury. A tax evasion case has been referred to Law.",
                ]);

                if ($corp->ceo_id) {
                    JournalService::custom($corp->ceo_id, 'audit_target', [
                        'message' => "Your corporation {$corp->name} has been audited by the city. "
                            . "$" . number_format($recovery) . " has been seized from your funds. "
                            . "A tax evasion case has been referred to the Law Department.",
                    ]);
                }

                return $this->success(
                    "You successfully audited {$corp->name} and managed to recover \${$recovery} from their slush fund that they hid from the city and added it to the city treasury. "
                    . " A tax evasion case has also been opened against them."
                );
            }

            
            $term->resolveAudit('failed', [
                'resolved_period' => $term->period,
                'corp_id'         => $corp->id,
                'corp_name'       => $corp->name,
                'crime_record_id' => $record->id,
            ]);

            $character->addXp(mt_rand(25, 50));

            if ($corp->ceo_id) {
                JournalService::custom($corp->ceo_id, 'audit_target', [
                    'message' => "City auditors investigated {$corp->name} but found no actionable evidence of tax evasion.",
                ]);
            }

            return $this->error(
                "The audit of {$corp->name} found insufficient evidence for a recovery. A case has still been referred to Law."
            );
        });
    }

    

    private function activeTerm(Character $character): ?MayorTerm
    {
        return MayorTerm::activeForCity($character->home_city_id);
    }

    
    private function chance(Character $character, Corporation $corp): int
    { 
        
        $character->loadMissing('stats');
        $effective = $character->stats?->effectiveStats() ?? [];

        $officerPower = ($effective['intelligence'] ?? 0) + ($effective['defense'] ?? 0);
        $corpPower    = max(1, $corp->strength);

        $ratio      = $officerPower / max(1, $corpPower * 10);
        $statFactor = log(max(0.01, $ratio), 2) * 3.0;

        $statChance         = 50.0 + $statFactor;
        $strengthMultiplier = max(0.15, sqrt($this->getStrength($character) / 100));

        return (int) max(25, min(95, (int) round($statChance * $strengthMultiplier)));
    }
}
