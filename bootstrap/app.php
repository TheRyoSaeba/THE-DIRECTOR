<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            
            
            
            
            \App\Http\Middleware\CheckPlayerState::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        
        $middleware->trustProxies(at: '*');

        // `throttle:*` -> ThrottleRequestsWithRedis: one Lua EVAL per limit
        // (DurationLimiter) on the default Redis connection instead of the
        // cache-store limiter's several GET/ADD/INCR round trips. The named
        // limiters in AppServiceProvider (guest/players/actions) are unchanged.
        $middleware->throttleWithRedis();

        $middleware->alias([   
            'city.access' => \App\Http\Middleware\CityCheck::class,
            'career'      => \App\Http\Middleware\EnsureCharacterCareer::class,
            'admin'       => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
  
        $exceptions->respond(function ($response, $exception, $request) {

            $statusCode = $response->getStatusCode();

            if ($statusCode === 429) {
                $message = 'Take it easy! You\'re moving too fast. Slow down and try again in a moment.';

                // Inertia request — render a flash on the current page, no
                // redirect needed.
                if ($request->header('X-Inertia')) {
                    return back()->with('error', $message);
                }

                // JSON / API — return JSON with Retry-After preserved.
                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $message,
                    ], 429, $response->headers->all());
                }

                // Plain HTML — render a 429 view, do NOT redirect. `back()` on
                // a rate-limited GET creates an infinite redirect loop: the
                // referrer is the rate-limited URL, redirect back to it,
                // 429 again, redirect, repeat until the browser bails. The
                // page itself shows the message.
                $view = view()->exists('errors.429') ? 'errors.429' : 'errors.500';
                return response()->view($view, [
                    'status' => 429,
                    'message' => $message,
                ], 429);
            }

            if (! in_array($statusCode, [403, 404, 405, 500, 503])) {
                return $response;
            }

            // Local debugging: show Laravel's real error page for server errors
            // instead of the styled 500 page (production has APP_DEBUG=false).
            if ($statusCode >= 500 && config('app.debug')) {
                return $response;
            }

            
            
            if ($request->header('X-Inertia')) {
                try {
                    return Inertia::render('Errors/Catchall', ['status' => $statusCode])
                        ->toResponse($request)
                        ->setStatusCode($statusCode);
                } catch (\Throwable) {
                    
                    
                    
                }
            }

            
            $blade = match ($statusCode) {
                503     => 'errors.503',
                default => view()->exists("errors.{$statusCode}") ? "errors.{$statusCode}" : 'errors.500',
            };

            return response()->view($blade, ['status' => $statusCode], $statusCode);
        });

    })->create();
