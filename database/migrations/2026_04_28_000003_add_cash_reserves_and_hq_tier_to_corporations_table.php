<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            // Clean capital pool. Distinct from slush_fund (dirty crime proceeds).
            // All property purchases, daily upkeep, and legal fines deduct from here.
            // Withdrawals and upkeep both trigger the city's corporate_tax_rate.
            // Corps must launder (subsidiary) or earn legitimately to build reserves.
            $table->unsignedBigInteger('cash_reserves')->default(0)->after('slush_fund');

            // Kept here so isFull() and getMaxMemberSlotsAttribute() never need to
            // join corporation_properties on every check.
            // Updated in the same transaction as the HQ property row on purchase/upgrade.
            // 1 = 3 members, 2 = 5 members, 3 = 7 members.
            $table->unsignedTinyInteger('hq_tier')->default(0)->after('cash_reserves');
        });
    }

    public function down(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            $table->dropColumn(['cash_reserves', 'hq_tier']);
        });
    }
};
