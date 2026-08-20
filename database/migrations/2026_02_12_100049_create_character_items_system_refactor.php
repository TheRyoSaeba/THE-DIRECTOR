<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    
    public function up(): void
    {
        Schema::table('game_items', function (Blueprint $table) {
            if (!Schema::hasColumn('game_items', 'slot')) {
                $table->string('slot')->nullable()->after('type'); 
            }
        });

        Schema::create('character_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->onDelete('cascade');
            $table->foreignId('game_item_id')->constrained('game_items')->onDelete('cascade');

            $table->integer('durability_remaining')->nullable();
            $table->string('location')->default('on_hand'); 
            $table->boolean('is_equipped')->default(false);
            $table->string('equipped_slot')->nullable(); 

            $table->jsonb('data')->nullable(); 
            $table->timestamps();
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('character_items');
        Schema::table('game_items', function (Blueprint $table) {
            $table->dropColumn('slot');
        });
    }
};
