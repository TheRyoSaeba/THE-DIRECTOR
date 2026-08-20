<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TalentSystemTest extends TestCase
{
    use DatabaseTransactions;

    

    private function seedBaseData(): array
    {
        $city = \DB::table('cities')->insertGetId([
            'name'       => 'Test City',
            'slug'       => 'test-city-' . uniqid(),
            'crime_rate' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $policeCareer    = \DB::table('careers')->whereRaw('LOWER(code) = ?', ['police'])->first();
        $unemployedCareer = \DB::table('careers')->whereRaw('LOWER(code) = ?', ['unemployed'])->first();

        if (! $policeCareer || ! $unemployedCareer) {
            throw new \RuntimeException('Required careers (police, unemployed) must exist in the database.');
        }

        return [
            'city_id'              => $city,
            'police_career_id'     => $policeCareer->id,
            'unemployed_career_id' => $unemployedCareer->id,
        ];
    }

    private function createCharacter(User $user, int $cityId, int $careerId, array $overrides = []): Character
    {
        $character = Character::create(array_merge([
            'user_id'      => $user->id,
            'display_name' => 'TestChar_' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $cityId,
            'home_city_id' => $cityId,
            'career_id'    => $careerId,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => 1000,
        ], $overrides));

        CharacterStats::create([
            'character_id' => $character->id,
            'influence'    => 0,
            'intelligence' => 100,
            'offense'      => 100,
            'defense'      => 100,
            'luck'         => 100,
        ]);

        CharacterTimers::create(['character_id' => $character->id]);

        return $character->fresh(['stats', 'timers', 'career']);
    }

    private function giveAllDegrees(Character $character): void
    {
        $character->update(['degrees' => [
            'finance'  => ['city_id' => $character->city_id, 'cycles' => 20, 'completed_at' => now()->toIso8601String()],
            'law'      => ['city_id' => $character->city_id, 'cycles' => 30, 'completed_at' => now()->toIso8601String()],
            'medicine' => ['city_id' => $character->city_id, 'cycles' => 20, 'completed_at' => now()->toIso8601String()],
        ]]);
    }

    
    private function activateTalent(Character $character, string $talentId): void
    {
        $duration  = config('timers.talent_duration');
        $cooldown  = config('timers.talent_cooldown');
        $expiresAt = now()->addSeconds($duration)->timestamp;

        $character->update(['active_talent' => "{$talentId}:{$expiresAt}"]);
        $character->timers()->update([
            'next_talents_at' => now()->addSeconds($duration + $cooldown)->getTimestamp(),
        ]);
        $character->refresh()->load('timers');
    }

    
    private function writeExpiredTalent(Character $character, string $talentId, int $expiredSecondsAgo = 60): void
    {
        $expiresAt = now()->subSeconds($expiredSecondsAgo)->timestamp;
        $character->update(['active_talent' => "{$talentId}:{$expiresAt}"]);
        $character->timers()->update([
            'next_talents_at' => now()->subSecond()->getTimestamp(),
        ]);
        $character->refresh()->load('timers');
    }

    
    private function writeExpiredTalentWithActiveCooldown(Character $character, string $talentId): void
    {
        $expiresAt = now()->subSecond()->timestamp;
        $character->update(['active_talent' => "{$talentId}:{$expiresAt}"]);
        $character->timers()->update([
            'next_talents_at' => now()->addSeconds(config('timers.talent_cooldown'))->getTimestamp(),
        ]);
        $character->refresh()->load('timers');
    }

    

    public function test_has_all_degrees_returns_false_with_no_degrees(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $this->assertFalse($char->hasAllDegrees());
    }

    public function test_has_all_degrees_returns_false_with_partial_degrees(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $char->update(['degrees' => [
            'finance' => ['city_id' => $char->city_id, 'cycles' => 20, 'completed_at' => now()->toIso8601String()],
            'law'     => ['city_id' => $char->city_id, 'cycles' => 5,  'completed_at' => null],
        ]]);

        $this->assertFalse($char->hasAllDegrees());
    }

    public function test_has_all_degrees_returns_true_with_all_completed(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);
        $this->giveAllDegrees($char);

        $this->assertTrue($char->fresh()->hasAllDegrees());
    }

    

    public function test_has_defense_in_depth_requirements_wrong_career(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id'], [
            'career_rank' => 5,
            'career_xp'   => 200000,
        ]);

        $this->assertFalse($char->hasDefenseInDepthRequirements());
    }

    public function test_has_defense_in_depth_requirements_police_low_rank(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['police_career_id'], [
            'career_rank' => 2,
            'career_xp'   => 200000,
        ]);

        $this->assertFalse($char->hasDefenseInDepthRequirements());
    }

    public function test_has_defense_in_depth_requirements_police_low_xp(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['police_career_id'], [
            'career_rank' => 3,
            'career_xp'   => 124999,
        ]);

        $this->assertFalse($char->hasDefenseInDepthRequirements());
    }

    public function test_has_defense_in_depth_requirements_police_exact_threshold(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['police_career_id'], [
            'career_rank' => 3,
            'career_xp'   => 125000,
        ]);

        $this->assertTrue($char->hasDefenseInDepthRequirements());
    }

    public function test_has_defense_in_depth_requirements_police_above_threshold(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['police_career_id'], [
            'career_rank' => 5,
            'career_xp'   => 500000,
        ]);

        $this->assertTrue($char->hasDefenseInDepthRequirements());
    }

    public function test_switching_career_loses_defense_in_depth_requirements(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['police_career_id'], [
            'career_rank' => 5,
            'career_xp'   => 200000,
        ]);

        $this->assertTrue($char->hasDefenseInDepthRequirements());

        $char->update(['career_id' => $base['unemployed_career_id']]);
        $char->load('career');

        $this->assertFalse($char->hasDefenseInDepthRequirements());
    }

    

    public function test_has_talent_active_returns_false_when_no_talent(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $this->assertFalse($char->hasTalentActive());
        $this->assertFalse($char->hasTalentActive('over_educated'));
        $this->assertFalse($char->hasTalentActive('defense_in_depth'));
    }

    public function test_has_talent_active_returns_true_when_active_and_expiry_future(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);
        $this->activateTalent($char, 'over_educated');

        $this->assertTrue($char->hasTalentActive());
        $this->assertTrue($char->hasTalentActive('over_educated'));
        $this->assertFalse($char->hasTalentActive('defense_in_depth'));
    }

    public function test_has_talent_active_returns_false_and_clears_when_expiry_past(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        
        $pastExpiry = now()->subSecond()->timestamp;
        $char->update(['active_talent' => "over_educated:{$pastExpiry}"]);
        $char->timers()->update(['next_talents_at' => now()->subSecond()->getTimestamp()]);
        $char->refresh()->load('timers');

        $this->assertFalse($char->hasTalentActive('over_educated'));
        $this->assertNull($char->fresh()->active_talent);
    }

    public function test_expired_talent_auto_clears_on_check(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $pastExpiry = now()->subMinutes(5)->timestamp;
        $char->update(['active_talent' => "defense_in_depth:{$pastExpiry}"]);
        $char->timers()->update(['next_talents_at' => now()->subMinutes(5)->getTimestamp()]);
        $char->refresh()->load('timers');

        $this->assertStringStartsWith('defense_in_depth:', $char->active_talent);

        $result = $char->hasTalentActive();

        $this->assertFalse($result);
        $this->assertNull($char->fresh()->active_talent);
    }

    public function test_buff_expired_but_cooldown_still_active(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        
        $this->writeExpiredTalentWithActiveCooldown($char, 'over_educated');

        
        $this->assertFalse($char->hasTalentActive('over_educated'));
        
        $this->assertFalse($char->canActivateTalent());
        $this->assertTrue($char->timers->next_talents_at->isFuture());
    }

    public function test_can_activate_only_after_full_cooldown_window_clears(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        
        $this->writeExpiredTalent($char, 'over_educated');

        $this->assertFalse($char->hasTalentActive());
        $this->assertTrue($char->canActivateTalent());
    }

    public function test_only_one_talent_active_at_a_time(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['police_career_id'], [
            'career_rank' => 5,
            'career_xp'   => 200000,
        ]);
        $this->giveAllDegrees($char);

        $this->activateTalent($char, 'over_educated');
        $this->assertTrue($char->hasTalentActive('over_educated'));
        $this->assertFalse($char->hasTalentActive('defense_in_depth'));

        
        $futureExpiry = now()->addSeconds(config('timers.talent_duration'))->timestamp;
        $char->update(['active_talent' => "defense_in_depth:{$futureExpiry}"]);
        $char->refresh();

        $this->assertFalse($char->hasTalentActive('over_educated'));
        $this->assertTrue($char->hasTalentActive('defense_in_depth'));
    }

    public function test_has_talent_active_with_null_timers(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();

        
        
        $char = Character::create([
            'user_id'       => $user->id,
            'display_name'  => 'NoTimers_' . uniqid(),
            'gender'        => 'male',
            'city_id'       => $base['city_id'],
            'home_city_id'  => $base['city_id'],
            'career_id'     => $base['unemployed_career_id'],
            'health'        => 100,
            'max_health'    => 100,
            'cash_on_hand'  => 1000,
            'active_talent' => 'over_educated', 
        ]);

        $this->assertFalse($char->hasTalentActive());
        $this->assertNull($char->fresh()->active_talent);
    }

    

    public function test_can_activate_talent_with_no_prior_use(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $this->assertTrue($char->canActivateTalent());
    }

    public function test_cannot_activate_talent_while_buff_active(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);
        $this->giveAllDegrees($char);
        $this->activateTalent($char, 'over_educated');

        $this->assertFalse($char->canActivateTalent());
    }

    public function test_cannot_activate_talent_during_cooldown_window(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $this->writeExpiredTalentWithActiveCooldown($char, 'over_educated');

        $this->assertFalse($char->canActivateTalent());
    }

    

    public function test_over_educated_halves_work_cooldown(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);
        $this->giveAllDegrees($char);
        $this->activateTalent($char, 'over_educated');

        $workCooldown = config('timers.work');
        $expected     = (int) ceil($workCooldown / 2);

        $this->assertTrue($char->hasTalentActive('over_educated'));
        $this->assertEquals(60, $workCooldown);
        $this->assertEquals(30, $expected);
    }

    public function test_over_educated_does_not_halve_when_expired(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);
        $this->giveAllDegrees($char);
        $this->writeExpiredTalent($char, 'over_educated');

        $this->assertFalse($char->hasTalentActive('over_educated'));
        $this->assertEquals(60, config('timers.work'));
    }

    

    public function test_defense_reduction_doubles_with_defense_in_depth(): void
    {
        $defenderDefense = 10000;

        $normalReduction = 1 / (1 + (sqrt($defenderDefense) / 250));
        $talentReduction = $normalReduction * $normalReduction;

        $this->assertGreaterThan($talentReduction, $normalReduction);
        $this->assertEqualsWithDelta($normalReduction ** 2, $talentReduction, 0.0001);
        $this->assertLessThan(1, $normalReduction);
        $this->assertGreaterThan(0, $normalReduction);
        $this->assertLessThan($normalReduction, $talentReduction);
    }

    public function test_defense_reduction_math_at_various_defense_levels(): void
    {
        foreach ([100, 500, 1000, 5000, 10000, 50000] as $defense) {
            $normal     = 1 / (1 + (sqrt($defense) / 250));
            $withTalent = $normal * $normal;

            $this->assertLessThan($normal, $withTalent,
                "Defense in Depth should reduce damage multiplier at defense={$defense}");
            $this->assertGreaterThan(0, $normal * 100 - $withTalent * 100,
                "Talent should provide meaningful reduction at defense={$defense}");
        }
    }

    

    public function test_activate_talent_endpoint_rejects_unknown_talent(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $response = $this->actingAs($user)->post('/talents/activate', ['talent_id' => 'nonexistent_talent']);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Unknown talent.');
    }

    public function test_activate_requires_talent_id_field(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $response = $this->actingAs($user)->post('/talents/activate', []);

        $response->assertSessionHasErrors('talent_id');
    }

    public function test_activate_over_educated_rejects_without_degrees(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $response = $this->actingAs($user)->post('/talents/activate', ['talent_id' => 'over_educated']);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'You have not unlocked this talent.');
        $this->assertNull($char->fresh()->active_talent);
    }

    public function test_activate_over_educated_succeeds_with_all_degrees(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);
        $this->giveAllDegrees($char);

        $response = $this->actingAs($user)->post('/talents/activate', ['talent_id' => 'over_educated']);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $fresh = $char->fresh()->load('timers');
        
        $this->assertStringStartsWith('over_educated:', $fresh->active_talent);
        $this->assertTrue($fresh->timers->next_talents_at->isFuture());
        
        $expectedWindow = config('timers.talent_duration') + config('timers.talent_cooldown');
        $this->assertGreaterThanOrEqual(
            now()->addSeconds($expectedWindow - 5)->timestamp,
            $fresh->timers->next_talents_at->timestamp
        );
    }

    public function test_activate_defense_in_depth_rejects_without_requirements(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $response = $this->actingAs($user)->post('/talents/activate', ['talent_id' => 'defense_in_depth']);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'You have not unlocked this talent.');
    }

    public function test_activate_defense_in_depth_succeeds_with_requirements(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['police_career_id'], [
            'career_rank' => 3,
            'career_xp'   => 125000,
        ]);

        $response = $this->actingAs($user)->post('/talents/activate', ['talent_id' => 'defense_in_depth']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertStringStartsWith('defense_in_depth:', $char->fresh()->active_talent);
    }

    public function test_cannot_activate_while_talent_buff_is_running(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['police_career_id'], [
            'career_rank' => 5,
            'career_xp'   => 200000,
        ]);
        $this->giveAllDegrees($char);
        $this->activateTalent($char, 'over_educated');

        $response = $this->actingAs($user)->post('/talents/activate', ['talent_id' => 'defense_in_depth']);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'You already have a talent active.');
        
        $this->assertStringStartsWith('over_educated:', $char->fresh()->active_talent);
    }

    public function test_cannot_activate_during_cooldown_window_after_buff_expires(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);
        $this->giveAllDegrees($char);

        
        $this->writeExpiredTalentWithActiveCooldown($char, 'over_educated');

        $response = $this->actingAs($user)->post('/talents/activate', ['talent_id' => 'over_educated']);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Your talent is still on cooldown.');
    }

    public function test_can_activate_new_talent_after_full_window_expires(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['police_career_id'], [
            'career_rank' => 5,
            'career_xp'   => 200000,
        ]);
        $this->giveAllDegrees($char);

        
        $this->writeExpiredTalent($char, 'over_educated');

        $response = $this->actingAs($user)->post('/talents/activate', ['talent_id' => 'defense_in_depth']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertStringStartsWith('defense_in_depth:', $char->fresh()->active_talent);
    }

    

    public function test_talent_page_loads_for_authenticated_user(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);

        $response = $this->actingAs($user)->get('/talents');

        $response->assertStatus(200);
    }

    public function test_talent_page_exposes_active_remaining_and_cooldown_remaining(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);
        $this->giveAllDegrees($char);
        $this->activateTalent($char, 'over_educated');

        $response = $this->actingAs($user)->get('/talents');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Talents')
            ->has('talents', 2)
            ->where('talents.0.id', 'over_educated')
            ->where('talents.0.active', true)
            ->where('talents.0.on_cooldown', false)
            
            ->where('talents.0.active_remaining', fn ($v) => $v > 0 && $v <= config('timers.talent_duration'))
            
            ->where('talents.0.cooldown_remaining', 0)
        );
    }

    public function test_talent_page_shows_cooldown_remaining_after_buff_expires(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacter($user, $base['city_id'], $base['unemployed_career_id']);
        $this->giveAllDegrees($char);

        $this->writeExpiredTalentWithActiveCooldown($char, 'over_educated');

        $response = $this->actingAs($user)->get('/talents');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Talents')
            ->where('talents.0.active', false)
            ->where('talents.0.on_cooldown', true)
            ->where('talents.0.active_remaining', 0)
            ->where('talents.0.cooldown_remaining', fn ($v) => $v > 0)
        );
    }
}
