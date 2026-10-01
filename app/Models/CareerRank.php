<?php

namespace App\Models;

use App\Support\SafeCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerRank extends Model
{
    use HasFactory;

    protected $fillable = [
        'career_id',
        'rank_level',
        'rank_name',
        'xp_required',
        'avatar_url',
    ];

    protected $casts = [
        'rank_level' => 'integer',
        'xp_required' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn(self $rank) => SafeCache::forget(self::cacheKey($rank->career_id)));
        static::deleted(fn(self $rank) => SafeCache::forget(self::cacheKey($rank->career_id)));
    }

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    protected ?self$memoNext = null;
    protected ?self$memoPrev = null;

    public function getNextRankAttribute(): ?self
    {
        return $this->memoNext ??= self::getNextForCharacter($this->career_id, $this->rank_level);
    }

    public function getPreviousRankAttribute(): ?self
    {
        return $this->memoPrev ??= self::findForCharacter($this->career_id, $this->rank_level - 1);
    }

    public function getRankRequirements(int $currentXp): array
    {
        $next = $this->next_rank;

        if (!$next) {
            return [
                'progress' => 100,
                'xp_needed' => 0,
                'ready' => false,
                'max_rank' => true
            ];
        }

        $gap = $next->xp_required - $this->xp_required;
        $earned = $currentXp - $this->xp_required;

        return [
            'progress' => ($gap > 0) ? (int)max(0, min(100, ($earned / $gap) * 100)) : 100,
            'xp_needed' => max(0, $next->xp_required - $currentXp),
            'ready' => $currentXp >= $next->xp_required,
            'max_rank' => false
        ];
    }

    public static function getRanksForCareer(int $careerId)
    {
        // Request-scoped memo: current rank, next rank, avatar and the
        // promotion check all read this key; it now costs at most one Redis
        // read per request (zero when HandleInertiaRequests prefetched it).
        return SafeCache::rememberForeverMemo(self::cacheKey($careerId), function () use ($careerId) {
            return self::where('career_id', $careerId)->get();
        }, collect());
    }

    public static function cacheKey(int $careerId): string
    {
        return "career_ranks_{$careerId}_v3";
    }

    public static function findForCharacter(int $careerId, int $rankLevel): ?self
    {
        return self::getRanksForCareer($careerId)->where('rank_level', $rankLevel)->first();
    }

    public static function getNextForCharacter(int $careerId, int $currentRankLevel): ?self
    {
        return self::getRanksForCareer($careerId)->where('rank_level', $currentRankLevel + 1)->first();
    }

    public static function bulkLoadForCharacters(array $careerIds, array $rankLevels): array
    {
        $allFetched = collect();
        foreach (array_unique($careerIds) as $id) {
            $allFetched = $allFetched->merge(self::getRanksForCareer($id));
        }

        return $allFetched->whereIn('rank_level', $rankLevels)
            ->keyBy(fn($r) => "{$r->career_id}_{$r->rank_level}")
            ->toArray();
    }
}
