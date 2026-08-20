<?php

namespace App\Models;

use App\Casts\UnixTimestamp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterTimers extends Model
{
    protected $table = 'character_timers';

    public $timestamps = false;

    protected $fillable = [
        'character_id',
        'next_work_at',
        'next_action_at',
        'next_travel_at',
        'next_talents_at',
        'next_study_at',
        'next_conflict_at',
        'hospital_until',
        'protection_until',
        'hospital_reason',
        'jail_until',
        'strength',
        'last_relocation_at',
        'pending_relocation_city_id',
        'last_actioned_at',
    ];

    protected $casts = [
        'next_work_at' => UnixTimestamp::class,
        'next_action_at' => UnixTimestamp::class,
        'next_travel_at' => UnixTimestamp::class,
        'next_talents_at' => UnixTimestamp::class,
        'next_study_at' => UnixTimestamp::class,
        'next_conflict_at' => UnixTimestamp::class,
        'hospital_until' => UnixTimestamp::class,
        'protection_until' => UnixTimestamp::class,
        'jail_until' => UnixTimestamp::class,
        'strength'         => 'decimal:2',
        'last_actioned_at' => UnixTimestamp::class,
    ];

    //! hospital_until bigint DEFAULT '0'::bigint NOT NULL,
    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
