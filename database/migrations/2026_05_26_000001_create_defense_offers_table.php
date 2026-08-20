<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('defense_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crime_record_id')->constrained('crime_records')->cascadeOnDelete();
            $table->foreignId('attorney_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('defendant_id')->constrained('characters')->cascadeOnDelete();
            $table->integer('fee');
            $table->string('status', 20)->default('pending');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestampsTz();

            $table->index(['crime_record_id', 'status']);
            $table->index(['attorney_id', 'status']);
            $table->index(['defendant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('defense_offers');
    }
};
