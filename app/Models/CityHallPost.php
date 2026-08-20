<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class CityHallPost extends Model
{
    protected $table = 'city_hall_posts';

    protected $fillable = [
        'city_id',
        'character_id',
        'author_name',
        'author_role',
        'type',
        'parent_id',
        'title',
        'body',
        'reply_count',
        'is_pinned',
        'is_locked',
        'views',
    ];

    protected $casts = [
        'is_pinned' => 'bool',
        'is_locked' => 'bool',
        'views'     => 'int',
    ];

    

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Character::class, 'character_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    

    public function scopeAnnouncements($query)
    {
        return $query->where('type', 'announcement')->whereNull('parent_id');
    }

    public function scopeForumThreads($query)
    {
        return $query->where('type', 'forum')->whereNull('parent_id');
    }

    public function scopeInCity($query, int $cityId)
    {
        return $query->where('city_id', $cityId);
    }

    public function scopeForumOrdered($query)
    {
        return $query->orderByDesc('is_pinned')->orderByDesc('updated_at');
    }
}
