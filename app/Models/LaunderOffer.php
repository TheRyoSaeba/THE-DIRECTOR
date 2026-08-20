<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LaunderOffer extends Model
{
    protected $table = 'launder_offers';

    protected $fillable = [
        'banker_id',
        'client_id',
        'amount',
        'cut_pct',
        'amount_sent',
        'status',
    ];

    protected $casts = [
        'amount'      => 'integer',
        'cut_pct'     => 'decimal:2',
        'amount_sent' => 'integer',
    ];

    const STATUS_PENDING     = 'pending';
    const STATUS_CLIENT_SENT = 'client_sent';
    const STATUS_EXECUTED    = 'executed';
    const STATUS_FAILED      = 'failed';
    const STATUS_CANCELLED   = 'cancelled';

    public function banker(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'banker_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'client_id');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_CLIENT_SENT]);
    }

    public function scopeForBanker($query, int $bankerId)
    {
        return $query->where('banker_id', $bankerId);
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_CLIENT_SENT]);
    }

    public function hasClientSentFunds(): bool
    {
        return $this->status === self::STATUS_CLIENT_SENT && $this->amount_sent > 0;
    }
}
