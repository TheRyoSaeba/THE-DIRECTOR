<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;


class Leaderboard extends Model
{
    protected $table = 'leaderboards';

    protected $fillable = [
        'character_id', 'display_name', 'avatar_url', 'glow_color',
        'career_name', 'rank_name', 'home_city_name',
        'corporation_name', 'corporation_image_url', 'corporation_position',
        'kills', 'total_earns', 'rating',
        'is_historical', 'died_at', 'born_at', 'snapshotted_at',
    ];

    protected $casts = [
        'kills' => 'integer',
        'total_earns' => 'integer',
        'rating' => 'integer',
        'is_historical' => 'boolean',
        'died_at' => 'datetime',
        'born_at' => 'datetime',
        'snapshotted_at' => 'datetime',
    ];

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    
    public static function snapshotOnDeath(int $characterId): void
    {
    
        DB::statement("
            WITH dead AS (
                SELECT
                    c.id,
                    c.display_name,
                    COALESCE(c.custom_avatar_url, crk.avatar_url) AS avatar_url,
                    COALESCE(c.glow_color, 'cyan')               AS glow_color,
                    COALESCE(c.total_earns, 0)                   AS total_earns,
                    ca.name                                      AS career_name,
                    COALESCE(crk.rank_name, 'Staff')             AS rank_name,
                    ci.name                                      AS home_city_name,
                    corp.name                                    AS corporation_name,
                    corp.image_url                               AS corporation_image_url,
                    CASE
                        WHEN corp.id IS NULL THEN NULL
                        WHEN corp.is_holding_company = false
                             AND corp.ceo_id = c.id THEN 'CEO'
                        WHEN c.corporation_position IS NOT NULL
                             THEN c.corporation_position
                        ELSE 'member'
                    END                                          AS corporation_position,
                    COALESCE(ch.kills, 0)                        AS kills,
                    c.created_at                                 AS born_at,
                    GREATEST(1, LEAST(99, ROUND(
                        1 + 110 * (
                            LOG10(GREATEST(0.001,
                                cs.offense      * 2.0
                              + cs.defense      * 1.5
                              + cs.intelligence * 2.5
                              + cs.luck         * 1.0
                              + cs.influence    * 500.0
                              + c.total_character_exp / 20.0
                            )) - 4
                        ) / 3
                    )::int)) AS rating
                FROM characters c
                JOIN character_stats cs  ON cs.character_id  = c.id
                JOIN careers ca          ON ca.id             = c.career_id
                JOIN cities ci           ON ci.id             = c.home_city_id
                LEFT JOIN career_ranks crk
                    ON crk.career_id = c.career_id
                   AND crk.rank_level = c.career_rank
                LEFT JOIN character_histories ch ON ch.character_id = c.id
                LEFT JOIN corporations corp
                    ON corp.id = c.corporation_id
                   AND corp.deleted_at IS NULL
                WHERE c.id = ?
            ),
            alive_p50 AS (
                SELECT COALESCE(
                    PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY
                        GREATEST(1, LEAST(99, ROUND(
                            1 + 110 * (
                                LOG10(GREATEST(0.001,
                                    cs2.offense      * 2.0
                                  + cs2.defense      * 1.5
                                  + cs2.intelligence * 2.5
                                  + cs2.luck         * 1.0
                                  + cs2.influence    * 500.0
                                  + c2.total_character_exp / 20.0
                                )) - 4
                            ) / 3
                        )::int))
                    ), 1) AS p50
                FROM characters c2
                JOIN character_stats cs2 ON cs2.character_id = c2.id
                WHERE c2.deleted_at IS NULL AND c2.health > 0
            )
            INSERT INTO leaderboards (
                character_id, display_name, avatar_url, glow_color,
                career_name, rank_name, home_city_name,
                corporation_name, corporation_image_url, corporation_position,
                kills, total_earns, rating,
                is_historical, died_at, born_at, snapshotted_at, created_at, updated_at
            )
            SELECT
                d.id, d.display_name, d.avatar_url, d.glow_color,
                d.career_name, d.rank_name, d.home_city_name,
                d.corporation_name, d.corporation_image_url, d.corporation_position,
                d.kills, d.total_earns, d.rating,
                true, NOW(), d.born_at, NOW(), NOW(), NOW()
            FROM dead d
            CROSS JOIN alive_p50 p
            WHERE d.rating >= p.p50
            ON CONFLICT (character_id) WHERE character_id IS NOT NULL
            DO UPDATE SET
                is_historical         = true,
                died_at               = NOW(),
                born_at               = EXCLUDED.born_at,
                display_name          = EXCLUDED.display_name,
                avatar_url            = EXCLUDED.avatar_url,
                glow_color            = EXCLUDED.glow_color,
                career_name           = EXCLUDED.career_name,
                rank_name             = EXCLUDED.rank_name,
                home_city_name        = EXCLUDED.home_city_name,
                corporation_name      = EXCLUDED.corporation_name,
                corporation_image_url = EXCLUDED.corporation_image_url,
                corporation_position  = EXCLUDED.corporation_position,
                kills                 = EXCLUDED.kills,
                total_earns           = EXCLUDED.total_earns,
                rating                = EXCLUDED.rating,
                snapshotted_at        = EXCLUDED.snapshotted_at,
                updated_at            = EXCLUDED.updated_at
            WHERE leaderboards.is_historical = false
        ", [$characterId]);
    }


    public static function clearOnRevival(int $characterId): void
    {
        DB::table('leaderboards')->where('character_id', $characterId)->delete();
    }
}
