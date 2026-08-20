<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {

        Schema::table('users', function (Blueprint $table) {
            $table->index('username', 'idx_users_username');
            $table->index('is_banned', 'idx_users_is_banned');
        });
    }

    
    public function down(): void
    {
        Schema::table('characters', function (Blueprint $table) {
            $table->dropIndex('idx_characters_city_id');
            $table->dropIndex('idx_characters_career_id');
            $table->dropIndex('idx_characters_user_id');
            $table->dropIndex('idx_characters_home_city_id');
            $table->dropIndex('idx_characters_alive_city');
            $table->dropIndex('idx_characters_display_name');
        });

        Schema::table('sessions', function (Blueprint $table) {
            $table->dropIndex('idx_sessions_user_id');
            $table->dropIndex('idx_sessions_last_activity');
        });

        Schema::table('career_earns', function (Blueprint $table) {
            $table->dropIndex('idx_career_earns_career_rank');
        });

        Schema::table('character_stats', function (Blueprint $table) {
            $table->dropIndex('idx_character_stats_character_id');
            $table->dropIndex('idx_character_stats_influence');
        });

        Schema::table('character_timers', function (Blueprint $table) {
            $table->dropIndex('idx_character_timers_character_id');
        });

        Schema::table('character_journals', function (Blueprint $table) {
            $table->dropIndex('idx_character_journals_character_id');
            $table->dropIndex('idx_character_journals_created_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_username');
            $table->dropIndex('idx_users_is_banned');
        });
    }
};
