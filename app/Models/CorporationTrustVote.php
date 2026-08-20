<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporationTrustVote extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'holding_company_id',
        'initiated_by_id',
        'winner_id',
        'status',
        'expires_at',
        'completed_at',
        'promotion_completed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'promotion_completed_at' => 'datetime',
    ];

    public function holdingCompany(): BelongsTo
    {
        return $this->belongsTo(Corporation::class, 'holding_company_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'initiated_by_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'winner_id');
    }

    public function ballots(): HasMany
    {
        return $this->hasMany(CorporationTrustVoteBallot::class, 'trust_vote_id');
    }

    public function scopePending($query)
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopePromotionPending($query)
    {
        return $query
            ->where('status', self::STATUS_COMPLETED)
            ->whereNull('promotion_completed_at');
    }

    public static function requiredVotesFor(int $eligibleCount): int
    {
        return max(1, (int) ceil($eligibleCount * 0.65));
    }
}
