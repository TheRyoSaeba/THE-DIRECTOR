<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launder_offers', function (Blueprint $table) {
            $table->id();
            
            
            $table->foreignId('banker_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('characters')->cascadeOnDelete();
            $table->integer('amount');           
            $table->decimal('cut_pct', 4, 2);   
            $table->integer('amount_sent')->nullable(); 
            
            
            
            
            
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['banker_id', 'status']);
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launder_offers');
    }
};
