<?php

namespace App\Services;

use App\Models\Business;

class DerivativeTrading
{
    private const REGIMES = ['bull', 'bear', 'sideways', 'volatile'];

    private const BASE_PROBABILITIES = [
        0 => ['call' => 65, 'put' => 32],
        1 => ['call' => 30, 'put' => 68],
        2 => ['call' => 50, 'put' => 50],
        3 => ['call' => 44, 'put' => 48],
    ];

    private const PROFILES = [
        'JPM' => [
            'display' => 'JPM',
            'name' => 'JPMorgan Chase',
            'exchange' => 'NYSE',
            'sector' => 'Finance',
            'base_price' => 195.00,
            'price_range' => 0.06,
            'regime_seconds' => 3600,
            'volatility_factor' => 0.04,
            'multiplier' => 1.50,
            'loss_depth' => 0.70,
            'utc_start' => 13,
            'utc_end' => 21,
            'hour_boost' => 8,
        ],
        'GS' => [
            'display' => 'GS',
            'name' => 'Goldman Sachs',
            'exchange' => 'NYSE',
            'sector' => 'Finance',
            'base_price' => 480.00,
            'price_range' => 0.09,
            'regime_seconds' => 2700,
            'volatility_factor' => 0.06,
            'multiplier' => 1.75,
            'loss_depth' => 0.80,
            'utc_start' => 13,
            'utc_end' => 21,
            'hour_boost' => 6,
        ],
        '7203' => [
            'display' => 'TOYOTA',
            'name' => 'Toyota Motor',
            'exchange' => 'Nikkei 225',
            'sector' => 'Automotive',
            'base_price' => 210.00,
            'price_range' => 0.07,
            'regime_seconds' => 7200,
            'volatility_factor' => 0.045,
            'multiplier' => 1.60,
            'loss_depth' => 0.72,
            'utc_start' => 0,
            'utc_end' => 7,
            'hour_boost' => 10,
        ],
        '6758' => [
            'display' => 'SONY',
            'name' => 'Sony Group',
            'exchange' => 'Nikkei 225',
            'sector' => 'Technology',
            'base_price' => 88.00,
            'price_range' => 0.14,
            'regime_seconds' => 1800,
            'volatility_factor' => 0.09,
            'multiplier' => 2.00,
            'loss_depth' => 0.88,
            'utc_start' => 0,
            'utc_end' => 7,
            'hour_boost' => 8,
        ],
        '005930' => [
            'display' => 'SAMSUNG',
            'name' => 'Samsung Electronics',
            'exchange' => 'KOSPI',
            'sector' => 'Technology',
            'base_price' => 54.00,
            'price_range' => 0.16,
            'regime_seconds' => 1200,
            'volatility_factor' => 0.11,
            'multiplier' => 2.30,
            'loss_depth' => 1.00,
            'utc_start' => 0,
            'utc_end' => 6,
            'hour_boost' => 12,
        ],
    ];

    public function publicSnapshots(int $utcSeconds): array
    {
        return array_values(array_map(
            fn (string $ticker) => $this->publicSnapshot($ticker, $utcSeconds),
            array_keys(self::PROFILES),
        ));
    }

    public function publicSnapshot(string $ticker, int $utcSeconds): ?array
    {
        $ticker = strtoupper(trim($ticker));
        $profile = $this->profile($ticker);

        if ($profile === null) {
            return null;
        }

        $price = $this->tickerPrice($ticker, $profile, $utcSeconds);
        $previousPrice = $this->tickerPrice($ticker, $profile, $utcSeconds - 30);

        $chartPrices = [];
        for ($i = 59; $i >= 0; $i--) {
            $chartPrices[] = round($this->tickerPrice($ticker, $profile, $utcSeconds - ($i * 60)), 2);
        }

        return [
            'profile' => [
                'symbol' => $ticker,
                'displaySymbol' => (string) $profile['display'],
                'name' => (string) $profile['name'],
                'exchange' => (string) $profile['exchange'],
                'sector' => (string) $profile['sector'],
                'payoutMultiplier' => (float) $profile['multiplier'],
            ],
            'regime' => $this->regimeName($this->regimeIndex($ticker, $utcSeconds, (int) $profile['regime_seconds'])),
            'price' => round($price, 2),
            'priceDelta' => round($price - $previousPrice, 2),
            'chartPrices' => $chartPrices,
        ];
    }

