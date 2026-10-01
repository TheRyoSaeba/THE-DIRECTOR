<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session lifecycle that used to be done by deleting `sessions` rows, made
 * driver-independent (works for SESSION_DRIVER=redis and =database).
 *
 * Per-user epoch = users.last_login_at. Every place that used to delete a
 * user's session rows also writes last_login_at (login, logout, 4-hour forced
 * re-login, idle auto-logout), so a session remembers the last_login_at it was
 * created under and ends as soon as the user row carries a newer one. The user
 * row is already loaded on every authenticated request, so the check costs no
 * extra round trip, needs no migration, and survives a Redis flush (a flush
 * can only log people out, never let a revoked session back in).
 */
final class UserSessions
{
    public const EPOCH       = 'sess_epoch';   // users.last_login_at (unix) this session was created under
    public const STARTED     = 'sess_at';      // time() the session was created / adopted
    public const PRESENCE_AT = 'presence_at';  // last heartbeat written for this session (throttle)
    public const PRESENCE_IP = 'presence_ip';  // IP of that heartbeat

    /** Call right after Auth::login() (+ regenerate) with the freshly stamped user. */
    public static function started(Request $request, User $user): void
    {
        $now = time();
        $session = $request->session();
        $session->put(self::EPOCH, $user->last_login_at?->getTimestamp() ?? 0);
        $session->put(self::STARTED, $now);
        $session->put(self::PRESENCE_AT, $now);
        $session->put(self::PRESENCE_IP, $request->ip());

        Presence::touch($user->id, $request->ip(), $now);
    }

    /**
     * Per authenticated request. Ends the session (returns a redirect) when it
     * was superseded by a newer login/logout, idled past the online window or
     * was globally revoked; otherwise writes the presence heartbeat — at most
     * once per Presence::TOUCH_INTERVAL (or when the IP changes). The throttle
     * stamp lives in the session itself, so a request that isn't due costs zero
     * extra round trips.
     */
    public static function enforce(Request $request, User $user): ?Response
    {
        $session = $request->session();
        $now = time();
        $lastLogin = $user->last_login_at?->getTimestamp();

        $epoch = $session->get(self::EPOCH);
        if ($epoch === null) {
            // Session created before this code (or by another login path):
            // adopt the current epoch. Under the database driver such sessions
            // are still purged at login/logout by purgeDatabaseSessions().
            $session->put(self::EPOCH, $lastLogin ?? 0);
            $session->put(self::STARTED, $now);
        } elseif ($lastLogin !== null && (int) $epoch < $lastLogin) {
            return self::end($request, 'You have been logged out. Please log in again.');
        }

        $presenceAt = (int) $session->get(self::PRESENCE_AT, 0);
        if ($presenceAt > 0 && $now - $presenceAt > Presence::ONLINE_WINDOW) {
            // Same rule as the old auto-logout cron (session idle > 45 min).
            return self::end($request, 'You were logged out after 45 minutes of inactivity.');
        }

        $ip = $request->ip();
        if ($now - $presenceAt >= Presence::TOUCH_INTERVAL || $session->get(self::PRESENCE_IP) !== $ip) {
            $revokedBefore = Presence::touch($user->id, $ip, $now);
            $session->put(self::PRESENCE_AT, $now);
            $session->put(self::PRESENCE_IP, $ip);

            if ($revokedBefore !== null && (int) $session->get(self::STARTED, 0) < $revokedBefore) {
                return self::end($request, 'You have been logged out. Please log in again.');
            }
        }

        return null;
    }

    /**
     * End every session of this user everywhere (what deleting all their
     * session rows used to do). Also marks them offline.
     */
    public static function logoutEverywhere(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
        Presence::forget($user->id);
        self::purgeDatabaseSessions($user->id);
    }

    /**
     * Legacy path for SESSION_DRIVER=database only: delete the user's other
     * session rows, exactly as before, so sessions that predate the epoch keys
     * are also killed. No-op for every other driver.
     */
    public static function purgeDatabaseSessions(int $userId, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $userId)
            ->when($exceptSessionId, fn($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }

    /**
     * Every IP the user is known to have used recently: the presence IP history
     * (30 days), plus live session rows while the database driver is in use.
     *
     * @return string[]
     */
    public static function knownIps(int $userId): array
    {
        $ips = Presence::recentIps($userId);

        if (config('session.driver') === 'database') {
            $ips = array_merge($ips, DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', $userId)
                ->whereNotNull('ip_address')
                ->distinct()
                ->pluck('ip_address')
                ->all());
        }

        return array_values(array_unique(array_filter($ips)));
    }

    private static function end(Request $request, string $message): Response
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', $message);
    }
}
