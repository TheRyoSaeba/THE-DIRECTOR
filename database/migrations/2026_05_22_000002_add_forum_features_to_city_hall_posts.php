<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('city_hall_posts', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('reply_count');
            $table->boolean('is_locked')->default(false)->after('is_pinned');
            $table->unsignedInteger('views')->default(0)->after('is_locked');

            $table->index(['city_id', 'type', 'is_pinned', 'created_at'], 'city_hall_posts_forum_order_idx');
        });
    }

    public function down(): void
    {
        Schema::table('city_hall_posts', function (Blueprint $table) {
            $table->dropIndex('city_hall_posts_forum_order_idx');
            $table->dropColumn(['is_pinned', 'is_locked', 'views']);
        });
    }
};
