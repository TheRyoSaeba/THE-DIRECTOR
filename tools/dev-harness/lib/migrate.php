<?php
// Runs migrations on a FRESH local DB. The owner's real schema drifted from the migration
// files, so a few patches are applied at fixed points. Every patch lives here.
//
//   Patch A (after the first 4 base migrations: users, cities, careers, career_ranks):
//     - cities.slug column (later data-migrations query cities.slug)
//     - reference careers 1..13 and cities 1..3 that data-migrations assume exist
//       (career_ranks are NOT inserted here: a migration inserts customs ranks and would collide)
//   Patch B (after all migrations): backfill rank levels 1..6 (xp (r-1)*1000) wherever missing.

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__ . '/bootstrap.php';

$base = [
    'database/migrations/2026_02_04_000001_create_users_table.php',
    'database/migrations/2026_02_04_000002_create_cities_table.php',
    'database/migrations/2026_02_04_000003_create_careers_table.php',
    'database/migrations/2026_02_04_000004_create_career_ranks_table.php',
];

$run = function (array $args): void {
    $code = Artisan::call('migrate', $args + ['--force' => true]);
    echo Artisan::output();
    if ($code !== 0) {
        fwrite(STDERR, "migrate failed (exit $code)\n");
        exit($code);
    }
};

if (!Schema::hasTable('careers')) {
    $run(['--path' => $base]);
}

// ---- Patch A ---------------------------------------------------------------
DB::statement('ALTER TABLE cities ADD COLUMN IF NOT EXISTS slug varchar(255)');

$fresh = !Schema::hasTable('characters'); // reference data only on a fresh DB (retail is deleted later by a migration)
if ($fresh && DB::table('careers')->count() === 0) {
    $now = now();
    $codes = ['unemployed', 'police', 'healthcare', 'law', 'banking', 'corporation', 'politics',
              'customs', 'technician', 'criminal', 'retail', 'labor', 'secret'];
    foreach ($codes as $i => $code) {
        DB::table('careers')->insert([
            'id' => $i + 1, 'code' => $code, 'name' => ucfirst($code),
            'description' => ucfirst($code) . ' career (dev-harness)', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    DB::statement("SELECT setval(pg_get_serial_sequence('careers','id'), (SELECT MAX(id) FROM careers))");
    echo "patch A: inserted 13 careers\n";
}
if ($fresh && DB::table('cities')->count() === 0) {
    $now = now();
    foreach ([[1, 'New York', 'new-york'], [2, 'Tokyo', 'tokyo'], [3, 'London', 'london']] as [$id, $name, $slug]) {
        DB::table('cities')->insert(['id' => $id, 'name' => $name, 'slug' => $slug, 'created_at' => $now, 'updated_at' => $now]);
    }
    DB::statement("SELECT setval(pg_get_serial_sequence('cities','id'), (SELECT MAX(id) FROM cities))");
    echo "patch A: inserted 3 cities\n";
}

// ---- Remaining migrations ----------------------------------------------------
$run([]);

// ---- Patch B ---------------------------------------------------------------
// Careers with no ranks get 6; careers seeded by data-migrations with gaps (customs/technician
// only get rank 2 on a fresh DB) get the missing levels 1..6 filled in. xp_required = (r-1)*1000.
$now = now();
$filled = [];
foreach (DB::table('careers')->orderBy('id')->get() as $career) {
    $have = DB::table('career_ranks')->where('career_id', $career->id)->pluck('rank_level')->all();
    for ($r = 1; $r <= 6; $r++) {
        if (in_array($r, $have, false)) {
            continue;
        }
        DB::table('career_ranks')->insert([
            'career_id' => $career->id, 'rank_level' => $r, 'rank_name' => ucfirst($career->code) . " Rank $r",
            'xp_required' => ($r - 1) * 1000, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $filled[$career->code] = true;
    }
}
if ($filled) {
    echo 'patch B: backfilled ranks for ' . implode(', ', array_keys($filled)) . "\n";
}
echo "migrations ok\n";
