<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name', 80);
            $table->string('description', 200)->nullable();
            $table->string('icon', 32)->nullable(); // phosphor icon name OR unicode glyph
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });

        Schema::create('wiki_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('wiki_categories')->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('title', 120);
            $table->string('lede', 250)->nullable();
            $table->text('body_markdown');
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();  // null = draft
            $table->boolean('is_landing_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();

            $table->unique(['category_id', 'slug']);
            $table->index('published_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_pages');
        Schema::dropIfExists('wiki_categories');
    }
};
