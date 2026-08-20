<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            Log::warning('Unauthenticated admin access attempt', [
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
            ]);
            abort(404, 'Not Found');
        }

        if ($request->user()->is_admin !== true) {
            Log::warning('Non-admin attempted admin access', [
                'user_id' => $request->user()->id,
                'username' => $request->user()->username,
                'ip' => $request->ip(),
                'url' => $request->fullUrl(),
            ]);
            abort(404, 'Not Found');
        }

        if ($request->method() !== 'GET') {
            Log::info('Admin action', [
                'admin_id' => $request->user()->id,
                'admin_username' => $request->user()->username,
                'method' => $request->method(),
                'route' => $request->route()?->getName(),
                'ip' => $request->ip(),
            ]);
        }

        return $next($request);
    }
}
