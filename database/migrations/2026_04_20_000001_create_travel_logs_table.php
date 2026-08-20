<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::create('travel_logs', function (Blueprint $table) {
            $table->id();
            
            
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            
            
            
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            
            
            $table->string('character_name');
            $table->string('gender')->nullable();
            $table->integer('conviction_count')->default(0);
            
            
            $table->enum('direction', ['IN', 'OUT']);
            
            
            $table->foreignId('other_city_id')->nullable()->constrained('cities')->nullOnDelete();
            
            
            
            $table->jsonb('snapshot_data')->nullable();
            
            
            $table->foreignId('searched_by_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->boolean('was_searched')->default(false);
            $table->jsonb('search_results')->nullable(); 

            $table->timestamps();
            
            
            $table->index(['city_id', 'created_at']);
            $table->index('character_id');
        });
    }

    
    public function down(): void
    {
        Schema::dropIfExists('travel_logs');
    }
};
