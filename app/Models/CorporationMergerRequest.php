<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporationMergerRequest extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'requester_id',
        'target_id',
        'requester_corporation_id',
        'target_corporation_id',
        'requester_successor_id',
        'target_successor_id',
        'holding_name',
        'holding_image_url',
        'status',
        'expires_at',
        'completed_at',
        'requester_handoff_completed_at',
        'target_handoff_completed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'requester_handoff_completed_at' => 'datetime',
        'target_handoff_completed_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'requester_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'target_id');
    }

    public function requesterCorporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class, 'requester_corporation_id');
    }

    public function targetCorporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class, 'target_corporation_id');
    }

    public function requesterSuccessor(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'requester_successor_id');
    }

    public function targetSuccessor(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'target_successor_id');
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
}
