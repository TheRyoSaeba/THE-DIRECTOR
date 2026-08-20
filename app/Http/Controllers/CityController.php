<?php

namespace App\Http\Controllers;

use App\Events\CityEvents\CityEventDispatcher;
use App\Models\Business;
use App\Models\Character;
use App\Models\CharacterHistory;
use App\Models\City;
use App\Models\MayorTerm;
use App\Services\MayorService;
use App\Support\SafeCache;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class CityController extends Controller
{

//! ONCE STARTING CAREERS ARE DONE ADD OPTION TO RETRAIN BACK INTO THEM.
    
    public function show(Request $request, City $city)
    {
        if ($this->isOnlyInertiaPartial($request, 'population')) {
            return Inertia::render('City/City', [
                'population' => Inertia::optional(fn() => $this->populationPayload($city)),
            ]);
        }

        if ($this->isOnlyInertiaPartial($request, 'cityNews')) {
            return Inertia::render('City/City', [
                'cityNews' => Inertia::optional(fn() => $this->cityNewsPayload($city)),
            ]);
        }

        [$character, $city] = $this->getContext($request, $city);

        
        
       CityEventDispatcher::dispatch($character, 'move');

        //TODO market like ticker city event live alert 
        
        
        
        /*if ($city->mayor_id) {
            $term = MayorTerm::activeForCity($city->id);
            if ($term) {
                try {
                    MayorService::tick($term, $city);
                    $city->refresh();
                } catch (\Throwable $e) {
                    Log::error('[CityController] MayorService::tick failed.', [
                        'city_id' => $city->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }*/

        $residents = $city->residents()->alive()->count();

        $services = Business::byCity($city)
            ->active()
            ->orderBy('sort_order')
            ->get()
            ->map(fn($b) => [
                'name' => $b->name,
                'code' => $b->code,
                'slug' => $b->slug,
                'image_url' => $b->image_url,
                'description' => $b->description,
                'icon' => $b->icon, 
            ]);

     
        $policies = [
            'income_tax_rate'        => (int)  $city->income_tax_rate,
            'corporate_tax_rate'     => (int)  $city->corporate_tax_rate,
            'corp_regulation_active' => (bool) $city->corp_regulation_active,
            'death_sentence_active'  => (bool) $city->death_sentence_active,
        ];

        return Inertia::render('City/City', [
            'cityData' => [
                'id'          => $city->id,
                'name'        => $city->name,
                'slug'        => $city->slug,
                'description' => $city->description,
                'image_url'   => $city->image_url,
                'crime_rate'  => (int) $city->crime_rate,
                'mayor'       => $city->mayor_display_name,
                'residents'  => $residents,
                'policies'    => $policies,
            ],
            'services'  => $services,
            'cityNews' => Inertia::optional(fn() => $this->cityNewsPayload($city)),
            'population' => Inertia::optional(fn() => $this->populationPayload($city)),
        ]);
    }

    private function cityNewsPayload(City $city): array
    {
        //less than 12 hours 
        $cutoff = now('UTC')->subHours(12);
        $items = collect();

        Character::onlyTrashed()
            ->where('city_id', $city->id)
            ->where('deleted_at', '>=', $cutoff)
            ->whereIn('death_cause', ['Murdered', 'Organized Hit', 'Explosive', 'Combat'])
            ->orderByDesc('deleted_at')
            ->limit(6)
            ->get(['id', 'display_name', 'deleted_at'])
            ->each(function ($character) use ($items) {
                $items->push([
                    'id' => "death-{$character->id}",
                    'type' => 'death',
                    'message' => "{$character->display_name} has been killed!",
                    'occurredAt' => $character->deleted_at?->toIso8601String(),
                ]);
            });

        CharacterHistory::query()
            ->where('updated_at', '>=', $cutoff)
            ->whereNotNull('career_timeline')
            ->whereHas('character', fn($query) => $query->where('home_city_id', $city->id))
            ->with(['character:id,display_name,city_id'])
            ->get(['character_id', 'career_timeline'])
            ->each(function (CharacterHistory $history) use ($items, $cutoff) {
                if (! $history->character) {
                    return;
                }

                foreach ($history->career_timeline ?? [] as $index => $event) {
                    if (($event['type'] ?? null) !== 'promoted' || empty($event['new_rank']) || empty($event['at'])) {
                        continue;
                    }

                    try {
                        $occurredAt = Carbon::createFromFormat('d/m/y H:i:s T', $event['at'], 'UTC');
                    } catch (\Throwable) {
                        continue;
                    }

                    if ($occurredAt->lt($cutoff)) {
                        continue;
                    }

                    $items->push([
                        'id' => "promotion-{$history->character_id}-{$index}",
                        'type' => 'promotion',
                        'message' => "{$history->character->display_name} has been promoted to {$event['new_rank']}.",
                        'occurredAt' => $occurredAt->toIso8601String(),
                    ]);
                }
            });

        collect(SafeCache::get("city_news:business_purchases:{$city->id}", []))
            ->filter(function ($event) use ($cutoff) {
                if (! is_array($event) || empty($event['occurredAt'])) {
                    return false;
                }

                try {
                    return Carbon::parse($event['occurredAt'])->gte($cutoff);
                } catch (\Throwable) {
                    return false;
                }
            })
            ->each(fn($event) => $items->push($event));

        return ['items' => $items
            ->sortByDesc('occurredAt')
            ->take(8)
            ->values()
            ->all()];
    }

    private function populationPayload(City $city)
    {
        return $city->characters()
            ->withTrashed()
            ->with(['career:id,name'])
            ->orderByRaw('deleted_at IS NOT NULL')
            ->orderBy('display_name')
            ->paginate(20)
            ->through(function ($char) {
                $rank = $char->current_rank;

                return [
                    'displayName' => $char->display_name,
                    'avatarUrl'   => $char->avatar_url,
                    'rank'        => $rank->rank_name ?? 'Entry Level',
                    'career'      => $char->career?->name ?? 'Unknown',
                    'glowColor'   => $char->glow_color ?? 'cyan',
                    'isDead'      => $char->trashed(),
                ];
            });
    }

}
