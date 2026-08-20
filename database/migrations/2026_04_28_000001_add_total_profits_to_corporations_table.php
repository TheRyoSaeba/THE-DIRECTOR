<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            // Running total of all money the corporation has genuinely earned.
            // Dirty cash deposited to slush_fund by members does NOT count —
            // only proceeds from successful corporate actions (investment fraud,
            // future medical sales, subsidiary laundering output, etc).
            $table->unsignedBigInteger('total_profits')->default(0)->after('slush_fund');
        });
    }

    public function down(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            $table->dropColumn('total_profits');
        });
    }
};
