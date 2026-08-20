<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('career_ranks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('career_id');
            $table->integer('rank_level');
            $table->string('rank_name');
            $table->integer('xp_required')->default(0);
            $table->string('avatar_url')->nullable();
            $table->timestamps();

            $table->foreign('career_id')->references('id')->on('careers')->onDelete('cascade');
            $table->unique(['career_id', 'rank_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_ranks');
    }
};
