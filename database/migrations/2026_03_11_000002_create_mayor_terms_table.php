<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;


return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mayor_terms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('city_id')
                ->constrained('cities')
                ->cascadeOnDelete();

            $table->foreignId('character_id')
                ->constrained('characters')
                ->cascadeOnDelete();

            
            $table->foreignId('election_id')
                ->constrained('elections')
                ->cascadeOnDelete();

            
            $table->tinyInteger('period')->default(1);

            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            
            $table->string('end_reason', 20)->nullable();

            
            
            $table->integer('city_funds')->default(500_000);
            $table->smallInteger('assembly_score')->default(100);

            
            $table->smallInteger('budget_law')->default(25);
            $table->smallInteger('budget_corp_reg')->default(25);
            $table->smallInteger('budget_services')->default(25);
            $table->smallInteger('budget_bonds')->default(25);

            
            $table->bigInteger('last_period_at')->nullable();

            
            
            
            $table->jsonb('ledger')->default('[]');

            
            
            
            $table->jsonb('actions_log')->default('{}');

            $table->timestamps();

            
            $table->index(['city_id', 'ended_at'], 'mayor_terms_city_active');
            $table->index(['character_id', 'ended_at'], 'mayor_terms_character_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mayor_terms');
    }
};
