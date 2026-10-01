<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CronJobService
{

    public function installExtension(): bool
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_cron');
            Log::info('pg_cron extension installed successfully');
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to install pg_cron extension', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }


    public function isInstalled(): bool
    {
        try {
            $result = DB::select(
                "SELECT EXISTS(SELECT 1 FROM pg_extension WHERE extname = 'pg_cron') as installed"
            );
            return $result[0]->installed ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }


    public function scheduleJob(string $name, string $schedule, string $command): bool
    {
        try {
            DB::select("SELECT cron.schedule(?, ?, ?)", [$name, $schedule, $command]);

            Log::info('Scheduled new cron job', [
                'name' => $name,
                'schedule' => $schedule
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to schedule cron job', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }


    public function unscheduleJob(string $name): bool
    {
        try {
            DB::select("SELECT cron.unschedule(?)", [$name]);
            Log::info('Unscheduled cron job', ['name' => $name]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to unschedule cron job', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }


    public function getJobByName(string $name): ?\stdClass
    {
        if (!$this->isInstalled()) {
            return null;
        }

        return DB::selectOne('SELECT * FROM cron.job WHERE jobname = ?', [$name]);
    }


    public function getAllJobs(): array
    {
        if (!$this->isInstalled()) {
            return [];
        }

        return DB::select('
            SELECT 
                jobid,
                schedule,
                command,
                nodename,
                nodeport,
                database,
                username,
                active,
                jobname
            FROM cron.job
            ORDER BY jobid
        ');
    }


    public function getJobHistory(int $limit = 50): array
    {
        if (!$this->isInstalled()) {
            return [];
        }

        return DB::select("
            SELECT 
                runid,
                jobid,
                job_pid,
                database,
                username,
                LEFT(command, 100) as command_preview,
                status,
                return_message,
                start_time,
                end_time,
                EXTRACT(EPOCH FROM (end_time - start_time)) as duration_seconds
            FROM cron.job_run_details
            ORDER BY start_time DESC
            LIMIT ?
        ", [$limit]);
    }


    public function getFailedJobs(int $limit = 20): array
    {
        if (!$this->isInstalled()) {
            return [];
        }

        return DB::select("
            SELECT 
                runid,
                jobid,
                status,
                return_message,
                start_time
            FROM cron.job_run_details
            WHERE status = 'failed'
            ORDER BY start_time DESC
            LIMIT ?
        ", [$limit]);
    }


    public function getJobStats(): array
    {
        if (!$this->isInstalled()) {
            return [];
        }

        return DB::select("
            SELECT 
                j.jobid,
                j.jobname,
                j.schedule,
                j.database,
                j.active,
                COUNT(r.runid) as total_runs,
                COUNT(CASE WHEN r.status = 'succeeded' THEN 1 END) as successful,
                COUNT(CASE WHEN r.status = 'failed' THEN 1 END) as failed,
                AVG(EXTRACT(EPOCH FROM (r.end_time - r.start_time))) as avg_duration,
                MAX(r.start_time) as last_run
            FROM cron.job j
            LEFT JOIN cron.job_run_details r ON j.jobid = r.jobid
            GROUP BY j.jobid, j.jobname, j.schedule, j.database, j.active
            ORDER BY j.jobid
        ");
    }


    public function toggleJob(int $jobId, bool $active): bool
    {
        try {
            DB::update('UPDATE cron.job SET active = ? WHERE jobid = ?', [$active, $jobId]);
            Log::info('Toggled cron job', ['jobid' => $jobId, 'active' => $active]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to toggle cron job', ['jobid' => $jobId, 'error' => $e->getMessage()]);
            return false;
        }
    }


    public function updateJobById(int $jobId, array $data): bool
    {
        try {
            $job = DB::selectOne('SELECT jobname FROM cron.job WHERE jobid = ?', [$jobId]);
            if (!$job) {
                Log::error('Cron job not found by ID', ['jobid' => $jobId]);
                return false;
            }

            $updates = [];
            $params = [];

            if (!empty($data['schedule'])) {
                $updates[] = 'schedule = ?';
                $params[] = $data['schedule'];
            }

            if (!empty($data['command'])) {
                $updates[] = 'command = ?';
                $params[] = $data['command'];
            }

            if (isset($data['active'])) {
                $updates[] = 'active = ?';
                $params[] = (bool) $data['active'];
            }

            if (empty($updates)) {
                return true;
            }

            $params[] = $jobId;
            $setClause = implode(', ', $updates);
            DB::update("UPDATE cron.job SET {$setClause} WHERE jobid = ?", $params);

            Log::info('Updated cron job by ID', ['jobid' => $jobId, 'jobname' => $job->jobname]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update cron job by ID', ['jobid' => $jobId, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Partially update an existing named cron job's schedule and/or command.
     * Both parameters are optional — pass only what you want to change.
     *
     * PHP 8.4: explicit ?string nullable instead of the deprecated implicit
     * nullable (string $x = null) form.
     */
    public function updateJob(string $name, ?string $schedule = null, ?string $command = null): bool
    {
        $allowedJobs = [
            'health-regen',
            'auto-logout',
            'restock-items',
            'business-availability',
            'business-hourly-profit',
            'cleanup-12h',
            'leaderboard-update',
            'daily-influence',
            'daily-upkeep',
        ];

        if (!in_array($name, $allowedJobs)) {
            Log::error('Attempted to update non-whitelisted cron job', ['name' => $name]);
            return false;
        }

        try {
            $updates = [];
            $params = [];

            if ($schedule) {
                $updates[] = 'schedule = ?';
                $params[] = $schedule;
            }

            if ($command) {
                $updates[] = 'command = ?';
                $params[] = $command;
            }

            if (empty($updates)) {
                return true;
            }

            $params[] = $name;
            $setClause = implode(', ', $updates);

            DB::update("UPDATE cron.job SET {$setClause} WHERE jobname = ?", $params);

            Log::info('Updated cron job', ['name' => $name, 'updates' => $updates]);
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to update cron job', ['name' => $name, 'error' => $e->getMessage()]);
            return false;
        }
    }


    public function setupGameJobs(): bool
    {
        if (!$this->isInstalled()) {
            Log::error('pg_cron not installed');
            return false;
        }

        $jobs = [
            // strength-recovery removed: strength now regenerates on read
            // (CharacterTimers::effectiveStrength), so no job rewrites every row.


            [
                'name' => 'health-regen',
                'schedule' => '*/30 * * * *',
                'command' => "
                    UPDATE characters
                    SET health = LEAST(max_health, health + 2)
                    WHERE health < max_health AND deleted_at IS NULL;
                "
            ],



            [
                'name' => 'auto-logout',
                'schedule' => '*/10 * * * *',
                'command' => "
                    UPDATE users 
                    SET last_login_at = NOW()
                    WHERE id IN (
                        SELECT DISTINCT user_id 
                        FROM sessions 
                        WHERE user_id IS NOT NULL 
                            AND last_activity < EXTRACT(EPOCH FROM NOW() - INTERVAL '45 minutes')
                    );

                    DELETE FROM sessions 
                    WHERE user_id IS NOT NULL 
                        AND last_activity < EXTRACT(EPOCH FROM NOW() - INTERVAL '45 minutes');
                "
            ],

            [
                'name' => 'restock-items',
                'schedule' => '*/30 * * * *',
                'command' => "
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
            stock       = LEAST(gi.max_stock, gi.stock + (floor(random() * (e.max_stock - e.stock)) + 1)::integer),
            restock_at  = CURRENT_TIMESTAMP,
            updated_at  = NOW()
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
    FROM restocked;
    "
            ],
            //TODO make sure university checks that they got the degree from that city

            [
                'name' => 'business-hourly-profit',
                'schedule' => '0 * * * *',
                'command' => "
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
        WHERE b.id = rc.id;

        INSERT INTO activity_logs (user_id, action, subject_type, changes, ip_address, created_at)
        VALUES (NULL, 'system.business_hourly_profit', 'businesses', jsonb_build_object('success', true), '127.0.0.1', NOW());
    "
            ],

            [
                'name' => 'business-availability',
                'schedule' => '*/30 * * * *',
                'command' => "
    WITH eligible_businesses AS (
        SELECT 
            b.id,
            b.code,
            CASE 
                WHEN b.code IN ('bank', 'university')                 THEN (floor(random() * 400000) + 1100000)::bigint
                WHEN b.code IN ('city-hall', 'police', 'transit-hub') THEN (floor(random() * 400000) +  700000)::bigint
                ELSE                                                       (floor(random() * 250000) +  250000)::bigint
            END as new_price
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
            base_price = eb.new_price,
            updated_at = NOW()
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
    FROM updates;
    "
            ],

            [
                'name' => 'leaderboard-update',
                'schedule' => '0 0 * * *',










                'command' => "
                    DO \$\$
                    DECLARE
                        v_p50 numeric;
                    BEGIN
                        -- 50th percentile (median) rating of all alive characters
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

                        -- Upsert alive characters at or above median threshold.
                        -- Corporation join mirrors Leaderboard::snapshotOnDeath
                        -- so living and dead snapshots carry the same shape;
                        -- the CASE synthesises 'CEO' for operating-company
                        -- CEOs whose raw corporation_position is NULL.
                        INSERT INTO leaderboards (
                            character_id, display_name, avatar_url, glow_color,
                            career_name, rank_name, home_city_name,
                            corporation_name, corporation_image_url, corporation_position,
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
                            corp.name,
                            corp.image_url,
                            CASE
                                WHEN corp.id IS NULL THEN NULL
                                WHEN corp.is_holding_company = false
                                     AND corp.ceo_id = c.id THEN 'CEO'
                                WHEN c.corporation_position IS NOT NULL
                                     THEN c.corporation_position
                                ELSE 'member'
                            END,
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
                        LEFT JOIN corporations corp
                            ON corp.id = c.corporation_id
                           AND corp.deleted_at IS NULL
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
                        WHERE leaderboards.is_historical = false;

                        -- Remove alive entries that fell below median threshold
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
                    END \$\$;
                "
            ],

            [
                'name' => 'cleanup-12h',
                'schedule' => '0 0,8,16 * * *',
                'command' => "
        DO \$\$ 
        BEGIN
            DELETE FROM sessions
            WHERE last_activity < EXTRACT(EPOCH FROM NOW() - INTERVAL '12 hours');

            DELETE FROM characters
            WHERE deleted_at IS NOT NULL
              AND deleted_at < NOW() - INTERVAL '12 hours';

            DELETE FROM activity_logs
            WHERE created_at < NOW() - INTERVAL '48 hours';

            DELETE FROM cron.job_run_details
            WHERE end_time < NOW() - INTERVAL '48 hours';

            INSERT INTO activity_logs (user_id, action, created_at, ip_address)
            VALUES (NULL, 'system.cleanup_12h', NOW(), '127.0.0.1');
        END \$\$;
    "
            ],
            [
                'name' => 'daily-influence',
                'schedule' => '0 0 * * *',
                'command' => "
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
                      AND c.health > 0;

                    INSERT INTO activity_logs (user_id, action, subject_type, changes, ip_address, created_at)
                    VALUES (NULL, 'system.daily_influence', 'character_stats', jsonb_build_object('success', true), '127.0.0.1', NOW());
                "
            ],
            [
                'name' => 'daily-upkeep',
                'schedule' => '15 0 * * *',
                'command' => "
                    WITH locked_corporations AS MATERIALIZED (
                        SELECT id, cash_reserves, is_holding_company
                        FROM corporations
                        WHERE deleted_at IS NULL
                        FOR UPDATE
                    ),
                    locked_properties AS MATERIALIZED (
                        SELECT cp.*
                        FROM corporation_properties cp
                        JOIN locked_corporations c ON c.id = cp.corporation_id
                        WHERE cp.corporation_id IS NOT NULL
                          AND cp.condition = 'CONSTRUCTED'
                          AND (
                              cp.last_upkeep_at IS NULL
                              OR cp.last_upkeep_at <= CURRENT_TIMESTAMP - INTERVAL '24 hours'
                          )
                          AND (
                              c.is_holding_company = false
                              OR cp.type = 'hq'
                          )
                        ORDER BY
                            cp.corporation_id,
                            CASE WHEN cp.type = 'hq' THEN 1 ELSE 0 END,
                            cp.type,
                            cp.tier,
                            cp.id
                        FOR UPDATE
                    ),
                    due AS MATERIALIZED (
                        SELECT
                            lp.id,
                            lp.corporation_id,
                            lp.type,
                            lp.name,
                            lp.price,
                            CEIL(GREATEST(lp.price, 0)::numeric * 0.10)::bigint AS upkeep_cost,
                            lc.cash_reserves,
                            SUM(CEIL(GREATEST(lp.price, 0)::numeric * 0.10)::bigint) OVER (
                                PARTITION BY lp.corporation_id
                                ORDER BY
                                    CASE WHEN lp.type = 'hq' THEN 1 ELSE 0 END,
                                    lp.type,
                                    lp.tier,
                                    lp.id
                                ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW
                            ) AS running_upkeep
                        FROM locked_properties lp
                        JOIN locked_corporations lc ON lc.id = lp.corporation_id
                    ),
                    evaluated AS MATERIALIZED (
                        SELECT
                            *,
                            cash_reserves >= running_upkeep AS can_pay
                        FROM due
                    ),
                    property_updates AS (
                        UPDATE corporation_properties cp
                        SET
                            condition = CASE WHEN e.can_pay THEN cp.condition ELSE 'DEFAULTED' END,
                            last_upkeep_at = CURRENT_TIMESTAMP,
                            updated_at = CURRENT_TIMESTAMP
                        FROM evaluated e
                        WHERE cp.id = e.id
                        RETURNING cp.id, e.corporation_id, e.upkeep_cost, e.can_pay
                    ),
                    corp_paid AS (
                        SELECT
                            corporation_id,
                            COALESCE(SUM(upkeep_cost) FILTER (WHERE can_pay), 0)::bigint AS paid_total
                        FROM property_updates
                        GROUP BY corporation_id
                    ),
                    corp_updates AS (
                        UPDATE corporations c
                        SET
                            cash_reserves = GREATEST(0, c.cash_reserves - cp.paid_total),
                            updated_at = CURRENT_TIMESTAMP
                        FROM corp_paid cp
                        WHERE c.id = cp.corporation_id
                        RETURNING c.id
                    )
                    INSERT INTO activity_logs (user_id, action, subject_type, changes, ip_address, created_at, updated_at)
                    SELECT
                        NULL,
                        'system.corporation_daily_upkeep',
                        'corporation_properties',
                        jsonb_build_object(
                            'properties_processed', COUNT(*),
                            'properties_paid', COUNT(*) FILTER (WHERE can_pay),
                            'properties_defaulted', COUNT(*) FILTER (WHERE NOT can_pay),
                            'total_paid', COALESCE(SUM(upkeep_cost) FILTER (WHERE can_pay), 0),
                            'total_defaulted', COALESCE(SUM(upkeep_cost) FILTER (WHERE NOT can_pay), 0),
                            'corporations_updated', (SELECT COUNT(*) FROM corp_updates)
                        ),
                        '127.0.0.1',
                        NOW(),
                        NOW()
                    FROM property_updates
                    HAVING COUNT(*) > 0;
                "
            ],
        ];

        $success = true;
        foreach ($jobs as $job) {
            if (!$this->scheduleJob($job['name'], $job['schedule'], $job['command'])) {
                $success = false;
            }
        }

        return $success;
    }


    public function removeGameJobs(): bool
    {
        $jobNames = [
            'strength-recovery',
            'health-regen',
            'auto-logout',
            'restock-items',
            'business-availability',
            'business-hourly-profit',
            'leaderboard-update',
            'cleanup-12h',
            'daily-influence',
            'daily-upkeep',
        ];

        $success = true;
        foreach ($jobNames as $name) {
            if (!$this->unscheduleJob($name)) {
                $success = false;
            }
        }

        return $success;
    }


    public function resetGameJobs(): bool
    {
        $this->removeGameJobs();
        return $this->setupGameJobs();
    }


    public function healthCheck(): array
    {
        $stats = $this->getJobStats();
        $failed = $this->getFailedJobs(10);

        $health = [
            'installed' => $this->isInstalled(),
            'total_jobs' => count($stats),
            'active_jobs' => collect($stats)->where('active', true)->count(),
            'recent_failures' => count($failed),
            'jobs' => []
        ];

        foreach ($stats as $job) {
            $failureRate = $job->total_runs > 0
                ? ($job->failed / $job->total_runs) * 100
                : 0;

            $health['jobs'][] = [
                'name' => $job->jobname,
                'active' => $job->active,
                'schedule' => $job->schedule,
                'database' => $job->database,
                'total_runs' => $job->total_runs,
                'success_rate' => $job->total_runs > 0
                    ? round(($job->successful / $job->total_runs) * 100, 2)
                    : 0,
                'failure_rate' => round($failureRate, 2),
                'avg_duration' => round($job->avg_duration ?? 0, 3),
                'last_run' => $job->last_run,
                'status' => $failureRate > 5 ? 'warning' : 'healthy'
            ];
        }

        return $health;
    }
}
