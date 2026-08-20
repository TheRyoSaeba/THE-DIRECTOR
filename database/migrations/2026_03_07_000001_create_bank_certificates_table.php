<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_certificates', function (Blueprint $table) {
            $table->id();

            
            $table->foreignId('business_id')
                ->constrained('businesses')
                ->cascadeOnDelete();

            
            $table->foreignId('character_id')
                ->constrained('characters')
                ->cascadeOnDelete();

            $table->unsignedInteger('principal');        
            $table->unsignedSmallInteger('rate');        
            $table->unsignedInteger('interest_owed');    

            $table->timestamp('matures_at');             
            $table->timestamp('settled_at')->nullable(); 

            
            
            $table->string('outcome', 12)->nullable();

            $table->timestamps();

            
            $table->index(['business_id', 'settled_at']);
            $table->index(['character_id', 'settled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_certificates');
    }
};
