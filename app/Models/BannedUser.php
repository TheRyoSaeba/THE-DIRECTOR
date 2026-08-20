<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BannedUser extends Model
{
    protected $table = 'banned_users';

    protected $fillable = [
        'user_id',
        'type',
        'value',
        'reason',
        'banned_by',
        'banned_at',
        'expires_at',
    ];

    protected $casts = [
        'banned_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public static function isBanned(string $type, string $value): bool
    {
        return self::where('type', $type)
            ->where('value', $value)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    public static function banIdentifier(
        string $type,
        string $value,
        ?string $reason = null,
        ?int $bannedBy = null,
        ?\DateTimeInterface $expiresAt = null,
        ?int $userId = null,
    ): void {
        self::updateOrCreate(
            ['type' => $type, 'value' => $value],
            [
                'user_id' => $userId,
                'reason' => $reason,
                'banned_by' => $bannedBy,
                'banned_at' => now(),
                'expires_at' => $expiresAt,
            ]
        );
    }

    /**
     * Remove every identifier ban tied to a user. Called on unban so the
     * user's IP/cookie bans are lifted along with their account — without
     * this, an unbanned user is bounced straight back to /banned by the
     * identifier check and silently re-banned.
     */
    public static function clearForUser(int $userId): int
    {
        return self::where('user_id', $userId)->delete();
    }
}
