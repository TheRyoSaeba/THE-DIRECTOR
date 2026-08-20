<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['forum_posts', 'forum_replies'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['character_id']);
                $table->foreignId('character_id')->nullable()->change();
                $table->foreign('character_id')->references('id')->on('characters')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['forum_posts', 'forum_replies'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropForeign(['character_id']);
                $table->foreignId('character_id')->nullable(false)->change();
                $table->foreign('character_id')->references('id')->on('characters');
            });
        }
    }
};
