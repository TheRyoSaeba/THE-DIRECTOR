<?php

namespace App\Events\CityEvents;

use App\Models\Character;


final class CityEventDispatcher
{
    
    private static array $registry = [
        'work'   => [
            CityUnrest::class,
        ],
        'travel' => [
            Mugging::class,
        ],
        'move' => [
            Mugging::class,
        ],
        'action' => [
            Mugging::class,
        ],
    ];

    
    public static function dispatch(
        Character $character,
        string $context,
        bool $stopAtFirst = true
    ): ?CityEvent {
        $classes = self::$registry[$context] ?? [];

        if (empty($classes)) {
            return null;
        }

        
        $character->loadMissing(['homeCity', 'city']);

        foreach ($classes as $class) {
            
            $event = new $class();

            if ($event->attempt($character)) {
                if ($stopAtFirst) {
                    return $event;
                }
            }
        }

        return null;
    }

    
    public static function dispatchWork(Character $character): ?string
    {
        $event = self::dispatch($character, 'work');

        if ($event instanceof CityUnrest) {
            return $event->getBlockMessage();
        }

        return null;
    }
}
