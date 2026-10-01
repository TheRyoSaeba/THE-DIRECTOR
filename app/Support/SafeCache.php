<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SafeCache
{
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            return Cache::get($key, $default);
        } catch (\Throwable $e) {
            Log::warning('[Cache] get failed; using default.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    public static function put(string $key, mixed $value, mixed $ttl = null): bool
    {
        try {
            return Cache::put($key, $value, $ttl);
        } catch (\Throwable $e) {
            Log::warning('[Cache] put failed.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public static function remember(string $key, mixed $ttl, callable $resolver, mixed $fallback = null): mixed
    {
        try {
            return Cache::remember($key, $ttl, $resolver);
        } catch (\Throwable $cacheException) {
            Log::warning('[Cache] remember failed; using direct resolver.', [
                'key' => $key,
                'error' => $cacheException->getMessage(),
            ]);
        }

        try {
            return $resolver();
        } catch (\Throwable $resolverException) {
            Log::error('[Cache] direct resolver failed.', [
                'key' => $key,
                'error' => $resolverException->getMessage(),
            ]);

            return $fallback;
        }
    }

    public static function rememberForever(string $key, callable $resolver, mixed $fallback = null): mixed
    {
        try {
            return Cache::rememberForever($key, $resolver);
        } catch (\Throwable $cacheException) {
            Log::warning('[Cache] rememberForever failed; using direct resolver.', [
                'key' => $key,
                'error' => $cacheException->getMessage(),
            ]);
        }

        try {
            return $resolver();
        } catch (\Throwable $resolverException) {
            Log::error('[Cache] direct resolver failed.', [
                'key' => $key,
                'error' => $resolverException->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * Warm the request-scoped memo (Cache::memo()) for several keys with a
     * single round trip (MGET). Later rememberMemo()/rememberForeverMemo()
     * calls for these keys are then served from memory. Keys that are absent
     * are memoised as null, so the remember* helpers still run their resolver
     * and write the value back with their own TTL. If Redis is down this is a
     * no-op and the remember* helpers degrade exactly like remember().
     *
     * Cache::memo() is a scoped container binding: Octane flushes it after
     * every request (FlushTemporaryContainerInstances) and the queue worker
     * after every job, so nothing memoised here outlives the request.
     */
    public static function prefetch(array $keys): void
    {
        $keys = array_values(array_unique(array_filter($keys, 'is_string')));

        if ($keys === []) {
            return;
        }

        try {
            Cache::memo()->many($keys);
        } catch (\Throwable $e) {
            Log::warning('[Cache] prefetch failed; falling back to per-key reads.', [
                'keys' => $keys,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * remember() through the request-scoped memo: a key is read from Redis at
     * most once per request (or not at all after prefetch()).
     */
    public static function rememberMemo(string $key, mixed $ttl, callable $resolver, mixed $fallback = null): mixed
    {
        try {
            return Cache::memo()->remember($key, $ttl, $resolver);
        } catch (\Throwable $cacheException) {
            Log::warning('[Cache] remember failed; using direct resolver.', [
                'key' => $key,
                'error' => $cacheException->getMessage(),
            ]);
        }

        try {
            return $resolver();
        } catch (\Throwable $resolverException) {
            Log::error('[Cache] direct resolver failed.', [
                'key' => $key,
                'error' => $resolverException->getMessage(),
            ]);

            return $fallback;
        }
    }

    public static function rememberForeverMemo(string $key, callable $resolver, mixed $fallback = null): mixed
    {
        try {
            return Cache::memo()->rememberForever($key, $resolver);
        } catch (\Throwable $cacheException) {
            Log::warning('[Cache] rememberForever failed; using direct resolver.', [
                'key' => $key,
                'error' => $cacheException->getMessage(),
            ]);
        }

        try {
            return $resolver();
        } catch (\Throwable $resolverException) {
            Log::error('[Cache] direct resolver failed.', [
                'key' => $key,
                'error' => $resolverException->getMessage(),
            ]);

            return $fallback;
        }
    }

    public static function forget(string $key): void
    {
        try {
            // Same single DEL as Cache::forget(), but also drops the key from
            // the request-scoped memo so a later rememberMemo() in the same
            // request cannot serve the invalidated value.
            Cache::memo()->forget($key);
        } catch (\Throwable $e) {
            Log::warning('[Cache] forget failed.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
