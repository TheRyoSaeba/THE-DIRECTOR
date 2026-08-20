<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;





Schedule::call(function () {
    DB::statement("
        UPDATE character_timers AS t
        SET strength = LEAST(100.00, t.strength + 8.333)
        FROM characters AS c
        WHERE t.character_id = c.id
          AND c.deleted_at IS NULL
          AND c.health > 0
          AND t.strength < 100.00
    ");
})->everyFiveMinutes()->name('strength-recovery')->withoutOverlapping();


Schedule::call(function () {
    DB::statement("
        UPDATE characters
        SET health = LEAST(max_health, health + 2)
        WHERE health < max_health
          AND deleted_at IS NULL
    ");
})->everyThirtyMinutes()->name('health-regen')->withoutOverlapping();


Schedule::call(function () {

    DB::statement("
        UPDATE users
        SET last_login_at = NOW()
        WHERE id IN (
            SELECT DISTINCT user_id
            FROM sessions
            WHERE user_id IS NOT NULL
              AND last_activity < EXTRACT(EPOCH FROM NOW() - INTERVAL '45 minutes')
        )
    ");


    DB::statement("
        DELETE FROM sessions
        WHERE user_id IS NOT NULL
          AND last_activity < EXTRACT(EPOCH FROM NOW() - INTERVAL '45 minutes')
    ");
})->everyTenMinutes()->name('auto-logout')->withoutOverlapping();


Schedule::call(function () {
    DB::statement("
        WITH eligible AS (
            SELECT
                id,
                stock,
                max_stock,
                CASE
                    WHEN price < 10000  THEN 80
                    WHEN price >= 100000 THEN 5
                    WHEN price >= 50000  THEN 15
                    ELSE (80 - ((price - 10000) * 65.0 / 40000.0))::integer
                END AS success_chance,
                (floor(random() * 100) + 1)::integer AS roll
            FROM game_items
            WHERE is_active = true
              AND max_stock IS NOT NULL
              AND stock < max_stock
              AND (restock_at IS NULL OR restock_at < CURRENT_TIMESTAMP - INTERVAL '3 hours')
        ),
        restocked AS (
            UPDATE game_items gi
            SET
                stock      = LEAST(gi.max_stock, gi.stock + (floor(random() * (e.max_stock - e.stock)) + 1)::integer),
                restock_at = CURRENT_TIMESTAMP,
                updated_at = NOW()
            FROM eligible e
            WHERE gi.id = e.id
              AND e.roll <= e.success_chance
            RETURNING gi.id
        )
        INSERT INTO activity_logs (user_id, action, subject_type, changes, ip_address, created_at)
        SELECT
            NULL,
            'system.item_restock',
            'game_items',
            jsonb_build_object('items_restocked', COUNT(*)),
            '127.0.0.1',
            NOW()
        FROM restocked
    ");
})->everyThirtyMinutes()->name('restock-items')->withoutOverlapping();


Schedule::call(function () {
    DB::statement("
        WITH career_counts AS (
            SELECT 
                b.id,
                (
                    SELECT COUNT(c.id) 
                    FROM characters c
                    LEFT JOIN careers cr ON cr.code = CASE 
                        WHEN b.code = 'bank' THEN 'banking'
                        WHEN b.code = 'hospital' THEN 'healthcare'
                        WHEN b.code = 'police' THEN 'police'
                        WHEN b.code = 'city-hall' THEN 'politics'
                        WHEN b.code = 'transit-hub' THEN 'customs'
                    END
                    WHERE c.home_city_id = b.city_id
                      AND c.deleted_at IS NULL 
                      AND c.health > 0
                      AND (
                          (b.code != 'university' AND c.career_id = cr.id)
                          OR 
                          (b.code = 'university' AND c.degrees IS NOT NULL AND (
                                 ((c.degrees::jsonb)->'finance'->>'completed_at' IS NOT NULL AND ((c.degrees::jsonb)->'finance'->>'city_id')::int = b.city_id)
                              OR ((c.degrees::jsonb)->'law'->>'completed_at' IS NOT NULL AND ((c.degrees::jsonb)->'law'->>'city_id')::int = b.city_id)
                              OR ((c.degrees::jsonb)->'medicine'->>'completed_at' IS NOT NULL AND ((c.degrees::jsonb)->'medicine'->>'city_id')::int = b.city_id)
                          ))    
                      )
                ) as resident_count
            FROM businesses b
            WHERE b.code NOT ILIKE 'shop-%' AND b.code NOT IN ('pachinko')
        ),
        revenue_calc AS (
            SELECT 
                id,
                (1000 * GREATEST(1, 1 * resident_count))::integer as revenue
            FROM career_counts
        )
        UPDATE businesses b
        SET balance = b.balance + rc.revenue
        FROM revenue_calc rc
        WHERE b.id = rc.id
    ");

    DB::statement("
        INSERT INTO activity_logs (user_id, action, subject_type, changes, ip_address, created_at)
        VALUES (NULL, 'system.business_hourly_profit', 'businesses', jsonb_build_object('success', true), '127.0.0.1', NOW())
    ");
})->hourly()->name('business-hourly-profit')->withoutOverlapping();


Schedule::call(function () {
    DB::statement("
        UPDATE character_stats cs
        SET influence = LEAST(80, GREATEST(0, cs.influence + 
            (CASE WHEN car.code = 'unemployed' THEN -1 WHEN c.career_rank >= 2 THEN 1 ELSE 0 END) + 
            COALESCE(biz.biz_count, 0)
        ))
        FROM characters c
        JOIN careers car ON c.career_id = car.id
        LEFT JOIN (
            SELECT owner_id, COUNT(*) as biz_count
            FROM businesses
            WHERE is_active = true AND owner_id IS NOT NULL
            GROUP BY owner_id
        ) biz ON biz.owner_id = c.id
        WHERE cs.character_id = c.id
          AND c.deleted_at IS NULL
          AND c.health > 0
    ");

    DB::statement("
        INSERT INTO activity_logs (user_id, action, subject_type, changes, ip_address, created_at)
        VALUES (NULL, 'system.daily_influence', 'character_stats', jsonb_build_object('success', true), '127.0.0.1', NOW())
    ");
})->daily()->name('daily-influence')->withoutOverlapping();


Schedule::call(function () {
    DB::statement("
        WITH eligible_businesses AS (
            SELECT
                b.id,
                b.code,
                CASE
                    WHEN b.code IN ('bank', 'university')                 THEN (floor(random() * 400000) + 1100000)::bigint
                    WHEN b.code IN ('city-hall', 'police', 'transit-hub') THEN (floor(random() * 400000) +  700000)::bigint
                    ELSE                                                       (floor(random() * 250000) +  250000)::bigint
                END AS new_price
            FROM businesses b
            LEFT JOIN characters c ON b.owner_id = c.id
            LEFT JOIN users u ON c.user_id = u.id
            WHERE b.is_active = true
              AND (
                  b.owner_id IS NULL
                  OR c.deleted_at IS NOT NULL
                  OR c.health <= 0
                  OR u.is_banned = true
              )
              AND b.is_purchasable = false
              AND (b.updated_at < CURRENT_TIMESTAMP - INTERVAL '30 minutes' OR b.updated_at IS NULL)
              AND (random() < 0.17)
        ),
        cleanup AS (
            UPDATE businesses b
            SET owner_id = NULL, updated_at = NOW()
            FROM characters c
            LEFT JOIN users u ON c.user_id = u.id
            WHERE b.owner_id = c.id
              AND (c.deleted_at IS NOT NULL OR c.health <= 0 OR u.is_banned = true)
        ),
        updates AS (
            UPDATE businesses b
            SET
                is_purchasable = true,
                base_price     = eb.new_price,
                updated_at     = NOW()
            FROM eligible_businesses eb
            WHERE b.id = eb.id
            RETURNING b.id, b.code, b.name, eb.new_price
        )
        INSERT INTO activity_logs (user_id, action, subject_type, changes, ip_address, created_at)
        SELECT
            NULL,
            'system.business_availability',
            'businesses',
            jsonb_build_object(
                'businesses_updated', COUNT(*),
                'details', jsonb_agg(jsonb_build_object('id', id, 'code', code, 'name', name, 'price', new_price))
            ),
            '127.0.0.1',
            NOW()
        FROM updates
    ");
})->everyThirtyMinutes()->name('business-availability')->withoutOverlapping();


Schedule::call(function () {
    DB::unprepared("
      DO $$
      DECLARE
        v_p50 numeric;
      BEGIN
        -- 50th percentile rating of all alive characters
            SELECT COALESCE(
            PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY
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
            )::int))
        ), 1) INTO v_p50
    FROM characters c
    JOIN character_stats cs ON cs.character_id = c.id
    WHERE c.deleted_at IS NULL AND c.health > 0;

    -- Upsert alive characters above threshold
    INSERT INTO leaderboards (
        character_id, display_name, avatar_url, glow_color,
        career_name, rank_name, home_city_name,
        kills, total_earns, rating,
        is_historical, died_at, snapshotted_at, created_at, updated_at
    )
    SELECT
        c.id,
        c.display_name,
        COALESCE(c.custom_avatar_url, crk.avatar_url),
        COALESCE(c.glow_color, 'cyan'),
        ca.name,
        COALESCE(crk.rank_name, 'Staff'),
        ci.name,
        COALESCE(ch.kills, 0),
        COALESCE(c.total_earns, 0),
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
        )::int)),
        false, NULL, NOW(), NOW(), NOW()
        FROM characters c
        JOIN character_stats cs  ON cs.character_id = c.id
        JOIN careers ca          ON ca.id = c.career_id
        JOIN cities ci           ON ci.id = c.home_city_id
        LEFT JOIN career_ranks crk
            ON crk.career_id = c.career_id
            AND crk.rank_level = c.career_rank
        LEFT JOIN character_histories ch ON ch.character_id = c.id
        WHERE c.deleted_at IS NULL
          AND c.health > 0
          AND GREATEST(1, LEAST(99, ROUND(
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
      )::int)) >= v_p50
    ON CONFLICT (character_id) WHERE character_id IS NOT NULL
    DO UPDATE SET
        display_name   = EXCLUDED.display_name,
        avatar_url     = EXCLUDED.avatar_url,
        glow_color     = EXCLUDED.glow_color,
        career_name    = EXCLUDED.career_name,
        rank_name      = EXCLUDED.rank_name,
        home_city_name = EXCLUDED.home_city_name,
        kills          = EXCLUDED.kills,
        total_earns    = EXCLUDED.total_earns,
        rating         = EXCLUDED.rating,
        snapshotted_at = EXCLUDED.snapshotted_at,
        updated_at     = EXCLUDED.updated_at
    WHERE leaderboards.is_historical = false;

    -- Remove alive entries that fell below threshold
    DELETE FROM leaderboards
    WHERE is_historical = false
      AND character_id IS NOT NULL
      AND character_id NOT IN (
          SELECT c2.id
          FROM characters c2
          JOIN character_stats cs2 ON cs2.character_id = c2.id
          WHERE c2.deleted_at IS NULL
            AND c2.health > 0
            AND GREATEST(1, LEAST(99, ROUND(
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
              )::int)) >= v_p50
      );
    END $$;
    ");
})->daily()->name('leaderboard-update')->withoutOverlapping();


Schedule::call(function () {
    DB::statement("DELETE FROM sessions");

    DB::statement("
        DELETE FROM characters
        WHERE deleted_at IS NOT NULL
          AND deleted_at < NOW() - INTERVAL '12 hours'
    ");

    DB::statement("
        DELETE FROM activity_logs
        WHERE created_at < NOW() - INTERVAL '12 hours'
    ");


    DB::statement("
        INSERT INTO activity_logs (user_id, action, created_at, ip_address)
        VALUES (NULL, 'system.cleanup_12h', NOW(), '127.0.0.1')
    ");


})->days([0, 1, 2, 3, 4, 5, 6])->at('00:00')->name('cleanup-12h-midnight')->withoutOverlapping();

Schedule::call(function () {

    DB::statement("DELETE FROM sessions");
    DB::statement("DELETE FROM characters WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL '12 hours'");
    DB::statement("DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL '12 hours'");
    DB::statement("INSERT INTO activity_logs (user_id, action, created_at, ip_address) VALUES (NULL, 'system.cleanup_12h', NOW(), '127.0.0.1')");
})->at('08:00')->name('cleanup-12h-morning')->withoutOverlapping();

Schedule::call(function () {
    DB::statement("DELETE FROM sessions");
    DB::statement("DELETE FROM characters WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL '12 hours'");
    DB::statement("DELETE FROM activity_logs WHERE created_at < NOW() - INTERVAL '12 hours'");
    DB::statement("INSERT INTO activity_logs (user_id, action, created_at, ip_address) VALUES (NULL, 'system.cleanup_12h', NOW(), '127.0.0.1')");
})->at('16:00')->name('cleanup-12h-evening')->withoutOverlapping();
