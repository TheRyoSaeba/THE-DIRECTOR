<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCharacterCareer
{
    public function handle(Request $request, Closure $next, string $career): Response
    {
        $character = $request->user()?->getLoadedCharacter();

        if (! $character) {
            return redirect()->route('/');
        }

        
        if (strcasecmp($character->career->name ?? '', $career) !== 0) {
            return redirect()->route('dashboard');
        }

        //make exception for corporation
        
    
        elseif (strcasecmp($career, 'Corporation') === 0) {
            return $next($request);
        }
        
        elseif (strcasecmp($career, 'Law') === 0 || strcasecmp($career, 'Technician') === 0) {
        
  
        }

        
        elseif (! $character->isInHomeCity() && $request->route()?->getName() !== 'career.police.actions.arrest') {
            return redirect()->route('dashboard')
                ->with('error', "You must be at your home city's {$this->getAreaName($career)} to do this!");
        }

        return $next($request);
    }

    
    private function getAreaName(string $career): string
    {
        return match (strtolower($career)) {
            'police' => 'precinct',
            'healthcare' => 'Emergency Room',
            'banking' => 'bank',
            'customs' => 'airport',
            default => 'Jurisdiction',
        };
    }
}
