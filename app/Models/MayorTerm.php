<?php

namespace App\Models;

use App\Support\CrimeThresholds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;


class MayorTerm extends Model
{

    public const PERIOD_SECONDS = 42 * 3600;


    public const PERIODS_PER_TERM = 4;


    public const STARTING_FUNDS = 500_000;


    public const STARTING_ASSEMBLY = 100;


    public const COST_SUPPRESS = 50_000;
    public const COST_PARDON = 75_000;
    public const PARDON_COOLDOWN_SECONDS = 10 * 3600;
    public const COST_DISMISS_OFFICER = 50_000;
    public const COST_DISMISS_COMM = 150_000;
    public const COST_ENABLE_CORP_REG = 25_000;
    public const COST_ENABLE_BONDS = 10_000;
    public const COST_AUDIT = 75_000;
    public const COST_DEATH_SENTENCE = 100_000;

    public const BOND_MIN = 50_000;
    public const BOND_MAX_PCT = 0.40;


    // Assembly deltas are deliberately ASYMMETRIC: a good period recovers
    // less than a bad period costs, so sustained mismanagement is
    // unrecoverable and bad mid-period actions (suppression, bond default)
    // can still cross the 50 removal line. But a clean, solvent period is
    // now a meaningful +10 total (+6 positive, +4 clean) so a competent
    // mayor who recovers from one bad period isn't mathematically doomed.
    //   perfect period   = +10   (DELTA_POSITIVE_PERIOD + DELTA_CLEAN_PERIOD)
    //   bad period       = -15   (DELTA_NEGATIVE_PERIOD)
    //   bad + high crime = -20
    public const DELTA_POSITIVE_PERIOD = 6;
    public const DELTA_NEGATIVE_PERIOD = -15;
    public const DELTA_HIGH_CRIME = -5;
    public const DELTA_BOND_DEFAULT = -10;
    public const DELTA_SUPPRESSION = -4;
    public const DELTA_SUPPRESSION_PERIODIC = -8;
    public const DELTA_AUDIT_SUCCESS = 4;
    public const DELTA_CLEAN_PERIOD = 4;
    public const DELTA_BOND_MATURED = 5;


    public const END_TERM_COMPLETE = 'term_complete';
    public const END_ASSEMBLY = 'assembly';
    public const END_REMOVED = 'removed';
    public const END_RESIGNED = 'resigned';


    public const RETAINER_XP = 1_000;
    public const RETAINER_CASH = 100_000;


    public const INFLUENCE_PENALTY_KICKED = 10;
    public const INFLUENCE_PENALTY_RESIGNED = 3;
    //! i know this isn't how income tax or tax brackets work,  again verisimilitude
    protected $fillable = [
        'city_id',
        'character_id',
        'election_id',
        'period',
        'started_at',
        'ended_at',
        'end_reason',
        'city_funds',
        'assembly_score',
        'budget_law',
        'budget_corp_reg',
        'budget_services',
        'budget_bonds',
        'last_period_at',
        'ledger',
        'actions_log',
    ];

