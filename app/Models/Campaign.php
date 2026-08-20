<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class Campaign extends Model
{
    protected $fillable = [
        'election_id', 'candidate_id', 'city_id',
        'manifesto', 'campaign_fund', 'votes',
        'vote_percentage', 'status',
    ];

    protected $casts = [
        'campaign_fund'   => 'integer',
        'votes'           => 'integer',
        'vote_percentage' => 'float',
    ];

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'candidate_id');
    }
}
