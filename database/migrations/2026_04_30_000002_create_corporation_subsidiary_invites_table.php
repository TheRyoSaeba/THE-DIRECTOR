<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporation_subsidiary_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_company_id')->constrained('corporations')->cascadeOnDelete();
            $table->foreignId('target_corporation_id')->constrained('corporations')->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('target_ceo_id')->constrained('characters')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->index(['holding_company_id', 'status']);
            $table->index(['target_corporation_id', 'status']);
            $table->index(['requester_id', 'status']);
            $table->index(['target_ceo_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporation_subsidiary_invites');
    }
};
