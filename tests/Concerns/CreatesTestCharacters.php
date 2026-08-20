<?php

namespace Tests\Concerns;

use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\User;


trait CreatesTestCharacters
{
    
    protected function makeCharacter(
        City $city,
        int  $careerId,
        int  $rank        = 1,
        int  $careerXp    = 0,
        int  $intelligence = 1_000,
        int  $luck         = 1_000,
        int  $offense      = 1_000,
        int  $defense      = 1_000,
        int  $cashOnHand   = 50_000,
        int  $cashInBank   = 100_000,
    ): Character {
        $user = User::factory()->create();

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'TestChar-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $city->id,
            'home_city_id' => $city->id,
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
            'intelligence' => $intelligence,
            'luck'         => $luck,
            'offense'      => $offense,
            'defense'      => $defense,
            'influence'    => 0,
        ]);

        CharacterTimers::create([
            'character_id'   => $char->id,
            'next_action_at' => null,
        ]);

        return $char;
    }
}
