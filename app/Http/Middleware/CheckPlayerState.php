<?php

namespace App\Http\Middleware;

use App\Models\BannedUser;
use App\Services\UserService;
use App\Support\Presence;
use App\Support\UserSessions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class CheckPlayerState
{
    private const COOKIE_NAME   = 'trust_token';
    private const COOKIE_TTL    = 1051200; 
    private const BAN_CACHE_TTL = 300;     

    public function __construct(private UserService $userService) {}

    public function handle(Request $request, Closure $next): Response
    {
        
        if ($request->routeIs('logout')) {
            return $next($request);
        }

        $isBannedRoute = $request->routeIs('banned');
        $isAdminedRoute = $request->routeIs('admined');
        $isLoginRoute = $request->routeIs('login');

        

        
        if (! $isBannedRoute && ! $isAdminedRoute && ! $isLoginRoute) {
            $ip          = $request->ip();
            $cookieValue = $request->cookie(self::COOKIE_NAME);

            // Returns ['banned' => bool, 'expires_at' => ?DateTime]. The
            // expiry is the lifetime of whatever ban matched, so any ban we
            // derive below (cookie, account, swept accounts) inherits it and a
            // temp ban stays temp. Permanent wins if any match is permanent.
            $match = $this->checkIdentifierBan($request, $ip, $cookieValue);

            if ($match['banned']) {
                $matchedExpiry = $match['expires_at'];

                if (Auth::check()) {
                    $user = Auth::user();

                    // Key the cookie ban to this user (so unban can clear it)
                    // and carry the matched expiry.
                    if (! $cookieValue) {
                        $cookieValue = hash('sha256', $ip . now()->timestamp . rand());
                    }
                    BannedUser::banIdentifier('cookie', $cookieValue, "Banned user: {$user->username}", null, $matchedExpiry, $user->id);
                    Cookie::queue(self::COOKIE_NAME, $cookieValue, self::COOKIE_TTL);

                    if (! $user->is_banned) {
                        $user->ban(null, $matchedExpiry);
                        $user->character?->kill('banned');
                    }

                    return redirect()->route('banned');
                }

                // Anonymous visitor on a banned identifier. Auto-generate a
                // cookie ban (if none) carrying the matched expiry, then sweep
                // any non-banned accounts on this IP — also inheriting the
                // expiry and keyed to each account so they can be unbanned.
                if (! $cookieValue) {
                    $cookieValue = hash('sha256', $ip . now()->timestamp . rand());
                    BannedUser::banIdentifier('cookie', $cookieValue, 'Auto-generated from banned IP', null, $matchedExpiry);
                }
                Cookie::queue(self::COOKIE_NAME, $cookieValue, self::COOKIE_TTL);

                \App\Models\User::where('last_ip', $ip)
                    ->where('is_banned', false)
                    ->each(function ($u) use ($matchedExpiry) {
                        $u->ban('Evading ban via new account.', $matchedExpiry);
                        $u->character?->kill('Evading ban via new account.');
                    });

                return redirect()->route('admined');
            }
        }

        
        
        
        
        if (! Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();

        
        if ($user->last_login_at && now()->subHours(4)->greaterThan($user->last_login_at)) {
            // Bumping last_login_at ends this user's other sessions too (see
            // UserSessions: last_login_at is the per-user session epoch).
            $user->last_login_at = now();
            $user->save();

            Presence::forget($user->id);
            UserSessions::purgeDatabaseSessions($user->id, session()->getId());

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        
            return redirect()->route('login')->with('error', 'Your session has expired after 4 hours. Please log in again.');
        }

        // Superseded by a newer login/logout, idle > 45 min, or globally
        // revoked → log out; otherwise write the (throttled) presence heartbeat.
        if ($ended = UserSessions::enforce($request, $user)) {
            return $ended;
        }

        
        
        
        
        if ($user->is_banned) {
            // Temp ban that has elapsed: lift it lazily and let them through.
            // This is the auto-expiry path — no cron needed, the ban clears on
            // the banned user's next request once banned_until has passed.
            if (! $user->isCurrentlyBanned()) {
                $this->userService->unbanUser($user);
            } else {
                if ($isBannedRoute) {
                    return $next($request);
                }

                $ip          = $request->ip();
                $cookieValue = $request->cookie(self::COOKIE_NAME);
                $this->propagateBanIdentifiers($request, $user, $ip, $cookieValue, $user->banned_until);

                return redirect()->route('banned');
            }
        }

        
        if ($isBannedRoute || $isAdminedRoute) {
            return redirect()->route('dashboard');
        }

        
        $character = $user->getLoadedCharacter();

        if (! $character) {
            if ($request->routeIs('character.create') || $request->routeIs('character.store')) {
                return $next($request);
            }
            return redirect()->route('character.create');
        }

        if ($request->routeIs('character.create') || $request->routeIs('character.store')) {
            return redirect()->route('dashboard');
        }

        if ($character->trashed()) {
            if (! $request->routeIs('death') && ! $request->routeIs('death.reincarnate') && ! $request->routeIs('death.last-words')) {
                return redirect()->route('death');
            }
            return $next($request);
        }

        if ($request->routeIs('death') || $request->routeIs('death.reincarnate')) {
            return redirect()->route('dashboard');
        }

        if ($character->timers?->jail_until?->isFuture()) {
            if (! $request->routeIs('jail')) {
                return redirect()->route('jail');
            }
            return $next($request);
        }

        if ($request->routeIs('jail')) {
            return redirect()->route('dashboard');
        }

        if ($character->timers?->hospital_until?->isFuture()) {
            if (! $request->routeIs('hospital')) {
                return redirect()->route('hospital');
            }
            return $next($request);
        }

        if ($request->routeIs('hospital')) {
            return redirect()->route('dashboard');
        }

        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }

    

    
    /**
     * One pass over the identifier bans matching this ip/cookie. Returns both
     * whether a ban is in force AND its effective expiry (null = permanent;
     * permanent wins over any temp match). Both are cached together for
     * BAN_CACHE_TTL so we don't re-hit the DB every request — and so the
     * expiry used to derive new bans is the same value the cache decided on.
     *
     * @return array{banned: bool, expires_at: ?\DateTimeInterface}
     */
    private function checkIdentifierBan(Request $request, string $ip, ?string $cookieValue): array
    {
        $cacheKey    = 'ban_ck_' . md5($ip . '|' . ($cookieValue ?? ''));
        $cachedUntil = $request->session()->get("{$cacheKey}_until", 0);

        if (time() < $cachedUntil) {
            $cachedExpiry = $request->session()->get("{$cacheKey}_expiry");

            return [
                'banned'     => (bool) $request->session()->get("{$cacheKey}_result", false),
                'expires_at' => $cachedExpiry ? \Carbon\Carbon::parse($cachedExpiry) : null,
            ];
        }

        $rows = BannedUser::query()
            ->where(function ($q) use ($ip, $cookieValue) {
                $q->where(fn($w) => $w->where('type', 'ip')->where('value', $ip));
                if ($cookieValue) {
                    $q->orWhere(fn($w) => $w->where('type', 'cookie')->where('value', $cookieValue));
                }
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->get();

        $banned = $rows->isNotEmpty();

        // Permanent (null expiry) wins; otherwise the latest temp expiry.
        $expiry = $banned && ! $rows->contains(fn($r) => $r->expires_at === null)
            ? $rows->max('expires_at')
            : null;

        $request->session()->put("{$cacheKey}_result", $banned);
        $request->session()->put("{$cacheKey}_expiry", $expiry?->toIso8601String());
        $request->session()->put("{$cacheKey}_until",  time() + self::BAN_CACHE_TTL);

        return ['banned' => $banned, 'expires_at' => $expiry];
    }

    
    private function propagateBanIdentifiers(Request $request, $user, string $ip, ?string $cookieValue, ?\DateTimeInterface $expiresAt = null): void
    {
        BannedUser::banIdentifier('ip', $ip, "Banned user: {$user->username}", null, $expiresAt, $user->id);

        if (! $cookieValue) {
            $cookieValue = hash('sha256', $user->id . now()->timestamp . rand());
        }

        BannedUser::banIdentifier('cookie', $cookieValue, "Banned user: {$user->username}", null, $expiresAt, $user->id);
        Cookie::queue(self::COOKIE_NAME, $cookieValue, self::COOKIE_TTL);

        $cacheKey = 'ban_ck_' . md5($ip . '|' . $cookieValue);
        $request->session()->forget(["{$cacheKey}_result", "{$cacheKey}_until"]);
    }
}
