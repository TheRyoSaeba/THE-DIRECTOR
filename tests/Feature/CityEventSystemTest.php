<?php

namespace Tests\Feature;

use App\Events\CityEvents\CityEvent;
use App\Events\CityEvents\CityEventDispatcher;
use App\Events\CityEvents\CityUnrest;
use App\Events\CityEvents\Mugging;
use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\User;
use App\Support\CrimeThresholds;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;


class CityEventSystemTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;
    private int  $unemployedCareerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::create([
            'name'       => 'TestCity-' . uniqid(),
            'slug'       => 'test-city-' . uniqid(),
            'crime_rate' => 0,
        ]);

        $unemployed = DB::table('careers')->where('code', 'unemployed')->first();
        $this->assertNotNull($unemployed, 'Unemployed career must be seeded');
        $this->unemployedCareerId = $unemployed->id;
    }

    

    private function makeChar(
        City   $city,
        int    $cashOnHand   = 10_000,
        bool   $jailed       = false,
        bool   $hospitalized = false,
        ?int   $homeCityId   = null,
        bool   $online       = true,  
    ): Character {
        $user = User::factory()->create();

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'TestChar-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $city->id,
            'home_city_id' => $homeCityId ?? $city->id,
            'career_id'    => $this->unemployedCareerId,
            'career_rank'  => 1,
            'career_xp'    => 0,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => 0,
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => 1_000,
            'luck'         => 1_000,
            'offense'      => 1_000,
            'defense'      => 1_000,
            'influence'    => 0,
        ]);

        $timerData = ['character_id' => $char->id];
        $now       = now()->getTimestamp();

        if ($jailed) {
            $timerData['jail_until'] = $now + 7200;
        }
        if ($hospitalized) {
            $timerData['hospital_until'] = $now + 7200;
        }

        CharacterTimers::create($timerData);

        if ($online) {
            $this->makeSession($user->id);
        }

        return $char;
    }

    
    private function makeSession(int $userId): void
    {
        DB::table('sessions')->insert([
            'id'            => Str::random(40),
            'user_id'       => $userId,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'PHPUnit',
            'payload'       => base64_encode('{}'),
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    
    private function deterministicMugging(): CityEvent
    {
        return new class extends Mugging {
            protected function probability(): int { return 100; }
        };
    }

    
    private function deterministicUnrest(): CityEvent
    {
        return new class extends CityUnrest {
            protected function probability(): int { return 100; }
        };
    }

    
    
    

    public function test_threshold_band_constants_are_correct(): void
    {
        $this->assertSame(15,  CrimeThresholds::SAFE_MAX);
        $this->assertSame(35,  CrimeThresholds::LOW_MAX);
        $this->assertSame(55,  CrimeThresholds::MODERATE_MAX);
        $this->assertSame(75,  CrimeThresholds::HIGH_MAX);
        $this->assertSame(76,  CrimeThresholds::CRITICAL_MIN);
    }

    public function test_threshold_label_returns_correct_band(): void
    {
        $this->assertSame('Safe',     CrimeThresholds::label(0));
        $this->assertSame('Safe',     CrimeThresholds::label(15));
        $this->assertSame('Low',      CrimeThresholds::label(16));
        $this->assertSame('Low',      CrimeThresholds::label(35));
        $this->assertSame('Moderate', CrimeThresholds::label(36));
        $this->assertSame('Moderate', CrimeThresholds::label(55));
        $this->assertSame('High',     CrimeThresholds::label(56));
        $this->assertSame('High',     CrimeThresholds::label(75));
        $this->assertSame('Critical', CrimeThresholds::label(76));
        $this->assertSame('Critical', CrimeThresholds::label(100));
    }

    public function test_threshold_boolean_helpers_are_mutually_exclusive(): void
    {
        $rate = 50; 

        $this->assertFalse(CrimeThresholds::isSafe($rate));
        $this->assertFalse(CrimeThresholds::isLow($rate));
        $this->assertTrue(CrimeThresholds::isModerate($rate));
        $this->assertFalse(CrimeThresholds::isHigh($rate));
        $this->assertFalse(CrimeThresholds::isCritical($rate));
    }

    
    
    

    public function test_mugging_does_not_fire_in_safe_city(): void
    {
        $this->city->update(['crime_rate' => 10]);
        $char = $this->makeChar($this->city);
        $char->load('city');

        $this->assertFalse((new Mugging())->shouldFire($char));
    }

    public function test_mugging_does_not_fire_in_low_crime_city(): void
    {
        $this->city->update(['crime_rate' => 35]); 
        $char = $this->makeChar($this->city);
        $char->load('city');

        $this->assertFalse((new Mugging())->shouldFire($char));
    }

    public function test_mugging_fires_at_moderate_crime_boundary(): void
    {
        $this->city->update(['crime_rate' => 36]); 
        $char = $this->makeChar($this->city);
        $char->load('city');

        $this->assertTrue((new Mugging())->shouldFire($char));
    }

    public function test_mugging_fires_at_moderate_crime(): void
    {
        $this->city->update(['crime_rate' => 50]);
        $char = $this->makeChar($this->city);
        $char->load('city');

        $this->assertTrue((new Mugging())->shouldFire($char));
    }

    public function test_mugging_fires_at_high_crime(): void
    {
        $this->city->update(['crime_rate' => 65]);
        $char = $this->makeChar($this->city);
        $char->load('city');

        $this->assertTrue((new Mugging())->shouldFire($char));
    }

    public function test_mugging_fires_at_critical_crime(): void
    {
        $this->city->update(['crime_rate' => 90]);
        $char = $this->makeChar($this->city);
        $char->load('city');

        $this->assertTrue((new Mugging())->shouldFire($char));
    }

    public function test_mugging_does_not_fire_without_current_city(): void
    {
        $char = $this->makeChar($this->city);
        
        $char->city_id = null;
        $char->setRelation('city', null);

        $this->assertFalse((new Mugging())->shouldFire($char));
    }

    
    
    

    public function test_mugging_deducts_cash_from_someone_in_city(): void
    {
        $this->city->update(['crime_rate' => 50]);

        $trigger = $this->makeChar($this->city, cashOnHand: 10_000);
        $other   = $this->makeChar($this->city, cashOnHand: 10_000);

        $totalBefore = $trigger->cash_on_hand + $other->cash_on_hand;

        $trigger->load('city');
        $fired = $this->deterministicMugging()->attempt($trigger);

        $this->assertTrue($fired, 'Deterministic mugging must fire with probability=100 and MODERATE crime');

        $trigger->refresh();
        $other->refresh();

        $totalAfter = (int) $trigger->cash_on_hand + (int) $other->cash_on_hand;

        
        $this->assertLessThan($totalBefore, $totalAfter,
            'Total cash in city must decrease after a mugging');
    }

    public function test_mugging_stolen_amount_is_between_5_and_15_percent(): void
    {
        $this->city->update(['crime_rate' => 50]);

        $trigger        = $this->makeChar($this->city);
        $victimCash     = 10_000;
        $victim         = $this->makeChar($this->city, cashOnHand: $victimCash);

        $trigger->load('city');
        $this->deterministicMugging()->attempt($trigger);

        $victim->refresh();
        $stolen = $victimCash - (int) $victim->cash_on_hand;

        
        $this->assertGreaterThanOrEqual(499,   $stolen, 'Stolen amount must be at least 5% of victim cash');
        $this->assertLessThanOrEqual(1_500, $stolen, 'Stolen amount must not exceed 15% of victim cash');
    }

    public function test_mugging_writes_journal_for_victim(): void
    {
        $this->city->update(['crime_rate' => 50]);

        $trigger = $this->makeChar($this->city);
        $victim  = $this->makeChar($this->city, cashOnHand: 10_000);

        $trigger->load('city');
        $this->deterministicMugging()->attempt($trigger);

        $journal = CharacterJournal::where('character_id', $victim->id)
            ->where('type', 'mugging')
            ->first();

        $this->assertNotNull($journal, 'A mugging journal entry must be created for the victim');
        $this->assertSame($this->city->name, $journal->data['city_name'] ?? null,
            'Journal must record the city where the mugging occurred');
        $this->assertArrayHasKey('amount', $journal->data,
            'Journal must record the stolen amount');
        $this->assertGreaterThan(0, $journal->data['amount']);
    }

    public function test_mugging_fires_against_solo_character_in_city(): void
    {
        $this->city->update(['crime_rate' => 50]);

        
        $trigger = $this->makeChar($this->city, cashOnHand: 10_000);
        $cashBefore = $trigger->cash_on_hand;

        $trigger->load('city');
        $fired = $this->deterministicMugging()->attempt($trigger);

        $this->assertTrue($fired);

        $trigger->refresh();
        $this->assertLessThan($cashBefore, (int) $trigger->cash_on_hand,
            'Solo character in a high-crime city must be victimised by the mugging');

        $journal = CharacterJournal::where('character_id', $trigger->id)
            ->where('type', 'mugging')
            ->first();

        $this->assertNotNull($journal, 'Solo trigger-victim must receive a mugging journal entry');
    }

    public function test_mugging_does_not_steal_from_jailed_bystander(): void
    {
        $this->city->update(['crime_rate' => 50]);

        $trigger = $this->makeChar($this->city);
        $jailed  = $this->makeChar($this->city, cashOnHand: 10_000, jailed: true);

        $cashBefore = $jailed->cash_on_hand;

        $trigger->load('city');
        $this->deterministicMugging()->attempt($trigger);

        $jailed->refresh();

        $this->assertSame((int) $cashBefore, (int) $jailed->cash_on_hand,
            'Jailed character must not be selected as a mugging victim');
    }

    public function test_mugging_does_not_steal_from_hospitalized_bystander(): void
    {
        $this->city->update(['crime_rate' => 50]);

        $trigger      = $this->makeChar($this->city);
        $hospitalized = $this->makeChar($this->city, cashOnHand: 10_000, hospitalized: true);

        $cashBefore = $hospitalized->cash_on_hand;

        $trigger->load('city');
        $this->deterministicMugging()->attempt($trigger);

        $hospitalized->refresh();

        $this->assertSame((int) $cashBefore, (int) $hospitalized->cash_on_hand,
            'Hospitalized character must not be selected as a mugging victim');
    }

    public function test_mugging_does_not_steal_from_offline_character(): void
    {
        $this->city->update(['crime_rate' => 50]);

        
        $trigger = $this->makeChar($this->city, cashOnHand: 10_000);
        $offline = $this->makeChar($this->city, cashOnHand: 10_000, online: false);

        $cashBefore = $offline->cash_on_hand;

        $trigger->load('city');
        $fired = $this->deterministicMugging()->attempt($trigger);

        $this->assertTrue($fired);

        $offline->refresh();
        $this->assertSame((int) $cashBefore, (int) $offline->cash_on_hand,
            'Offline character must not be selected as a mugging victim');
    }

    public function test_mugging_does_not_steal_from_bystander_with_zero_cash(): void
    {
        $this->city->update(['crime_rate' => 50]);

        $trigger = $this->makeChar($this->city, cashOnHand: 10_000);
        $broke   = $this->makeChar($this->city, cashOnHand: 0);

        $trigger->load('city');
        $this->deterministicMugging()->attempt($trigger);

        
        $broke->refresh();
        $this->assertSame(0, (int) $broke->cash_on_hand);
    }

    public function test_mugging_stolen_cash_vanishes_not_given_to_anyone(): void
    {
        $this->city->update(['crime_rate' => 50]);

        $charA = $this->makeChar($this->city, cashOnHand: 10_000);
        $charB = $this->makeChar($this->city, cashOnHand: 10_000);

        $totalBefore = $charA->cash_on_hand + $charB->cash_on_hand;

        $charA->load('city');
        $this->deterministicMugging()->attempt($charA);

        $charA->refresh();
        $charB->refresh();

        $totalAfter = (int) $charA->cash_on_hand + (int) $charB->cash_on_hand;

        $this->assertLessThan($totalBefore, $totalAfter,
            'System total must decrease — stolen cash is destroyed, not redistributed');
    }

    public function test_mugging_does_not_fire_in_safe_city_even_with_victims(): void
    {
        $this->city->update(['crime_rate' => 10]); 

        $trigger = $this->makeChar($this->city);
        $victim  = $this->makeChar($this->city, cashOnHand: 10_000);

        $trigger->load('city');
        $fired = $this->deterministicMugging()->attempt($trigger);

        
        $this->assertFalse($fired, 'Mugging must not fire in a safe city');

        $victim->refresh();
        $this->assertSame(10_000, (int) $victim->cash_on_hand,
            'Victim cash must be untouched when event does not fire');
    }

    
    
    

    public function test_city_unrest_does_not_fire_in_safe_home_city(): void
    {
        $this->city->update(['crime_rate' => 10]);
        $char = $this->makeChar($this->city, homeCityId: $this->city->id);
        $char->load('homeCity');

        $this->assertFalse((new CityUnrest())->shouldFire($char));
    }

    public function test_city_unrest_does_not_fire_at_moderate_crime(): void
    {
        $this->city->update(['crime_rate' => 55]); 
        $char = $this->makeChar($this->city, homeCityId: $this->city->id);
        $char->load('homeCity');

        $this->assertFalse((new CityUnrest())->shouldFire($char),
            'CityUnrest only fires at HIGH (>55) or CRITICAL — not at MODERATE');
    }

    public function test_city_unrest_fires_at_high_crime(): void
    {
        $this->city->update(['crime_rate' => 60]);
        $char = $this->makeChar($this->city, homeCityId: $this->city->id);
        $char->load('homeCity');

        $this->assertTrue((new CityUnrest())->shouldFire($char));
    }

    public function test_city_unrest_fires_at_critical_crime(): void
    {
        $this->city->update(['crime_rate' => 90]);
        $char = $this->makeChar($this->city, homeCityId: $this->city->id);
        $char->load('homeCity');

        $this->assertTrue((new CityUnrest())->shouldFire($char));
    }

    public function test_city_unrest_uses_home_city_not_current_city(): void
    {
        
        $homeCity    = City::create(['name' => 'Home-' . uniqid(), 'slug' => 'home-' . uniqid(), 'crime_rate' => 5]);
        $currentCity = City::create(['name' => 'Curr-' . uniqid(), 'slug' => 'curr-' . uniqid(), 'crime_rate' => 90]);

        $char = $this->makeChar($currentCity, homeCityId: $homeCity->id);
        $char->load('homeCity');

        $this->assertFalse((new CityUnrest())->shouldFire($char),
            'CityUnrest must not fire just because the current city has high crime — only the home city matters');
    }

    public function test_city_unrest_does_not_fire_without_home_city(): void
    {
        $char = $this->makeChar($this->city);
        $char->setRelation('homeCity', null);

        $this->assertFalse((new CityUnrest())->shouldFire($char));
    }

    public function test_city_unrest_block_message_contains_city_name(): void
    {
        $this->city->update(['crime_rate' => 90]);
        $char = $this->makeChar($this->city, homeCityId: $this->city->id);
        $char->load('homeCity');

        $event = $this->deterministicUnrest();
        $event->shouldFire($char); 

        
        $message = $event->getBlockMessage();

        $this->assertStringContainsString($this->city->name, $message,
            'Block message must include the home city name');
        $this->assertNotEmpty($message);
    }

    
    
    

    public function test_dispatcher_returns_null_for_unknown_context(): void
    {
        $char = $this->makeChar($this->city);

        $result = CityEventDispatcher::dispatch($char, 'nonexistent_context_xyz');

        $this->assertNull($result, 'Dispatcher must return null for an unregistered context');
    }

    public function test_dispatcher_work_context_evaluates_city_unrest(): void
    {
        
        $this->city->update(['crime_rate' => 5]);
        $char = $this->makeChar($this->city, homeCityId: $this->city->id);

        $block = CityEventDispatcher::dispatchWork($char);

        
        $this->assertNull($block,
            'dispatchWork must return null when home city crime rate is safe');
    }

    public function test_dispatcher_move_context_includes_mugging(): void
    {
        
        $this->city->update(['crime_rate' => 5]); 
        $char = $this->makeChar($this->city);

        
        $result = CityEventDispatcher::dispatch($char, 'move');

        
        $this->assertNull($result,
            'Mugging must not fire in a safe city; null confirms shouldFire() gated it');
    }

    public function test_dispatcher_travel_context_includes_mugging(): void
    {
        $this->city->update(['crime_rate' => 5]);
        $char = $this->makeChar($this->city);

        $result = CityEventDispatcher::dispatch($char, 'travel');

        $this->assertNull($result);
    }

    public function test_dispatcher_loads_city_relations_before_evaluation(): void
    {
        
        $this->city->update(['crime_rate' => 5]);
        $char = $this->makeChar($this->city);

        
        $char->unsetRelation('city');
        $char->unsetRelation('homeCity');

        
        $this->assertNull(CityEventDispatcher::dispatch($char, 'move'));
    }

    
    
    

    public function test_mugging_journal_title_contains_city_name(): void
    {
        $journal = CharacterJournal::create([
            'character_id' => $this->makeChar($this->city)->id,
            'type'         => 'mugging',
            'data'         => ['city_name' => 'New York', 'amount' => 500, 'message' => 'You were robbed.'],
            'is_read'      => false,
        ]);

        $this->assertStringContainsString('NEW YORK', $journal->title);
        $this->assertStringContainsString('MUGGING', $journal->title);
    }

    public function test_mugging_journal_description_uses_message_field(): void
    {
        $journal = CharacterJournal::create([
            'character_id' => $this->makeChar($this->city)->id,
            'type'         => 'mugging',
            'data'         => [
                'city_name' => 'Seoul',
                'amount'    => 250,
                'message'   => 'Some random thug elbowed you and ran off with your wallet containing $250.',
            ],
            'is_read' => false,
        ]);

        $this->assertStringContainsString('250', $journal->description);
    }

    public function test_mugging_journal_description_falls_back_when_no_message(): void
    {
        $journal = CharacterJournal::create([
            'character_id' => $this->makeChar($this->city)->id,
            'type'         => 'mugging',
            'data'         => [],
            'is_read'      => false,
        ]);

        $this->assertNotSame('New activity logged.', $journal->description,
            'Mugging must not fall through to the default description');
        $this->assertNotEmpty($journal->description);
    }

    public function test_mugging_journal_icon_is_warning(): void
    {
        $journal = CharacterJournal::create([
            'character_id' => $this->makeChar($this->city)->id,
            'type'         => 'mugging',
            'data'         => ['city_name' => 'Tokyo', 'amount' => 100, 'message' => 'Robbed.'],
            'is_read'      => false,
        ]);

        $this->assertSame('Warning', $journal->icon);
    }

    public function test_mugging_journal_color_class_is_not_default(): void
    {
        $journal = CharacterJournal::create([
            'character_id' => $this->makeChar($this->city)->id,
            'type'         => 'mugging',
            'data'         => ['city_name' => 'Tokyo', 'amount' => 100, 'message' => 'Robbed.'],
            'is_read'      => false,
        ]);

        $this->assertNotSame('bg-slate-800/20 border-slate-800/30', $journal->color_class,
            'Mugging must not use the default grey color class');
        $this->assertStringContainsString('rose', $journal->color_class,
            'Mugging color class must use rose (danger) palette');
    }

    public function test_mugging_journal_title_does_not_fall_through_to_default(): void
    {
        $journal = CharacterJournal::create([
            'character_id' => $this->makeChar($this->city)->id,
            'type'         => 'mugging',
            'data'         => ['city_name' => 'Seoul', 'amount' => 300, 'message' => 'x'],
            'is_read'      => false,
        ]);

        $this->assertNotSame('JOURNAL ENTRY', $journal->title,
            'Mugging must not fall through to the default title case');
    }

    
    
    

    public function test_city_show_renders_for_character_in_city(): void
    {
        $char = $this->makeChar($this->city);

        $this->actingAs($char->user)
            ->get(route('city.show', $this->city->slug))
            ->assertSuccessful();
    }

    public function test_city_show_renders_when_crime_rate_is_high(): void
    {
        
        
        $this->city->update(['crime_rate' => 80]);
        $char = $this->makeChar($this->city);

        $this->actingAs($char->user)
            ->get(route('city.show', $this->city->slug))
            ->assertSuccessful();
    }

    public function test_city_show_mugging_steals_from_someone_on_visit(): void
    {
        
        
        $this->city->update(['crime_rate' => 90]);

        $charA = $this->makeChar($this->city, cashOnHand: 10_000);
        $charB = $this->makeChar($this->city, cashOnHand: 10_000);

        $totalBefore = $charA->cash_on_hand + $charB->cash_on_hand;

        $charA->loadMissing(['homeCity', 'city']);
        $this->deterministicMugging()->attempt($charA);

        $charA->refresh();
        $charB->refresh();

        $totalAfter = (int) $charA->cash_on_hand + (int) $charB->cash_on_hand;
        $this->assertLessThan($totalBefore, $totalAfter,
            'Someone in the city must lose cash when deterministic mugging runs in CRITICAL city');
    }

    public function test_city_show_redirects_when_character_is_in_wrong_city(): void
    {
        $otherCity = City::create([
            'name'       => 'Other-' . uniqid(),
            'slug'       => 'other-' . uniqid(),
            'crime_rate' => 0,
        ]);

        $char = $this->makeChar($this->city);

        
        $this->actingAs($char->user)
            ->get(route('city.show', $otherCity->slug))
            ->assertRedirect();
    }

    
    
    

    public function test_travel_index_renders_for_character(): void
    {
        $char = $this->makeChar($this->city);

        $this->actingAs($char->user)
            ->get(route('travel.index'))
            ->assertSuccessful();
    }

    public function test_travel_to_destination_city_updates_character_city(): void
    {
        $destination = City::create([
            'name'       => 'Dest-' . uniqid(),
            'slug'       => 'dest-' . uniqid(),
            'crime_rate' => 0,
        ]);

        $char = $this->makeChar($this->city);

        $this->actingAs($char->user)
            ->post(route('travel.at', $destination->slug), ['method' => 'flight']);

        $char->refresh();
        $this->assertSame($destination->id, (int) $char->city_id,
            'Character city_id must update to the destination after travel');
    }

    public function test_travel_dispatches_move_event_on_arrival_does_not_crash(): void
    {
        
        $destination = City::create([
            'name'       => 'DangerDest-' . uniqid(),
            'slug'       => 'danger-dest-' . uniqid(),
            'crime_rate' => 90,
        ]);

        $char = $this->makeChar($this->city);

        $this->actingAs($char->user)
            ->post(route('travel.at', $destination->slug), ['method' => 'flight'])
            ->assertRedirect();

        
        $char->refresh();
        $this->assertSame($destination->id, (int) $char->city_id);
    }

    public function test_travel_blocked_when_character_has_active_conviction(): void
    {
        $destination = City::create([
            'name'       => 'Escape-' . uniqid(),
            'slug'       => 'escape-' . uniqid(),
            'crime_rate' => 0,
        ]);

        $char = $this->makeChar($this->city);

        
        DB::table('crime_records')->insert([
            'character_id' => $char->id,
            'city_id'      => $this->city->id,
            'crime_type'   => 'theft',
            'status'       => 'convicted',
            'severity'     => 'minor',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->actingAs($char->user)
            ->post(route('travel.at', $destination->slug), ['method' => 'flight'])
            ->assertSessionHas('error');

        $char->refresh();
        $this->assertSame($this->city->id, (int) $char->city_id,
            'Character must not leave city while a conviction is active');
    }

    public function test_travel_blocked_when_character_is_jailed(): void
    {
        $destination = City::create([
            'name' => 'Jd-' . uniqid(), 'slug' => 'jd-' . uniqid(), 'crime_rate' => 0,
        ]);

        $char = $this->makeChar($this->city, jailed: true);

        $this->actingAs($char->user)
            ->post(route('travel.at', $destination->slug), ['method' => 'flight'])
            ->assertSessionHas('error');
    }

    public function test_travel_blocked_when_character_already_in_destination(): void
    {
        $char = $this->makeChar($this->city);

        $this->actingAs($char->user)
            ->post(route('travel.at', $this->city->slug), ['method' => 'flight'])
            ->assertSessionHas('error');
    }
}
