<?php

namespace App\Http\Middleware;

use App\Models\City;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;


class CityCheck
{
    public function handle(Request $request, Closure $next): Response
    {
        $city = $request->route('city');

        // Route::bind('city') (AppServiceProvider) already turned the slug
        // into a fresh City for every /{city} route. This branch only runs
        // for a route without that binding; it resolves the same way (fresh
        // row, no hour-long cache of mutable city columns).
        if (is_string($city)) {
            $city = City::resolveForRoute($city, $request->user(), caseInsensitive: true);
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
