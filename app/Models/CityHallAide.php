<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class CityHallAide extends Model
{
    protected $table = 'city_hall_aides';

    protected $fillable = [
        'city_id',
        'mayor_term_id',
        'character_id',
        'display_name',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(MayorTerm::class, 'mayor_term_id');
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
