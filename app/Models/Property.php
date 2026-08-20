<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Property extends Model
{
    use HasFactory;

    const CONDITION_CONSTRUCTED = 'CONSTRUCTED';
    const CONDITION_BOMB        = 'BOMB';
    const CONDITION_DESTROYED   = 'DESTROYED';

    
    public static function effectiveBonuses(Character $character): array
    {
        $empty = ['influence' => 0, 'intelligence' => 0, 'offense' => 0, 'defense' => 0];

        if (! $character->property) {
            return $empty;
        }

        if (! in_array($character->property_condition, [self::CONDITION_CONSTRUCTED, self::CONDITION_BOMB])) {
            return $empty;
        }

        return $character->property->bonuses();
    }

    
    public static function hasBomb(Character $character): bool
    {
        return $character->property_id !== null
            && $character->property_condition === self::CONDITION_BOMB;
    }

    protected $fillable = [
        'name',
        'price',
        'image_url',
        'vehicle_capacity',
        'safe_capacity',
        'has_alarm',
        'influence_bonus_pct',
        'intelligence_bonus_pct',
        'offense_bonus_pct',
        'defense_bonus_pct',
    ];

    protected $casts = [
        'price' => 'integer',
        'vehicle_capacity' => 'integer',
        'safe_capacity' => 'integer',
        'has_alarm' => 'boolean',
        'influence_bonus_pct' => 'integer',
        'intelligence_bonus_pct' => 'integer',
        'offense_bonus_pct' => 'integer',
        'defense_bonus_pct' => 'integer',
    ];

    public function owners(): HasMany
    {
        return $this->hasMany(Character::class);
    }

    public function scopeAffordable($query, int $maxPrice)
    {
        return $query->where('price', '<=', $maxPrice);
    }


    public function scopeWithBonuses($query)
    {
        return $query->where(function ($q) {
            $q->where('influence_bonus_pct', '>', 0)
                ->orWhere('intelligence_bonus_pct', '>', 0)
                ->orWhere('offense_bonus_pct', '>', 0)
                ->orWhere('defense_bonus_pct', '>', 0);
        });
    }

    public function getFormattedPriceAttribute(): string
    {
        return '$' . number_format($this->price);
    }

    public function getSellPriceAttribute(): int
    {
        return (int)round($this->price * 0.5); 
    }

    public function getTotalBonusesAttribute(): int
    {
        return $this->influence_bonus_pct +
            $this->intelligence_bonus_pct +
            $this->offense_bonus_pct +
            $this->defense_bonus_pct;
    }

    public function getHasBonusesAttribute(): bool
    {
        return $this->total_bonuses > 0;
    }

    public function bonuses(): array
    {
        return [
            'influence' => $this->influence_bonus_pct,
            'intelligence' => $this->intelligence_bonus_pct,
            'offense' => $this->offense_bonus_pct,
            'defense' => $this->defense_bonus_pct,
        ];
    }

}