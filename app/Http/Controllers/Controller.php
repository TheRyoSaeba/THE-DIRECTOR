<?php

namespace App\Http\Controllers;

use App\Models\Character; 
use App\Models\City;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function getContext(Request $request, ?City $city = null): array
    {
        $character = $request->attributes->get('character');
        $city = $city ?? $request->attributes->get('city');

        if (!$character || !$city) {
            throw new \Exception('Character or City context not found.');
        }

        return [$character, $city];
    }

    protected function requireCharacter(Request $request, ?string $redirectRoute = null): Character|RedirectResponse|null
    {
        $character = $request->user()->getLoadedCharacter();
        
        if (!$character) {
            if ($redirectRoute) {
                return redirect()->route($redirectRoute)->with('error', 'No character found');
            }
            return null;
        }
        
        return $character;
    }

    protected function isOnlyInertiaPartial(Request $request, string $prop): bool
    {
        $partialData = $request->header('X-Inertia-Partial-Data');

        if (! $partialData) {
            return false;
        }

        $props = array_values(array_filter(array_map('trim', explode(',', $partialData))));

        return count($props) === 1 && $props[0] === $prop;
    }
}
