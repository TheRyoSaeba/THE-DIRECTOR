<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerPromotion extends Model
{
    protected $fillable = [
        'career_id',
        'target_rank',
        'scenario',
        'option_1',
        'option_2',
        'consequences',
        'rewards',
    ];

    protected $casts = [
        'consequences' => 'array',
        'rewards'      => 'array',
        'target_rank'  => 'integer',
    ];

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public static function findForPromotion(int $careerId, int $targetRank): ?self
    {
        return self::where('career_id', $careerId)
            ->where('target_rank', $targetRank)
            ->first();
    }

    
    public function consequenceFor(int $option): string
    {
        return $this->consequences[(string) $option] ?? 'Your promotion has been processed.';
    }

    
    public function rewardsFor(int $option): array
    {
        return $this->rewards[(string) $option] ?? [];
    }
}
