<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresIndexes() as $statement) {
                DB::statement($statement);
            }

            return;
        }

        Schema::table('characters', function (Blueprint $table) {
            $table->index('user_id', 'idx_characters_user_id_hot');
            $table->index(['city_id', 'deleted_at', 'display_name'], 'idx_characters_city_alive_name_hot');
            $table->index(['home_city_id', 'deleted_at'], 'idx_characters_home_city_alive_hot');
            $table->index(['career_id', 'career_rank', 'career_xp'], 'idx_characters_career_rank_xp_hot');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->index(['user_id', 'last_activity'], 'idx_sessions_user_activity_hot');
        });

        Schema::table('career_earns', function (Blueprint $table) {
            $table->index(['career_id', 'min_rank', 'min_career_xp'], 'idx_career_earns_availability_hot');
        });

        Schema::table('character_journals', function (Blueprint $table) {
            $table->index(['character_id', 'created_at'], 'idx_character_journals_character_created_hot');
        });

        Schema::table('character_items', function (Blueprint $table) {
            $table->index(['character_id', 'is_equipped', 'location'], 'idx_character_items_character_equipped_location_hot');
        });
    }

    public function down(): void
    {
        $names = [
            'idx_characters_user_id_hot',
            'idx_characters_city_alive_name_hot',
            'idx_characters_home_city_alive_hot',
            'idx_characters_career_rank_xp_hot',
            'idx_sessions_user_activity_hot',
            'idx_career_earns_availability_hot',
            'idx_character_journals_character_created_hot',
            'idx_character_items_character_equipped_location_hot',
            'idx_cities_slug_lower_hot',
            'idx_businesses_city_slug_lower_hot',
            'idx_game_items_slug_lower_hot',
        ];

        foreach ($names as $name) {
            $concurrently = DB::getDriverName() === 'pgsql' ? ' CONCURRENTLY' : '';
            DB::statement("DROP INDEX{$concurrently} IF EXISTS {$name}");
        }
    }

    private function postgresIndexes(): array
    {
        return [
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_characters_user_id_hot ON characters (user_id)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_characters_city_alive_name_hot ON characters (city_id, deleted_at, display_name)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_characters_home_city_alive_hot ON characters (home_city_id, deleted_at)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_characters_career_rank_xp_hot ON characters (career_id, career_rank, career_xp)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sessions_user_activity_hot ON sessions (user_id, last_activity DESC) WHERE user_id IS NOT NULL',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_career_earns_availability_hot ON career_earns (career_id, min_rank, min_career_xp)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_character_journals_character_created_hot ON character_journals (character_id, created_at DESC)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_character_items_character_equipped_location_hot ON character_items (character_id, is_equipped, location)',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_cities_slug_lower_hot ON cities (LOWER(slug))',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_businesses_city_slug_lower_hot ON businesses (city_id, LOWER(slug))',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_game_items_slug_lower_hot ON game_items (LOWER(slug))',
        ];
    }
};
