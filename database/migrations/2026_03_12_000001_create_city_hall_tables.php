<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        
        
        
        
        Schema::create('city_hall_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('character_id')->nullable()->nullOnDelete(); 
            $table->string('author_name', 60);          
            $table->string('author_role', 10)->default('resident'); 
            $table->string('type', 12)->default('forum'); 
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('city_hall_posts')
                ->cascadeOnDelete();
            $table->string('title', 80)->nullable();    
            $table->string('body', 500);
            $table->unsignedSmallInteger('reply_count')->default(0); 
            $table->timestamps();

            $table->index(['city_id', 'type', 'created_at']);
            $table->index(['parent_id', 'created_at']);
        });

        
        Schema::table('character_timers', function (Blueprint $table) {
            $table->bigInteger('last_relocation_at')->nullable()->after('jail_until');
            
            $table->foreignId('pending_relocation_city_id')
                ->nullable()
                ->after('last_relocation_at')
                ->constrained('cities')
                ->nullOnDelete();
        });

        
        
        
        
        
        Schema::create('city_hall_aides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mayor_term_id')
                ->constrained('mayor_terms')
                ->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 60);         
            $table->timestamps();

            $table->unique(['mayor_term_id', 'character_id']);
            $table->index(['city_id', 'mayor_term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_hall_aides');

        Schema::table('character_timers', function (Blueprint $table) {
            $table->dropForeign(['pending_relocation_city_id']);
            $table->dropColumn(['last_relocation_at', 'pending_relocation_city_id']);
        });

        Schema::dropIfExists('city_hall_posts');
    }
};
