<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporationTrustVoteBallot extends Model
{
    protected $fillable = [
        'trust_vote_id',
        'voter_id',
        'candidate_id',
    ];

    public function trustVote(): BelongsTo
    {
        return $this->belongsTo(CorporationTrustVote::class, 'trust_vote_id');
    }

    public function voter(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'voter_id');
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'candidate_id');
    }
}
