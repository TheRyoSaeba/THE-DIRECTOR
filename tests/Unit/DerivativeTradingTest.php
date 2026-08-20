<?php

namespace Tests\Unit;

use App\Services\DerivativeTrading;
use Tests\TestCase;

class DerivativeTradingTest extends TestCase
{
    public function test_public_snapshots_do_not_expose_private_trading_inputs(): void
    {
        $snapshot = app(DerivativeTrading::class)->publicSnapshots(1_779_330_000)[0];

        $this->assertArrayHasKey('profile', $snapshot);
        $this->assertArrayHasKey('regime', $snapshot);
        $this->assertArrayHasKey('price', $snapshot);
        $this->assertArrayHasKey('priceDelta', $snapshot);
        $this->assertCount(60, $snapshot['chartPrices']);

        foreach (['regimeSeconds', 'regime_seconds', 'loss_depth', 'utc_start', 'utc_end', 'hour_boost', 'price_range', 'volatility_factor'] as $key) {
            $this->assertArrayNotHasKey($key, $snapshot);
            $this->assertArrayNotHasKey($key, $snapshot['profile']);
        }
    }

    public function test_win_payouts_use_position_cap_as_banker_cut_with_personal_cap(): void
    {
        $service = app(DerivativeTrading::class);

        $small = $service->winPayouts(100_000, 1.50, 10);
        $this->assertSame(5_000, $small['banker']);
        $this->assertSame(45_000, $small['bank']);

        $large = $service->winPayouts(2_500_000, 2.30, 25);
        $this->assertSame(150_000, $large['banker']);
        $this->assertSame(3_100_000, $large['bank']);
    }
}
