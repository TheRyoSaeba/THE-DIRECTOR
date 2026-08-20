<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->unique()->nullable()->after('id');
            $table->string('google_token', 500)->nullable()->after('google_id');
            $table->string('google_refresh_token', 500)->nullable()->after('google_token');

            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'google_token', 'google_refresh_token']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
