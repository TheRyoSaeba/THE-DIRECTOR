<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lazy strength regeneration (replaces the "strength-recovery" pg_cron job).
 *
 * Adds character_timers.strength_updated_at (bigint unix seconds, same shape
 * as the other timer columns / App\Casts\UnixTimestamp). CharacterTimers
 * derives the effective strength from (strength, strength_updated_at) on read.
 *
 * Postgres: the column default is a STABLE expression (extract(epoch from
 * now())), so PG11+ adds it as a "fast default" — no table rewrite, no UPDATE
 * of every row; existing rows read the ALTER-time value (= backfill to now)
 * and new rows get their insert time. The ALTER needs ACCESS EXCLUSIVE only
 * for a catalog change (milliseconds); lock_timeout makes it fail fast rather
 * than queue behind a long transaction and stall every reader behind it.
 * Run over the DIRECT (non -pooler) Neon connection.
 *
 * Deploy order: run this migration + ship the code, THEN unschedule the cron.
 * (If the cron fires in between it just adds 8.33 to rows whose
 * strength_updated_at is "now" — harmless, at most one extra tick.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('character_timers', 'strength_updated_at')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
            DB::statement(
                'ALTER TABLE character_timers ADD COLUMN IF NOT EXISTS strength_updated_at bigint '
                . 'NOT NULL DEFAULT (extract(epoch from now()))::bigint'
            );

            return;
        }

        Schema::table('character_timers', function (Blueprint $table) {
            $table->bigInteger('strength_updated_at')->default(0);
        });
        DB::table('character_timers')->update(['strength_updated_at' => time()]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('character_timers', 'strength_updated_at')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '5s'");
        }

        Schema::table('character_timers', function (Blueprint $table) {
            $table->dropColumn('strength_updated_at');
        });
    }
};
