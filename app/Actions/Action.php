<?php

namespace App\Actions;

use App\Models\Character;
use App\Models\City;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

//! ALL ACTIONS USE A MINIUM OF  CHARACTER STRENGTH AND ACTION TIMER.
//! very important here as everywhere else that inflation of money is controlled.

abstract class Action
{
    abstract public function getId(): string;

    abstract public function getShape(Character $character): array;

    abstract public function canExecute(Character $character): array;

    abstract public function execute(Character $character, array $params): RedirectResponse;

    public function isGroupAction(): bool
    {
        return false;
    }

    public function isStealthAction(): bool
    {
        return false;
    }

    protected function success(string $message): RedirectResponse
    {
        return back()->with('success', $message);
    }

    protected function error(string $message): RedirectResponse
    {
        return back()->with('error', $message);
    }

    protected function isStrengthDepleted(Character $character): bool
    {
        return (float) ($character->timers?->strength ?? 0) <= 0;
    }

    protected function isTargetOnActionCooldown(Character $target): bool
    {
        $target->loadMissing('timers');
        return $target->timers?->last_actioned_at?->isFuture() ?? false;
    }

    
    protected function setTargetActionCooldown(Character $target, int $minSeconds, int $maxSeconds): void
    {
        DB::table('character_timers')
            ->where('character_id', $target->id)
            ->update(['last_actioned_at' => now()->addSeconds(rand($minSeconds, $maxSeconds))->getTimestamp()]);
    }

    protected function isOnCooldown(Character $character): bool
    {
        return $character->timers?->next_action_at?->isFuture() ?? false;
    }
    
    protected function isHospitalized(Character $character): bool
    {
        return $character->timers?->hospital_until?->isFuture() ?? false;
    }

    
    protected function isJailed(Character $character): bool
    {
        return $character->timers?->jail_until?->isFuture() ?? false;
    }

    
    protected function isAvailable(Character $character): bool
    {
        return !$this->isHospitalized($character) && !$this->isJailed($character);
    }


    protected function getStrength(Character $character): float
    {
        return (float) ($character->timers?->strength ?? 0);
    }

    protected function resetStrength(Character $character): void
    {
        $character->timers?->update(['strength' => 0])
            ?? $character->timers()->create(['strength' => 0]);
    }

    protected function setCooldown(Character $character, int $seconds): void
    {
        $character->timers?->update(['next_action_at' => now()->addSeconds($seconds)])
            ?? $character->timers()->create(['next_action_at' => now()->addSeconds($seconds)]);
    }

    protected function setCooldownMinutes(Character $character, int $minutes): void
    {
        $this->setCooldown($character, $minutes * 60);
    }

    protected function setCooldownHours(Character $character, int $hours): void
    {
        $this->setCooldown($character, $hours * 3600);
    }

    

    
    protected function crimeRateBonus(?City $city, float $weight): float
    {
        if (! $city) {
            return 0.0;
        }

        return (float) $city->crime_rate * $weight;
    }

    protected function cityTargetOptions(Character $character): array
    {
        $key = "city_targets:{$character->city_id}:{$character->id}";

        return $this->requestMemo($key, fn() => Character::inCity($character->city_id)
            ->where('id', '!=', $character->id)
            ->get(['id', 'display_name'])
            ->map(fn($c) => ['id' => $c->id, 'name' => $c->display_name])
            ->values()
            ->all()
        );
    }

    protected function homeCityNameTargetOptions(Character $character): array
    {
        $key = "home_city_name_targets:{$character->city_id}:{$character->id}";

        return $this->requestMemo($key, fn() => Character::where('home_city_id', $character->city_id)
            ->where('id', '!=', $character->id)
            ->whereNull('deleted_at')
            ->get(['id', 'display_name'])
            ->map(fn($c) => ['id' => $c->display_name, 'name' => $c->display_name])
            ->values()
            ->all()
        );
    }

    protected function activeBusinessNameOptions(Character $character): array
    {
        $key = "active_business_names:{$character->city_id}";

        return $this->requestMemo($key, fn() => \App\Models\Business::where('city_id', $character->city_id)
            ->active()
            ->get(['name'])
            ->map(fn($b) => ['id' => $b->name, 'name' => $b->name])
            ->values()
            ->all()
        );
    }

    private function requestMemo(string $key, callable $resolver): mixed
    {
        if (! app()->bound('request')) {
            return $resolver();
        }

        $attributes = request()->attributes;
        $memo = $attributes->get('action_memo', []);

        if (array_key_exists($key, $memo)) {
            return $memo[$key];
        }

        $value = $resolver();
        $memo[$key] = $value;
        $attributes->set('action_memo', $memo);

        return $value;
    }

    
    
    
    
    
    

    protected function cacheGet(string $key): ?array
    {
        try {
            return Cache::get($key);
        } catch (\Throwable $e) {
            Log::error('[Action][Cache] GET failed.', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    protected function cachePut(string $key, array $data, int $ttlSeconds): bool
    {
        try {
            Cache::put($key, $data, $ttlSeconds);
            return true;
        } catch (\Throwable $e) {
            Log::error('[Action][Cache] PUT failed.', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function cacheForget(string $key): bool
    {
        try {
            Cache::forget($key);
            return true;
        } catch (\Throwable $e) {
            Log::error('[Action][Cache] FORGET failed.', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    protected function cacheHas(string $key): bool
    {
        try {
            return Cache::has($key);
        } catch (\Throwable $e) {
            Log::error('[Action][Cache] HAS failed.', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    
    public static function safeForget(string $key): bool
    {
        try {
            Cache::forget($key);
            return true;
        } catch (\Throwable $e) {
            Log::error('[Action][Cache] FORGET (static) failed.', [
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

}
