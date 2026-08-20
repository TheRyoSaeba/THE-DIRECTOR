<?php

namespace App\Models;

use App\Models\GameItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Models\Character;

class CustomsLog extends Model
{
    protected $table = 'travel_logs';

    protected $fillable = [
        'city_id',
        'character_id',
        'character_name',
        'gender',
        'conviction_count',
        'direction',
        'other_city_id',
        'snapshot_data',
        'searched_by_id',
        'was_searched',
        'search_results',
    ];

    protected $casts = [
        'snapshot_data'    => 'array',
        'search_results'   => 'array',
        'was_searched'     => 'boolean',
        'conviction_count' => 'integer',
    ];

    
    

    

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    public function otherCity()
    {
        return $this->belongsTo(City::class, 'other_city_id');
    }

    public function character()
    {
        return $this->belongsTo(Character::class, 'character_id');
    }

    public function searchedBy()
    {
        return $this->belongsTo(Character::class, 'searched_by_id');
    }

    

    
    public static function logTravel(Character $character, int $fromCityId, int $toCityId): void
    {
        $items = $character->items()
            ->onHand()
            ->with('template:id,name,slug,type,slot,image_url')
            ->get()
            ->map(fn ($item) => [
                'name'      => $item->template->name      ?? null,
                'slug'      => $item->template->slug      ?? null,
                'type'      => $item->template->type      ?? null,
                'slot'      => $item->template->slot      ?? null,
                'image_url' => $item->template->image_url ?? null,
            ])
            ->values()
            ->all();

        $snapshot = [
            'avatar_url'   => $character->avatar_url,
            'cash_on_hand' => (int) $character->cash_on_hand,
            'dirty_cash'   => (int) $character->dirty_cash,
            'items'        => $items,
        ];

        $convictions = $character->convictionCount();
        $now         = now();
        $base        = [
            'character_id'     => $character->id,
            'character_name'   => $character->display_name,
            'gender'           => $character->gender,
            'conviction_count' => $convictions,
            'snapshot_data'    => json_encode($snapshot),
            'created_at'       => $now,
            'updated_at'       => $now,
        ];

        DB::table('travel_logs')->insert([
            array_merge($base, [
                'city_id'      => $fromCityId,
                'direction'    => 'OUT',
                'other_city_id' => $toCityId,
            ]),
            array_merge($base, [
                'city_id'      => $toCityId,
                'direction'    => 'IN',
                'other_city_id' => $fromCityId,
            ]),
        ]);
    }

    

    
    public static function forCustomsPage(
        \Illuminate\Support\Collection $logs,
        array $cityNames,
        string $homeCityName
    ): array {
        return $logs->map(function ($log) use ($cityNames, $homeCityName) {
            $snapshot      = is_array($log->snapshot_data) ? $log->snapshot_data : [];
            $otherCityName = $cityNames[$log->other_city_id] ?? 'Unknown';

            $timeDiff = $log->created_at
                ? $log->created_at->diffForHumans(null, true, true) . ' ago'
                : 'Unknown';

            return [
                'id'             => $log->id,
                'character_name' => $log->character_name,
                'gender'         => $log->gender,
                'conviction_count' => (int) $log->conviction_count,
                'direction'      => $log->direction,
                'time_diff'      => $timeDiff,
                'was_searched'   => (bool) $log->was_searched,
                'other_city'     => $otherCityName,
                
                'from_city'      => $log->direction === 'IN' ? $otherCityName : $homeCityName,
                'to_city'        => $log->direction === 'IN' ? $homeCityName  : $otherCityName,
                'snapshot'       => [
                    'avatar_url'   => $snapshot['avatar_url']   ?? null,
                    'cash_on_hand' => (int) ($snapshot['cash_on_hand'] ?? 0),
                    'dirty_cash'   => (int) ($snapshot['dirty_cash']   ?? 0),
                    'items'        => $snapshot['items'] ?? [],
                ],
            ];
        })->values()->all();
    }
}
