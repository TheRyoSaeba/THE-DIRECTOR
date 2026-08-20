<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporation_merger_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('target_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('requester_corporation_id')->constrained('corporations')->cascadeOnDelete();
            $table->foreignId('target_corporation_id')->constrained('corporations')->cascadeOnDelete();
            $table->foreignId('requester_successor_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('target_successor_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('holding_name', 100);
            $table->string('holding_image_url', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('requester_handoff_completed_at')->nullable();
            $table->timestampTz('target_handoff_completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['requester_id', 'status']);
            $table->index(['target_id', 'status']);
            $table->index(['requester_corporation_id', 'status']);
            $table->index(['target_corporation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporation_merger_requests');
    }
};
