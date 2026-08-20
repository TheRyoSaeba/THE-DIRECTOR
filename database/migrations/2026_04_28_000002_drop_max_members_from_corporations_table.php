<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('corporations', 'max_members')) {
            return;
        }

        Schema::table('corporations', function (Blueprint $table) {
            $table->dropColumn('max_members');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('corporations', 'max_members')) {
            return;
        }

        Schema::table('corporations', function (Blueprint $table) {
            $table->integer('max_members')->default(1)->after('parent_trust_id');
        });
    }
};
