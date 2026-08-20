<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Account-level temp bans. NULL banned_until = permanent (the existing
        // behaviour). A future timestamp = the ban auto-lifts after it passes.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('banned_until')->nullable()->after('banned_at');
        });

        // Tie each identifier ban to the user it was created for, so unbanning
        // that user can precisely remove their IP/cookie bans. NULL = a manual
        // identifier ban not associated with a specific account.
        Schema::table('banned_users', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('banned_until');
        });

        Schema::table('banned_users', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
