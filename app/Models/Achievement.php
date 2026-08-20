<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Achievement extends Model
{
    protected $fillable = [
        'slug', 'name', 'icon', 'icon_url', 'description',
        'is_secret', 'trigger_type', 'trigger_value',
    ];

    protected $casts = [
        'is_secret' => 'boolean',
        'trigger_value' => 'integer',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_achievements')
            ->withPivot('unlocked_at')
            ->withTimestamps();
    }

    public function scopeVisible($query)
    {
        return $query->whereRaw('"is_secret" IS FALSE');
    }
}
