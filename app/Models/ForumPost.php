<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ForumPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'character_id',
        'title',
        'body',
        'is_pinned',
        'is_locked',
        'is_solved',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'is_locked' => 'boolean',
        'is_solved' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(ForumCategory::class, 'category_id');
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function replies()
    {
        return $this->hasMany(ForumReply::class, 'post_id');
    }


    protected static function booted(): void
    {
        /* static::created(function (self $post) {
             $slug = $post->category?->slug ?? optional(
                 ForumCategory::find($post->category_id)
             )->slug;

             if ($slug === 'changelogs') {
                 \App\Jobs\PostForumThreadToDiscord::dispatch($post->id)->afterCommit();
             }*/
    }
}
