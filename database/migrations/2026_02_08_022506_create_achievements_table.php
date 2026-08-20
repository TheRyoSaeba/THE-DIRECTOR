// database/migrations/2025_02_07_000001_update_achievements_system.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('DISCARD PLANS');

        if (! Schema::hasTable('achievements')) {
            Schema::create('achievements', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name');
                $table->string('icon'); 
                $table->text('description')->nullable();
                $table->string('category')->default('general');
                $table->integer('points')->default(10);
                $table->boolean('is_secret')->default(false);
                $table->timestamps();
            });
        }

        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('achievement_id')->constrained()->onDelete('cascade');
            $table->timestamp('unlocked_at')->useCurrent();
            $table->timestamps();

            $table->unique(['user_id', 'achievement_id']);

            $table->index(['user_id']);
            $table->index(['achievement_id']);
        });

        if (Schema::hasColumn('users', 'achievements')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('achievements');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
        Schema::dropIfExists('achievements');

        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('achievements')->nullable();
        });
    }
};
