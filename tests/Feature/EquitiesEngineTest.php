<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class EquitiesEngineTest extends TestCase
{
    use DatabaseTransactions;

    private City     $city;
    private Business $bank;
    private int      $bankingCareerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::create([
            'name'       => 'EquitiesCity-' . uniqid(),
            'slug'       => 'eq-city-' . uniqid(),
            'crime_rate' => 0,
        ]);

        $banking = DB::table('careers')->where('code', 'banking')->first();
        $this->assertNotNull($banking, 'Banking career must exist in seeded DB');
        $this->bankingCareerId = $banking->id;

        $this->bank = Business::create([
            'name'           => 'Test Bank',
            'slug'           => 'test-bank-eq-' . uniqid(),
            'code'           => 'test-bank-eq-' . uniqid(),
            'type'           => 'bank',
            'city_id'        => $this->city->id,
            'balance'        => 10_000_000,
            'is_purchasable' => false,
        ]);
    }

    

    private function makeCharacter(int $luck = 100_000, int $careerRank = 3): Character
    {
        $user = User::factory()->create();

        $character = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'Eq-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $this->city->id,
            'home_city_id' => $this->city->id,
            'career_id'    => $this->bankingCareerId,
            'career_rank'  => $careerRank,
            'cash_on_hand' => 500_000,
            'cash_in_bank' => 0,
            'health'       => 100,
        ]);

        CharacterStats::create([
            'character_id' => $character->id,
            'intelligence' => 100,
            'offense'      => 100,
            'defense'      => 100,
            'luck'         => $luck,
            'influence'    => 0,
        ]);

        CharacterTimers::create([
            'character_id'   => $character->id,
            'next_action_at' => 0,
        ]);

        return $character;
    }

    
    
    
    
    
    
    
    
    
    
    
    

    
    public function hash_matches_known_typescript_output(): void
    {
        $this->assertSame(220100216, $this->hash('JPM',    0));
        $this->assertSame(466503354, $this->hash('GS',     100));
        $this->assertSame(800420201, $this->hash('005930', 999));
    }

    

    
    public function different_tickers_produce_different_regimes_at_same_epoch(): void
    {
        $utc     = 1_700_000_000;
        $regimes = [];

        foreach (['JPM', 'GS', '7203', '6758', '005930'] as $t) {
            $p = $this->profile($t);
            $regimes[$t] = $this->hash($t, (int) floor($utc / $p['regime_s'])) % 4;
        }

        $unique = array_unique(array_values($regimes));
        $this->assertGreaterThan(1, count($unique),
            'All five tickers landed in the same regime — seed independence is broken');
    }

    

    
    public function all_four_regimes_are_reachable_for_every_ticker(): void
    {
        foreach (['JPM', 'GS', '7203', '6758', '005930'] as $ticker) {
            $found = [];
            for ($epoch = 0; $epoch < 1000; $epoch++) {
                $found[$this->hash($ticker, $epoch) % 4] = true;
                if (count($found) === 4) {
                    break;
                }
            }
            $this->assertCount(4, $found, "Ticker {$ticker} never reached all four regimes in 1000 epochs");
        }
    }

    

    
    public function luck_factor_respects_documented_scale(): void
    {
        
        $cases = [
            [1,           0.10],
            [10,          0.175],
            [100,         0.351],
            [1_000,       0.526],
            [100_000,     0.877],
            [PHP_INT_MAX, 1.15],
        ];

        foreach ($cases as $case) {
            [$luck, $expected] = $case;
            $factor = $this->luckFactor($luck);
            $this->assertGreaterThanOrEqual(0.10, $factor, "Factor below floor at luck={$luck}");
            $this->assertLessThanOrEqual(1.15,    $factor, "Factor above cap at luck={$luck}");
            $this->assertEqualsWithDelta($expected, $factor, 0.005, "Factor mismatch at luck={$luck}");
        }

        $prev = 0.0;
        foreach ([100, 1_000, 10_000, 100_000, 200_000] as $luck) {
            $f = $this->luckFactor($luck);
            $this->assertGreaterThanOrEqual($prev, $f, "Factor not monotonic at luck={$luck}");
            $prev = $f;
        }
    }

    

    
    public function effective_probability_compresses_toward_50_at_low_luck(): void
    {
        $base = 65;

        $low  = $this->effectiveProb($base, 500);
        $mid  = $this->effectiveProb($base, 10_000);
        $high = $this->effectiveProb($base, 100_000);

        $this->assertLessThan(60.0,  $low);
        $this->assertGreaterThan(60.0, $mid);
        $this->assertEqualsWithDelta(63.2, $high, 0.3);
        $this->assertTrue($low < $mid && $mid < $high);
    }

    

    
    public function effective_probability_never_inverts_regime_signal(): void
    {
        $bullCallLow = $this->effectiveProb(65, 1);
        $bearCallLow = $this->effectiveProb(30, 1);

        $this->assertGreaterThan($bearCallLow, $bullCallLow,
            'Luck compression must not invert regime signal');
    }

    

    
    public function effective_probability_stays_within_valid_range(): void
    {
        foreach ([30, 44, 48, 50, 65, 68] as $base) {
            foreach ([1, 100, 1_000, 20_000, 100_000, 999_999] as $luck) {
                $p = $this->effectiveProb($base, $luck);
                $this->assertGreaterThanOrEqual(10.0, $p, "Below 10% at base={$base} luck={$luck}");
                $this->assertLessThanOrEqual(90.0,    $p, "Above 90% at base={$base} luck={$luck}");
            }
        }
    }

    

    
    public function active_hours_boost_correct_directional_reads(): void
    {
        $jpm  = $this->profile('JPM');
        $base = 65;

        $during  = $this->hourBias($base, 0, 'call', $jpm, 15);
        $outside = $this->hourBias($base, 0, 'call', $jpm, 8);

        $this->assertGreaterThan($outside, $during, 'Bull CALL should be boosted during NYSE hours');
        $this->assertLessThanOrEqual(85, $during, 'Hour bias must not exceed 85');
    }

    

    
    public function active_hours_dampen_wrong_directional_reads(): void
    {
        $jpm  = $this->profile('JPM');
        $base = 30;

        $during  = $this->hourBias($base, 1, 'call', $jpm, 15);
        $outside = $this->hourBias($base, 1, 'call', $jpm, 8);

        $this->assertLessThan($outside, $during, 'Wrong direction during active hours should be penalised');
        $this->assertGreaterThanOrEqual(15, $during, 'Hour bias floor is 15');
    }

    

    
    public function sideways_and_volatile_regimes_ignore_hour_bias(): void
    {
        $jpm = $this->profile('JPM');
        foreach ([2, 3] as $ri) {
            $during  = $this->hourBias(50, $ri, 'call', $jpm, 15);
            $outside = $this->hourBias(50, $ri, 'call', $jpm, 8);
            $this->assertSame($during, $outside, "Regime {$ri} should be immune to hour bias");
        }
    }

    

    
    public function winning_trade_money_uses_position_cap_as_banker_cut(): void
    {
        $character  = $this->makeCharacter();
        $bankBefore = (int) $this->bank->fresh()->balance;
        $stake      = 100_000;
        $mult       = 1.50; 
        $positionCapPercent = 10;

        $grossReturn    = (int) floor($stake * $mult);  
        $netGain        = $grossReturn - $stake;         
        $payoutToBanker = min((int) floor($netGain * ($positionCapPercent / 100)), 150_000);
        $payoutToBank   = $netGain - $payoutToBanker;

        DB::table('businesses')->where('id', $this->bank->id)->increment('balance', $payoutToBank);
        $character->increment('cash_on_hand', $payoutToBanker);

        $this->assertSame($bankBefore + $payoutToBank, (int) $this->bank->fresh()->balance);
        $this->assertSame(500_000 + $payoutToBanker,   (int) $character->fresh()->cash_on_hand);
        $this->assertSame($netGain, $payoutToBank + $payoutToBanker, 'Net gain must be fully distributed');
    }

    

    
    public function losing_trade_applies_correct_loss_depth_per_ticker(): void
    {
        $cases = [
            ['JPM',    100_000, 70_000,  30_000],
            ['005930', 100_000, 100_000, 0],
        ];

        foreach ($cases as [$ticker, $stake, $expectedLoss, $expectedSalvage]) {
            $p       = $this->profile($ticker);
            $loss    = (int) floor($stake * $p['loss_depth']);
            $salvage = $stake - $loss;

            $this->assertSame($expectedLoss,    $loss,    "Loss depth wrong for {$ticker}");
            $this->assertSame($expectedSalvage, $salvage, "Salvage wrong for {$ticker}");
        }
    }

    

    
    public function win_flash_message_is_first_person_and_regime_accurate(): void
    {
        $cases = [
            ['bull',     'CALL', 'bought a CALL', 'expecting it to climb', 'and it did'],
            ['bull',     'PUT',  'bought a PUT',  'against the trend',     'surprised you'],
            ['bear',     'PUT',  'bought a PUT',  'expecting it to fall',  'and it did'],
            ['bear',     'CALL', 'bought a CALL', 'against the trend',     'reversal paid off'],
            ['sideways', 'CALL', 'caught a breakout', 'flat market',       'The bank earned'],
        ];

        foreach ($cases as $case) {
            [$regime, $dir] = $case;
            $snippets = array_slice($case, 2);

            $msg = $this->buildWinMessage('JPM', $dir, $regime, 50_000, 12_000, 8_000);
            foreach ($snippets as $snippet) {
                $this->assertStringContainsString($snippet, $msg,
                    "Win message for {$regime}+{$dir} missing: \"{$snippet}\"");
            }
        }
    }

    

    
    public function loss_flash_message_is_first_person_and_regime_accurate(): void
    {
        $cases = [
            ['bull',     'PUT',  'bought a PUT',  'hoping it would drop', 'uptrend held'],
            ['bull',     'CALL', 'bought a CALL', 'rally to continue',    'stalled'],
            ['bear',     'CALL', 'bought a CALL', 'hoping for a recovery','sell-off continued'],
            ['sideways', 'CALL', 'looking for a breakout', 'stayed flat', 'closed at a loss'],
        ];

        foreach ($cases as $case) {
            [$regime, $dir] = $case;
            $snippets = array_slice($case, 2);

            $msg = $this->buildLossMessage('JPM', $dir, $regime, 100_000, 70_000, 30_000);
            foreach ($snippets as $snippet) {
                $this->assertStringContainsString($snippet, $msg,
                    "Loss message for {$regime}+{$dir} missing: \"{$snippet}\"");
            }
        }
    }

    

    
    public function loss_message_includes_salvage_note_only_when_applicable(): void
    {
        $with    = $this->buildLossMessage('JPM',    'CALL', 'bull', 100_000, 70_000,  30_000);
        $without = $this->buildLossMessage('005930', 'CALL', 'bull', 100_000, 100_000, 0);

        $this->assertStringContainsString('recovered', $with);
        $this->assertStringNotContainsString('recovered', $without);
    }

    

    
    public function position_cap_defaults_to_10_percent_of_bank_balance(): void
    {
        $this->assertSame(1_000_000, (int) floor(10_000_000 * 0.10));

        $character = $this->makeCharacter();
        $response  = $this->actingAs($character->user)
            ->post(route('career.banking.trade'), [
                'ticker'    => 'JPM',
                'direction' => 'call',
                'amount'    => 1_000_001,
            ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('10% of bank capital', session('error') ?? '');
    }


    public function bank_manager_can_update_position_cap(): void
    {
        $manager = $this->makeCharacter(careerRank: 4);

        $response = $this->actingAs($manager->user)
            ->post(route('career.banking.position-cap'), [
                'position_cap_percent' => 17,
            ]);

        $response->assertSessionHas('success');
        $this->assertSame(17, (int) $this->bank->fresh()->getSetting('position_cap_percent'));
    }


    public function custom_position_cap_is_enforced(): void
    {
        $this->bank->setSetting('position_cap_percent', 25);

        $character = $this->makeCharacter();
        $response  = $this->actingAs($character->user)
            ->post(route('career.banking.trade'), [
                'ticker'    => 'JPM',
                'direction' => 'call',
                'amount'    => 2_500_001,
            ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('25% of bank capital', session('error') ?? '');
    }

    

    
    public function trade_is_blocked_when_action_timer_is_active(): void
    {
        $character = $this->makeCharacter();
        $character->timers()->update([
            'next_action_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        $response = $this->actingAs($character->user)
            ->post(route('career.banking.trade'), [
                'ticker'    => 'JPM',
                'direction' => 'call',
                'amount'    => 1_000,
            ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('wait', strtolower(session('error') ?? ''));
    }

    

    
    public function rank_gate_blocks_trade_below_rank_3(): void
    {
        $character = $this->makeCharacter(luck: 100_000, careerRank: 2);

        $response = $this->actingAs($character->user)
            ->post(route('career.banking.trade'), [
                'ticker'    => 'JPM',
                'direction' => 'call',
                'amount'    => 1_000,
            ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('rank', strtolower(session('error') ?? ''));
    }

    

    
    public function trade_is_rejected_below_minimum_position_size(): void
    {
        $character = $this->makeCharacter();

        $response = $this->actingAs($character->user)
            ->post(route('career.banking.trade'), [
                'ticker'    => 'JPM',
                'direction' => 'call',
                'amount'    => 999,
            ]);

        $response->assertSessionHasErrors(['amount']);
    }

    

    private function hash(string $ticker, int $epoch): int
    {
        $s = $ticker . ':' . $epoch;
        $h = 5381;
        for ($i = 0, $len = strlen($s); $i < $len; $i++) {
            $h = ((($h << 5) + $h) ^ ord($s[$i])) & 0x7FFFFFFF;
        }
        return $h & 0x7FFFFFFF;
    }

    private function luckFactor(int $luck): float
    {
        return min(1.15, max(0.10, log10(max(1, $luck)) / 5.7));
    }

    private function effectiveProb(int $base, int $luck): float
    {
        return 50.0 + ($base - 50) * $this->luckFactor($luck);
    }

    private function hourBias(int $base, int $regimeIndex, string $direction, array $profile, int $utcHour): int
    {
        if ($regimeIndex === 2 || $regimeIndex === 3) {
            return $base;
        }

        $s      = (int) $profile['utc_start'];
        $e      = (int) $profile['utc_end'];
        $active = ($s < $e)
            ? ($utcHour >= $s && $utcHour < $e)
            : ($utcHour >= $s || $utcHour < $e);

        if (! $active) {
            return $base;
        }

        $correct = ($regimeIndex === 0 && $direction === 'call')
                || ($regimeIndex === 1 && $direction === 'put');
        $boost   = (int) $profile['hour_boost'];

        return $correct
            ? min(85, $base + $boost)
            : max(15, $base - (int) floor($boost / 2));
    }

    private function profile(string $ticker): array
    {
        return [
            'JPM'    => ['display' => 'JPM',     'mult' => 1.50, 'loss_depth' => 0.70, 'regime_s' => 3600, 'utc_start' => 13, 'utc_end' => 21, 'hour_boost' => 8],
            'GS'     => ['display' => 'GS',      'mult' => 1.75, 'loss_depth' => 0.80, 'regime_s' => 2700, 'utc_start' => 13, 'utc_end' => 21, 'hour_boost' => 6],
            '7203'   => ['display' => 'TOYOTA',  'mult' => 1.60, 'loss_depth' => 0.72, 'regime_s' => 7200, 'utc_start' => 0,  'utc_end' => 7,  'hour_boost' => 10],
            '6758'   => ['display' => 'SONY',    'mult' => 2.00, 'loss_depth' => 0.88, 'regime_s' => 1800, 'utc_start' => 0,  'utc_end' => 7,  'hour_boost' => 8],
            '005930' => ['display' => 'SAMSUNG', 'mult' => 2.30, 'loss_depth' => 1.00, 'regime_s' => 1200, 'utc_start' => 0,  'utc_end' => 6,  'hour_boost' => 12],
        ][$ticker] ?? [];
    }

    private function buildWinMessage(string $display, string $dir, string $regime, int $stake, int $payoutBank, int $payoutBanker): string
    {
        $fmtStake    = '$' . number_format($stake);
        $fmtBankGain = '$' . number_format($payoutBank);
        $fmtMyGain   = '$' . number_format($payoutBanker);

        return match (true) {
            $regime === 'bull' && $dir === 'CALL' =>
                "You bought a CALL on {$display} for {$fmtStake} expecting it to climb — and it did. "
                . "The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
            $regime === 'bull' && $dir === 'PUT' =>
                "You bought a PUT on {$display} for {$fmtStake} against the trend — the market surprised you and flipped. "
                . "The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
            $regime === 'bear' && $dir === 'PUT' =>
                "You bought a PUT on {$display} for {$fmtStake} expecting it to fall — and it did. "
                . "The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
            $regime === 'bear' && $dir === 'CALL' =>
                "You bought a CALL on {$display} for {$fmtStake} against the trend — a surprise reversal paid off. "
                . "The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
            $regime === 'sideways' =>
                "You bought a {$dir} on {$display} for {$fmtStake} and caught a breakout from a flat market. "
                . "The bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
            default =>
                "Your {$dir} on {$display} for {$fmtStake} closed in the money. "
                . "Bank earned {$fmtBankGain}, your cut is {$fmtMyGain}.",
        };
    }

    private function buildLossMessage(string $display, string $dir, string $regime, int $stake, int $actualLoss, int $salvage): string
    {
        $fmtStake   = '$' . number_format($stake);
        $fmtLoss    = '$' . number_format($actualLoss);
        $salvageMsg = $salvage > 0
            ? ', but the bank recovered $' . number_format($salvage) . ' of the stake.'
            : '';

        return match (true) {
            $regime === 'bull' && $dir === 'PUT' =>
                "You bought a PUT on {$display} for {$fmtStake} hoping it would drop, but the uptrend held. "
                . "Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
            $regime === 'bull' && $dir === 'CALL' =>
                "You bought a CALL on {$display} for {$fmtStake} expecting the rally to continue, but it stalled. "
                . "Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
            $regime === 'bear' && $dir === 'CALL' =>
                "You bought a CALL on {$display} for {$fmtStake} hoping for a recovery, but the sell-off continued. "
                . "Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
            $regime === 'bear' && $dir === 'PUT' =>
                "You bought a PUT on {$display} for {$fmtStake} expecting it to keep falling, but it pushed through your level. "
                . "Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
            $regime === 'sideways' =>
                "You bought a {$dir} on {$display} for {$fmtStake} looking for a breakout, but the market stayed flat. "
                . "Your position closed at a loss of {$fmtLoss}{$salvageMsg}",
            default =>
                "Your {$dir} on {$display} for {$fmtStake} closed at a loss of {$fmtLoss}.{$salvageMsg}",
        };
    }
}
