<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerEarn extends Model
{
    use HasFactory;

    protected $table = 'career_earns';

    protected $fillable = [
        'career_id', 'code', 'title', 'min_rank', 'min_career_xp',
        'rng_min', 'rng_max', 'payout_min', 'payout_max',
        'xp_gain_min', 'xp_gain_max',
        'stat_intelligence_min', 'stat_intelligence_max',
        'stat_offense_min', 'stat_offense_max',
        'stat_defense_min', 'stat_defense_max',
        'stat_luck_min', 'stat_luck_max',
        'stat_influence_min', 'stat_influence_max',
        'success_message', 'failure_message',
    ];

    protected $casts = [
        'career_id' => 'integer',
        'min_rank' => 'integer',
        'min_career_xp' => 'integer',
        'rng_min' => 'integer',
        'rng_max' => 'integer',
        'payout_min' => 'integer',
        'payout_max' => 'integer',
        'xp_gain_min' => 'integer',
        'xp_gain_max' => 'integer',
    ];

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public function isAvailableFor(Character $character): bool
    {
        if ($this->career_id !== $character->career_id) {
            return false;
        }

        return $character->career_rank >= $this->min_rank
            && $character->career_xp >= $this->min_career_xp;
    }

    public function rollSuccess(int $characterXp): bool
    {
        $roll = random_int($this->rng_min, $this->rng_max);

        return $characterXp >= $roll;
    }

    public function calculatePayout(): int
    {
        return random_int($this->payout_min, $this->payout_max);
    }

    public function calculateXpGain(): int
    {
        return random_int($this->xp_gain_min, $this->xp_gain_max);
    }

    public function ApplyStats(): array
    {
        $gains = [];
        $stats = ['intelligence', 'offense', 'defense', 'luck'];

        foreach ($stats as $stat) {
            $min = $this->{ "stat_{$stat}_min"} ?? 0;
            $max = $this->{ "stat_{$stat}_max"} ?? 0;

            if ($max > 0) {
                $gains[$stat] = random_int($min, $max);
            }
        }

        return $gains;
    }

    public function calculateInfluenceGain(): float
    {
        if ($this->stat_influence_min <= 0 && $this->stat_influence_max <= 0) {
            return 0;
        }

        return random_int($this->stat_influence_min, $this->stat_influence_max) / 1000;
    }

    public function getFormattedSuccessMessage(int $payout): string
    {
        return str_replace('{payout}', number_format($payout), $this->success_message ?? 'Success! You completed the task and earned ${payout}.');
    }

    public function getFormattedFailureMessage(): string
    {
        return $this->failure_message ?? 'You failed to complete the task.';
    }
}
