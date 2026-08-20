<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void
    {
        Schema::create('character_histories', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('character_id');
            $table->foreign('character_id')
                ->references('id')->on('characters')
                ->onDelete('cascade');

            $table->string('character_name', 100);

            $table->string('category', 30);

            $table->string('type', 100);

            $table->jsonb('data')->default('{}');

            $table->timestampTz('occurred_at');

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['character_id', 'category']);

            $table->index(['character_id', 'type']);

            $table->index(['character_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_histories');
    }
};
