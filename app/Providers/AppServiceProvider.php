<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\DB;
class AppServiceProvider extends ServiceProvider
{
    
    public function register(): void
    {
    }

    public function boot(): void
    {

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

         if (app()->environment('local')) {
        DB::listen(function ($query) {
            if (str_contains($query->sql, '"is_active" = ?') && 
                isset($query->bindings[0]) && 
                $query->bindings[0] === 1) {
                
                logger()->error('Found bad is_active query', [
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10)
                ]);
            }
        });
    }

        RateLimiter::for('guest', function (Request $request) {
             
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('players', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('actions', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

    }
}