    public function quote(string $ticker, string $direction, int $utcSeconds, int $luck): ?array
    {
        $ticker = strtoupper(trim($ticker));
        $direction = strtolower(trim($direction));
        $profile = $this->profile($ticker);

        if ($profile === null || ! in_array($direction, ['call', 'put'], true)) {
            return null;
        }

        $regimeIndex = $this->regimeIndex($ticker, $utcSeconds, (int) $profile['regime_seconds']);
        $base = self::BASE_PROBABILITIES[$regimeIndex][$direction] ?? 50;
        $biased = $this->applyHourBias($base, $regimeIndex, $direction, $profile, (int) gmdate('G', $utcSeconds));

        return [
            'ticker' => $ticker,
            'display' => (string) $profile['display'],
            'direction' => $direction,
            'regime' => $this->regimeName($regimeIndex),
            'multiplier' => (float) $profile['multiplier'],
            'loss_depth' => (float) $profile['loss_depth'],
            'win_chance' => $this->effectiveProbability($biased, $luck),
        ];
    }

    public function positionCapPercent(Business $bank): int
    {
        $value = (int) $bank->getSetting('position_cap_percent', 10);

        return max(5, min(25, $value));
    }

    public function maxStake(int $bankBalance, int $positionCapPercent): int
    {
        return (int) floor(max(0, $bankBalance) * ($positionCapPercent / 100));
    }

    public function winPayouts(int $stake, float $multiplier, int $positionCapPercent): array
    {
        $grossReturn = (int) floor($stake * $multiplier);
        $netGain = max(0, $grossReturn - $stake);
        $banker = min((int) floor($netGain * ($positionCapPercent / 100)), 150_000);

        return [
            'bank' => $netGain - $banker,
            'banker' => $banker,
        ];
    }

    private function profile(string $ticker): ?array
    {
        return self::PROFILES[$ticker] ?? null;
    }

    private function tickerSeed(string $ticker, int $epoch): int
    {
        $value = $ticker . ':' . $epoch;
        $hash = 5381;

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $hash = ((($hash << 5) + $hash) ^ ord($value[$i])) & 0x7FFFFFFF;
        }

        return $hash & 0x7FFFFFFF;
    }

    private function regimeIndex(string $ticker, int $utcSeconds, int $regimeSeconds): int
    {
        return $this->tickerSeed($ticker, (int) floor($utcSeconds / $regimeSeconds)) % 4;
    }

    private function regimeName(int $regimeIndex): string
    {
        return self::REGIMES[$regimeIndex] ?? 'unknown';
    }

    private function tickerPrice(string $ticker, array $profile, int $utcSeconds): float
    {
        $basePrice = (float) $profile['base_price'];
        $regimeSeconds = (int) $profile['regime_seconds'];
        $epoch = (int) floor($utcSeconds / $regimeSeconds);
        $subProgress = ($utcSeconds % $regimeSeconds) / $regimeSeconds;

        $startSeed = $this->tickerSeed($ticker, $epoch);
        $endSeed = $this->tickerSeed($ticker, $epoch + 1);
        $priceRange = (float) $profile['price_range'];
        $startOffset = (($startSeed % 2000) / 1000 - 1) * $priceRange;
        $endOffset = (($endSeed % 2000) / 1000 - 1) * $priceRange;
        $trendOffset = $startOffset + (($endOffset - $startOffset) * $subProgress);

        $tickEpoch = (int) floor($utcSeconds / 30);
        $tickSeed = $this->tickerSeed($ticker . '_t', $tickEpoch);
        $microNoise = (($tickSeed % 1000) / 1000 - 0.5) * (float) $profile['volatility_factor'] * $basePrice * 0.15;

        return max($basePrice * 0.5, $basePrice * (1 + $trendOffset) + $microNoise);
    }

    private function applyHourBias(int $base, int $regimeIndex, string $direction, array $profile, int $utcHour): int
    {
        if ($regimeIndex === 2 || $regimeIndex === 3) {
            return $base;
        }

        $start = (int) $profile['utc_start'];
        $end = (int) $profile['utc_end'];
        $active = $start < $end
            ? ($utcHour >= $start && $utcHour < $end)
            : ($utcHour >= $start || $utcHour < $end);

        if (! $active) {
            return $base;
        }

        $correctRead = ($regimeIndex === 0 && $direction === 'call') || ($regimeIndex === 1 && $direction === 'put');
        $boost = (int) $profile['hour_boost'];

        return $correctRead ? min(85, $base + $boost) : max(15, $base - (int) floor($boost / 2));
    }

    private function effectiveProbability(int $base, int $luck): float
    {
        $factor = min(1.15, max(0.10, log10(max(1, $luck)) / 5.7));

        return 50.0 + (($base - 50) * $factor);
    }
}
