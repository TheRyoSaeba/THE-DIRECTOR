<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CharacterItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'character_id',
        'game_item_id',
        'durability_remaining',
        'location',
        'is_equipped',
        'equipped_slot',
        'data',
    ];

    protected $casts = [
        'is_equipped' => 'boolean',
        'durability_remaining' => 'integer',
        'data' => 'array',
    ];

    public function character()
    {
        return $this->belongsTo(Character::class);
    }

    public function template()
    {
        return $this->belongsTo(GameItem::class , 'game_item_id');
    }

    //? made this and we don't even really use it 
    public function scopeOnHand($query)
    {
        return $query->where('location', 'on_hand');
    }


    public function scopeInSafe($query)
    {
        return $query->where('location', 'safe');
    }


    public function scopeInGarage($query)
    {
        return $query->where('location', 'garage');
    }

    public function scopePlantedRcieds($query)
    {
        return $query
            ->whereHas('template', fn ($q) => $q->where('slug', 'ILIKE', 'rcied'))
            ->whereRaw("data->>'target_character_id' IS NOT NULL");
    }

    public function scopeEquipped($query)
    {
        return $query->whereRaw('"is_equipped" IS TRUE');
    }

    public function conditionPercent(): ?int
    {
        $template = $this->template;

        if (! $template) {
            return null;
        }

        $itemData = is_array($this->data) ? $this->data : [];
        $templateData = is_array($template->data) ? $template->data : [];
        $maxUnits = (int) ($itemData['pack_units'] ?? $templateData['pack_units'] ?? 0);

        if ($template->type === 'item' && $maxUnits > 1) {
            $units = (int) ($itemData['units'] ?? $maxUnits);
            $units = max(0, min($maxUnits, $units));

            return (int) round(($units / $maxUnits) * 100);
        }

        $max = (int) ($template->durability ?? 0);
        $current = $this->durability_remaining;

        return ($max > 0 && $current !== null)
            ? (int) round(($current / $max) * 100)
            : null;
    }
}
