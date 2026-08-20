<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GameItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'type',
        'slot',
        'description',
        'image_url',
        'price',
        'is_active',
        'stock',
        'max_stock',
        'restock_at',
        'durability',
        'offense',
        'defense',
        'intelligence',
        'influence',
        'luck',
        'data',
        'kill_result_message',
        'damage_result_message',
        'damage_journal_message',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'integer',
        'restock_at' => 'datetime',
        'stock' => 'integer',
        'max_stock' => 'integer',
        'durability' => 'integer',
        'offense' => 'integer',
        'defense' => 'integer',
        'intelligence' => 'integer',
        'influence' => 'integer',
        'luck' => 'integer',
        'data' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereRaw('"is_active" IS TRUE');
    }

    public function scopeWeapons(Builder $query): Builder
    {
        return $query->where('type', 'weapon');
    }

    public function scopeArmor(Builder $query): Builder
    {
        return $query->where('type', 'armor');
    }

    /**
     * Resolve a weapon combat message with runtime tokens substituted.
     *
     * Returns null when the column is not set, so ConflictController can
     * fall through to its existing generic string without any null checks
     * scattered across the call sites.
     *
     * Available tokens:
     *   {attacker} — attacker display_name
     *   {defender} — defender display_name
     *   {damage}   — numeric damage dealt
     *   {crit}     — "CRITICAL HIT! " or empty string
     *   {injury}   — " They lost N max HP!" or empty string
     *
     * @param 'kill_result_message'|'damage_result_message'|'damage_journal_message' $column
     */
    public function resolveWeaponMessage(
        string $column,
        string $attacker,
        string $defender,
        int $damage = 0,
        bool $isCritical = false,
        int $maxHealthLost = 0
    ): ?string {
        $template = $this->{$column} ?? null;

        if ($template === null || $template === '') {
            return null;
        }

        $critText   = $isCritical ? 'CRITICAL HIT! ' : '';
        $injuryText = $maxHealthLost > 0 ? " They lost {$maxHealthLost} max HP!" : '';

        return str_replace(
            ['{attacker}', '{defender}', '{damage}', '{crit}', '{injury}'],
            [$attacker,    $defender,    (string) $damage, $critText, $injuryText],
            $template
        );
    }
}
