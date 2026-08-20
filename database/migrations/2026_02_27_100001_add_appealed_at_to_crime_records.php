<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crime_records', function (Blueprint $table) {
            $table->timestampTz('appealed_at')->nullable()->after('sentenced_at');
            $table->index('appealed_at'); 
        });
    }

    public function down(): void
    {
        Schema::table('crime_records', function (Blueprint $table) {
            $table->dropIndex(['appealed_at']);
            $table->dropColumn('appealed_at');
        });
    }
};
