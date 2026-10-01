<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * character_stats.influence: integer -> numeric(10,3).
 *
 * The code has always treated influence as fractional (CharacterStats casts
 * 'float', addInfluence/removeInfluence take floats, ConflictService /
 * CrimeRecord award 0.02-0.05 steps). With PDO emulated prepares the float is
 * sent as the literal '7.001' and Postgres rejects it for an integer column
 * (SQLSTATE 22P02) -> HTTP 500 on /work/attempt and conflict resolution.
 *
 * Safety:
 *  - Only ALTERs when information_schema says the column is still an integer
 *    type, so it is a no-op where production was already changed by hand.
 *  - integer -> numeric is NOT binary-coercible, so Postgres rewrites the
 *    table and rebuilds its indexes under ACCESS EXCLUSIVE. character_stats
 *    is one narrow row per character (a few thousand rows at most), so the
 *    rewrite is expected to take well under a second (tens of ms at 10k rows);
 *    readers/writers of character_stats block for that duration only.
 *    lock_timeout = 5s makes it abort (and be retried) instead of queueing
 *    behind a long-running transaction and blocking everyone behind it.
 *  - No views depend on the column (checked pg_depend), and every SQL reader
 *    (leaderboard rating `cs.influence * 500.0`, daily-influence
 *    `LEAST(80, GREATEST(0, cs.influence + ...))`) works unchanged on numeric.
 * Run over the DIRECT (non -pooler) Neon connection.
 *
 * down() rounds back to integer (lossy for fractional values, by necessity).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (! $this->isIntegerType()) {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE character_stats ALTER COLUMN influence TYPE numeric(10,3) USING influence::numeric(10,3)');
        DB::statement('ALTER TABLE character_stats ALTER COLUMN influence SET DEFAULT 0');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if ($this->isIntegerType()) {
            return;
        }

        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE character_stats ALTER COLUMN influence TYPE integer USING round(influence)::integer');
        DB::statement('ALTER TABLE character_stats ALTER COLUMN influence SET DEFAULT 0');
    }

    private function isIntegerType(): bool
    {
        $type = DB::scalar(
            "SELECT data_type FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = 'character_stats' AND column_name = 'influence'"
        );

        return in_array($type, ['integer', 'smallint', 'bigint'], true);
    }
};
