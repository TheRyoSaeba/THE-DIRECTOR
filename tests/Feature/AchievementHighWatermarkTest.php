<?php

namespace Tests\Feature;

use App\Models\Achievement;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AchievementHighWatermarkTest extends TestCase
{
    use DatabaseTransactions;

    private function seedBaseData(): array
    {
        $city = \DB::table('cities')->insertGetId([
            'name' => 'Test City',
            'crime_rate' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $career = \DB::table('careers')->insertGetId([
            'code' => 'test',
            'name' => 'Test Career',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['city_id' => $city, 'career_id' => $career];
    }

    private function createCharacterForUser(User $user, int $cityId, int $careerId, string $name = 'TestChar'): Character
    {
        $character = Character::create([
            'user_id' => $user->id,
            'display_name' => $name . '_' . uniqid(),
            'gender' => 'male',
            'city_id' => $cityId,
            'home_city_id' => $cityId,
            'career_id' => $careerId,
            'health' => 100,
            'max_health' => 100,
            'cash_on_hand' => 1000,
        ]);

        CharacterStats::create(['character_id' => $character->id]);

        return $character;
    }

    public function test_earns_achievement_unlocks_at_threshold(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();

        Achievement::create([
            'slug' => 'earns-10',
            'name' => '10 Earns',
            'icon' => '💰',
            'trigger_type' => 'earns',
            'trigger_value' => 10,
        ]);

        $char = $this->createCharacterForUser($user, $base['city_id'], $base['career_id']);

        $char->update(['total_earns' => 5]);
        $this->assertFalse($user->fresh()->hasAchievement('earns-10'));

        $char->update(['total_earns' => 10]);
        $this->assertTrue($user->fresh()->hasAchievement('earns-10'));
    }

    public function test_new_character_must_relearn_earns_from_zero(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();

        Achievement::create([
            'slug' => 'earns-10',
            'name' => '10 Earns',
            'icon' => '💰',
            'trigger_type' => 'earns',
            'trigger_value' => 10,
        ]);

        Achievement::create([
            'slug' => 'earns-20',
            'name' => '20 Earns',
            'icon' => '💰💰',
            'trigger_type' => 'earns',
            'trigger_value' => 20,
        ]);

        $char1 = $this->createCharacterForUser($user, $base['city_id'], $base['career_id'], 'Char1');
        $char1->update(['total_earns' => 15]);

        $this->assertTrue($user->fresh()->hasAchievement('earns-10'));
        $this->assertFalse($user->fresh()->hasAchievement('earns-20'));

        $char1->kill('test', 'testing');

        $char2 = $this->createCharacterForUser($user, $base['city_id'], $base['career_id'], 'Char2');
        $this->assertEquals(0, $char2->total_earns);

        $char2->update(['total_earns' => 5]);
        $this->assertFalse($user->fresh()->hasAchievement('earns-20'));

        $char2->update(['total_earns' => 10]);
        $this->assertFalse($user->fresh()->hasAchievement('earns-20'));
        $this->assertEquals(1, $user->fresh()->achievements()->where('slug', 'earns-10')->count());

        $char2->update(['total_earns' => 20]);
        $this->assertTrue($user->fresh()->hasAchievement('earns-20'));
    }

    public function test_influence_achievement_resets_with_new_character(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();

        Achievement::create([
            'slug' => 'influence-50',
            'name' => '50 Influence',
            'icon' => '⭐',
            'trigger_type' => 'influence',
            'trigger_value' => 50,
        ]);

        Achievement::create([
            'slug' => 'influence-100',
            'name' => '100 Influence',
            'icon' => '⭐⭐',
            'trigger_type' => 'influence',
            'trigger_value' => 100,
        ]);

        $char1 = $this->createCharacterForUser($user, $base['city_id'], $base['career_id'], 'Inf1');
        $char1->stats->addInfluence(75);

        $this->assertTrue($user->fresh()->hasAchievement('influence-50'));
        $this->assertFalse($user->fresh()->hasAchievement('influence-100'));

        $char1->kill('test', 'testing');

        $char2 = $this->createCharacterForUser($user, $base['city_id'], $base['career_id'], 'Inf2');
        $this->assertEquals(0, $char2->stats->influence);

        $char2->stats->addInfluence(30);
        $this->assertFalse($user->fresh()->hasAchievement('influence-100'));

        $char2->stats->addInfluence(75);
        $this->assertTrue($user->fresh()->hasAchievement('influence-100'));
    }

    public function test_influence_caps_at_150(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();
        $char = $this->createCharacterForUser($user, $base['city_id'], $base['career_id']);

        $char->stats->addInfluence(140);
        $this->assertEquals(140, $char->stats->fresh()->influence);

        $char->stats->fresh()->addInfluence(20);
        $this->assertEquals(150, $char->stats->fresh()->influence);
    }

    public function test_degrees_achievement_resets_with_new_character(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();

        Achievement::create([
            'slug' => 'degrees-1',
            'name' => '1 Degree',
            'icon' => '🎓',
            'trigger_type' => 'degrees',
            'trigger_value' => 1,
        ]);

        Achievement::create([
            'slug' => 'degrees-2',
            'name' => '2 Degrees',
            'icon' => '🎓🎓',
            'trigger_type' => 'degrees',
            'trigger_value' => 2,
        ]);

        Achievement::create([
            'slug' => 'degrees-3',
            'name' => '3 Degrees',
            'icon' => '🎓🎓🎓',
            'trigger_type' => 'degrees',
            'trigger_value' => 3,
        ]);

        $char1 = $this->createCharacterForUser($user, $base['city_id'], $base['career_id'], 'Deg1');

        $char1->update(['degrees' => [
            ['name' => 'Law', 'completed_at' => now()->toDateTimeString()],
        ]]);
        $this->assertTrue($user->fresh()->hasAchievement('degrees-1'));
        $this->assertFalse($user->fresh()->hasAchievement('degrees-2'));

        $char1->update(['degrees' => [
            ['name' => 'Law', 'completed_at' => now()->toDateTimeString()],
            ['name' => 'Medicine', 'completed_at' => now()->toDateTimeString()],
        ]]);
        $this->assertTrue($user->fresh()->hasAchievement('degrees-2'));

        $char1->kill('test', 'testing');

        $char2 = $this->createCharacterForUser($user, $base['city_id'], $base['career_id'], 'Deg2');
        $this->assertNull($char2->degrees);

        $char2->update(['degrees' => [
            ['name' => 'Banking', 'completed_at' => now()->toDateTimeString()],
        ]]);
        $this->assertFalse($user->fresh()->hasAchievement('degrees-3'));

        $char2->update(['degrees' => [
            ['name' => 'Banking', 'completed_at' => now()->toDateTimeString()],
            ['name' => 'Corporate', 'completed_at' => now()->toDateTimeString()],
            ['name' => 'Politics', 'completed_at' => now()->toDateTimeString()],
        ]]);
        $this->assertTrue($user->fresh()->hasAchievement('degrees-3'));
    }

    public function test_already_owned_achievements_not_duplicated(): void
    {
        $base = $this->seedBaseData();
        $user = User::factory()->create();

        Achievement::create([
            'slug' => 'earns-10',
            'name' => '10 Earns',
            'icon' => '💰',
            'trigger_type' => 'earns',
            'trigger_value' => 10,
        ]);

        $char1 = $this->createCharacterForUser($user, $base['city_id'], $base['career_id'], 'Dup1');
        $char1->update(['total_earns' => 15]);
        $this->assertTrue($user->fresh()->hasAchievement('earns-10'));

        $char1->kill('test', 'testing');

        $char2 = $this->createCharacterForUser($user, $base['city_id'], $base['career_id'], 'Dup2');
        $char2->update(['total_earns' => 15]);

        $this->assertEquals(1, $user->fresh()->achievements()->where('slug', 'earns-10')->count());
    }
}
