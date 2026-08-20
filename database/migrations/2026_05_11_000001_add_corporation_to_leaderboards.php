<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leaderboards', function (Blueprint $table) {
            $table->string('corporation_name', 100)->nullable()->after('home_city_name');
            $table->string('corporation_image_url', 2048)->nullable()->after('corporation_name');
            $table->string('corporation_position', 40)->nullable()->after('corporation_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('leaderboards', function (Blueprint $table) {
            $table->dropColumn(['corporation_name', 'corporation_image_url', 'corporation_position']);
        });
    }
};
