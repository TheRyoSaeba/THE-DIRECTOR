<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Career;
use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\Leaderboard;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class HospitalReviveTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;
    private int  $healthcareId;
    private int  $unemployedId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::create([
            'name'       => 'ReviveCity-' . uniqid(),
            'slug'       => 'revive-city-' . uniqid(),
            'crime_rate' => 0,
        ]);

        $this->healthcareId = DB::table('careers')->where('code', 'healthcare')->value('id');
        $this->unemployedId = DB::table('careers')->where('code', 'unemployed')->value('id');

        $this->assertNotNull($this->healthcareId, 'healthcare career must be seeded');
        $this->assertNotNull($this->unemployedId,  'unemployed career must be seeded');
    }

    

    private function makeCharacter(
        int    $careerId   = 0,
        int    $rank       = 1,
        int    $cash       = 500_000,
        int    $xp        = 200_000,
        int    $totalXp   = 300_000,
        int    $health    = 100,
        int    $maxHealth = 100,
        ?string $talent   = null,
    ): Character {
        $user = User::factory()->create();

        $char = Character::create([
            'user_id'             => $user->id,
            'display_name'        => 'TC-' . uniqid(),
            'gender'              => 'male',
            'city_id'             => $this->city->id,
            'home_city_id'        => $this->city->id,
            'career_id'           => $careerId ?: $this->unemployedId,
            'career_rank'         => $rank,
            'career_xp'           => $xp,
            'total_character_exp' => $totalXp,
            'health'              => $health,
            'max_health'          => $maxHealth,
            'cash_on_hand'        => $cash,
            'cash_in_bank'        => 0,
            'active_talent'       => $talent,
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => 10_000,
            'luck'         => 10_000,
            'offense'      => 10_000,
            'defense'      => 10_000,
            'influence'    => 0,
        ]);

        CharacterTimers::create(['character_id' => $char->id]);

        return $char;
    }

    
    private function makeSurgeon(int $rank = 4, int $cash = 100_000): Character
    {
        return $this->makeCharacter(
            careerId: $this->healthcareId,
            rank:     $rank,
            cash:     $cash,
            talent:   'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
        );
    }

    
    private function makeCorpse(
        int    $cash    = 500_000,
        int    $totalXp = 200_000,
        string $cause   = 'combat',
    ): Character {
        $char = $this->makeCharacter(cash: $cash, totalXp: $totalXp);

        
        DB::table('characters')->where('id', $char->id)->update([
            'deleted_at'  => now()->subMinutes(10),
            'health'      => 0,
            'death_cause' => $cause,
        ]);

        return $char->fresh();
    }

    private function makeHospital(): Business
    {
        return Business::create([
            'city_id'        => $this->city->id,
            'code'           => 'hospital',
            'name'           => 'Revive Hospital ' . uniqid(),
            'slug'           => 'revive-hospital-' . uniqid(),
            'is_active'      => true,
            'is_purchasable' => true,
            'base_price'     => 500_000,
            'balance'        => 100_000,
            'sort_order'     => 1,
            'data'           => ['surgery_fee' => 10_000, 'gender_reassignment_fee' => 50_000],
        ]);
    }

    
    
    

    public function test_revive_blocked_for_non_healthcare_surgeon(): void
    {
        $nonSurgeon = $this->makeCharacter(talent: 'lazarus_connection:' . now()->addHours(2)->getTimestamp());
        $corpse     = $this->makeCorpse();

        $this->actingAs($nonSurgeon->user)
            ->post(route('profile.revive', ['displayName' => $corpse->display_name]))
            ->assertSessionHas('error');
    }

    public function test_revive_blocked_when_surgeon_has_no_lazarus_talent(): void
    {
        $surgeon = $this->makeCharacter(careerId: $this->healthcareId, rank: 4); 
        $corpse  = $this->makeCorpse();

        $this->actingAs($surgeon->user)
            ->post(route('profile.revive', ['displayName' => $corpse->display_name]))
            ->assertSessionHas('error');
    }

    public function test_revive_blocked_when_lazarus_talent_has_expired(): void
    {
        $surgeon = $this->makeCharacter(
            careerId: $this->healthcareId,
            rank:     4,
            talent:   'lazarus_connection:' . now()->subHour()->getTimestamp(), 
        );
        $corpse = $this->makeCorpse();

        $this->actingAs($surgeon->user)
            ->post(route('profile.revive', ['displayName' => $corpse->display_name]))
            ->assertSessionHas('error');
    }

    public function test_revive_blocked_when_surgeon_is_jailed(): void
    {
        $surgeon = $this->makeSurgeon();
        $corpse  = $this->makeCorpse();

        DB::table('character_timers')
            ->where('character_id', $surgeon->id)
            ->update(['jail_until' => now()->addHours(2)->getTimestamp()]);

        $this->actingAs($surgeon->user)
            ->post(route('profile.revive', ['displayName' => $corpse->display_name]))
            ->assertSessionHas('error');
    }

    
    
    

    public function test_revive_blocked_when_target_is_alive(): void
    {
        $surgeon = $this->makeSurgeon();
        $living  = $this->makeCharacter(); 

        $this->actingAs($surgeon->user)
            ->post(route('profile.revive', ['displayName' => $living->display_name]))
            ->assertSessionHas('error');
    }

    public function test_revive_blocked_when_12h_window_has_expired(): void
    {
        $surgeon = $this->makeSurgeon();
        $corpse  = $this->makeCorpse();

        
        DB::table('characters')->where('id', $corpse->id)->update([
            'deleted_at' => now()->subHours(13),
        ]);

        $this->actingAs($surgeon->user)
            ->post(route('profile.revive', ['displayName' => $corpse->display_name]))
            ->assertSessionHas('error');
    }

    public function test_revive_blocked_when_already_revived(): void
    {
        $surgeon = $this->makeSurgeon();
        $corpse  = $this->makeCorpse();

        DB::table('characters')->where('id', $corpse->id)->update([
            'revived_at' => now()->subHours(1),
        ]);

        $this->actingAs($surgeon->user)
            ->post(route('profile.revive', ['displayName' => $corpse->display_name]))
            ->assertSessionHas('error');
    }

    public function test_revive_blocked_for_suicide(): void
    {
        $surgeon = $this->makeSurgeon();
        $corpse  = $this->makeCorpse(cause: 'Suicide');

        $this->actingAs($surgeon->user)
            ->post(route('profile.revive', ['displayName' => $corpse->display_name]))
            ->assertSessionHas('error');
    }

    public function test_revive_blocked_for_banned_user_and_talent_is_not_burned(): void
    {
        $surgeon = $this->makeSurgeon();
        $corpse  = $this->makeCorpse();

        
        DB::table('users')->where('id', $corpse->user_id)->update(['is_banned' => true]);

        $this->actingAs($surgeon->user)
            ->post(route('profile.revive', ['displayName' => $corpse->display_name]))
            ->assertSessionHas('error');

        
        $surgeon->refresh();
        $this->assertNotNull($surgeon->active_talent, 'talent must not be burned for banned corpse');
    }

    
    
    

    
    private function forceOnline(Character $char): void
    {
        DB::table('sessions')->insert([
            'id'            => 'test-session-' . $char->user_id,
            'user_id'       => $char->user_id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'test',
            'payload'       => 'test',
            'last_activity' => now()->getTimestamp(),
        ]);
    }

    public function test_revive_success_restores_target_to_10_percent_health(): void
    {
        $surgeon = $this->makeSurgeon(rank: 4, cash: 100_000);
        
        
        DB::table('characters')->where('id', $surgeon->id)->update(['career_xp' => 9_999_999]);

        $corpse = $this->makeCorpse(cash: 0, totalXp: 100_000);
        $this->forceOnline($corpse);

        
        
        for ($attempt = 0; $attempt < 20; $attempt++) {
            
            DB::table('characters')->where('id', $corpse->id)->update([
                'deleted_at'  => now()->subMinutes(10),
                'health'      => 0,
                'revived_at'  => null,
                'death_cause' => 'combat',
            ]);
            DB::table('characters')->where('id', $surgeon->id)->update([
                'active_talent' => 'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
            ]);

            $response = $this->actingAs($surgeon->user)
                ->post(route('profile.revive', ['displayName' => $corpse->display_name]));

            if (session('success')) {
                break;
            }
        }

        $this->assertSessionHas('success');

        $corpse->refresh();
        $expectedHealth = max(1, (int) ceil($corpse->max_health * 0.10));
        $this->assertSame($expectedHealth, $corpse->health, 'revived health must be 10% of max');
        $this->assertNull($corpse->deleted_at, 'deleted_at must be cleared on successful revive');
        $this->assertNull($corpse->death_cause, 'death_cause must be cleared');
    }

    public function test_revive_success_stamps_revived_at_and_locks_timers_12h(): void
    {
        $surgeon = $this->makeSurgeon(rank: 4);
        DB::table('characters')->where('id', $surgeon->id)->update(['career_xp' => 9_999_999]);

        $corpse = $this->makeCorpse(cash: 0);
        $this->forceOnline($corpse);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            DB::table('characters')->where('id', $corpse->id)->update([
                'deleted_at' => now()->subMinutes(10), 'health' => 0,
                'revived_at' => null, 'death_cause' => 'combat',
            ]);
            DB::table('characters')->where('id', $surgeon->id)->update([
                'active_talent' => 'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
            ]);
            $this->actingAs($surgeon->user)
                ->post(route('profile.revive', ['displayName' => $corpse->display_name]));
            if (session('success')) break;
        }

        $this->assertSessionHas('success');

        $corpse->refresh();
        $this->assertNotNull($corpse->revived_at, 'revived_at must be stamped on success');

        $timers = DB::table('character_timers')->where('character_id', $corpse->id)->first();
        $minLock = now()->addHours(11)->getTimestamp();
        $this->assertGreaterThan($minLock, $timers->next_work_at,     'next_work_at locked 12h');
        $this->assertGreaterThan($minLock, $timers->next_action_at,   'next_action_at locked 12h');
        $this->assertGreaterThan($minLock, $timers->next_conflict_at, 'next_conflict_at locked 12h');
        $this->assertGreaterThan($minLock, $timers->protection_until, 'protection_until locked 12h');
    }

    public function test_revive_success_clears_leaderboard_row(): void
    {
        $surgeon = $this->makeSurgeon(rank: 4);
        DB::table('characters')->where('id', $surgeon->id)->update(['career_xp' => 9_999_999]);

        $corpse = $this->makeCorpse(cash: 0);
        $this->forceOnline($corpse);

        
        DB::table('leaderboards')->insert([
            'character_id'   => $corpse->id,
            'display_name'   => $corpse->display_name,
            'avatar_url'     => null,
            'glow_color'     => 'cyan',
            'career_name'    => 'Test',
            'rank_name'      => 'Staff',
            'home_city_name' => 'City',
            'kills'          => 0,
            'total_earns'    => 0,
            'rating'         => 55,
            'is_historical'  => true,
            'died_at'        => now()->subMinutes(10),
            'snapshotted_at' => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            DB::table('characters')->where('id', $corpse->id)->update([
                'deleted_at' => now()->subMinutes(10), 'health' => 0,
                'revived_at' => null, 'death_cause' => 'combat',
            ]);
            DB::table('characters')->where('id', $surgeon->id)->update([
                'active_talent' => 'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
            ]);
            $this->actingAs($surgeon->user)
                ->post(route('profile.revive', ['displayName' => $corpse->display_name]));
            if (session('success')) break;
        }

        $this->assertSessionHas('success');
        $this->assertFalse(
            DB::table('leaderboards')->where('character_id', $corpse->id)->exists(),
            'leaderboard row must be cleared on successful revive',
        );
    }

    public function test_revive_success_applies_15_percent_xp_penalty(): void
    {
        $surgeon = $this->makeSurgeon(rank: 4);
        DB::table('characters')->where('id', $surgeon->id)->update(['career_xp' => 9_999_999]);

        $originalXp = 200_000;
        $corpse     = $this->makeCorpse(cash: 0, totalXp: $originalXp);
        $this->forceOnline($corpse);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            DB::table('characters')->where('id', $corpse->id)->update([
                'deleted_at' => now()->subMinutes(10), 'health' => 0,
                'revived_at' => null, 'death_cause' => 'combat',
                'total_character_exp' => $originalXp,
            ]);
            DB::table('characters')->where('id', $surgeon->id)->update([
                'active_talent' => 'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
            ]);
            $this->actingAs($surgeon->user)
                ->post(route('profile.revive', ['displayName' => $corpse->display_name]));
            if (session('success')) break;
        }

        $this->assertSessionHas('success');

        $expectedXp = $originalXp - (int) floor($originalXp * 0.15); 
        $actualXp   = (int) DB::table('characters')->where('id', $corpse->id)->value('total_character_exp');
        $this->assertSame($expectedXp, $actualXp, '15% XP penalty must be applied on revival');
    }

    public function test_revive_success_surgeon_talent_is_burned(): void
    {
        $surgeon = $this->makeSurgeon(rank: 4);
        DB::table('characters')->where('id', $surgeon->id)->update(['career_xp' => 9_999_999]);

        $corpse = $this->makeCorpse(cash: 0);
        $this->forceOnline($corpse);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            DB::table('characters')->where('id', $corpse->id)->update([
                'deleted_at' => now()->subMinutes(10), 'health' => 0,
                'revived_at' => null, 'death_cause' => 'combat',
            ]);
            DB::table('characters')->where('id', $surgeon->id)->update([
                'active_talent' => 'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
            ]);
            $this->actingAs($surgeon->user)
                ->post(route('profile.revive', ['displayName' => $corpse->display_name]));
            if (session('success')) break;
        }

        $this->assertSessionHas('success');

        $surgeon->refresh();
        $this->assertNull($surgeon->active_talent, 'active_talent must be cleared after revive');
        $timers = DB::table('character_timers')->where('character_id', $surgeon->id)->first();
        $this->assertGreaterThan(now()->addHours(11)->getTimestamp(), $timers->next_talents_at,
            'next_talents_at must be locked 12h after talent burn',
        );
    }

    public function test_revive_success_fee_split_is_ten_percent_of_cash(): void
    {
        $surgeon  = $this->makeSurgeon(rank: 4, cash: 0);
        $hospital = $this->makeHospital();
        DB::table('characters')->where('id', $surgeon->id)->update(['career_xp' => 9_999_999]);

        $corpse = $this->makeCorpse(cash: 100_000);
        $this->forceOnline($corpse);

        $hospitalBalBefore = $hospital->balance;

        for ($attempt = 0; $attempt < 20; $attempt++) {
            DB::table('characters')->where('id', $corpse->id)->update([
                'deleted_at' => now()->subMinutes(10), 'health' => 0,
                'revived_at' => null, 'death_cause' => 'combat',
                'cash_on_hand' => 100_000,
            ]);
            DB::table('characters')->where('id', $surgeon->id)->update([
                'active_talent' => 'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
                'cash_on_hand'  => 0,
            ]);
            $this->actingAs($surgeon->user)
                ->post(route('profile.revive', ['displayName' => $corpse->display_name]));
            if (session('success')) break;
        }

        $this->assertSessionHas('success');

        
        
        
        $corpse->refresh();
        $surgeon->refresh();
        $hospital->refresh();

        $this->assertSame(90_000, (int) $corpse->cash_on_hand,  'target must be charged 10% of cash');
        $this->assertSame(1_000,  (int) $surgeon->cash_on_hand, 'surgeon gets 10% of fee');
        $this->assertSame($hospitalBalBefore + 9_000, (int) $hospital->balance, 'hospital gets 90% of fee');
    }

    public function test_revive_success_zero_cash_target_no_negative_balance(): void
    {
        $surgeon = $this->makeSurgeon(rank: 4);
        DB::table('characters')->where('id', $surgeon->id)->update(['career_xp' => 9_999_999]);

        $corpse = $this->makeCorpse(cash: 0);
        $this->forceOnline($corpse);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            DB::table('characters')->where('id', $corpse->id)->update([
                'deleted_at' => now()->subMinutes(10), 'health' => 0,
                'revived_at' => null, 'death_cause' => 'combat',
                'cash_on_hand' => 0,
            ]);
            DB::table('characters')->where('id', $surgeon->id)->update([
                'active_talent' => 'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
            ]);
            $this->actingAs($surgeon->user)
                ->post(route('profile.revive', ['displayName' => $corpse->display_name]));
            if (session('success')) break;
        }

        $this->assertSessionHas('success');

        $balance = (int) DB::table('characters')->where('id', $corpse->id)->value('cash_on_hand');
        $this->assertGreaterThanOrEqual(0, $balance, 'cash_on_hand must never go negative');
    }

    public function test_revive_success_writes_journal_for_target(): void
    {
        $surgeon = $this->makeSurgeon(rank: 4);
        DB::table('characters')->where('id', $surgeon->id)->update(['career_xp' => 9_999_999]);

        $corpse = $this->makeCorpse(cash: 0);
        $this->forceOnline($corpse);

        for ($attempt = 0; $attempt < 20; $attempt++) {
            DB::table('characters')->where('id', $corpse->id)->update([
                'deleted_at' => now()->subMinutes(10), 'health' => 0,
                'revived_at' => null, 'death_cause' => 'combat',
            ]);
            DB::table('characters')->where('id', $surgeon->id)->update([
                'active_talent' => 'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
            ]);
            $this->actingAs($surgeon->user)
                ->post(route('profile.revive', ['displayName' => $corpse->display_name]));
            if (session('success')) break;
        }

        $this->assertSessionHas('success');

        $journal = CharacterJournal::where('character_id', $corpse->id)
            ->where('type', 'revived')
            ->first();
        $this->assertNotNull($journal, 'revived journal entry must be created for target');
    }

    
    
    

    public function test_revive_failure_stamps_revived_at_and_burns_talent(): void
    {
        
        $surgeon = $this->makeCharacter(
            careerId: $this->healthcareId,
            rank:     1,
            xp:       1,
            totalXp:  1,
            talent:   'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
        );

        $corpse = $this->makeCorpse(cash: 0, totalXp: 9_999_999); 
        $this->forceOnline($corpse);

        
        for ($attempt = 0; $attempt < 50; $attempt++) {
            DB::table('characters')->where('id', $corpse->id)->update([
                'deleted_at' => now()->subMinutes(10), 'health' => 0,
                'revived_at' => null, 'death_cause' => 'combat',
            ]);
            DB::table('characters')->where('id', $surgeon->id)->update([
                'active_talent' => 'lazarus_connection:' . now()->addHours(2)->getTimestamp(),
            ]);

            $this->actingAs($surgeon->user)
                ->post(route('profile.revive', ['displayName' => $corpse->display_name]));

            
            $errMsg = session('error') ?? '';
            if (str_contains($errMsg, "wasn't enough") || str_contains($errMsg, 'failed')) {
                break;
            }
        }

        
        $corpseRow = DB::table('characters')->where('id', $corpse->id)->first();
        $this->assertNotNull($corpseRow->revived_at, 'revived_at must be stamped even on dice fail');
        $this->assertNotNull($corpseRow->deleted_at, 'target must still be dead after failed revive');

        $surgeon->refresh();
        $this->assertNull($surgeon->active_talent, 'talent must be burned even on dice fail');
    }
}
