<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('career_earns')->update([
            'payout_min' => DB::raw('payout_min * 2'),
            'payout_max' => DB::raw('payout_max * 2'),
        ]);
    }

    public function down(): void
    {
        DB::table('career_earns')->update([
            'payout_min' => DB::raw('payout_min / 2'),
            'payout_max' => DB::raw('payout_max / 2'),
        ]);
    }
};
