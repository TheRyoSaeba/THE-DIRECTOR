<?php

namespace App\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Redis;

/**
 * Who is online / when was a user last seen — kept in Redis instead of being
 * derived from the `sessions` table, so it works the same whatever the
 * session driver is.
 *
 *   presence:last_seen   ZSET  member = user id, score = unix time of the last
 *                              heartbeat (written at most every TOUCH_INTERVAL
 *                              seconds per user by UserSessions::heartbeat()).
 *   presence:ips:{id}    ZSET  member = IP, score = last time seen from it
 *                              (capped to IP_CAP entries, expires after IP_TTL).
 *
 * "Online" = heartbeat within ONLINE_WINDOW (45 min — the same idle window the
 * old auto-logout cron used on sessions.last_activity). Idle members are moved
 * out of the set by expireIdle() (scheduler, every 10 min — replaces the old
 * sessions-based auto-logout); a probabilistic prune in touch() keeps the set
 * bounded even if that job never runs.
 *
 * Every method fails soft: Redis being unavailable means "nobody is online",
 * never an exception on a page.
 */
final class Presence
{
    public const ONLINE_WINDOW  = 2700;      // 45 min
    public const TOUCH_INTERVAL = 30;        // max one presence write per user per 30s
    public const IP_TTL         = 2592000;   // 30 days
    public const IP_CAP         = 20;
    public const PRUNE_AFTER    = 1209600;   // 14 days — safety net only
    private const PRUNE_ODDS    = 200;       // 1 in N touches also prunes

    private const KEY            = 'presence:last_seen';
    private const IPS_PREFIX     = 'presence:ips:';
    private const REVOKED_BEFORE = 'sessions:revoked_before';

    public static function redis(): Connection
    {
        return Redis::connection(config('session.presence_connection') ?: 'default');
    }

    /**
     * Record a heartbeat (and the IP it came from) — one pipelined round trip.
     * Also reads the global "sessions revoked before" stamp in the same trip so
     * UserSessions can honour a log-everyone-out without a per-request lookup.
     *
     * @return int|null  unix time before which all sessions are revoked (null = none / unknown)
     */
    public static function touch(int $userId, ?string $ip = null, ?int $now = null): ?int
    {
        $now ??= time();
        $prune = random_int(1, self::PRUNE_ODDS) === 1;

        try {
            $conn = self::redis();
            if ($conn instanceof PhpRedisConnection) {
                $res = $conn->pipeline(function ($pipe) use ($userId, $ip, $now, $prune) {
                    $pipe->get(self::REVOKED_BEFORE);
                    $pipe->zAdd(self::KEY, $now, (string) $userId);
                    if ($ip) {
                        $ipKey = self::IPS_PREFIX . $userId;
                        $pipe->zAdd($ipKey, $now, $ip);
                        $pipe->zRemRangeByRank($ipKey, 0, -(self::IP_CAP + 1));
                        $pipe->expire($ipKey, self::IP_TTL);
                    }
                    if ($prune) {
                        $pipe->zRemRangeByScore(self::KEY, '-inf', (string) ($now - self::PRUNE_AFTER));
                    }
                });
                $revoked = $res[0] ?? null;
            } else {
                $revoked = $conn->get(self::REVOKED_BEFORE);
                $conn->zadd(self::KEY, $now, (string) $userId);
                if ($ip) {
                    $ipKey = self::IPS_PREFIX . $userId;
                    $conn->zadd($ipKey, $now, $ip);
                    $conn->zremrangebyrank($ipKey, 0, -(self::IP_CAP + 1));
                    $conn->expire($ipKey, self::IP_TTL);
                }
            }
        } catch (\Throwable) {
            return null; // presence is best effort
        }

        return ($revoked === false || $revoked === null) ? null : (int) $revoked;
    }

    /**
     * Revoke every session that started before now (all users). Takes effect on
     * each session's next heartbeat (<= TOUCH_INTERVAL). Replacement for the
     * scheduler's blanket `DELETE FROM sessions`.
     */
    public static function revokeAllSessions(?int $now = null): void
    {
        self::redis()->set(self::REVOKED_BEFORE, (string) ($now ?? time()));
    }

    /** Mark a user offline right now (explicit logout / forced logout). IP history is kept. */
    public static function forget(int $userId): void
    {
        try {
            self::redis()->zrem(self::KEY, (string) $userId);
        } catch (\Throwable) {
        }
    }

    public static function onlineCutoff(?int $now = null): int
    {
        return ($now ?? time()) - self::ONLINE_WINDOW;
    }

