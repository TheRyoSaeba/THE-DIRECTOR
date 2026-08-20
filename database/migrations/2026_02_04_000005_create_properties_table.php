<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('price');
            $table->string('image_url')->nullable();
            $table->integer('vehicle_capacity')->default(0);
            $table->integer('safe_capacity')->default(0);
            $table->boolean('has_alarm')->default(false);
            $table->integer('influence_bonus_pct')->default(0);
            $table->integer('intelligence_bonus_pct')->default(0);
            $table->integer('offense_bonus_pct')->default(0);
            $table->integer('defense_bonus_pct')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
