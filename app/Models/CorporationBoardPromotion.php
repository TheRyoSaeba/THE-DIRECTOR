<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporationBoardPromotion extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'holding_company_id',
        'subsidiary_id',
        'promoted_by_id',
        'promoted_ceo_id',
        'successor_id',
        'status',
        'expires_at',
        'completed_at',
        'handoff_completed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'handoff_completed_at' => 'datetime',
    ];

    public function holdingCompany(): BelongsTo
    {
        return $this->belongsTo(Corporation::class, 'holding_company_id');
    }

    public function subsidiary(): BelongsTo
    {
        return $this->belongsTo(Corporation::class, 'subsidiary_id');
    }

    public function promotedBy(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'promoted_by_id');
    }

    public function promotedCeo(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'promoted_ceo_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'successor_id');
    }

    public function scopePending($query)
    {
        return $query
            ->where('status', self::STATUS_PENDING)
            ->whereNull('handoff_completed_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
