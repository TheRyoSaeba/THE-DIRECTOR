<?php

namespace App\Console\Commands;

use App\Models\CorporationProperty;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class MedicalEconomicsStressTest extends Command
{
    protected $signature = 'corp:medical-economics
        {--days=30 : Simulated days}
        {--workers=2 : Medical workers per corporation}
        {--runs-per-worker-day=36 : Production runs each worker completes per day}
        {--corps-per-ppp=3 : Independent corporations simulated per PPP tier}
        {--starting-slush=500000 : Starting slush fund for each corporation}
        {--starting-reserves=500000 : Starting clean cash reserves for each corporation}
        {--hq-tier=1 : Headquarters tier to include in upkeep}
        {--medical=1 : Whether the company owns one medical property}
        {--laundering=1 : Whether the company owns one laundering property}
        {--mirror-runs-per-day=1 : CFO mirror transactions completed per simulated day}
        {--mirror-amount-per-run=200000 : Slush converted by each mirror transaction}
        {--banker-percent=2 : Banker fee percentage for mirror transactions}
        {--investment-runs-per-day=0 : Optional investment fraud attempts per day}
        {--investment-success=35 : Investment fraud success percentage}
        {--bank-deposits=5000000 : Simulated bank depositor pool for investment fraud}
        {--bank-balance=1000000 : Simulated bank business balance for investment fraud}
        {--ppp=all : all, a single PPP, or a comma list like 500,1000,2000}
        {--show-daily : Print a daily ledger for every PPP tier}';

    protected $description = 'Model average daily corporation economics against fixed property upkeep.';

    private const INVESTMENT_FRAUD_FEE = 50_000;
    private const OFFSHORE_TRUST_CAP = 5_000_000;

    public function handle(): int
    {
        $config = [
            'days' => $this->positiveIntOption('days'),
            'workers' => $this->nonNegativeIntOption('workers'),
            'runs_per_worker_day' => $this->nonNegativeIntOption('runs-per-worker-day'),
            'corps_per_ppp' => $this->positiveIntOption('corps-per-ppp'),
            'starting_slush' => $this->nonNegativeIntOption('starting-slush'),
            'starting_reserves' => $this->nonNegativeIntOption('starting-reserves'),
            'hq_tier' => min(3, max(1, $this->positiveIntOption('hq-tier'))),
            'medical' => (bool) $this->nonNegativeIntOption('medical'),
            'laundering' => (bool) $this->nonNegativeIntOption('laundering'),
            'mirror_runs_per_day' => $this->nonNegativeIntOption('mirror-runs-per-day'),
            'mirror_amount_per_run' => $this->nonNegativeIntOption('mirror-amount-per-run'),
            'banker_percent' => min(
                CorporationProperty::MAX_MIRROR_BANKER_PERCENTAGE,
                max(CorporationProperty::MIN_MIRROR_BANKER_PERCENTAGE, $this->positiveIntOption('banker-percent'))
            ),
            'investment_runs_per_day' => $this->nonNegativeIntOption('investment-runs-per-day'),
            'investment_success' => $this->percentageOption('investment-success'),
            'bank_deposits' => $this->nonNegativeIntOption('bank-deposits'),
            'bank_balance' => $this->nonNegativeIntOption('bank-balance'),
            'show_daily' => (bool) $this->option('show-daily'),
        ];

        $pppValues = $this->pppValues((string) $this->option('ppp'));
        $prices = $this->portfolioPrices($config);
        $properties = $this->propertyLedger($prices, $config);
        $scheduledUpkeep = collect($properties)->sum('upkeep');

        $this->line('');
        $this->warn('  CORPORATION DAILY ECONOMICS');
        $this->line(sprintf(
            '  %d workers x %d runs/day = %d production runs/day. %d simulated corp%s per PPP.',
            $config['workers'],
            $config['runs_per_worker_day'],
            $config['workers'] * $config['runs_per_worker_day'],
            $config['corps_per_ppp'],
            $config['corps_per_ppp'] === 1 ? '' : 's',
        ));
        $this->line(sprintf(
            '  Portfolio: HQ%s %s%s. Scheduled upkeep/day: %s.',
            $config['hq_tier'],
            $config['medical'] ? '+ medical ' : '',
            $config['laundering'] ? '+ PanamaCo' : '',
            $this->money($scheduledUpkeep),
        ));
        $this->line('');

        try {
            $summaryRows = [];
            $dailyTables = [];

            foreach ($pppValues as $ppp) {
                $runs = [];

                for ($i = 0; $i < $config['corps_per_ppp']; $i++) {
                    mt_srand(1337 + ($ppp * 100) + $i);
                    $runs[] = $this->simulateCompany($ppp, $config, $properties);
                }

                $averageDays = $this->averageDailyRows($runs);
                $summaryRows[] = $this->summaryRow($ppp, $averageDays, $config, $scheduledUpkeep);

                if ($config['show_daily'] || count($pppValues) === 1) {
                    $dailyTables[$ppp] = array_map(fn(array $day) => $this->dailyDisplayRow($day), $averageDays);
                }
            }

            $this->table(
                ['PPP', 'status', 'net/day', 'profitable', 'default', 'end value', 'packs/prod day', 'med/day', 'labor/day', 'upkeep/day'],
                $summaryRows,
            );

            foreach ($dailyTables as $ppp => $rows) {
                $this->line('');
                $this->line('  Daily ledger for PPP ' . $this->money($ppp));
                $this->table(
                    ['day', 'runs', 'packs', 'medical', 'labor', 'mirror net', 'upkeep due', 'upkeep paid', 'net', 'end value', 'default'],
                    $rows,
                );
            }
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->error($e->getFile() . ':' . $e->getLine());

            return 1;
        }

        $this->line('');

        return 0;
    }

    private function simulateCompany(int $ppp, array $config, array $properties): array
    {
        $state = [
            'slush' => $config['starting_slush'],
            'reserves' => $config['starting_reserves'],
            'offshore' => 0,
            'properties' => $properties,
        ];
        $days = [];

        for ($day = 1; $day <= $config['days']; $day++) {
            $startValue = $this->totalValue($state);
            $row = [
                'day' => $day,
                'runs' => 0,
                'packs' => 0,
                'medical_gross' => 0,
                'labor_cost' => 0,
                'mirror_net' => 0,
                'investment_net' => 0,
                'upkeep_due' => collect($properties)->sum('upkeep'),
                'upkeep_paid' => 0,
                'default' => '-',
            ];

            if ($config['medical'] && $this->propertyOperational($state, CorporationProperty::TYPE_MEDICAL)) {
                $this->runMedicalDay($state, $row, $ppp, $config);
            }

            $this->runInvestmentDay($state, $row, $config);

            if ($config['laundering'] && $this->propertyOperational($state, CorporationProperty::TYPE_LAUNDERING)) {
                $this->runMirrorDay($state, $row, $config);
            }

            $this->payUpkeep($state, $row);

            $row['end_value'] = $this->totalValue($state);
            $row['net'] = $row['end_value'] - $startValue;
            $row['slush'] = $state['slush'];
            $row['reserves'] = $state['reserves'];
            $row['offshore'] = $state['offshore'];
            $days[] = $row;

            if ($row['default'] !== '-') {
                break;
            }
        }

        return $days;
    }

    private function runMedicalDay(array &$state, array &$row, int $ppp, array $config): void
    {
        $attempts = $config['workers'] * $config['runs_per_worker_day'];

        for ($run = 0; $run < $attempts; $run++) {
            $packs = mt_rand(1, 3);
            $labor = $packs * $ppp;

            if ($state['slush'] < $labor) {
                continue;
            }

            $state['slush'] -= $labor;
            $sale = $packs * $this->rollNpcPriceForSimulation($this->weightedProductForSimulation());
            $state['slush'] += $sale;

            $row['runs']++;
            $row['packs'] += $packs;
            $row['labor_cost'] += $labor;
            $row['medical_gross'] += $sale;
        }
    }

    private function runInvestmentDay(array &$state, array &$row, array $config): void
    {
        for ($i = 0; $i < $config['investment_runs_per_day']; $i++) {
            if ($state['slush'] < self::INVESTMENT_FRAUD_FEE) {
                return;
            }

            $state['slush'] -= self::INVESTMENT_FRAUD_FEE;
            $row['investment_net'] -= self::INVESTMENT_FRAUD_FEE;

            if (mt_rand(1, 100) > $config['investment_success']) {
                continue;
            }

            $payout = min(5_000_000, (int) floor($config['bank_deposits'] * 0.10))
                + (int) floor($config['bank_balance'] * 0.05);
            $state['slush'] += $payout;
            $row['investment_net'] += $payout;
        }
    }

    private function runMirrorDay(array &$state, array &$row, array $config): void
    {
        for ($i = 0; $i < $config['mirror_runs_per_day']; $i++) {
            $amount = min(
                $config['mirror_amount_per_run'],
                $state['slush'],
                max(0, self::OFFSHORE_TRUST_CAP - $state['offshore'])
            );

            if ($amount <= 0) {
                return;
            }

            $state['slush'] -= $amount;
            $bankerFee = (int) floor($amount * ($config['banker_percent'] / 100));
            $spread = (int) floor($amount * (mt_rand(
                CorporationProperty::MIRROR_SPREAD_PERCENT_MIN,
                CorporationProperty::MIRROR_SPREAD_PERCENT_MAX
            ) / 100));
            $state['reserves'] += max(0, $amount - $bankerFee);
            $state['offshore'] += $spread;
            $row['mirror_net'] += $spread - $bankerFee;
        }
    }

    private function payUpkeep(array &$state, array &$row): void
    {
        foreach ($state['properties'] as &$property) {
            if (!$property['operational']) {
                continue;
            }

            $cost = $property['upkeep'];

            if ($state['reserves'] >= $cost) {
                $state['reserves'] -= $cost;
                $row['upkeep_paid'] += $cost;
                continue;
            }

            $property['operational'] = false;
            $row['default'] = $row['default'] === '-'
                ? $property['type']
                : $row['default'] . ',' . $property['type'];
        }
        unset($property);
    }

    private function averageDailyRows(array $runs): array
    {
        $corpCount = max(1, count($runs));
        $days = [];

        foreach ($runs as $run) {
            foreach ($run as $row) {
                $index = $row['day'] - 1;
                $days[$index] ??= [
                    'day' => $row['day'],
                    'runs' => 0,
                    'packs' => 0,
                    'medical_gross' => 0,
                    'labor_cost' => 0,
                    'mirror_net' => 0,
                    'investment_net' => 0,
                    'upkeep_due' => 0,
                    'upkeep_paid' => 0,
                    'net' => 0,
                    'end_value' => 0,
                    'default_count' => 0,
                    'default' => '-',
                ];

                foreach (['runs', 'packs', 'medical_gross', 'labor_cost', 'mirror_net', 'investment_net', 'upkeep_due', 'upkeep_paid', 'net', 'end_value'] as $key) {
                    $days[$index][$key] += $row[$key];
                }

                if ($row['default'] !== '-') {
                    $days[$index]['default_count']++;
                    $days[$index]['default'] = $row['default'];
                }
            }
        }

        foreach ($days as &$day) {
            foreach (['runs', 'packs', 'medical_gross', 'labor_cost', 'mirror_net', 'investment_net', 'upkeep_due', 'upkeep_paid', 'net', 'end_value'] as $key) {
                $day[$key] /= $corpCount;
            }

            if ($day['default_count'] === 0) {
                $day['default'] = '-';
            } elseif ($day['default_count'] !== $corpCount) {
                $day['default'] .= ' (' . $day['default_count'] . '/' . $corpCount . ')';
            }
        }
        unset($day);

        return array_values($days);
    }

    private function summaryRow(int $ppp, array $days, array $config, int $scheduledUpkeep): array
    {
        $dayCount = max(1, count($days));
        $productionDays = collect($days)->filter(fn(array $day) => $day['runs'] > 0)->count();
        $productionDays = max(1, $productionDays);
        $firstDefault = collect($days)->first(fn(array $day) => $day['default'] !== '-');
        $profitableDays = collect($days)->filter(fn(array $day) => $day['net'] > 0)->count();
        $avgNet = collect($days)->sum('net') / $dayCount;
        $last = $days[array_key_last($days)];

        return [
            'PPP' => $this->money($ppp),
            'status' => $firstDefault ? 'DEFAULTED' : ($avgNet >= 0 ? 'PROFIT' : 'LOSS'),
            'net/day' => $this->money($avgNet),
            'profitable' => $profitableDays . '/' . $dayCount,
            'default' => $firstDefault ? 'day ' . $firstDefault['day'] . ' ' . $firstDefault['default'] : '-',
            'end value' => $this->money($last['end_value']),
            'packs/prod day' => number_format(collect($days)->sum('packs') / $productionDays, 1),
            'med/day' => $this->money(collect($days)->sum('medical_gross') / $dayCount),
            'labor/day' => $this->money(collect($days)->sum('labor_cost') / $dayCount),
            'upkeep/day' => $this->money($scheduledUpkeep),
        ];
    }

    private function dailyDisplayRow(array $day): array
    {
        return [
            'day' => $day['day'],
            'runs' => number_format($day['runs'], 1),
            'packs' => number_format($day['packs'], 1),
            'medical' => $this->money($day['medical_gross']),
            'labor' => $this->money($day['labor_cost']),
            'mirror net' => $this->money($day['mirror_net']),
            'upkeep due' => $this->money($day['upkeep_due']),
            'upkeep paid' => $this->money($day['upkeep_paid']),
            'net' => $this->money($day['net']),
            'end value' => $this->money($day['end_value']),
            'default' => $day['default'],
        ];
    }

    private function propertyOperational(array $state, string $type): bool
    {
        foreach ($state['properties'] as $property) {
            if ($property['type'] === $type && $property['operational']) {
                return true;
            }
        }

        return false;
    }

    private function totalValue(array $state): int
    {
        return (int) $state['slush'] + (int) $state['reserves'] + (int) $state['offshore'];
    }

    private function propertyLedger(array $prices, array $config): array
    {
        $properties = [];

        if ($config['medical']) {
            $properties[] = [
                'type' => CorporationProperty::TYPE_MEDICAL,
                'upkeep' => $this->upkeepCost($prices['medical']),
                'operational' => true,
            ];
        }

        if ($config['laundering']) {
            $properties[] = [
                'type' => CorporationProperty::TYPE_LAUNDERING,
                'upkeep' => $this->upkeepCost($prices['laundering']),
                'operational' => true,
            ];
        }

        $properties[] = [
            'type' => CorporationProperty::TYPE_HQ,
            'upkeep' => $this->upkeepCost($prices['hq']),
            'operational' => true,
        ];

        return $properties;
    }

    private function portfolioPrices(array $config): array
    {
        return [
            'hq' => $this->templatePrice(CorporationProperty::TYPE_HQ, $config['hq_tier'], match ($config['hq_tier']) {
                1 => 1_000_000,
                2 => 2_500_000,
                default => 5_000_000,
            }),
            'medical' => $this->templatePrice(CorporationProperty::TYPE_MEDICAL, 1, 500_000),
            'laundering' => $this->templatePrice(CorporationProperty::TYPE_LAUNDERING, 1, 500_000),
        ];
    }

    private function weightedProductForSimulation(): string
    {
        $totalWeight = collect(CorporationProperty::MEDICAL_PRODUCT_MARKETS)
            ->sum(fn(array $market) => max(0, (int) ($market['production_weight'] ?? 0)));

        if ($totalWeight <= 0) {
            return CorporationProperty::MEDICINE_TAK925;
        }

        $roll = mt_rand(1, $totalWeight);

        foreach (CorporationProperty::MEDICAL_PRODUCT_MARKETS as $slug => $market) {
            $roll -= max(0, (int) ($market['production_weight'] ?? 0));

            if ($roll <= 0) {
                return $slug;
            }
        }

        return CorporationProperty::MEDICINE_TAK925;
    }

    private function rollNpcPriceForSimulation(string $product): int
    {
        $market = CorporationProperty::medicalProductMarket($product);
        $min = max(0, (int) ($market['npc_price_min'] ?? 0));
        $max = max($min, (int) ($market['npc_price_max'] ?? $min));

        return mt_rand($min, $max);
    }

    private function templatePrice(string $type, int $tier, int $fallback): int
    {
        return (int) (CorporationProperty::template($type, $tier)?->price ?? $fallback);
    }

    private function upkeepCost(int $price): int
    {
        return (int) ceil(max(0, $price) * 0.10);
    }

    private function pppValues(string $raw): array
    {
        if ($raw === 'all') {
            return [1_000, 2_000, 3_000, 4_000, 5_000];
        }

        $values = collect(explode(',', $raw))
            ->map(fn(string $value) => (int) trim($value))
            ->filter(fn(int $value) => $value >= CorporationProperty::MIN_MEDICAL_PAYOUT_PER_PACK
                && $value <= CorporationProperty::MAX_MEDICAL_PAYOUT_PER_PACK)
            ->unique()
            ->values()
            ->all();

        if (empty($values)) {
            throw new InvalidArgumentException('No valid PPP values supplied.');
        }

        return $values;
    }

    private function positiveIntOption(string $name): int
    {
        return max(1, (int) $this->option($name));
    }

    private function nonNegativeIntOption(string $name): int
    {
        return max(0, (int) $this->option($name));
    }

    private function percentageOption(string $name): int
    {
        return min(100, max(0, (int) $this->option($name)));
    }

    private function money(int|float $amount): string
    {
        return '$' . number_format((int) round($amount));
    }
}
