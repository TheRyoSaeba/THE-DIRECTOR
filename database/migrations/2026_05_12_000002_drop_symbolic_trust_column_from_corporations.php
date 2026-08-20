<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('corporations', 'is_trust')) {
            return;
        }

        Schema::table('corporations', function (Blueprint $table) {
            $table->dropColumn('is_trust');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('corporations', 'is_trust')) {
            return;
        }

        Schema::table('corporations', function (Blueprint $table) {
            $table->boolean('is_trust')->default(false)->after('is_holding_company');
        });
    }
};
