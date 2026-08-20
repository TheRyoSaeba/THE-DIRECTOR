<?php

namespace App\Events\CityEvents;

use App\Models\Character;
use App\Support\CrimeThresholds;


class CityUnrest extends CityEvent
{
    private ?string $cityName = null;

    protected function probability(): int
    {
        return 10;
    }

    public function shouldFire(Character $character): bool
    {
        //! Technically, it's odd that it stops you from working even when not in your home city but remember verisimilitude!
        $homeCity = $character->homeCity;

        if (! $homeCity) {
            return false;
        }

        $rate = (float) $homeCity->crime_rate;

        if (!CrimeThresholds::isCritical($rate)) {
            return false;
        }

        
        $this->cityName = $homeCity->name;
        return true;
    }

    protected function handle(Character $character): void
    {
        
        
        
    }

    
    public function getBlockMessage(): string
    {
        $name = $this->cityName ?? 'Your city';
        return "{$name} is on fire, you tried to go to work today, but there was so much violent crime and you had to stay home!";
    }
}
