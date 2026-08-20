<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DefenseOffer extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_DECLINED = 'declined';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'crime_record_id',
        'attorney_id',
        'defendant_id',
        'fee',
        'status',
        'accepted_at',
        'executed_at',
    ];

    protected $casts = [
        'fee' => 'integer',
        'accepted_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function crimeRecord(): BelongsTo
    {
        return $this->belongsTo(CrimeRecord::class);
    }

    public function attorney(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'attorney_id');
    }

    public function defendant(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'defendant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_ACCEPTED]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_ACCEPTED], true);
    }
}
