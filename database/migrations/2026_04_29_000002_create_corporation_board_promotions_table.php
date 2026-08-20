<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporation_board_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_company_id')->constrained('corporations')->cascadeOnDelete();
            $table->foreignId('subsidiary_id')->constrained('corporations')->cascadeOnDelete();
            $table->foreignId('promoted_by_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('promoted_ceo_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('successor_id')->constrained('characters')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('handoff_completed_at')->nullable();
            $table->timestamps();

            $table->index(['holding_company_id', 'status']);
            $table->index(['subsidiary_id', 'status']);
            $table->index(['promoted_ceo_id', 'status']);
            $table->index(['successor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporation_board_promotions');
    }
};
