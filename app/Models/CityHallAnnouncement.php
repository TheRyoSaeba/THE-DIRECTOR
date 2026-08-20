<?php

namespace App\Models;


class CityHallAnnouncement extends CityHallPost
{
    
    protected static function booted(): void
    {
        static::addGlobalScope('announcement', fn ($q) => $q->where('type', 'announcement')->whereNull('parent_id'));

        static::creating(function (self $model) {
            $model->type = 'announcement';
        });
    }
}
