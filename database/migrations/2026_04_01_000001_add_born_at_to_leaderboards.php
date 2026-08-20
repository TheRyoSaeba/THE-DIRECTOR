<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('leaderboards', function (Blueprint $table) {
            $table->timestampTz('born_at')->nullable()->after('died_at');
        });
    }

    public function down(): void
    {
        Schema::table('leaderboards', function (Blueprint $table) {
            $table->dropColumn('born_at');
        });
    }
};
