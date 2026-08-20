<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->string('icon')->default('ChatTeardrop');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('forum_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('forum_categories')->cascadeOnDelete();
            $table->foreignId('character_id')->constrained('characters');
            $table->string('title');
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_solved')->default(false);
            $table->integer('votes')->default(0);
            $table->timestamps();

            $table->index(['category_id', 'is_pinned', 'created_at']);
        });

        Schema::create('forum_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('forum_posts')->cascadeOnDelete();
            $table->foreignId('character_id')->constrained('characters');
            $table->text('body');
            $table->boolean('is_solution')->default(false);
            $table->timestamps();

            $table->index(['post_id', 'created_at']);
        });

        Schema::create('forum_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('forum_posts')->cascadeOnDelete();
            $table->foreignId('character_id')->constrained('characters');
            $table->timestamps();

            $table->unique(['post_id', 'character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_votes');
        Schema::dropIfExists('forum_replies');
        Schema::dropIfExists('forum_posts');
        Schema::dropIfExists('forum_categories');
    }
};
