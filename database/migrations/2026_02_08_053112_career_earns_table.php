// database/migrations/2024_01_01_000009_create_career_earns_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('career_earns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('career_id');
            $table->string('code')->unique();
            $table->string('title');
            $table->integer('min_rank')->default(1);
            $table->integer('min_career_xp')->default(0);
            $table->integer('rng_min')->default(0);
            $table->integer('rng_max')->default(100);
            $table->integer('payout_min')->default(100);
            $table->integer('payout_max')->default(500);
            $table->integer('xp_gain_min')->default(1);
            $table->integer('xp_gain_max')->default(5);
            $table->integer('stat_intelligence_min')->default(0);
            $table->integer('stat_intelligence_max')->default(0);
            $table->integer('stat_offense_min')->default(0);
            $table->integer('stat_offense_max')->default(0);
            $table->integer('stat_defense_min')->default(0);
            $table->integer('stat_defense_max')->default(0);
            $table->integer('stat_luck_min')->default(0);
            $table->integer('stat_luck_max')->default(0);
            $table->integer('stat_influence_min')->default(0);
            $table->integer('stat_influence_max')->default(0);
            $table->text('success_message')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->foreign('career_id')->references('id')->on('careers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_earns');
    }
};
