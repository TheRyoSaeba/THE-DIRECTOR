<?php

namespace Tests\Feature;

use App\Actions\Extortion;
use App\Models\Career;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CorporationProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class HqExtortionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeCity(int $crimeRate = 35): City
    {
        return City::create([
            'name' => 'ExtCity-' . uniqid(),
            'slug' => 'ext-city-' . uniqid(),
            'crime_rate' => $crimeRate,
        ]);
    }

    private function makeCharacter(
        City $city,
        int $offense = 1000,
        int $defense = 1000,
        int $strength = 100,
        int $cashOnHand = 100_000,
    ): Character {
        $user = User::factory()->create();
        $careerId = Career::findByCode('corporation')->id;

        $character = Character::create([
            'user_id' => $user->id,
            'display_name' => 'ExtChar-' . uniqid(),
            'gender' => 'male',
            'city_id' => $city->id,
            'home_city_id' => $city->id,
            'career_id' => $careerId,
            'career_rank' => 1,
            'career_xp' => 0,
            'total_character_exp' => 0,
            'health' => 100,
            'max_health' => 100,
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => 0,
            'dirty_cash' => 0,
        ]);

        CharacterStats::create([
            'character_id' => $character->id,
            'influence' => 50,
            'intelligence' => 1000,
            'offense' => $offense,
            'defense' => $defense,
            'luck' => 1000,
        ]);

        CharacterTimers::create([
            'character_id' => $character->id,
            'next_action_at' => 0,
            'strength' => $strength,
        ]);

        return $character->fresh();
    }

    private function makeCorp(
        Character $ceo,
        string $name,
        int $cashReserves = 1_000_000,
        bool $isHolding = false,
    ): Corporation {
        $corp = Corporation::create([
            'name' => $name . '-' . uniqid(),
            'home_city_id' => $ceo->home_city_id,
            'founder_id' => $ceo->id,
            'ceo_id' => $isHolding ? null : $ceo->id,
            'cash_reserves' => $cashReserves,
            'is_holding_company' => $isHolding,
        ]);

        CorporationProperty::createStartingHeadquarters($corp, $isHolding ? 3 : 1);
        if (! $isHolding) {
            $corp->attachMember($ceo);
        }

        return $corp->fresh();
    }

    private function callChance(Character $attacker, City $city, int $ownerDefense, int $onlineInCity, int $hqTier): int
    {
        $method = new ReflectionMethod(Extortion::class, 'chance');
        $method->setAccessible(true);
        $attacker->loadMissing(['stats', 'timers']);
        return $method->invoke(new Extortion(), $attacker, $city, $ownerDefense, $onlineInCity, $hqTier);
    }

    // ===================== Functional tests =====================

    public function test_dropdown_lists_phase1_corps_with_constructed_hq_and_excludes_holdings(): void
    {
        $city = $this->makeCity();
        $opCeo = $this->makeCharacter($city);
        $opCorp = $this->makeCorp($opCeo, 'OperatingCorp');

        $holdCeo = $this->makeCharacter($city);
        $holding = $this->makeCorp($holdCeo, 'HoldingCo', isHolding: true);

        $attacker = $this->makeCharacter($city);

        $shape = (new Extortion())->getShape($attacker);
        $ids = array_column($shape['targets'], 'id');
        $names = array_column($shape['targets'], 'name');

        $this->assertContains('hq:' . $opCorp->name, $ids,
            'Phase-1 corp must appear with hq: routing key');
        $this->assertNotContains('hq:' . $holding->name, $ids,
            'Holding company must not appear in extortion targets');

        // Display label should be "<corp name>'s <hq name>" — corp identity first.
        $opLabel = $names[array_search('hq:' . $opCorp->name, $ids)];
        $this->assertStringContainsString($opCorp->name, $opLabel);
        $this->assertStringContainsString("'s ", $opLabel);
    }

    public function test_corp_with_only_pending_hq_is_not_listed_or_extortable(): void
    {
        $city = $this->makeCity();
        $ceo = $this->makeCharacter($city);
        $corp = $this->makeCorp($ceo, 'PendingCorp');
        $attacker = $this->makeCharacter($city);

        // Force the founding HQ into PENDING — no operational row remains.
        $corp->properties()->update(['condition' => CorporationProperty::CONDITION_PENDING]);

        $shape = (new Extortion())->getShape($attacker);
        $ids = array_column($shape['targets'], 'id');

        $this->assertNotContains('hq:' . $corp->name, $ids,
            'Corp without any CONSTRUCTED HQ must not appear in dropdown');

        $this->actingAs($attacker->user)
            ->post(route('actions.extortion'), ['target_id' => 'hq:' . $corp->name])
            ->assertSessionHas('error', 'That corporation has no operational HQ to extort.');
    }

    public function test_holding_company_hq_is_rejected_at_route_level(): void
    {
        $city = $this->makeCity();
        $holdCeo = $this->makeCharacter($city);
        $holding = $this->makeCorp($holdCeo, 'TheHolding', isHolding: true);
        $attacker = $this->makeCharacter($city);

        $this->actingAs($attacker->user)
            ->post(route('actions.extortion'), ['target_id' => 'hq:' . $holding->name])
            ->assertSessionHas('error', 'Target corporation not found in your city.');
    }

    public function test_corp_member_cannot_extort_their_own_corporation(): void
    {
        $city = $this->makeCity();
        $ceo = $this->makeCharacter($city);
        $corp = $this->makeCorp($ceo, 'OwnCorp');

        $this->actingAs($ceo->user)
            ->post(route('actions.extortion'), ['target_id' => 'hq:' . $corp->name])
            ->assertSessionHas('error', 'You cannot extort your own corporation.');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves);
    }

    public function test_protection_until_blocks_subsequent_extortion(): void
    {
        $city = $this->makeCity();
        $ceo = $this->makeCharacter($city);
        $corp = $this->makeCorp($ceo, 'ProtectedCorp');

        $corp->properties()->update(['protection_until' => now()->addMinutes(60)]);

        $attacker = $this->makeCharacter($city, offense: 100_000);

        $this->actingAs($attacker->user)
            ->post(route('actions.extortion'), ['target_id' => 'hq:' . $corp->name])
            ->assertSessionHas('error', 'Their security has been hardened recently and your tools cannot get a foothold. Try again later.');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves,
            'Reserves must not move when extortion is rejected by protection.');
    }

    public function test_extortion_charges_purchase_cost_and_sets_protection_regardless_of_outcome(): void
    {
        $city = $this->makeCity();
        $ceo = $this->makeCharacter($city);
        $corp = $this->makeCorp($ceo, 'AnyCorp');

        $attacker = $this->makeCharacter($city, offense: 100_000);
        $cashBefore = (int) $attacker->cash_on_hand;

        $this->actingAs($attacker->user)
            ->post(route('actions.extortion'), ['target_id' => 'hq:' . $corp->name]);

        $hq = $corp->fresh()->properties()->where('type', CorporationProperty::TYPE_HQ)->first();

        $this->assertSame($cashBefore - 1000, (int) $attacker->fresh()->cash_on_hand,
            'Attacker pays $1000 for ransomware regardless of success/failure.');
        $this->assertNotNull($hq->protection_until, 'protection_until must be set on the HQ row.');
        $this->assertTrue($hq->protection_until->isFuture(), 'protection_until must be in the future.');
        $this->assertGreaterThanOrEqual(now()->addMinutes(89), $hq->protection_until,
            'protection_until must be at least 90 minutes from now.');
        $this->assertLessThanOrEqual(now()->addMinutes(121), $hq->protection_until,
            'protection_until must be at most 120 minutes from now.');
    }

    public function test_successful_extortion_drains_cash_reserves_and_credits_dirty_cash(): void
    {
        // Near-best-case scenario: maxed crime rate, no CEO (defense floor),
        // tier-1 HQ. statChance hovers ~80; we retry with fresh attackers
        // to absorb the residual ~20% roll variance (5 tries → 99.97%
        // chance of at least one success).
        $city = $this->makeCity(crimeRate: 100);
        $corpName = 'WeakCorp-' . uniqid();
        $corp = Corporation::create([
            'name' => $corpName,
            'home_city_id' => $city->id,
            'founder_id' => null,
            'ceo_id' => null,
            'cash_reserves' => 5_000_000,
            'is_holding_company' => false,
        ]);
        CorporationProperty::createStartingHeadquarters($corp, 1);

        $reservesStart = 5_000_000;
        $totalDirtyCashGained = 0;
        $succeeded = false;

        for ($i = 0; $i < 5 && !$succeeded; $i++) {
            // Reset cooldown so each attempt actually rolls.
            $corp->properties()->update(['protection_until' => null]);

            // Fresh attacker per try — avoids strength depletion + lets us
            // measure dirty cash gained from this single attempt.
            $attacker = $this->makeCharacter($city, offense: 1_000_000);

            $reservesBefore = (int) $corp->fresh()->cash_reserves;

            $this->actingAs($attacker->user)
                ->post(route('actions.extortion'), ['target_id' => 'hq:' . $corp->name]);

            $reservesAfter = (int) $corp->fresh()->cash_reserves;
            if ($reservesAfter < $reservesBefore) {
                $succeeded = true;
                $totalDirtyCashGained = (int) $attacker->fresh()->dirty_cash;
                $stolen = $reservesBefore - $reservesAfter;

                $this->assertGreaterThan(0, $stolen);
                // Mirrors MAX_STEAL_AMOUNT (100k) defined privately on Extortion.
                $this->assertLessThanOrEqual(100_000, $stolen,
                    'Stolen amount must not exceed MAX_STEAL_AMOUNT cap.');
                $this->assertSame($stolen, $totalDirtyCashGained,
                    'Attacker dirty_cash must equal the amount drained from reserves.');
            }
        }

        $this->assertTrue($succeeded,
            'Veteran attacker (offense 1M) should drain a no-CEO tier-1 HQ within 5 tries.');
        $this->assertLessThan($reservesStart, (int) $corp->fresh()->cash_reserves);
    }

    // ===================== Statistical chance tests =====================

    public function test_chance_scaling_makes_statistical_sense_across_stat_groups_and_tiers(): void
    {
        $city = $this->makeCity(crimeRate: 50);

        // Three stat profiles. offenseFactor = (log10(offense) - 2) * 20, capped at 70.
        //   Veteran  (1M)   → factor 70 (capped)
        //   Moderate (10k)  → factor 40
        //   Weak     (1k)   → factor 20
        $veteran = $this->makeCharacter($city, offense: 1_000_000);
        $moderate = $this->makeCharacter($city, offense: 10_000);
        $weak = $this->makeCharacter($city, offense: 1_000);

        // Defense profiles. defensePenalty = sqrt(def)/25 capped at 15.
        //   No CEO    (1)       → penalty 0
        //   Maxed CEO (200k+)   → penalty 15 (cap)
        $noCeo = 1;
        $maxed = 200_000;

        // -- Veteran -- top of the curve
        $vNoTier1  = $this->callChance($veteran, $city, $noCeo, 1, 1);
        $vNoTier3  = $this->callChance($veteran, $city, $noCeo, 1, 3);
        $vMaxTier1 = $this->callChance($veteran, $city, $maxed, 1, 1);
        $vMaxTier3 = $this->callChance($veteran, $city, $maxed, 1, 3);

        $this->assertGreaterThanOrEqual(70, $vNoTier1,
            "Veteran vs tier-1 no-CEO should be the easiest target: {$vNoTier1}");
        $this->assertLessThan($vNoTier1, $vNoTier3,
            "Tier-3 must be harder than tier-1 for the same attacker: t1={$vNoTier1} t3={$vNoTier3}");
        $this->assertLessThan($vNoTier1, $vMaxTier1,
            "Maxed CEO must be harder than no CEO at the same tier: noCeo={$vNoTier1} maxed={$vMaxTier1}");
        $this->assertLessThan(60, $vMaxTier3,
            "Even maxed veteran shouldn't coin-flip tier-3 with maxed CEO: {$vMaxTier3}");
        $this->assertGreaterThan(0, $vMaxTier3, 'Should not be impossible.');

        // -- Moderate -- middle of the curve
        $mNoTier1  = $this->callChance($moderate, $city, $noCeo, 1, 1);
        $mMaxTier3 = $this->callChance($moderate, $city, $maxed, 1, 3);

        $this->assertLessThan($vNoTier1, $mNoTier1,
            "Moderate must be lower than veteran on the same target: vet={$vNoTier1} mod={$mNoTier1}");
        $this->assertLessThan(40, $mMaxTier3,
            "Moderate vs tier-3 maxed should be punishing: {$mMaxTier3}");

        // -- Weak -- bottom of the curve
        $wNoTier1  = $this->callChance($weak, $city, $noCeo, 1, 1);
        $wMaxTier3 = $this->callChance($weak, $city, $maxed, 1, 3);

        $this->assertLessThanOrEqual($mNoTier1, $wNoTier1,
            "Weak should not beat moderate on any target: weak={$wNoTier1} mod={$mNoTier1}");
        $this->assertLessThanOrEqual(15, $wMaxTier3,
            "Weak vs tier-3 maxed should be near floor: {$wMaxTier3}");
    }

    public function test_more_players_online_reduces_chance(): void
    {
        $city = $this->makeCity(crimeRate: 50);
        $attacker = $this->makeCharacter($city, offense: 100_000);

        $alone = $this->callChance($attacker, $city, 1, 1, 1);
        $busy  = $this->callChance($attacker, $city, 1, 8, 1);

        $this->assertLessThan($alone, $busy,
            "More people online must reduce chance: alone={$alone} busy={$busy}");
    }

    public function test_lower_strength_reduces_chance(): void
    {
        $city = $this->makeCity(crimeRate: 50);
        $full = $this->makeCharacter($city, offense: 100_000, strength: 100);
        $depleted = $this->makeCharacter($city, offense: 100_000, strength: 25);

        $fullChance = $this->callChance($full, $city, 1, 1, 1);
        $depletedChance = $this->callChance($depleted, $city, 1, 1, 1);

        $this->assertLessThan($fullChance, $depletedChance,
            "Lower strength must reduce chance: full={$fullChance} depleted={$depletedChance}");
    }

    public function test_higher_crime_rate_increases_chance(): void
    {
        $cleanCity = $this->makeCity(crimeRate: 5);
        $dirtyCity = $this->makeCity(crimeRate: 95);
        $attacker = $this->makeCharacter($cleanCity, offense: 100_000);

        $cleanChance = $this->callChance($attacker, $cleanCity, 100, 1, 1);
        $dirtyChance = $this->callChance($attacker, $dirtyCity, 100, 1, 1);

        $this->assertLessThan($dirtyChance, $cleanChance,
            "Higher crime rate must raise chance: clean={$cleanChance} dirty={$dirtyChance}");
    }
}
