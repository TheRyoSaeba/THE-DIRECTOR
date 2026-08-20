<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporation_trust_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_company_id')->constrained('corporations')->cascadeOnDelete();
            $table->foreignId('initiated_by_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('winner_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('promotion_completed_at')->nullable();
            $table->timestamps();

            $table->index(['holding_company_id', 'status']);
            $table->index(['winner_id', 'status']);
        });

        Schema::create('corporation_trust_vote_ballots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trust_vote_id')->constrained('corporation_trust_votes')->cascadeOnDelete();
            $table->foreignId('voter_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('characters')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['trust_vote_id', 'voter_id']);
            $table->index(['trust_vote_id', 'candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporation_trust_vote_ballots');
        Schema::dropIfExists('corporation_trust_votes');
    }
};
