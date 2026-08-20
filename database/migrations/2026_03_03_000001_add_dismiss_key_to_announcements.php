<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->uuid('dismiss_key')->nullable()->after('is_active');
        });

        
        DB::table('announcements')->whereNull('dismiss_key')->get()->each(function ($row) {
            DB::table('announcements')
                ->where('id', $row->id)
                ->update(['dismiss_key' => (string) Str::uuid()]);
        });

        Schema::table('announcements', function (Blueprint $table) {
            $table->uuid('dismiss_key')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('dismiss_key');
        });
    }
};
