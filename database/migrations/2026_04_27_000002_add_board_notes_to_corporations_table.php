<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            $table->text('board_notes')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('corporations', function (Blueprint $table) {
            $table->dropColumn('board_notes');
        });
    }
};
