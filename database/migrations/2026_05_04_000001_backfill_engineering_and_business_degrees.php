<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;


return new class extends Migration {
    public function up(): void
    {
        $now = now()->toIso8601String();

        $technicianCareerId = DB::table('careers')->where('code', 'technician')->value('id');
        $corporationCareerId = DB::table('careers')->where('code', 'corporation')->value('id');

        if ($technicianCareerId) {
            // Stamp each character's degree with their actual home_city_id
            // so the data matches what the auto-grant on character creation
            // does. JSONB merge with || preserves any other degrees on the
            // row; we only touch engineering when it's missing or incomplete.
            // Using jsonb_build_object to interpolate per-row home_city_id
            // means one UPDATE statement instead of one-per-character.
            DB::statement("
                UPDATE characters
                SET degrees = COALESCE(degrees, '{}'::jsonb) || jsonb_build_object(
                    'engineering',
                    jsonb_build_object(
                        'city_id', home_city_id,
                        'cycles', ?::int,
                        'completed_at', ?::text
                    )
                )
                WHERE career_id = ?
                  AND (
                      degrees IS NULL
                      OR NOT (degrees ?? 'engineering')
                      OR degrees->'engineering'->>'completed_at' IS NULL
                  )
            ", [
                (int) (config('timers.degree_cycles.engineering') ?? 10),
                $now,
                $technicianCareerId,
            ]);
        }

        if ($corporationCareerId) {
            DB::statement("
                UPDATE characters
                SET degrees = COALESCE(degrees, '{}'::jsonb) || jsonb_build_object(
                    'business',
                    jsonb_build_object(
                        'city_id', home_city_id,
                        'cycles', ?::int,
                        'completed_at', ?::text
                    )
                )
                WHERE career_id = ?
                  AND (
                      degrees IS NULL
                      OR NOT (degrees ?? 'business')
                      OR degrees->'business'->>'completed_at' IS NULL
                  )
            ", [
                (int) (config('timers.degree_cycles.business') ?? 30),
                $now,
                $corporationCareerId,
            ]);
        }
    }

    public function down(): void
    {
        // Strip the auto-granted degrees back out. Doesn't distinguish
        // between auto-grant and legitimate study completion — rolling
        // this migration back assumes you also want to remove engineering
        // and business as concepts. If you only want to remove the
        // backfill itself, do it by hand.
        DB::statement("UPDATE characters SET degrees = degrees - 'engineering' WHERE degrees ? 'engineering'");
        DB::statement("UPDATE characters SET degrees = degrees - 'business' WHERE degrees ? 'business'");
    }
};
