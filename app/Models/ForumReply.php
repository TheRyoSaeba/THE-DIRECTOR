<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ForumReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'character_id',
        'body',
        'is_solution',
    ];

    protected $casts = [
        'is_solution' => 'boolean',
    ];

    public function post()
    {
        return $this->belongsTo(ForumPost::class, 'post_id');
    }

    public function character()
    {
        return $this->belongsTo(Character::class);
    }
}
