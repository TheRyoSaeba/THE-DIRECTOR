<?php

namespace App\Http\Middleware;

use App\Models\City;
use App\Support\SafeCache;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;


class CityCheck
{
    public function handle(Request $request, Closure $next): Response
    {
        $city = $request->route('city');

        if (is_string($city)) {
            $slug = strtolower($city);
            $city = SafeCache::remember(
                "city_by_slug_{$slug}",
                3600,
                fn() => City::whereRaw('LOWER(slug) = ?', [$slug])->first()
            );
        }

        if (!$city) {
            abort(404, 'City not found');
        }

        $character = $request->user()->getLoadedCharacter();

        if (!$character) {
            return redirect()->route('character.create');
        }

        if ($character->trashed()) {
            return redirect()->route('death');
        }

        if ($character->city_id !== $city->id) {
            return redirect()->route('city.show', $character->city->slug)
                ->with('error', 'You must travel to ' . $city->name . ' first!');
        }

        $request->attributes->set('character', $character);
        $request->attributes->set('city', $city);

        return $next($request);
    }
}
