<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{

    public function up(): void
    {
        DB::statement('
            UPDATE career_earns SET
                min_career_xp = ROUND(min_career_xp / 2.0),
                rng_min       = ROUND(rng_min / 2.0),
                rng_max       = ROUND(rng_max / 2.0)
        ');

        DB::statement('
            UPDATE career_ranks SET
                xp_required = ROUND(xp_required / 2.0)
            WHERE rank_level > 1
        ');
    }

    public function down(): void
    {
        DB::statement('
            UPDATE career_earns SET
                min_career_xp = ROUND(min_career_xp * 2.0),
                rng_min       = ROUND(rng_min * 2.0),
                rng_max       = ROUND(rng_max * 2.0)
        ');

        DB::statement('
            UPDATE career_ranks SET
                xp_required = ROUND(xp_required * 2.0)
            WHERE rank_level > 1
        ');
    }
};
