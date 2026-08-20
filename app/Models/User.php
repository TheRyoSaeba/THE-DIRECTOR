<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Services\JournalService;


class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        "username",
        "email",
        "password",
        "google_id",
        "google_token",
        "google_refresh_token",
        "last_ip",
        "last_login_at",
        "is_banned",
        "ban_reason",
        "banned_at",
        "banned_until",
        "hide_achievements",
        "disable_card_flip",
        "email_opt_out_at",
    ];

    protected $hidden = [
        "password",
        "remember_token",
        "is_admin",
        "google_token",
        "google_refresh_token",
    ];

    protected $casts = [
        "last_login_at" => "datetime",
        "banned_at" => "datetime",
        "banned_until" => "datetime",
        "email_opt_out_at" => "datetime",
        "password" => "hashed",
        "is_banned" => "boolean",
        "is_admin" => "boolean",
        "hide_achievements" => "boolean",
        "disable_card_flip" => "boolean",
    ];

    protected static function booted(): void
    {
    }

    public function character()
    {
        return $this->hasOne(Character::class);
    }

    public function getLoadedCharacter()
    {
        if ($this->relationLoaded('character')) {
            return $this->getRelation('character');
        }

        $character = $this->character()
            ->withTrashed()
            ->with(['timers', 'career', 'city', 'corporation'])
            ->first();

        if ($character) {
            $character->setRelation(
                'homeCity',
                (int) $character->city_id === (int) $character->home_city_id
                    ? $character->city
                    : City::find($character->home_city_id)
            );
        }

        $this->setRelation('character', $character);

        return $character;
    }

    public function achievements()
    {
        return $this->belongsToMany(
            Achievement::class,
            "user_achievements",
        )->withPivot("unlocked_at");
    }

    public function hasAchievement($achievementSlug)
    {
        return $this->achievements()->where("slug", $achievementSlug)->exists();
    }

    public function checkAchievementThresholds(string $type, int $currentValue, ?\App\Models\Character $character = null): void
    {
        $ownedIds = $this->achievements()->pluck('achievements.id');

        Achievement::where('trigger_type', $type)
            ->where('trigger_value', '<=', $currentValue)
            ->whereNotIn('id', $ownedIds)
            ->each(function ($achievement) use ($character) {
                $this->achievements()->attach($achievement->id, ['unlocked_at' => now()]);
                $this->notifyAchievement($achievement, $character);
            });
    }

    public function notifyAchievement(Achievement $achievement, ?\App\Models\Character $character = null): void
    {
        $targetCharacter = $character ?? $this->character;
        if (!$targetCharacter)
            return;

        JournalService::custom(
            $targetCharacter->id,
            'achievement_unlocked',
            [
                'achievement_name' => $achievement->name,
                'achievement_icon' => $achievement->icon,
            ]
        );
    }

    public function scopeActive($query)
    {
        return $query->whereRaw('"is_banned" IS FALSE');
    }

    public function scopeBanned($query)
    {
        return $query->whereRaw('"is_banned" IS TRUE');
    }

    public function getHasCharacterAttribute(): bool
    {
        return $this->character()->withTrashed()->exists();
    }

    /**
     * Ban the account. A null $until is a permanent ban; a future timestamp
     * is a temporary ban that auto-lifts once it passes (see isCurrentlyBanned
     * and the CheckPlayerState middleware).
     */
    public function ban(?string $reason = null, ?\DateTimeInterface $until = null): void
    {
        $this->update([
            "is_banned" => true,
            "ban_reason" => $reason,
            "banned_at" => now(),
            "banned_until" => $until,
        ]);
    }

    public function unban(): void
    {
        $this->update([
            "is_banned" => false,
            "ban_reason" => null,
            "banned_at" => null,
            "banned_until" => null,
        ]);

        $character = $this->character()->withTrashed()->first();
        if ($character && $character->trashed()) {
            $character->restore();
            $character->update(["health" => 100]);
        } elseif ($character) {
            $character->update(["health" => 100]);
        }
    }

    /**
     * Whether the account ban is in force right now. A temp ban whose
     * banned_until has passed is no longer active even though is_banned is
     * still true in the row — the middleware lifts it lazily on next request.
     */
    public function isCurrentlyBanned(): bool
    {
        if (! $this->is_banned) {
            return false;
        }

        return $this->banned_until === null || $this->banned_until->isFuture();
    }
}
