<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crime_records', function (Blueprint $table) {
            $table->id();

            
            
            
            $table->unsignedBigInteger('character_id')->nullable();
            $table->unsignedBigInteger('corporation_id')->nullable(); 

            
            $table->unsignedBigInteger('city_id');

            
            
            
            $table->string('type', 50);
            $table->string('severity', 20);

            
            
            
            $table->unsignedTinyInteger('evidence_level')->default(0);

            
            
            
            
            
            
            
            
            
            
            $table->string('status', 30)->default('open');

            
            $table->unsignedBigInteger('detective_id')->nullable();   
            $table->unsignedBigInteger('prosecutor_id')->nullable();  
            $table->unsignedBigInteger('defense_id')->nullable();     
            $table->unsignedBigInteger('judge_id')->nullable();       

            
            $table->timestampTz('referred_at')->nullable();   
            $table->timestampTz('charged_at')->nullable();    
            $table->timestampTz('resolved_at')->nullable();   
            $table->timestampTz('sentenced_at')->nullable();  
            $table->timestampTz('committed_at');              

            
            
            
            $table->jsonb('sentence')->nullable();

            
            
            
            
            
            
            
            
            $table->jsonb('data')->default('{}');

            $table->timestampsTz(); 

            
            $table->foreign('character_id')
                ->references('id')->on('characters')->onDelete('set null');
            $table->foreign('corporation_id')
                ->references('id')->on('corporations')->onDelete('set null');
            $table->foreign('city_id')
                ->references('id')->on('cities')->onDelete('cascade');
            $table->foreign('detective_id')
                ->references('id')->on('characters')->onDelete('set null');
            $table->foreign('prosecutor_id')
                ->references('id')->on('characters')->onDelete('set null');
            $table->foreign('defense_id')
                ->references('id')->on('characters')->onDelete('set null');
            $table->foreign('judge_id')
                ->references('id')->on('characters')->onDelete('set null');

            
            $table->index(['city_id', 'status']);                    
            $table->index(['city_id', 'status', 'evidence_level']); 
            $table->index(['character_id', 'status']);               
            $table->index(['corporation_id', 'status']);             
            $table->index(['detective_id', 'status']);               
            $table->index('committed_at');
            $table->index('referred_at');                           
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crime_records');
    }
};
