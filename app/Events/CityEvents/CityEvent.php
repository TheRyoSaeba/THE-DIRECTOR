<?php

namespace App\Events\CityEvents;

use App\Models\Character;
use App\Models\City;

//! YOU MUST READ THIS FILE BEFORE EDITING ANY CITY EVENT
 
 //! Base contract for all CityEvent classes.
 
abstract class CityEvent
{
    
    abstract public function shouldFire(Character $character): bool;

    
    final public function attempt(Character $character): bool
    {
        if (! $this->shouldFire($character)) {
            return false;
        }

        if (mt_rand(1, 100) > $this->probability()) {
            return false;
        }

        $this->handle($character);

        \Illuminate\Support\Facades\Log::info('[CityEvent] Fired', [
            'event'        => static::class,
            'character_id' => $character->id,
            'city_id'      => $character->city?->id,
            'home_city_id' => $character->homeCity?->id,
        ]);

        return true;
    }

    
    abstract protected function probability(): int;

    
    abstract protected function handle(Character $character): void;
}
