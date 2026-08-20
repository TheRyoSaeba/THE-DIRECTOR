<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('character_timers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->onDelete('cascade');
            $table->bigInteger('next_work_at')->default(0);
            $table->bigInteger('next_action_at')->default(0);
            $table->bigInteger('next_travel_at')->default(0);
            $table->bigInteger('next_talents_at')->default(0);
            $table->bigInteger('next_study_at')->default(0);
            $table->bigInteger('next_conflict_at')->default(0);
            $table->bigInteger('hospital_until')->default(0);
            $table->bigInteger('protection_until')->default(0);
            $table->string('hospital_reason')->nullable();
            $table->decimal('strength', 5, 2)->default(100.00);

            $table->unique('character_id');
            $table->index('next_work_at');
            $table->index('hospital_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_timers');
    }
};
