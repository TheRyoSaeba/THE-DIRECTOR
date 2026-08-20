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

    public static function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (\Throwable $e) {
            Log::warning('[Cache] forget failed.', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
