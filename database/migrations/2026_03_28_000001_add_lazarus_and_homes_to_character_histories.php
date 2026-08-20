<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('character_histories', function (Blueprint $table) {
            $table->integer('talent_lazarus_connection_used')->default(0)->after('talent_goal_of_all_life_used');
        });
    }

    public function down(): void
    {
        Schema::table('character_histories', function (Blueprint $table) {
            $table->dropColumn(['homes_inspected', 'talent_lazarus_connection_used']);
        });
    }
};
