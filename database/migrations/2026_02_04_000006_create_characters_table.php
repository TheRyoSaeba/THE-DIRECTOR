<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('display_name')->unique();
            $table->string('gender');
            $table->unsignedBigInteger('city_id');
            $table->unsignedBigInteger('home_city_id');
            $table->unsignedBigInteger('career_id');
            $table->integer('career_rank')->default(1);
            $table->integer('career_xp')->default(0);
            $table->integer('total_character_exp')->default(0);
            $table->integer('health')->default(100);
            $table->integer('max_health')->default(100);
            $table->bigInteger('cash_on_hand')->default(1000);
            $table->bigInteger('cash_in_bank')->default(0);
            $table->bigInteger('dirty_cash')->default(0);
            $table->unsignedBigInteger('property_id')->nullable();
            $table->json('outfit')->nullable();
            $table->string('custom_avatar_url')->nullable();
            $table->string('glow_color')->default('cyan');
            $table->text('biography')->nullable();
            $table->text('additional_info')->nullable();
            $table->string('death_cause')->nullable();
            $table->string('death_reason')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('city_id')->references('id')->on('cities');
            $table->foreign('home_city_id')->references('id')->on('cities');
            $table->foreign('career_id')->references('id')->on('careers');
            $table->foreign('property_id')->references('id')->on('properties')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
