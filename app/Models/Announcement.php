<?php

namespace App\Models;

use App\Support\SafeCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Announcement extends Model
{

    public const HAS_ACTIVE_CACHE_KEY = 'announcements:has_active';

    protected $fillable = [
        'title',
        'message',
        'type',
        'published_at',
        'expires_at',
        'is_active',
        'dismiss_key',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            $model->dismiss_key = (string) Str::uuid();
        });


        static::updating(function (self $model) {
            if ($model->isDirty('is_active') && $model->is_active) {
                $model->dismiss_key = (string) Str::uuid();
            }
        });

        static::saved(fn() => SafeCache::forget(self::HAS_ACTIVE_CACHE_KEY));
        static::deleted(fn() => SafeCache::forget(self::HAS_ACTIVE_CACHE_KEY));
    }


    public static function hasActive(): bool
    {
        // Memo-backed so HandleInertiaRequests can batch this key with the
        // other shared-prop keys into one MGET (SafeCache::prefetch).
        return (bool) SafeCache::rememberMemo(
            self::HAS_ACTIVE_CACHE_KEY,
            now()->addMinutes(5),
            fn() => self::current()->exists(),
            false,
        );
    }

    protected $casts = [
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'dismiss_key' => 'string',
    ];

    public function scopeActive($query)
    {

        return $query->whereRaw('is_active = true');
    }

    public function scopePublished($query)
    {
        return $query->where('published_at', '<=', now());
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }


    public function scopeVisible($query)
    {
        return $query->active()->published();
    }


    public function scopeCurrent($query)
    {
        return $query->active()->published()->notExpired();
    }
}