    /** Is a lastSeen() score inside the online window? */
    public static function scoreIsOnline(?int $score, ?int $now = null): bool
    {
        return $score !== null && $score >= self::onlineCutoff($now);
    }

    public static function isOnline(int $userId): bool
    {
        return self::scoreIsOnline(self::lastSeen($userId));
    }

    /** Unix time of the user's last heartbeat, or null if unknown / pruned. */
    public static function lastSeen(int $userId): ?int
    {
        try {
            $score = self::redis()->zscore(self::KEY, (string) $userId);
        } catch (\Throwable) {
            return null;
        }

        return ($score === false || $score === null) ? null : (int) $score;
    }

    /**
     * Last-seen scores for many users in one round trip.
     *
     * @param  int[]  $userIds
     * @return array<int,int>  userId => unix time (unknown users omitted)
     */
    public static function lastSeenMany(array $userIds): array
    {
        $userIds = array_values(array_unique(array_map('intval', array_filter($userIds))));
        if (! $userIds) {
            return [];
        }

        try {
            $conn = self::redis();
            if ($conn instanceof PhpRedisConnection) {
                $scores = $conn->pipeline(function ($pipe) use ($userIds) {
                    foreach ($userIds as $id) {
                        $pipe->zScore(self::KEY, (string) $id);
                    }
                });
            } else {
                $scores = array_map(fn($id) => $conn->zscore(self::KEY, (string) $id), $userIds);
            }
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($userIds as $i => $id) {
            $s = $scores[$i] ?? null;
            if ($s !== false && $s !== null) {
                $out[$id] = (int) $s;
            }
        }

        return $out;
    }

    /**
     * Users online since $since (default: the 45-min window), most recent first.
     *
     * @return array<int,int>  userId => last seen unix time, ordered desc
     */
    public static function onlineUserIds(?int $since = null, ?int $limit = null): array
    {
        $since ??= self::onlineCutoff();
        $options = ['withscores' => true];
        if ($limit !== null) {
            $options['limit'] = [0, $limit];
        }

        try {
            $rows = self::redis()->zrevrangebyscore(self::KEY, '+inf', (string) $since, $options);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ((array) $rows as $member => $score) {
            $out[(int) $member] = (int) $score;
        }

        return $out;
    }

    /**
     * The subset of $userIds that is online.
     *
     * @return array<int,int>  userId => last seen
     */
    public static function onlineAmong(array $userIds): array
    {
        $cutoff = self::onlineCutoff();

        return array_filter(self::lastSeenMany($userIds), fn($s) => $s >= $cutoff);
    }

    /**
     * When did an offline user effectively go offline? Explicit/forced logouts
     * and the idle auto-logout write users.last_login_at; if the auto-logout job
     * hasn't processed an idle user yet, they "went offline" when their 45-min
     * window ran out. This is the value the old code read from last_login_at.
     */
    public static function wentOfflineAt(?int $lastSeen, ?\DateTimeInterface $lastLoginAt): ?int
    {
        $candidates = array_filter([
            $lastLoginAt?->getTimestamp(),
            $lastSeen !== null ? $lastSeen + self::ONLINE_WINDOW : null,
        ], fn($v) => $v !== null);

        return $candidates ? max($candidates) : null;
    }

    /**
     * IPs this user was seen from in the last IP_TTL (most recent first).
     *
     * @return string[]
     */
    public static function recentIps(int $userId): array
    {
        try {
            return array_values(array_map('strval', (array) self::redis()->zrevrange(self::IPS_PREFIX . $userId, 0, -1)));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Scheduler hook (replaces the sessions-based auto-logout): find the users
     * whose heartbeat is older than the online window, hand them to
     * $beforeRemove (e.g. stamp users.last_login_at), then drop them from the
     * set. Only members still below the cutoff are removed, so a user who came
     * back between the read and the delete keeps their fresh entry, and if the
     * callback throws nothing is removed (the next run retries).
     *
     * @param  callable(int[]):void|null  $beforeRemove
     * @return int[] user ids that just went idle
     */
    public static function expireIdle(?callable $beforeRemove = null, ?int $now = null): array
    {
        $cutoff = self::onlineCutoff($now) - 1; // strictly older than the window

        $conn = self::redis();
        $ids = array_map('intval', (array) $conn->zrangebyscore(self::KEY, '-inf', (string) $cutoff));
        if (! $ids) {
            return [];
        }

        if ($beforeRemove) {
            $beforeRemove($ids);
        }
        $conn->zremrangebyscore(self::KEY, '-inf', (string) $cutoff);

        return $ids;
    }
}
