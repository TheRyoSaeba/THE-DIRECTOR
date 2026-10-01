<?php

namespace App\Events\CityEvents;

use App\Models\Character;
use App\Services\JournalService;
use App\Support\CrimeThresholds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class Mugging extends CityEvent
{
    protected function probability(): int
    {
        return 2;
    }

    public function shouldFire(Character $character): bool
    {
        $currentCity = $character->city;

        if (! $currentCity) {
            return false;
        }

        $rate = (float) $currentCity->crime_rate;

        
        return $rate > CrimeThresholds::MODERATE_MAX;
    }

    protected function handle(Character $character): void
    {
        $currentCity = $character->city;

        if (! $currentCity) {
            return;
        }

        
        
        
        
        $now = now()->getTimestamp();

        $candidates = DB::table('characters')
            ->leftJoin('character_timers', 'characters.id', '=', 'character_timers.character_id')
            
            
            ->whereIntegerInRaw('characters.user_id', array_keys(\App\Support\Presence::onlineUserIds()))
            ->where('characters.city_id', $currentCity->id)
            ->whereNull('characters.deleted_at')
            ->where(function ($q) use ($now) {
                $q->whereNull('character_timers.jail_until')
                  ->orWhere('character_timers.jail_until', '<=', $now);
            })
            ->where(function ($q) use ($now) {
                $q->whereNull('character_timers.hospital_until')
                  ->orWhere('character_timers.hospital_until', '<=', $now);
            })
            ->where('characters.cash_on_hand', '>=', 1)
            ->limit(20)
            ->pluck('characters.id')
            ->all();

        if (empty($candidates)) {
            return;
        }

        $victimId = $candidates[array_rand($candidates)];

        
        try {
            DB::transaction(function () use ($victimId, $currentCity) {
                $victim = DB::table('characters')
                    ->where('id', $victimId)
                    ->lockForUpdate()
                    ->first();

                if (! $victim || $victim->cash_on_hand < 1) {
                    return;
                }

                $pct    = mt_rand(10, 25) / 100;
                $amount = (int) floor($victim->cash_on_hand * $pct);
                $amount = max(1, min($amount, (int) $victim->cash_on_hand));

                DB::table('characters')
                    ->where('id', $victimId)
                    ->decrement('cash_on_hand', $amount);

                JournalService::custom($victimId, 'mugging', [
                    'city_name' => $currentCity->name,
                    'amount'    => $amount,
                    'message'   => 'Some random thug elbowed you and ran off with your wallet containing $'
                        . number_format($amount) . '.',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('[CityEvent:Mugging] Failed.', [
                'victim_id'   => $victimId,
                'city_id'     => $currentCity->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}
