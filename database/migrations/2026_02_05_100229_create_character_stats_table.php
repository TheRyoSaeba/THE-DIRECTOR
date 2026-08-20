<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('character_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->onDelete('cascade');
            $table->integer('influence')->default(0);
            $table->integer('intelligence')->default(0);
            $table->integer('offense')->default(0);
            $table->integer('defense')->default(0);
            $table->integer('luck')->default(0);
            $table->timestamps();

            $table->unique('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_stats');
    }
};
