<?php

use Illuminate\Support\Str;

return [

    

    // Sessions live in Redis (dedicated `session` connection, see below).
    // `database` still works: presence/epoch logic does not depend on it.
    'driver' => env('SESSION_DRIVER', 'redis'),


    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', false),


    'encrypt' => env('SESSION_ENCRYPT', false),


    'files' => storage_path('framework/sessions'),


    // For the redis driver this is a Redis connection name — default to the
    // dedicated `session` connection (config/database.php, REDIS_SESSION_DB)
    // so flushing the cache DB never logs everyone out. For the database
    // driver it is a DB connection name (null = default).
    'connection' => env(
        'SESSION_CONNECTION',
        env('SESSION_DRIVER', 'redis') === 'redis' ? 'session' : null
    ),

    // Redis connection for the presence ZSET / IP history (App\Support\Presence).
    // Independent of the session driver. Defaults to the `default` connection
    // (REDIS_DB, normally 0) because it exists on every Redis plan; some hosted
    // Redis only supports DB 0, and if presence can't reach Redis every player
    // shows as offline (which changes who can be attacked). Keep REDIS_CACHE_DB
    // different from REDIS_DB so `cache:clear` never wipes presence.
    'presence_connection' => env('PRESENCE_REDIS_CONNECTION', 'default'),


    'table' => env('SESSION_TABLE', 'sessions'),


    'store' => env('SESSION_STORE'),


    'lottery' => [2, 100],


    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'laravel')).'-session'
    ),


    'path' => env('SESSION_PATH', '/'),


    'domain' => env('SESSION_DOMAIN'),


    'secure' => env('SESSION_SECURE_COOKIE'),


    'http_only' => env('SESSION_HTTP_ONLY', true),


    'same_site' => env('SESSION_SAME_SITE', 'lax'),


    'partitioned' => env('SESSION_PARTITIONED_COOKIE', false),

];
