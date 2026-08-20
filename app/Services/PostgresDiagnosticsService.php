<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PostgresDiagnosticsService
{
    public function getDatabaseHealth(): array
    {
        $notes = [];
        $latencyMs = null;
        $connected = false;

        try {
            $started = microtime(true);
            DB::selectOne('select 1 as ok');
            $latencyMs = round((microtime(true) - $started) * 1000, 2);
            $connected = true;
        } catch (\Throwable $e) {
            $notes[] = 'Database ping failed: ' . $e->getMessage();
        }

        $meta = [
            'driver' => config('database.default'),
            'connection' => DB::getDefaultConnection(),
            'database' => null,
            'server_version' => null,
            'latency_ms' => $latencyMs,
            'connected' => $connected,
        ];

        if (! $connected) {
            return [
                'checked_at' => now()->utc()->toIso8601String(),
                'meta' => $meta,
                'database_size' => ['bytes' => null, 'pretty' => null],
                'connections' => [
                    'total' => null,
                    'active' => null,
                    'idle' => null,
                    'waiting' => null,
                    'max' => null,
                ],
                'database_stats' => [
                    'cache_hit_ratio' => null,
                    'commits' => null,
                    'rollbacks' => null,
                    'deadlocks' => null,
                    'temp_bytes' => null,
                ],
                'long_running' => [],
                'locks' => [],
                'wal' => null,
                'settings' => [],
                'notes' => array_values(array_unique($notes)),
            ];
        }

        try {
            $meta['database'] = DB::selectOne('select current_database() as db')?->db;
        } catch (\Throwable) {
            $notes[] = 'Unable to read current database.';
        }

        try {
            $meta['server_version'] = DB::selectOne('select version() as version')?->version;
        } catch (\Throwable) {
            $notes[] = 'Unable to read PostgreSQL version().';
        }

        $dbSize = ['bytes' => null, 'pretty' => null];
        try {
            $row = DB::selectOne(
                'select pg_database_size(current_database()) as bytes, pg_size_pretty(pg_database_size(current_database())) as pretty',
            );
            $dbSize['bytes'] = isset($row?->bytes) ? (int) $row->bytes : null;
            $dbSize['pretty'] = $row?->pretty;
        } catch (\Throwable) {
            $notes[] = 'Unable to read pg_database_size().';
        }

        $connections = [
            'total' => null,
            'active' => null,
            'idle' => null,
            'waiting' => null,
            'max' => null,
        ];

        try {
            $row = DB::selectOne(
                "select
                    count(*) as total,
                    count(*) filter (where state = 'active') as active,
                    count(*) filter (where state = 'idle') as idle,
                    count(*) filter (where wait_event is not null) as waiting
                from pg_stat_activity",
            );

            $connections['total'] = (int) ($row->total ?? 0);
            $connections['active'] = (int) ($row->active ?? 0);
            $connections['idle'] = (int) ($row->idle ?? 0);
            $connections['waiting'] = (int) ($row->waiting ?? 0);
        } catch (\Throwable) {
            $notes[] = 'Unable to read pg_stat_activity connection counts.';
        }

        $databaseStats = [
            'cache_hit_ratio' => null,
            'commits' => null,
            'rollbacks' => null,
            'deadlocks' => null,
            'temp_bytes' => null,
        ];

        try {
            $row = DB::selectOne(
                'select blks_hit, blks_read, xact_commit, xact_rollback, deadlocks, temp_bytes
                 from pg_stat_database
                 where datname = current_database()',
            );

            $hits = (int) ($row->blks_hit ?? 0);
            $reads = (int) ($row->blks_read ?? 0);
            $databaseStats = [
                'cache_hit_ratio' => ($hits + $reads) > 0 ? round(($hits / ($hits + $reads)) * 100, 2) : null,
                'commits' => (int) ($row->xact_commit ?? 0),
                'rollbacks' => (int) ($row->xact_rollback ?? 0),
                'deadlocks' => (int) ($row->deadlocks ?? 0),
                'temp_bytes' => (int) ($row->temp_bytes ?? 0),
            ];
        } catch (\Throwable) {
            $notes[] = 'Unable to read pg_stat_database.';
        }

        $longRunning = [];
        try {
            $longRunning = collect(DB::select(
                "select
                    pid,
                    usename,
                    state,
                    wait_event_type,
                    wait_event,
                    age(now(), query_start)::text as duration,
                    left(query, 500) as query
                from pg_stat_activity
                where pid <> pg_backend_pid()
                  and state <> 'idle'
                  and query_start is not null
                order by query_start asc
                limit 8",
            ))->map(fn($row) => [
                'pid' => (int) $row->pid,
                'user' => $row->usename,
                'state' => $row->state,
                'wait' => trim(implode(' / ', array_filter([$row->wait_event_type ?? null, $row->wait_event ?? null]))),
                'duration' => $row->duration,
                'query' => $row->query,
            ])->all();
        } catch (\Throwable) {
            $notes[] = 'Unable to read long-running queries.';
        }

        $locks = [];
        try {
            $locks = collect(DB::select(
                'select mode, granted, count(*) as count
                 from pg_locks
                 group by mode, granted
                 order by granted asc, count(*) desc',
            ))->map(fn($row) => [
                'mode' => $row->mode,
                'granted' => (bool) $row->granted,
                'count' => (int) $row->count,
            ])->all();
        } catch (\Throwable) {
            $notes[] = 'Unable to read pg_locks.';
        }

        $wal = null;
        try {
            $walStat = DB::selectOne(
                'select wal_records, wal_fpi, wal_bytes, stats_reset from pg_stat_wal',
            );

            $wal = [
                'records' => (int) ($walStat->wal_records ?? 0),
                'fpi' => (int) ($walStat->wal_fpi ?? 0),
                'bytes' => (int) ($walStat->wal_bytes ?? 0),
                'stats_reset' => $walStat->stats_reset ?? null,
                'current_lsn' => null,
                'current_file' => null,
            ];

            $lsn = DB::selectOne('select pg_current_wal_lsn() as lsn, pg_walfile_name(pg_current_wal_lsn()) as file');
            $wal['current_lsn'] = $lsn?->lsn;
            $wal['current_file'] = $lsn?->file;
        } catch (\Throwable) {
            $notes[] = 'WAL stats unavailable or not permitted.';
        }

        $settings = $this->safeKeyValueSettings([
            'max_connections',
            'statement_timeout',
            'idle_in_transaction_session_timeout',
            'shared_buffers',
            'effective_cache_size',
            'work_mem',
            'wal_level',
            'max_wal_size',
            'checkpoint_timeout',
        ], $notes);
        $connections['max'] = $settings['max_connections'] ?? null;

        return [
            'checked_at' => now()->utc()->toIso8601String(),
            'meta' => $meta,
            'database_size' => $dbSize,
            'connections' => $connections,
            'database_stats' => $databaseStats,
            'long_running' => $longRunning,
            'locks' => $locks,
            'wal' => $wal,
            'settings' => $settings,
            'notes' => array_values(array_unique($notes)),
        ];
    }

    public function getWalDiagnostics(): array
    {
        return $this->getDatabaseHealth();
    }

    private function safeKeyValueSettings(array $names, array &$notes): array
    {
        $out = [];

        foreach ($names as $name) {
            try {
                $out[$name] = DB::selectOne(
                    'select setting from pg_settings where name = ?',
                    [$name],
                )?->setting;
            } catch (\Throwable $e) {
                $out[$name] = null;
                $notes[] = "Unable to read setting: {$name}.";
            }
        }

        return $out;
    }
}
