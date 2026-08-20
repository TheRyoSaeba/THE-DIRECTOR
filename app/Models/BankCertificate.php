<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;


class BankCertificate extends Model
{
    protected $fillable = [
        'business_id',
        'character_id',
        'principal',
        'rate',
        'interest_owed',
        'matures_at',
        'settled_at',
        'outcome',
    ];

    protected $casts = [
        'principal'     => 'integer',
        'rate'          => 'integer',
        'interest_owed' => 'integer',
        'matures_at'    => 'datetime',
        'settled_at'    => 'datetime',
    ];

    public const OUTCOME_MATURED   = 'matured';
    public const OUTCOME_DEFAULTED = 'defaulted';

    

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    

    
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('settled_at');
    }

    
    public function scopeMatured(Builder $query): Builder
    {
        return $query->whereNull('settled_at')
            ->where('matures_at', '<=', now());
    }

    
    public function scopeForBank(Builder $query, int $businessId): Builder
    {
        return $query->where('business_id', $businessId);
    }

    

    
    public function isLocked(): bool
    {
        return is_null($this->settled_at) && $this->matures_at->isFuture();
    }

    
    public function isMatured(): bool
    {
        return is_null($this->settled_at) && $this->matures_at->isPast();
    }

    
    public function rateLabel(): string
    {
        return number_format($this->rate / 100, 2) . '%';
    }

    
    public static function totalLiabilityForBank(int $businessId): int
    {
        return (int) static::active()
            ->forBank($businessId)
            ->selectRaw('COALESCE(SUM(principal + interest_owed), 0) AS total')
            ->value('total');
    }

    
    public static function activeCountForCharacter(int $characterId, int $businessId): int
    {
        return (int) static::active()
            ->where('character_id', $characterId)
            ->where('business_id', $businessId)
            ->count();
    }
}