    protected $casts = [
        'period' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'city_funds' => 'integer',
        'assembly_score' => 'integer',
        'budget_law' => 'integer',
        'budget_corp_reg' => 'integer',
        'budget_services' => 'integer',
        'budget_bonds' => 'integer',
        'last_period_at' => 'integer',
        'ledger' => 'array',
        'actions_log' => 'array',
    ];



    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }




    public function scopeActive($query)
    {
        return $query->whereNull('ended_at');
    }


    public function scopeForCity($query, int $cityId)
    {
        return $query->where('city_id', $cityId);
    }




    public function isValidBudget(): bool
    {
        return ($this->budget_law
            + $this->budget_corp_reg
            + $this->budget_services
            + $this->budget_bonds) === 100;
    }


    public function servicesMultiplier(): float
    {
        if ($this->budget_services >= 50)
            return 1.5;
        if ($this->budget_services >= 25)
            return 1.0;
        return 0.8;
    }


    public function lawPassiveDrift(): float
    {
        //crime needs to drift much faster
        if ($this->budget_law >= 100)
            return -35.0;
        if ($this->budget_law >= 75)
            return -25.0;
        if ($this->budget_law >= 50)
            return -10.0;
        if ($this->budget_law >= 25)
            return 0.0;
        return 1.0;
    }


    public function bondsUnlocked(): bool
    {
        return $this->budget_bonds >= 50;
    }

    public function corpRegUnlocked(): bool
    {
        return $this->budget_corp_reg >= 50;
    }


    public function auditActionUnlocked(): bool
    {
        return $this->budget_law >= 50;
    }


    public function suppressionCount(): int
    {
        return count($this->actions_log['suppressions'] ?? []);
    }

    public function hasActiveBond(): bool
    {
        $bond = $this->actions_log['bond'] ?? null;
        return $bond !== null && ($bond['matures_at'] ?? 0) > now()->getTimestamp();
    }


    public function getActiveBond(): ?array
    {
        if (!$this->hasActiveBond()) {
            return null;
        }
        return $this->actions_log['bond'];
    }


    public static function calculateBondYield(float $crimeRate): float
    {
        return max(4.0, 20.0 - ($crimeRate * 0.25));
    }


    public function maxBondPrincipal(): int
    {
        if ($this->city_funds < self::BOND_MIN) {
            return 0;
        }
        return (int) floor($this->city_funds * self::BOND_MAX_PCT);
    }




    public function auditInProgress(): bool
    {
        $audits = $this->actions_log['audits'] ?? [];
        foreach ($audits as $audit) {
            if (($audit['status'] ?? '') === 'pending') {
                return true;
            }
        }
        return false;
    }




    public function pardonCooldownPassed(): bool
    {
        $pardons = $this->actions_log['pardons'] ?? [];
        if (empty($pardons)) {
            return true;
        }
        $last = end($pardons);
        $lastAt = $last ? ($last['at'] ?? 0) : 0;
        return (now()->getTimestamp() - $lastAt) >= self::PARDON_COOLDOWN_SECONDS;
    }




    public function canDismissCommissioner(Character $commissioner): bool
    {
        $policeCareer = Career::findByCode('police');
        if (!$policeCareer) {
            return false;
        }


        $rank4Req = CareerRank::where('career_id', $policeCareer->id)
            ->where('rank_level', 4)
            ->value('xp_required') ?? PHP_INT_MAX;

        return Character::where('home_city_id', $commissioner->home_city_id)
            ->where('career_id', $policeCareer->id)
            ->where('career_rank', 3)
            ->where('career_xp', '>=', $rank4Req)
            ->where('id', '!=', $commissioner->id)
            ->alive()
            ->exists();
    }




    public function isOverdue(): bool
    {




        $reference = $this->last_period_at
            ?? $this->started_at?->getTimestamp()
            ?? now()->getTimestamp();

        return (now()->getTimestamp() - $reference) >= self::PERIOD_SECONDS;
    }




    public function addFunds(int $amount, string $source = 'other'): void
    {




        $log = $this->actions_log ?? [];
        $income = $log['period_income'] ?? ['tax' => 0, 'corptax' => 0, 'fine' => 0, 'bond' => 0, 'audit' => 0, 'other' => 0];
        $income[$source] = ($income[$source] ?? 0) + $amount;
        $log['period_income'] = $income;

        DB::table('mayor_terms')
            ->where('id', $this->id)
            ->update([
                'city_funds' => DB::raw('city_funds + ' . (int) $amount),
                'actions_log' => json_encode($log),
            ]);

        $this->city_funds += $amount;
        $this->actions_log = $log;
    }


    public function deductFunds(int $amount): bool
    {
        if ($this->city_funds < $amount) {
            return false;
        }

        DB::table('mayor_terms')
            ->where('id', $this->id)
            ->decrement('city_funds', $amount);

        $this->city_funds -= $amount;
        return true;
    }




    public function adjustAssembly(int $delta): int
    {
        $new = max(0, min(100, $this->assembly_score + $delta));

        DB::table('mayor_terms')
            ->where('id', $this->id)
            ->update(['assembly_score' => $new]);

        $this->assembly_score = $new;
        return $new;
    }




    public function appendLedgerEntry(array $entry): void
    {
        $ledger = $this->ledger ?? [];
        $ledger[] = $entry;


        if (count($ledger) > self::PERIODS_PER_TERM) {
            $ledger = array_slice($ledger, -self::PERIODS_PER_TERM);
        }

        DB::table('mayor_terms')
            ->where('id', $this->id)
            ->update(['ledger' => json_encode($ledger)]);

        $this->ledger = $ledger;
    }




    public function logAction(string $key, array $data): void
    {
        $log = $this->actions_log ?? [];
        $log[$key] = array_merge($log[$key] ?? [], [$data]);

        DB::table('mayor_terms')
            ->where('id', $this->id)
            ->update(['actions_log' => json_encode($log)]);

        $this->actions_log = $log;
    }


    public function setBond(?array $bond): void
    {
        $log = $this->actions_log ?? [];
        $log['bond'] = $bond;

        DB::table('mayor_terms')
            ->where('id', $this->id)
            ->update(['actions_log' => json_encode($log)]);

        $this->actions_log = $log;
    }


    public function resolveAudit(string $status, array $extra = []): void
    {
        $log = $this->actions_log ?? [];
        $audits = $log['audits'] ?? [];

        foreach (array_reverse(array_keys($audits)) as $i) {
            if (($audits[$i]['status'] ?? '') === 'pending') {
                $audits[$i] = array_merge($audits[$i], ['status' => $status], $extra);
                break;
            }
        }

        $log['audits'] = $audits;

        DB::table('mayor_terms')
            ->where('id', $this->id)
            ->update(['actions_log' => json_encode($log)]);

        $this->actions_log = $log;
    }




    public function getPolicies(): array
    {
        return $this->actions_log['policies'] ?? [
            'income_tax_rate' => 5,
            'corporate_tax_rate' => 0,
            'corp_regulation_active' => false,
            'bonds_active' => false,
            'death_sentence_active' => false,
        ];
    }

    public function setPolicy(string $key, mixed $value): void
    {
        $log = $this->actions_log ?? [];
        $log['policies'] = $this->getPolicies();
        $log['policies'][$key] = $value;

        DB::table('mayor_terms')
            ->where('id', $this->id)
            ->update(['actions_log' => json_encode($log)]);

        $this->actions_log = $log;
    }



    public function getIncomeTaxRateAttribute(): int
    {
        return $this->getPolicies()['income_tax_rate'];
    }

    public function getCorporateTaxRateAttribute(): int
    {
        return $this->getPolicies()['corporate_tax_rate'];
    }

    public function getCorpRegulationActiveAttribute(): bool
    {
        return (bool) $this->getPolicies()['corp_regulation_active'];
    }

    public function getBondsActiveAttribute(): bool
    {
        return (bool) $this->getPolicies()['bonds_active'];
    }

    public function getDeathSentenceActiveAttribute(): bool
    {
        return (bool) $this->getPolicies()['death_sentence_active'];
    }




    public static function createForElection(
        City $city,
        Character $mayor,
        Election $election
    ): self {
        return self::create([
            'city_id' => $city->id,
            'character_id' => $mayor->id,
            'election_id' => $election->id,
            'period' => 1,
            'started_at' => now(),
            'city_funds' => self::STARTING_FUNDS,
            'assembly_score' => self::STARTING_ASSEMBLY,
            'budget_law' => 25,
            'budget_corp_reg' => 25,
            'budget_services' => 25,
            'budget_bonds' => 25,
            'last_period_at' => null,
            'ledger' => [],
            'actions_log' => [
                'suppressions' => [],
                'pardons' => [],
                'dismissals' => [],
                'audits' => [],
                'bond' => null,
                'period_income' => ['tax' => 0, 'fine' => 0, 'bond' => 0, 'audit' => 0, 'other' => 0],
                'period_drain' => 0,
                'last_drained_at' => now()->getTimestamp(),
                'pending_drift' => 0.0,
                'bond_settled_this_period' => null,
                'policies' => [
                    'income_tax_rate' => 5,
                    'corporate_tax_rate' => 0,
                    'corp_regulation_active' => false,
                    'bonds_active' => false,
                    'death_sentence_active' => false,
                ],
            ],
        ]);
    }




    public static function activeForCity(int $cityId): ?self
    {
        return self::where('city_id', $cityId)
            ->whereNull('ended_at')
            ->first();
    }
}
