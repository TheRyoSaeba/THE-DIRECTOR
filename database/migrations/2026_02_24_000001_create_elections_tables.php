<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('elections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('city_id');
            $table->unsignedInteger('cycle_number')->default(1);
            $table->string('status')->default('registration'); 
            $table->timestamp('registration_start')->nullable();
            $table->timestamp('registration_end')->nullable();
            $table->timestamp('voting_start')->nullable();
            $table->timestamp('voting_end')->nullable();
            $table->unsignedBigInteger('winner_id')->nullable();
            $table->unsignedInteger('total_votes')->default(0);
            $table->timestamp('term_start')->nullable();
            $table->timestamp('term_end')->nullable();
            $table->timestamps();

            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');
            $table->foreign('winner_id')->references('id')->on('characters')->onDelete('set null');
            $table->index(['city_id', 'status']);
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('city_id');
            $table->text('manifesto');
            $table->bigInteger('campaign_fund')->default(0);
            $table->unsignedInteger('votes')->default(0);
            $table->decimal('vote_percentage', 5, 2)->default(0);
            $table->string('status')->default('active'); 
            $table->timestamps();

            $table->foreign('election_id')->references('id')->on('elections')->onDelete('cascade');
            $table->foreign('candidate_id')->references('id')->on('characters')->onDelete('cascade');
            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');
            $table->unique(['election_id', 'candidate_id']);
            $table->index(['election_id', 'status']);
        });

        Schema::create('campaign_votes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('election_id');
            $table->unsignedBigInteger('campaign_id');
            $table->unsignedBigInteger('voter_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('election_id')->references('id')->on('elections')->onDelete('cascade');
            $table->foreign('campaign_id')->references('id')->on('campaigns')->onDelete('cascade');
            $table->foreign('voter_id')->references('id')->on('characters')->onDelete('cascade');
            $table->unique(['election_id', 'voter_id']); 
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_votes');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('elections');
    }
};
