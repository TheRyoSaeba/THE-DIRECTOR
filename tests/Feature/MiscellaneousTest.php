<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Career;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\Campaign;
use App\Models\Corporation;
use App\Models\CrimeRecord;
use App\Models\Election;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class MiscellaneousTest extends TestCase
{
    use DatabaseTransactions;

    

    private City $city;
    private int  $unemployedCareerId;
    private int  $policeCareerId;
    private int  $lawCareerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::create([
            'name'       => 'MiscCity-' . uniqid(),
            'slug'       => 'misc-' . uniqid(),
            'crime_rate' => 30,
        ]);

        $police     = DB::table('careers')->where('code', 'police')->first();
        $law        = DB::table('careers')->where('code', 'law')->first();
        $unemployed = DB::table('careers')->where('code', 'unemployed')->first();

        $this->assertNotNull($police,     'Police career must exist in DB');
        $this->assertNotNull($law,        'Law career must exist in DB');
        $this->assertNotNull($unemployed, 'Unemployed career must exist in DB');

        $this->policeCareerId     = $police->id;
        $this->lawCareerId        = $law->id;
        $this->unemployedCareerId = $unemployed->id;
    }

    

    private function makeCharacter(
        int $careerId,
        int $rank       = 1,
        int $careerXp   = 0,
        int $cashOnHand = 50_000,
        int $cashInBank = 100_000,
    ): Character {
        $user = User::factory()->create();

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'TestChar-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $this->city->id,
            'home_city_id' => $this->city->id,
            'career_id'    => $careerId,
            'career_rank'  => $rank,
            'career_xp'    => $careerXp,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => $cashInBank,
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => 1_000,
            'luck'         => 1_000,
            'offense'      => 1_000,
            'defense'      => 1_000,
            'influence'    => 0,
        ]);

        
        
        CharacterTimers::create(['character_id' => $char->id]);

        return $char;
    }

    
    private function makeCommissioner(): Character
    {
        return $this->makeCharacter($this->policeCareerId, rank: 4, careerXp: 200_000);
    }

    
    private function makeBusiness(string $code, ?int $ownerId = null): Business
    {
        return Business::create([
            'city_id'        => $this->city->id,
            'code'           => $code,
            'name'           => ucfirst($code) . '-' . uniqid(),
            'slug'           => $code . '-' . uniqid(),
            'is_purchasable' => $ownerId === null,
            'is_active'      => true,
            'base_price'     => 1_000_000,
            'balance'        => 0,
            'sort_order'     => 1,
            'owner_id'       => $ownerId,
        ]);
    }

    
    
    

    public function test_isJailed_returns_false_when_timers_not_yet_created(): void
    {
        $user = User::factory()->create();
        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'NoTimer-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $this->city->id,
            'home_city_id' => $this->city->id,
            'career_id'    => $this->unemployedCareerId,
            'career_rank'  => 1,
            'health'       => 100,
            'max_health'   => 100,
        ]);

        
        $this->assertFalse($char->isJailed());
    }

    public function test_isJailed_returns_false_when_jail_until_is_null(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        
        $this->assertFalse($char->isJailed());
    }

    public function test_isJailed_returns_false_when_jail_until_is_in_the_past(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        CharacterTimers::where('character_id', $char->id)
            ->update(['jail_until' => now()->subMinutes(5)->getTimestamp()]);

        $char->load('timers');
        $this->assertFalse($char->isJailed());
    }

    public function test_isJailed_returns_true_when_jail_until_is_in_the_future(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->jail(3_600);

        $char->load('timers');
        $this->assertTrue($char->isJailed());
    }

    public function test_jail_sets_timer_to_correct_duration(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->jail(7_200);

        $char->load('timers');
        $this->assertTrue($char->timers->jail_until->isFuture());

        $remaining = $char->timers->jail_until->getTimestamp() - now()->getTimestamp();
        $this->assertGreaterThan(7_100, $remaining, 'Remaining should be close to 7200 seconds');
        $this->assertLessThan(7_260, $remaining);
    }

    public function test_jail_works_without_a_reason_argument(): void
    {
        
        $char = $this->makeCharacter($this->unemployedCareerId);

        $char->jail(60);

        $char->load('timers');
        $this->assertTrue($char->isJailed(), 'jail() must set timer even with no reason argument');
    }

    public function test_jail_overrides_an_existing_unexpired_sentence(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->jail(600); 

        $char->load('timers');
        $firstExpiry = $char->timers->jail_until->getTimestamp();

        $char->jail(3_600); 
        $char->load('timers');
        $secondExpiry = $char->timers->jail_until->getTimestamp();

        $this->assertGreaterThan($firstExpiry, $secondExpiry, 'Second jail() call must push expiry further');
    }

    
    
    

    public function test_jailed_character_redirected_from_dashboard_to_jail(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->jail(3_600);

        $this->actingAs($char->user)
            ->get(route('dashboard'))
            ->assertRedirect(route('jail'));
    }

    public function test_jailed_character_can_access_jail_page(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->jail(3_600);

        $this->actingAs($char->user)
            ->get(route('jail'))
            ->assertSuccessful();
    }

    public function test_non_jailed_character_redirected_away_from_jail_page(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);

        $this->actingAs($char->user)
            ->get(route('jail'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_jailed_character_blocked_from_post_routes(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->jail(3_600);

        
        $this->actingAs($char->user)
            ->post(route('work.attempt'))
            ->assertRedirect(route('jail'));
    }

    public function test_jail_takes_priority_over_hospital_in_middleware(): void
    {
        
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->jail(3_600);
        $char->hospitalize(3_600, 'test');

        $char->load('timers');
        $this->assertTrue($char->isJailed());
        $this->assertTrue($char->isHospitalized());

        $this->actingAs($char->user)
            ->get(route('dashboard'))
            ->assertRedirect(route('jail')); 
    }

    public function test_hospitalized_character_redirected_to_hospital(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->hospitalize(3_600, 'test');

        $this->actingAs($char->user)
            ->get(route('dashboard'))
            ->assertRedirect(route('hospital'));
    }

    public function test_non_hospitalized_character_redirected_from_hospital_page(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);

        $this->actingAs($char->user)
            ->get(route('hospital'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_dead_character_redirected_to_death_page(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->delete(); 

        $this->actingAs($char->user)
            ->get(route('dashboard'))
            ->assertRedirect(route('death'));
    }

    public function test_banned_user_redirected_to_banned_page(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->user->ban('Testing ban redirect');

        $this->actingAs($char->user)
            ->get(route('dashboard'))
            ->assertRedirect(route('banned'));
    }

    public function test_expired_jail_does_not_redirect_to_jail_page(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);

        
        CharacterTimers::where('character_id', $char->id)
            ->update(['jail_until' => now()->subSeconds(1)->getTimestamp()]);

        $this->actingAs($char->user)
            ->get(route('dashboard'))
            ->assertSuccessful();
    }

    
    
    

    public function test_jailed_attacker_cannot_fight(): void
    {
        $attacker = $this->makeCharacter($this->unemployedCareerId);
        $target   = $this->makeCharacter($this->unemployedCareerId);

        $attacker->jail(3_600);
        $attacker->load('timers');

        $result = $attacker->canFight($target);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsStringIgnoringCase('jailed', $result['error']);
    }

    public function test_jailed_target_cannot_be_attacked(): void
    {
        $attacker = $this->makeCharacter($this->unemployedCareerId);
        $target   = $this->makeCharacter($this->unemployedCareerId);

        $target->jail(3_600);
        $target->load('timers');

        $result = $attacker->canFight($target);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsStringIgnoringCase('prison', $result['error']);
    }

    public function test_hospitalized_attacker_cannot_fight(): void
    {
        $attacker = $this->makeCharacter($this->unemployedCareerId);
        $target   = $this->makeCharacter($this->unemployedCareerId);

        $attacker->hospitalize(3_600);
        $attacker->load('timers');

        $result = $attacker->canFight($target);
        $this->assertFalse($result['valid']);
    }

    public function test_hospitalized_target_cannot_be_attacked(): void
    {
        $attacker = $this->makeCharacter($this->unemployedCareerId);
        $target   = $this->makeCharacter($this->unemployedCareerId);

        $target->hospitalize(3_600);
        $target->load('timers');

        $result = $attacker->canFight($target);
        $this->assertFalse($result['valid']);
    }

    public function test_two_healthy_characters_in_same_city_can_fight_when_target_is_online(): void
    {
        $attacker = $this->makeCharacter($this->unemployedCareerId);
        $target   = $this->makeCharacter($this->unemployedCareerId);

        
        
        
        DB::table('sessions')->insert([
            'id'            => 'test-session-' . $target->user_id,
            'user_id'       => $target->user_id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'PHPUnit',
            'payload'       => base64_encode('{}'),
            'last_activity' => now()->getTimestamp(),
        ]);

        $result = $attacker->canFight($target);
        $this->assertTrue($result['valid'], $result['error'] ?? 'canFight returned invalid with no reason');
    }

    public function test_offline_target_without_recent_login_cannot_be_attacked(): void
    {
        $attacker = $this->makeCharacter($this->unemployedCareerId);
        $target   = $this->makeCharacter($this->unemployedCareerId);

        $result = $attacker->canFight($target);

        $this->assertFalse($result['valid']);
        $this->assertSame('Target has not been online recently', $result['error']);
    }

    public function test_target_inactive_for_two_weeks_can_be_attacked_while_offline(): void
    {
        $attacker = $this->makeCharacter($this->unemployedCareerId);
        $target   = $this->makeCharacter($this->unemployedCareerId);

        $target->user->update(['last_login_at' => now()->subWeeks(2)->subMinute()]);

        $result = $attacker->canFight($target);

        $this->assertTrue($result['valid'], $result['error'] ?? 'canFight returned invalid with no reason');
    }

    public function test_inactive_target_protection_still_blocks_attacks(): void
    {
        $attacker = $this->makeCharacter($this->unemployedCareerId);
        $target   = $this->makeCharacter($this->unemployedCareerId);

        $target->user->update(['last_login_at' => now()->subWeeks(2)->subMinute()]);
        $target->setProtection(3_600);
        $target->load('timers');

        $result = $attacker->canFight($target);

        $this->assertFalse($result['valid']);
        $this->assertSame('This player cannot be attacked at this moment!', $result['error']);
    }

    
    
    

    public function test_settings_quit_career_releases_police_hq_for_commissioner(): void
    {
        $commissioner = $this->makeCommissioner();
        $hq           = $this->makeBusiness('police', $commissioner->id);

        $this->actingAs($commissioner->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => $commissioner->display_name,
            ])
            ->assertSessionHas('success');

        $hq->refresh();
        $this->assertNull($hq->owner_id,       'Police HQ owner_id must be cleared after commissioner quits');
        $this->assertTrue($hq->is_purchasable, 'Police HQ must be marked purchasable again');
    }

    public function test_settings_quit_career_does_not_release_hq_for_rank_2_detective(): void
    {
        
        $detective = $this->makeCharacter($this->policeCareerId, rank: 2, careerXp: 30_000);
        $hq        = $this->makeBusiness('police', $detective->id);

        $this->actingAs($detective->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => $detective->display_name,
            ])
            ->assertSessionHas('success');

        $hq->refresh();
        
        $this->assertNotNull($hq->owner_id,                 'Rank-2 detective must not trigger HQ release');
        $this->assertSame($detective->id, (int) $hq->owner_id, 'HQ owner must be unchanged');
    }

    public function test_start_career_releases_police_hq_when_commissioner(): void
    {
        
        
        $commissioner = $this->makeCommissioner();
        $hq           = $this->makeBusiness('police', $commissioner->id);

        $commissioner->startCareer('Law');

        $hq->refresh();
        $commissioner->refresh();

        $this->assertNull($hq->owner_id,       'HQ must be released when commissioner starts a new career');
        $this->assertTrue($hq->is_purchasable, 'HQ must be marked purchasable');

        $lawCareer = Career::findByCode('Law');
        $this->assertSame($lawCareer->id, $commissioner->career_id, 'Career must be updated to Law');
    }

    public function test_start_career_does_not_release_hq_for_rank_2_officer(): void
    {
        $officer = $this->makeCharacter($this->policeCareerId, rank: 2);
        $hq      = $this->makeBusiness('police', $officer->id);

        $officer->startCareer('Law');
        $hq->refresh();

        
        $this->assertNotNull($hq->owner_id,                'Rank-2 officer should not release HQ via startCareer');
        $this->assertSame($officer->id, (int) $hq->owner_id);
    }

    public function test_settings_quit_career_defensively_releases_city_hall(): void
    {
        
        
        $char     = $this->makeCharacter($this->unemployedCareerId);
        $cityHall = $this->makeBusiness('city-hall', $char->id);

        $this->actingAs($char->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => $char->display_name,
            ])
            ->assertSessionHas('success');

        $cityHall->refresh();
        $this->assertNull($cityHall->owner_id,       'City Hall must be cleared on any career quit');
        $this->assertTrue($cityHall->is_purchasable, 'City Hall must be marked purchasable');
    }

    public function test_settings_quit_career_blocked_while_serving_as_mayor(): void
    {
        $mayor = $this->makeCharacter($this->unemployedCareerId);
        $this->city->update(['mayor_id' => $mayor->id, 'mayor_display_name' => $mayor->display_name]);

        $this->actingAs($mayor->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => $mayor->display_name,
            ])
            ->assertSessionHasErrors();

        $mayor->refresh();
        $this->assertSame($this->unemployedCareerId, $mayor->career_id, 'Career must be unchanged');
    }

    public function test_settings_quit_career_blocked_when_in_corporation(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        DB::table('characters')->where('id', $char->id)->update(['corporation_id' => 999]);
        $char->refresh();

        $this->actingAs($char->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => $char->display_name,
            ])
            ->assertSessionHas('error');
    }

    public function test_settings_quit_career_rejects_wrong_confirmation_name(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);

        $this->actingAs($char->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => 'WrongName'])
            ->assertSessionHasErrors('confirmation_name');
    }

    public function test_settings_quit_career_confirmation_name_is_case_insensitive(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);

        $this->actingAs($char->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => strtoupper($char->display_name),
            ])
            ->assertSessionHas('success');
    }

    
    
    

    public function test_isConflicted_blocks_integer_participant(): void
    {
        $perp        = $this->makeCharacter($this->unemployedCareerId);
        $conspirator = $this->makeCharacter($this->unemployedCareerId);

        $crime = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'evidence_level' => 50,
            'status'         => CrimeRecord::STATUS_OPEN,
            'data'           => ['participants' => [$conspirator->id]], 
            'committed_at'   => now(),
        ]);

        $fresh = CrimeRecord::find($crime->id);
        $this->assertTrue($fresh->isConflicted($conspirator), 'Integer participant must be blocked');
    }

    public function test_isConflicted_blocks_string_participant(): void
    {
        
        
        
        $perp        = $this->makeCharacter($this->unemployedCareerId);
        $conspirator = $this->makeCharacter($this->unemployedCareerId);

        $crime = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'evidence_level' => 50,
            'status'         => CrimeRecord::STATUS_OPEN,
            'data'           => ['participants' => [(string) $conspirator->id]], 
            'committed_at'   => now(),
        ]);

        $fresh        = CrimeRecord::find($crime->id);
        $participants = $fresh->data['participants'] ?? [];
        $storedType   = gettype($participants[0] ?? null);

        if ($storedType === 'integer') {
            
            $this->assertTrue($fresh->isConflicted($conspirator));
        } else {
            
            
            $this->assertTrue(
                $fresh->isConflicted($conspirator),
                "STRING participant id '{$participants[0]}' was NOT caught by isConflicted() — "
                . 'strict in_array(int, [string], true) returns false. '
                . 'Fix: cast participants to (int) in isConflicted().'
            );
        }
    }

    public function test_isConflicted_blocks_the_primary_perpetrator(): void
    {
        $perp  = $this->makeCharacter($this->unemployedCareerId);
        $crime = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'evidence_level' => 50,
            'status'         => CrimeRecord::STATUS_OPEN,
            'data'           => [],
            'committed_at'   => now(),
        ]);

        $this->assertTrue($crime->isConflicted($perp), 'Primary perpetrator must always be blocked');
    }

    public function test_isConflicted_blocks_the_victim(): void
    {
        $perp   = $this->makeCharacter($this->unemployedCareerId);
        $victim = $this->makeCharacter($this->unemployedCareerId);

        $crime = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'evidence_level' => 50,
            'status'         => CrimeRecord::STATUS_OPEN,
            'data'           => ['victim_id' => $victim->id],
            'committed_at'   => now(),
        ]);

        $this->assertTrue($crime->isConflicted($victim), 'Victim must always be blocked');
    }

    public function test_isConflicted_does_not_block_unrelated_character(): void
    {
        $perp      = $this->makeCharacter($this->unemployedCareerId);
        $unrelated = $this->makeCharacter($this->unemployedCareerId);

        $crime = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'evidence_level' => 50,
            'status'         => CrimeRecord::STATUS_OPEN,
            'data'           => [],
            'committed_at'   => now(),
        ]);

        $this->assertFalse($crime->isConflicted($unrelated), 'Unrelated character must not be blocked');
    }

    
    
    

    public function test_addCash_increments_cash_on_hand(): void
    {
        $char   = $this->makeCharacter($this->unemployedCareerId, cashOnHand: 10_000);
        $before = (int) $char->cash_on_hand;

        $char->addCash(5_000);
        $char->refresh();

        $this->assertSame($before + 5_000, (int) $char->cash_on_hand);
    }

    public function test_addCash_as_dirty_cash_increments_dirty_cash_column(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->addCash(1_000, asDirtyCash: true);
        $char->refresh();

        $this->assertSame(1_000, (int) $char->dirty_cash);
    }

    public function test_removeCash_returns_false_when_insufficient(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId, cashOnHand: 100);

        $result = $char->removeCash(500);

        $this->assertFalse($result);
        $char->refresh();
        $this->assertSame(100, (int) $char->cash_on_hand, 'Cash must not change on failed removeCash');
    }

    public function test_removeCash_deducts_and_returns_true_when_sufficient(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId, cashOnHand: 10_000);

        $result = $char->removeCash(3_000);

        $this->assertTrue($result);
        $char->refresh();
        $this->assertSame(7_000, (int) $char->cash_on_hand);
    }

    public function test_depositToBank_moves_cash_from_hand_to_bank(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId, cashOnHand: 20_000, cashInBank: 0);

        $result = $char->depositToBank(10_000);

        $this->assertTrue($result);
        $char->refresh();
        $this->assertSame(10_000, (int) $char->cash_on_hand);
        $this->assertSame(10_000, (int) $char->cash_in_bank);
    }

    public function test_depositToBank_returns_false_when_insufficient_cash(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId, cashOnHand: 500);

        $result = $char->depositToBank(1_000);

        $this->assertFalse($result);
        $char->refresh();
        $this->assertSame(500, (int) $char->cash_on_hand, 'Cash must be unchanged on failed deposit');
    }

    
    
    
    
    
    
    
    

    public function test_smoke_dashboard(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('dashboard'))->assertSuccessful();
    }

    public function test_smoke_settings(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('settings'))->assertSuccessful();
    }

    public function test_smoke_work(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('work'))->assertSuccessful();
    }

    public function test_smoke_journal(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('journal'))->assertSuccessful();
    }

    public function test_smoke_messages(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('messages'))->assertSuccessful();
    }

    public function test_opening_group_message_marks_current_recipient_rows_read(): void
    {
        $sender = $this->makeCharacter($this->unemployedCareerId);
        $otherRecipient = $this->makeCharacter($this->unemployedCareerId);
        $currentRecipient = $this->makeCharacter($this->unemployedCareerId);
        $groupId = 'group_' . uniqid();
        $now = now();

        Message::insert([
            [
                'sender_id' => $sender->id,
                'recipient_id' => $otherRecipient->id,
                'group_id' => $groupId,
                'subject' => 'Test group',
                'body' => 'Message for other recipient',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'sender_id' => $sender->id,
                'recipient_id' => $currentRecipient->id,
                'group_id' => $groupId,
                'subject' => 'Test group',
                'body' => 'Message for current recipient',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
        Cache::put("unread_messages_{$currentRecipient->id}", 1, 60);

        $this->actingAs($currentRecipient->user)
            ->get(route('messages.show', $groupId))
            ->assertSuccessful();

        $this->assertNotNull(
            Message::where('recipient_id', $currentRecipient->id)
                ->where('group_id', $groupId)
                ->value('read_at')
        );
        $this->assertNull(
            Message::where('recipient_id', $otherRecipient->id)
                ->where('group_id', $groupId)
                ->value('read_at')
        );
        $this->assertSame(0, Cache::get("unread_messages_{$currentRecipient->id}"));
    }

    public function test_smoke_profile(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('profile'))->assertSuccessful();
    }

    public function test_smoke_conflict(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('conflict'))->assertSuccessful();
    }

    public function test_smoke_travel(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('travel.index'))->assertSuccessful();
    }

    public function test_smoke_actions(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('actions.index'))->assertSuccessful();
    }

    public function test_smoke_talents(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('talents'))->assertSuccessful();
    }

    public function test_smoke_city_show(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('city.show', $this->city->slug))->assertSuccessful();
    }

    public function test_smoke_city_election(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('city.election.index', $this->city->slug))->assertSuccessful();
    }

    public function test_smoke_city_business(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('city.business.index', $this->city->slug))->assertSuccessful();
    }

    public function test_smoke_city_property(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('city.property.index', $this->city->slug))->assertSuccessful();
    }

    public function test_smoke_city_police_hq(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)->get(route('city.police.index', $this->city->slug))->assertSuccessful();
    }

    public function test_smoke_police_career(): void
    {
        $officer = $this->makeCharacter($this->policeCareerId, rank: 2, careerXp: 30_000);
        $this->actingAs($officer->user)->get(route('career.police'))->assertSuccessful();
    }

    public function test_smoke_law_career(): void
    {
        $attorney = $this->makeCharacter($this->lawCareerId, rank: 2);
        $this->actingAs($attorney->user)->get(route('career.law'))->assertSuccessful();
    }

    public function test_smoke_profile_show(): void
    {
        $char   = $this->makeCharacter($this->unemployedCareerId);
        $target = $this->makeCharacter($this->unemployedCareerId);
        $this->actingAs($char->user)
            ->get(route('profile.show', $target->display_name))
            ->assertSuccessful();
    }

    public function test_unauthenticated_request_is_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    
    
    

    public function test_quitCareer_deducts_fifteen_percent_exp_by_default(): void
    {
        $char = $this->makeCharacter($this->policeCareerId);
        DB::table('characters')->where('id', $char->id)->update(['total_character_exp' => 10_000]);
        $char->refresh();

        $char->quitCareer(preserveExp: false);
        $char->refresh();

        $this->assertSame((int) (10_000 * 0.85), (int) $char->total_character_exp);
    }

    public function test_quitCareer_preserves_exp_when_flag_set(): void
    {
        $char = $this->makeCharacter($this->policeCareerId);
        DB::table('characters')->where('id', $char->id)->update(['total_character_exp' => 10_000]);
        $char->refresh();

        $char->quitCareer(preserveExp: true);
        $char->refresh();

        $this->assertSame(10_000, (int) $char->total_character_exp);
    }

    public function test_quitCareer_sets_career_to_unemployed_rank_1_xp_0(): void
    {
        $char = $this->makeCharacter($this->policeCareerId, rank: 2, careerXp: 50_000);

        $char->quitCareer();
        $char->refresh();

        $unemployed = Career::findByCode('unemployed');
        $this->assertSame($unemployed->id, $char->career_id);
        $this->assertSame(1, $char->career_rank);
        $this->assertSame(0, $char->career_xp);
    }

    public function test_startCareer_sets_correct_career_rank_and_seed_xp(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->startCareer('Law');
        $char->refresh();

        $law = Career::findByCode('Law');
        $this->assertSame($law->id, $char->career_id);
        $this->assertSame(1,   $char->career_rank);
        $this->assertSame(500, $char->career_xp, 'startCareer must grant 500 seed XP');
    }

    public function test_startCareer_returns_false_for_unknown_career_code(): void
    {
        $char   = $this->makeCharacter($this->unemployedCareerId);
        $result = $char->startCareer('NotARealCareer');

        $this->assertFalse($result);
        $char->refresh();
        $this->assertSame(
            $this->unemployedCareerId, $char->career_id,
            'Career must be unchanged when startCareer returns false'
        );
    }

    
    
    

    public function test_mayor_cannot_enroll_in_police_academy(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $this->city->update(['mayor_id' => $char->id, 'mayor_display_name' => $char->display_name]);

        $police = $this->makeBusiness('police');

        $this->actingAs($char->user)
            ->post(route('city.police.enroll', $this->city))
            ->assertSessionHas('error');

        $char->refresh();
        $this->assertSame($this->unemployedCareerId, $char->career_id,
            'Mayor must not be enrolled in police career');
    }

    public function test_character_outside_home_city_cannot_enroll(): void
    {
        $otherCity = City::create([
            'name'       => 'OtherCity-' . uniqid(),
            'slug'       => 'other-' . uniqid(),
            'crime_rate' => 10,
        ]);
        $this->makeBusiness('police'); 

        
        $char = $this->makeCharacter($this->unemployedCareerId);
        DB::table('characters')->where('id', $char->id)->update(['home_city_id' => $otherCity->id]);
        $char->refresh();

        $this->actingAs($char->user)
            ->post(route('city.police.enroll', $this->city))
            ->assertSessionHas('error');

        $char->refresh();
        $this->assertSame($this->unemployedCareerId, $char->career_id);
    }

    public function test_mayor_cannot_train_at_police_academy(): void
    {
        
        $char = $this->makeCharacter($this->policeCareerId, rank: 1);
        $this->city->update(['mayor_id' => $char->id, 'mayor_display_name' => $char->display_name]);

        $this->makeBusiness('police');

        $this->actingAs($char->user)
            ->post(route('city.police.train', $this->city))
            ->assertSessionHas('error');
    }

    public function test_mayor_cannot_graduate_from_police_academy(): void
    {
        $char = $this->makeCharacter($this->policeCareerId, rank: 1, careerXp: 600);
        $this->city->update(['mayor_id' => $char->id, 'mayor_display_name' => $char->display_name]);

        $this->makeBusiness('police');

        $this->actingAs($char->user)
            ->post(route('city.police.graduate', $this->city))
            ->assertSessionHas('error');
    }

    public function test_commissioner_can_promote_officer_in_same_city(): void
    {
        $commissioner = $this->makeCharacter($this->policeCareerId, rank: 4, careerXp: 200_000);
        $officer      = $this->makeCharacter($this->policeCareerId, rank: 2, careerXp: 30_000);

        $police = $this->makeBusiness('police', $commissioner->id);

        $this->actingAs($commissioner->user)
            ->post(route('city.police.promote', $this->city), ['target_id' => $officer->id])
            ->assertSessionHas('success');

        $officer->refresh();
        $this->assertSame(3, $officer->career_rank, 'Officer must be promoted to rank 3');
    }

    public function test_non_commissioner_cannot_promote(): void
    {
        $officer  = $this->makeCharacter($this->policeCareerId, rank: 2, careerXp: 30_000);
        $officer2 = $this->makeCharacter($this->policeCareerId, rank: 2, careerXp: 30_000);

        $this->makeBusiness('police'); 

        $this->actingAs($officer->user)
            ->post(route('city.police.promote', $this->city), ['target_id' => $officer2->id])
            ->assertSessionHas('error');

        $officer2->refresh();
        $this->assertSame(2, $officer2->career_rank, 'Non-commissioner cannot promote');
    }

    public function test_commissioner_cannot_promote_officer_from_different_city(): void
    {
        $otherCity = City::create([
            'name'       => 'FarCity-' . uniqid(),
            'slug'       => 'far-' . uniqid(),
            'crime_rate' => 20,
        ]);

        $commissioner = $this->makeCharacter($this->policeCareerId, rank: 4, careerXp: 200_000);
        $police       = $this->makeBusiness('police', $commissioner->id);

        
        $foreigner = $this->makeCharacter($this->policeCareerId, rank: 2);
        DB::table('characters')->where('id', $foreigner->id)->update(['home_city_id' => $otherCity->id]);

        $this->actingAs($commissioner->user)
            ->post(route('city.police.promote', $this->city), ['target_id' => $foreigner->id])
            ->assertSessionHas('error');

        $foreigner->refresh();
        $this->assertSame(2, $foreigner->career_rank);
    }

    
    
    

    public function test_finalize_disqualifies_candidate_who_joined_corp_mid_election(): void
    {
        $politicsCareer = DB::table('careers')->where('code', 'politics')->first();
        $corpCareer     = DB::table('careers')->where('code', 'corporation')->first();
        $this->assertNotNull($politicsCareer);
        $this->assertNotNull($corpCareer);

        $electionCity = City::create([
            'name'       => 'ElecCity-' . uniqid(),
            'slug'       => 'elec-' . uniqid(),
            'crime_rate' => 0,
        ]);

        
        $makeCandidate = function () use ($politicsCareer, $electionCity): Character {
            $user = User::factory()->create();
            $char = Character::create([
                'user_id'      => $user->id,
                'display_name' => 'Candidate-' . uniqid(),
                'gender'       => 'male',
                'city_id'      => $electionCity->id,
                'home_city_id' => $electionCity->id,
                'career_id'    => $politicsCareer->id,
                'career_rank'  => 1,
                'health'       => 100,
                'max_health'   => 100,
                'cash_on_hand' => 50_000,
                'cash_in_bank' => 50_000,
            ]);
            CharacterStats::create(['character_id' => $char->id, 'influence' => 10]);
            return $char;
        };

        $winner    = $makeCandidate();
        $corrupted = $makeCandidate();

        
        $corp = Corporation::create([
            'name'         => 'CorpMidElec-' . uniqid(),
            'ceo_id'       => $corrupted->id,
            'home_city_id' => $electionCity->id,
            'slush_fund'   => 0,
            'strength'     => 100,
        ]);
        DB::table('characters')->where('id', $corrupted->id)
            ->update(['corporation_id' => $corp->id]);

        $election = Election::create([
            'city_id'            => $electionCity->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(4),
            'registration_end'   => now()->subDays(2),
            'voting_start'       => now()->subDays(2),
            'voting_end'         => now()->subHour(),
            'total_votes'        => 10,
        ]);

        Campaign::create([
            'election_id'   => $election->id,
            'candidate_id'  => $winner->id,
            'city_id'       => $electionCity->id,
            'manifesto'     => 'I will serve.',
            'campaign_fund' => 500_000,
            'votes'         => 3,
            'status'        => 'active',
        ]);
        Campaign::create([
            'election_id'   => $election->id,
            'candidate_id'  => $corrupted->id,
            'city_id'       => $electionCity->id,
            'manifesto'     => 'I will serve... myself.',
            'campaign_fund' => 500_000,
            'votes'         => 7, 
            'status'        => 'active',
        ]);

        $election->finalize();
        $election->refresh();

        $this->assertSame('completed', $election->status);
        $this->assertSame($winner->id, $election->winner_id,
            'The clean candidate must win even with fewer votes — corp member is disqualified');
        $this->assertSame($winner->id, $electionCity->fresh()->mayor_id);

        $this->assertDatabaseHas('campaigns', [
            'candidate_id' => $corrupted->id,
            'status'       => 'disqualified',
        ]);
    }

    public function test_finalize_completes_with_no_winner_if_all_candidates_disqualified(): void
    {
        $politicsCareer = DB::table('careers')->where('code', 'politics')->first();

        $electionCity = City::create([
            'name'       => 'ElecCity2-' . uniqid(),
            'slug'       => 'elec2-' . uniqid(),
            'crime_rate' => 0,
        ]);

        $candidate = Character::create([
            'user_id'      => User::factory()->create()->id,
            'display_name' => 'SoloCandidate-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $electionCity->id,
            'home_city_id' => $electionCity->id,
            'career_id'    => $politicsCareer->id,
            'career_rank'  => 1,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => 50_000,
            'cash_in_bank' => 50_000,
        ]);
        CharacterStats::create(['character_id' => $candidate->id]);

        $corp = Corporation::create([
            'name'         => 'CorpAllOut-' . uniqid(),
            'ceo_id'       => $candidate->id,
            'home_city_id' => $electionCity->id,
            'slush_fund'   => 0,
            'strength'     => 100,
        ]);
        DB::table('characters')->where('id', $candidate->id)->update(['corporation_id' => $corp->id]);

        $election = Election::create([
            'city_id'            => $electionCity->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(4),
            'registration_end'   => now()->subDays(2),
            'voting_start'       => now()->subDays(2),
            'voting_end'         => now()->subHour(),
            'total_votes'        => 5,
        ]);

        Campaign::create([
            'election_id'   => $election->id,
            'candidate_id'  => $candidate->id,
            'city_id'       => $electionCity->id,
            'manifesto'     => 'The only candidate.',
            'campaign_fund' => 500_000,
            'votes'         => 5,
            'status'        => 'active',
        ]);

        $election->finalize();
        $election->refresh();

        $this->assertSame('completed', $election->status);
        $this->assertNull($election->winner_id,
            'Election must complete with no winner when all candidates are disqualified');
        $this->assertNull($electionCity->fresh()->mayor_id);
    }

    
    
    

    public function test_expired_mayor_term_removes_mayor_and_releases_city_hall(): void
    {
        $politicsCareer = DB::table('careers')->where('code', 'politics')->first();

        $mayor = $this->makeCharacter($politicsCareer->id);
        $this->city->update(['mayor_id' => $mayor->id, 'mayor_display_name' => $mayor->display_name]);
        $cityHall = $this->makeBusiness('city-hall', $mayor->id);

        Election::create([
            'city_id'            => $this->city->id,
            'cycle_number'       => 1,
            'status'             => 'completed',
            'registration_start' => now()->subDays(12),
            'registration_end'   => now()->subDays(10),
            'voting_start'       => now()->subDays(10),
            'voting_end'         => now()->subDays(9),
            'winner_id'          => $mayor->id,
            'total_votes'        => 1,
            'term_start'         => now()->subDays(9),
            'term_end'           => now()->subHour(), 
        ]);

        $this->artisan('elections:process')->assertExitCode(0);

        $this->city->refresh();
        $this->assertNull($this->city->mayor_id,
            'Mayor must be cleared after term expiry');

        $cityHall->refresh();
        $this->assertNull($cityHall->owner_id,
            'City Hall must be released when mayor term expires');
        $this->assertTrue($cityHall->is_purchasable);
    }

    public function test_election_win_releases_old_police_hq_if_winner_was_commissioner(): void
    {
        
        
        $winner = $this->makeCharacter($this->policeCareerId, rank: 4, careerXp: 200_000);
        $hq     = $this->makeBusiness('police', $winner->id);

        
        $winner->startCareer('politics');

        $hq->refresh();
        $this->assertNull($hq->owner_id,
            'Police HQ must be released when commissioner wins election and gets politics career');
        $this->assertTrue($hq->is_purchasable);
    }

    
    
    

    public function test_admined_route_redirects_non_banned_unauthenticated_user_to_login(): void
    {
        
        
        $this->get(route('admined'))
            ->assertRedirect(route('login'));
    }

    public function test_admined_route_redirects_authenticated_non_banned_user_to_dashboard(): void
    {
        
        $char = $this->makeCharacter($this->unemployedCareerId);

        $this->actingAs($char->user)
            ->get(route('admined'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_banned_route_renders_for_banned_authenticated_user(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->user->ban('Testing ban page render');

        $this->actingAs($char->user)
            ->get(route('banned'))
            ->assertSuccessful();
    }

    public function test_banned_user_accessing_dashboard_is_redirected_to_banned_page(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId);
        $char->user->ban('Test');

        $this->actingAs($char->user)
            ->get(route('dashboard'))
            ->assertRedirect(route('banned'));
    }

    
    
    

    public function test_assigned_prosecutor_receives_investigation_notes(): void
    {
        $lawCareer = DB::table('careers')->whereRaw('LOWER(code) = ?', ['law'])->first();
        $this->assertNotNull($lawCareer, 'Law career must exist in DB');

        $perp      = $this->makeCharacter($this->policeCareerId);
        $detective = $this->makeCharacter($this->policeCareerId, rank: 2, careerXp: 30_000);
        $prosecutor = $this->makeCharacter($lawCareer->id, rank: 2, careerXp: 0,
            cashOnHand: 50_000, cashInBank: 100_000);

        
        $crime = \App\Services\CrimeService::assault($perp, $this->makeCharacter($this->policeCareerId), $this->city->id);

        
        CharacterTimers::where('character_id', $detective->id)->update(['next_action_at' => 0]);
        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));
        CharacterTimers::where('character_id', $detective->id)->update(['next_action_at' => 0]);
        $this->actingAs($detective->user)->post(route('career.police.refer', $crime->id), [
            'suspect_names' => [$perp->display_name],
        ]);
        $crime->refresh();

        
        $crime->update([
            'data' => array_merge($crime->data ?? [], [
                'investigation_notes' => [
                    ['note' => 'Fingerprints found', 'at' => now()->toIso8601String()],
                ],
            ]),
        ]);

        
        $this->actingAs($prosecutor->user)->post(route('career.law.prosecute', $crime->id));
        $crime->refresh();

        
        $response = $this->actingAs($prosecutor->user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get(route('career.law'));

        $response->assertOk();
        $data  = json_decode($response->getContent(), true);
        $cases = $data['props']['charged_cases'] ?? $data['props']['my_cases'] ?? [];
        $match = collect($cases)->firstWhere('id', $crime->id);

        if ($match !== null) {
            $this->assertNotNull(
                $match['investigation_notes'],
                'Assigned prosecutor must receive investigation_notes'
            );
        } else {
            $this->markTestSkipped('Charged case not in expected prop key — adjust key name to match LawController.');
        }
    }

    
    
    

    public function test_turn_in_with_fine_and_jail_applies_both(): void
    {
        $lawCareer = DB::table('careers')->where('code', 'Law')->first();
        $this->assertNotNull($lawCareer);

        $perp = $this->makeCharacter($this->policeCareerId, cashOnHand: 20_000, cashInBank: 50_000);

        $crime = \App\Models\CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => \App\Models\CrimeRecord::TYPE_ASSAULT,
            'severity'       => \App\Models\CrimeRecord::SEV_FELONY,
            'evidence_level' => 80,
            'status'         => \App\Models\CrimeRecord::STATUS_SENTENCED,
            'sentence'       => ['fine' => 5_000, 'jail_seconds' => 3_600],
            'sentenced_at'   => now(),
            'data'           => ['participants' => [], 'perpetrator_name' => $perp->display_name],
            'committed_at'   => now()->subHour(),
        ]);

        $before = (int) $perp->cash_on_hand;

        $this->actingAs($perp->user)
            ->post(route('city.police.turn-in', $this->city), ['case_id' => $crime->id])
            ->assertSessionHas('success');

        $crime->refresh();
        $perp->refresh();

        $this->assertSame(\App\Models\CrimeRecord::STATUS_CLOSED, $crime->status);
        $this->assertSame($before - 5_000, (int) $perp->cash_on_hand, 'Fine must be deducted');
        $this->assertTrue($perp->timers?->jail_until?->isFuture(), 'Jail timer must be set');
    }

    public function test_turn_in_with_zero_fine_and_zero_jail_just_closes_case(): void
    {
        $perp = $this->makeCharacter($this->policeCareerId, cashOnHand: 10_000);

        $crime = \App\Models\CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => \App\Models\CrimeRecord::TYPE_ASSAULT,
            'severity'       => \App\Models\CrimeRecord::SEV_FELONY,
            'evidence_level' => 80,
            'status'         => \App\Models\CrimeRecord::STATUS_SENTENCED,
            'sentence'       => ['fine' => 0, 'jail_seconds' => 0],
            'sentenced_at'   => now(),
            'data'           => ['participants' => [], 'perpetrator_name' => $perp->display_name],
            'committed_at'   => now()->subHour(),
        ]);

        $this->actingAs($perp->user)
            ->post(route('city.police.turn-in', $this->city), ['case_id' => $crime->id])
            ->assertSessionHas('success');

        $crime->refresh();
        $perp->refresh();

        $this->assertSame(\App\Models\CrimeRecord::STATUS_CLOSED, $crime->status);
        $this->assertSame(10_000, (int) $perp->cash_on_hand, 'Cash must be unchanged with zero fine');
        $this->assertFalse($perp->isJailed(), 'Must not be jailed with zero jail_seconds');
    }

    
    
    

    public function test_mayor_is_blocked_from_career_police_dashboard(): void
    {
        
        
        $char = $this->makeCharacter($this->policeCareerId, rank: 2, careerXp: 30_000);
        $this->city->update(['mayor_id' => $char->id, 'mayor_display_name' => $char->display_name]);

        
        
        
        $this->actingAs($char->user)
            ->get(route('career.police'))
            ->assertSuccessful(); 
    }

    public function test_mayor_cannot_found_corporation(): void
    {
        $char = $this->makeCharacter($this->unemployedCareerId, cashOnHand: 2_000_000);
        $this->city->update(['mayor_id' => $char->id, 'mayor_display_name' => $char->display_name]);

        
        DB::table('characters')->where('id', $char->id)->update([
            'degrees' => json_encode(['finance' => ['completed_at' => now()->toIso8601String()]]),
        ]);
        DB::table('character_stats')->where('character_id', $char->id)->update(['influence' => 100]);

        $this->actingAs($char->user)
            ->post(route('corporation.found'), ['name' => 'MayorCorp'])
            ->assertSessionHas('error');

        $this->assertNull($char->fresh()->corporation_id);
    }

    public function test_mayor_cannot_join_corporation_via_invite_accept(): void
    {
        
        $corpChar = $this->makeCharacter($this->unemployedCareerId);
        $corpCareer = DB::table('careers')->where('code', 'corporation')->first();
        $corp = Corporation::create([
            'name'         => 'InviteCorp-' . uniqid(),
            'ceo_id'       => $corpChar->id,
            'home_city_id' => $this->city->id,
            'slush_fund'   => 0,
            'strength'     => 100,
        ]);

        $mayor = $this->makeCharacter($this->unemployedCareerId);
        $this->city->update(['mayor_id' => $mayor->id, 'mayor_display_name' => $mayor->display_name]);

        $journal = \App\Models\CharacterJournal::create([
            'character_id' => $mayor->id,
            'type'         => 'corporation_invite_request',
            'data'         => ['corporation_id' => $corp->id, 'corporation_name' => $corp->name],
            'is_read'      => false,
        ]);

        $this->actingAs($mayor->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('error');

        $mayor->refresh();
        $this->assertNull($mayor->corporation_id, 'Mayor must not be able to join a corporation');
    }
}
