<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    
    public function up(): void
    {
        Schema::create('game_items', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('name');
            $blueprint->string('slug')->unique();
            $blueprint->string('type'); 
            $blueprint->text('description')->nullable();
            $blueprint->string('image_url')->nullable();

            $blueprint->integer('price')->default(0);
            $blueprint->boolean('is_active')->default(true);
            $blueprint->integer('stock')->nullable();
            $blueprint->integer('max_stock')->nullable();
            $blueprint->timestamp('restock_at')->nullable();

            $blueprint->integer('offense')->default(0);
            $blueprint->integer('defense')->default(0);
            $blueprint->integer('intelligence')->default(0);
            $blueprint->integer('influence')->default(0);
            $blueprint->integer('luck')->default(0);

            $blueprint->json('data')->nullable(); 
            $blueprint->timestamps();
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('game_items');
    }
};
