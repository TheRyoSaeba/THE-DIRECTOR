<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ForumCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
        'is_active',
        'admin_only',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'admin_only' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function posts()
    {
        return $this->hasMany(ForumPost::class, 'category_id');
    }

    public function scopeActive($query)
    {
        return $query->whereRaw('"is_active" IS TRUE');
    }
}
