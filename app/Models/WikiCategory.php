<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WikiCategory extends Model
{
    protected $table = 'wiki_categories';

    protected $fillable = [
        'slug', 'name', 'description', 'sort_order',
    ];

    public function pages(): HasMany
    {
        return $this->hasMany(WikiPage::class, 'category_id');
    }

    public function publishedPages(): HasMany
    {
        return $this->hasMany(WikiPage::class, 'category_id')
            ->whereNotNull('published_at')
            ->orderBy('sort_order')
            ->orderBy('title');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
