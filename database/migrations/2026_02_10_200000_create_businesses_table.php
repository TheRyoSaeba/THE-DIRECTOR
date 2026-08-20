<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->onDelete('cascade');

            $table->string('code');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('image_url')->nullable();

            $table->boolean('is_purchasable')->default(false);
            $table->integer('base_price')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->foreignId('owner_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('owner_title')->nullable();

            $table->string('leader_career_code')->nullable();
            $table->string('leader_title')->nullable();

            $table->integer('balance')->default(0);

            $table->jsonb('data')->nullable();

            $table->timestamps();

            $table->unique(['city_id', 'code']);
            $table->index(['city_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
