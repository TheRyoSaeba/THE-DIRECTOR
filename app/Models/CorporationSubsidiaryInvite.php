<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporationSubsidiaryInvite extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'holding_company_id',
        'target_corporation_id',
        'requester_id',
        'target_ceo_id',
        'status',
        'expires_at',
        'completed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function holdingCompany(): BelongsTo
    {
        return $this->belongsTo(Corporation::class, 'holding_company_id');
    }

    public function targetCorporation(): BelongsTo
    {
        return $this->belongsTo(Corporation::class, 'target_corporation_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'requester_id');
    }

    public function targetCeo(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'target_ceo_id');
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
