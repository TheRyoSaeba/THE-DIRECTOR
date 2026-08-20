<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leaderboards', function (Blueprint $table) {
            $table->id();

            
            
            
            $table->unsignedBigInteger('character_id')->nullable();
            $table->foreign('character_id')
                ->references('id')->on('characters')
                ->onDelete('set null');

            
            
            
            
            $table->string('display_name', 100);
            $table->string('avatar_url')->nullable();
            $table->string('glow_color', 20)->default('cyan');
            $table->string('career_name', 100);
            $table->string('rank_name', 100)->default('Staff');
            $table->string('home_city_name', 100);

            $table->integer('kills')->default(0);
            $table->bigInteger('total_earns')->default(0);

            
            $table->unsignedTinyInteger('rating')->default(1);

            
            
            $table->boolean('is_historical')->default(false)->index();

            
            
            
            $table->timestampTz('died_at')->nullable();

            
            $table->timestampTz('snapshotted_at')->nullable();

            $table->timestampsTz();
        });

        
        
        
        
        DB::statement('CREATE UNIQUE INDEX leaderboards_character_id_unique ON leaderboards (character_id) WHERE character_id IS NOT NULL');

        DB::statement('CREATE INDEX leaderboards_section_idx ON leaderboards (is_historical, rating DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('leaderboards');
    }
};
