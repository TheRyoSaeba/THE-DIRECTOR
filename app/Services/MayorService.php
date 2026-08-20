<?php

namespace App\Services;

use App\Models\Character;
use App\Models\City;
use App\Models\CityHallAide;
use App\Models\Election;
use App\Models\MayorTerm;
use App\Support\CrimeThresholds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class MayorService
{
    
    public static function tick(MayorTerm $term, City $city): ?MayorTerm
    {
        
        $term = self::applyLiveDrain($term, $city);
        if ($term === null) {
            return null; 
        }

        
        $term = self::checkBondEvents($term, $city);
        if ($term === null) {
            return null;
        }

        
        
        $cap = MayorTerm::PERIODS_PER_TERM;
        for ($i = 0; $i < $cap; $i++) {
            if ($term->ended_at !== null) {
                return null;
            }
            if (! $term->isOverdue()) {
                break;
            }
            if ($term->period > MayorTerm::PERIODS_PER_TERM) {
                break;
            }

            $term = self::collectPeriod($term, $city);
            if ($term === null) {
                return null;
            }
        }

        return $term;
    }

    
    public static function advancePeriodIfDue(MayorTerm $term, City $city): ?MayorTerm
    {
        return self::tick($term, $city);
    }

    private static function applyLiveDrain(MayorTerm $term, City $city): ?MayorTerm
    {
        $now         = now()->getTimestamp();
        $lastDrained = (int) ($term->actions_log['last_drained_at'] ?? $term->started_at?->getTimestamp());
        $elapsed     = $now - $lastDrained;

        
        if ($elapsed < 60) {
            return $term;
        }

        return DB::transaction(function () use ($term, $city, $elapsed, $now) {
            
            $term = MayorTerm::where('id', $term->id)->lockForUpdate()->first();
            if (! $term || $term->ended_at !== null) {
                return null;
            }

            $crimeRate = (float) $city->crime_rate;
            $fraction  = $elapsed / MayorTerm::PERIOD_SECONDS;

            
            
            
            $log = $term->actions_log ?? [];

            
            
            $drainPerPeriod = 500.0 * $crimeRate;
            $drainNow       = (int) floor($drainPerPeriod * $fraction);

            if ($drainNow > 0) {
                $term->deductFunds($drainNow);
                $log['period_drain'] = (int) ($log['period_drain'] ?? 0) + $drainNow;
            }

            
            
            
            
            $driftPerPeriod = $term->lawPassiveDrift();
            $driftNow       = $driftPerPeriod * $fraction;
            $pendingDrift   = (float) ($log['pending_drift'] ?? 0.0) + $driftNow;

            if (abs($pendingDrift) >= 0.05) {
                if ($pendingDrift > 0) {
                    $city->increaseCrimeRate(abs($pendingDrift));
                } else {
                    $city->decreaseCrimeRate(abs($pendingDrift));
                }
                $pendingDrift = 0.0;
            }

            $log['pending_drift']   = $pendingDrift;
            $log['last_drained_at'] = $now;

            
            DB::table('mayor_terms')->where('id', $term->id)
                ->update(['actions_log' => json_encode($log)]);
            $term->actions_log = $log;

            Log::info('[MayorService] Live drain tick.', [
                'term_id'       => $term->id,
                'elapsed_s'     => $elapsed,
                'drain'         => $drainNow,
                'drift_tick'    => round($driftNow, 4),
                'pending_drift' => round($pendingDrift, 4),
                'crime_rate'    => $crimeRate,
                'law_budget'    => $term->budget_law,
            ]);

            
            if ($term->assembly_score <= 50) {
                self::removeMayor($term, $city, MayorTerm::END_ASSEMBLY);
                return null;
            }

            return $term;
        });
    }

    
    
    

    
    private static function checkBondEvents(MayorTerm $term, City $city): ?MayorTerm
    {
        
        
        
        
        
        $bond = $term->actions_log['bond'] ?? null;
        if ($bond === null) {
            return $term; 
        }

        $nowTs     = now()->getTimestamp();
        $crimeRate = (float) $city->crime_rate;

        
        $crimeDefault  = CrimeThresholds::isAtOrAbove($crimeRate, CrimeThresholds::BOND_DEFAULT_THRESHOLD);
        $budgetDefault = ! $term->bondsUnlocked();

        if ($crimeDefault || $budgetDefault) {
            return DB::transaction(function () use ($term, $city, $crimeDefault) {
                $term = MayorTerm::where('id', $term->id)->lockForUpdate()->first();
                if (! $term || $term->ended_at !== null) return null;

                $term->setBond(null);

                $newAssembly = $term->adjustAssembly(MayorTerm::DELTA_BOND_DEFAULT);

                $log = $term->actions_log ?? [];
                $log['bond_settled_this_period'] = ['type' => 'default', 'at' => now()->getTimestamp()];
                DB::table('mayor_terms')->where('id', $term->id)
                    ->update(['actions_log' => json_encode($log)]);
                $term->actions_log = $log;

                JournalService::custom($term->character_id, 'bond_defaulted', [
                    'message' => 'Your municipal bond has defaulted. '
                        . ($crimeDefault
                            ? 'Crime rate exceeded the safety threshold (65%).'
                            : 'Bonds budget dropped below 50%.')
                        . ' The principal has been lost and the Assembly has lost confidence in your administration.',
                ]);

                Log::info('[MayorService] Bond defaulted (live check).', [
                    'term_id'        => $term->id,
                    'reason'         => $crimeDefault ? 'crime_threshold' : 'budget_pivot',
                    'assembly_after' => $newAssembly,
                ]);

                if ($newAssembly <= 50) {
                    self::removeMayor($term, $city, MayorTerm::END_ASSEMBLY);
                    return null;
                }

                return $term;
            });
        }

        if ($nowTs >= $bond['matures_at']) {
            return DB::transaction(function () use ($term, $bond) {
                $term = MayorTerm::where('id', $term->id)->lockForUpdate()->first();
                if (! $term || $term->ended_at !== null) return null;

                $principal = $bond['principal'];
                $yieldPct  = $bond['yield_pct'];
                $profit    = (int) floor($principal * ($yieldPct / 100));
                $total     = $principal + $profit;

                $term->addFunds($total, 'bond');
                $term->setBond(null);

                $newAssembly = $term->adjustAssembly(MayorTerm::DELTA_BOND_MATURED);

                $log = $term->actions_log ?? [];
                $log['bond_settled_this_period'] = [
                    'type'   => 'matured',
                    'return' => $total,
                    'at'     => now()->getTimestamp(),
                ];
                DB::table('mayor_terms')->where('id', $term->id)
                    ->update(['actions_log' => json_encode($log)]);
                $term->actions_log = $log;

                JournalService::custom($term->character_id, 'bond_matured', [
                    'message' => sprintf(
                        'Your municipal bond has matured. $%s principal + $%s yield = $%s returned to treasury. The Assembly has noted the sound fiscal management.',
                        number_format($principal),
                        number_format($profit),
                        number_format($total)
                    ),
                ]);

                Log::info('[MayorService] Bond matured (live check).', [
                    'term_id'        => $term->id,
                    'principal'      => $principal,
                    'total'          => $total,
                    'assembly_after' => $newAssembly,
                ]);

                return $term;
            });
        }

        return $term;
    }

    
    
    

    
    public static function applySuppressionAssemblyHit(MayorTerm $term, City $city): ?MayorTerm
    {
        $newAssembly = $term->adjustAssembly(MayorTerm::DELTA_SUPPRESSION);

        Log::info('[MayorService] Suppression assembly hit applied.', [
            'term_id'        => $term->id,
            'assembly_after' => $newAssembly,
        ]);

        if ($newAssembly <= 50) {
            self::removeMayor($term, $city, MayorTerm::END_ASSEMBLY);
            return null;
        }

        return $term;
    }

    
    
    

    
    public static function collectPeriod(MayorTerm $term, City $city): ?MayorTerm
    {
        return DB::transaction(function () use ($term, $city) {
            $term = MayorTerm::where('id', $term->id)->lockForUpdate()->first();
            if (! $term || $term->ended_at !== null) {
                return null;
            }

            $crimeRate = (float) $city->crime_rate;

            
            $log        = $term->actions_log ?? [];
            $income     = $log['period_income'] ?? [];
            $taxIn      = (int) ($income['tax']   ?? 0);
            $fineIn     = (int) ($income['fine']  ?? 0);
            $auditIn    = (int) ($income['audit'] ?? 0);
            $bondReturn = (int) ($income['bond']  ?? 0);
            $crimeDrain = (int) ($log['period_drain'] ?? 0);

            
            $assemblyDelta = self::calculatePeriodAssemblyDelta(
                $term,
                $crimeRate,
                $taxIn + $fineIn + $auditIn + $bondReturn,
                $crimeDrain,
                $log
            );

            $newAssembly = $term->adjustAssembly($assemblyDelta);

            
            $net = $taxIn + $fineIn + $auditIn + $bondReturn - $crimeDrain;

            $term->appendLedgerEntry([
                'period'         => $term->period,
                'tax_in'         => $taxIn,
                'fine_in'        => $fineIn,
                'audit_in'       => $auditIn,
                'bond_return'    => $bondReturn,
                'crime_drain'    => $crimeDrain,
                'net'            => $net,
                'assembly_delta' => $assemblyDelta,
                'crime_rate'     => round($crimeRate, 2),
                'at'             => now()->getTimestamp(),
            ]);

            
            $newPeriod = $term->period + 1;

            $log['period_income']            = ['tax' => 0, 'fine' => 0, 'bond' => 0, 'audit' => 0, 'other' => 0];
            $log['period_drain']             = 0;
            $log['bond_settled_this_period'] = null;
            $log['last_drained_at']          = now()->getTimestamp();

            DB::table('mayor_terms')->where('id', $term->id)->update([
                'period'         => $newPeriod,
                'last_period_at' => now()->getTimestamp(),
                'actions_log'    => json_encode($log),
            ]);

            $term->period         = $newPeriod;
            $term->last_period_at = now()->getTimestamp();
            $term->actions_log    = $log;

            Log::info('[MayorService] Period checkpoint.', [
                'term_id'           => $term->id,
                'period_just_ended' => $term->period - 1,
                'net'               => $net,
                'assembly_delta'    => $assemblyDelta,
                'assembly_after'    => $newAssembly,
                'crime_rate'        => $crimeRate,
            ]);

            
            if ($newAssembly <= 50) {
                self::removeMayor($term, $city, MayorTerm::END_ASSEMBLY);
                return null;
            }

            
            if ($newPeriod > MayorTerm::PERIODS_PER_TERM) {
                self::removeMayor($term, $city, MayorTerm::END_TERM_COMPLETE);
                return null;
            }

            
            
            
            
            
            DB::table('characters')
                ->where('id', $term->character_id)
                ->update([
                    'cash_on_hand'        => DB::raw('cash_on_hand + ' . MayorTerm::RETAINER_CASH),
                    'career_xp'           => DB::raw('career_xp + '   . MayorTerm::RETAINER_XP),
                    'total_character_exp' => DB::raw('total_character_exp + ' . MayorTerm::RETAINER_XP),
                ]);

            Log::info('[MayorService] Period retainer awarded.', [
                'term_id'      => $term->id,
                'character_id' => $term->character_id,
                'xp'           => MayorTerm::RETAINER_XP,
                'cash'         => MayorTerm::RETAINER_CASH,
            ]);

            return $term;
        });
    }

    
    
    

    
    private static function calculatePeriodAssemblyDelta(
        MayorTerm $term,
        float $crimeRate,
        int $totalRevenue,
        int $totalDrain,
        array $log
    ): int {
        $delta = 0;
        $net   = $totalRevenue - $totalDrain;

        $delta += $net >= 0
            ? MayorTerm::DELTA_POSITIVE_PERIOD
            : MayorTerm::DELTA_NEGATIVE_PERIOD;

        if (CrimeThresholds::isAtOrAbove($crimeRate, CrimeThresholds::BOND_DEFAULT_THRESHOLD)) {
            $delta += MayorTerm::DELTA_HIGH_CRIME;
        }

        $suppressions = count($log['suppressions'] ?? []);
        if ($suppressions > 0) {
            $delta += $suppressions * MayorTerm::DELTA_SUPPRESSION_PERIODIC;
        }

        $audits = $log['audits'] ?? [];
        foreach ($audits as $audit) {
            if (($audit['status'] ?? '') === 'success'
                && isset($audit['resolved_period'])
                && $audit['resolved_period'] === ($term->period - 1)) {
                $delta += MayorTerm::DELTA_AUDIT_SUCCESS;
                break;
            }
        }

        
        
        
        $currentPeriod    = $term->period;  
        $dismissals       = $log['dismissals'] ?? [];
        $dismissedThisPeriod = collect($dismissals)->contains(
            fn ($d) => (int) ($d['period'] ?? -1) === $currentPeriod
        );
        $bondSettled      = $log['bond_settled_this_period'] ?? null;
        $bondDefaultedNow = $bondSettled !== null && ($bondSettled['type'] ?? '') === 'default';
        $isClean          = $suppressions === 0 && ! $dismissedThisPeriod && ! $bondDefaultedNow;

        if ($isClean) {
            $delta += MayorTerm::DELTA_CLEAN_PERIOD;
        }

        return $delta;
    }

    
    
    

    public static function removeMayor(
        MayorTerm $term,
        City $city,
        string $reason = MayorTerm::END_REMOVED
    ): void {
        DB::table('mayor_terms')->where('id', $term->id)->update([
            'ended_at'   => now(),
            'end_reason' => $reason,
        ]);

        $term->ended_at   = now();
        $term->end_reason = $reason;

        $mayor = $term->character ?? Character::find($term->character_id);

        if ($mayor) {
            $message = match ($reason) {
                MayorTerm::END_ASSEMBLY => "The Assembly has removed you from office in {$city->name} after confidence in your administration collapsed.",
                MayorTerm::END_TERM_COMPLETE => "Your mayoral term in {$city->name} has ended.",
                MayorTerm::END_RESIGNED => "You resigned as mayor of {$city->name}.",
                default => "You have been removed from office in {$city->name}.",
            };

            JournalService::custom(
                $mayor->id,
                $reason === MayorTerm::END_TERM_COMPLETE ? 'mayor_term_expired' : 'mayor_removed',
                [
                    'city_id' => $city->id,
                    'city_name' => $city->name,
                    'reason' => $reason,
                    'message' => $message,
                ]
            );

            $preserveExp = ($reason === MayorTerm::END_TERM_COMPLETE);
            $mayor->quitCareer(preserveExp: $preserveExp);

            if ($reason === MayorTerm::END_TERM_COMPLETE) {
                \App\Models\CharacterHistory::addHistory($mayor, 'terms_served');
            }

            
            
            
            
            $mayor->loadMissing('stats');
            if ($mayor->stats) {
                $penalty = match ($reason) {
                    MayorTerm::END_TERM_COMPLETE => 0,
                    MayorTerm::END_RESIGNED      => MayorTerm::INFLUENCE_PENALTY_RESIGNED,
                    default                      => MayorTerm::INFLUENCE_PENALTY_KICKED,
                };

                if ($penalty > 0) {
                    $mayor->stats->removeInfluence((float) $penalty);
                }
            }
        }

        CityHallAide::where('mayor_term_id', $term->id)->delete();

        $city->removeMayor();

        Log::info('[MayorService] Mayor term ended.', [
            'term_id'  => $term->id,
            'city_id'  => $city->id,
            'reason'   => $reason,
            'mayor_id' => $term->character_id,
        ]);
    }
}
