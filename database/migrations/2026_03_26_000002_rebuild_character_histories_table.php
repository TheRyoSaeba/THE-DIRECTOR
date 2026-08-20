<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('character_histories');

        Schema::create('character_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id')->primary();
            $table->foreign('character_id')->references('id')->on('characters')->onDelete('cascade');

            
            $table->integer('attacks_landed')->default(0);
            $table->integer('attacks_missed')->default(0);
            $table->integer('kills')->default(0);
            $table->integer('instant_kills')->default(0);
            $table->integer('critical_hits')->default(0);
            $table->integer('gbh_landed')->default(0);
            $table->integer('gbh_missed')->default(0);
            $table->integer('times_hit')->default(0);

            
            $table->integer('cases_investigated')->default(0);
            $table->integer('cases_closed')->default(0);
            $table->integer('arrests_made')->default(0);

            
            $table->integer('cases_prosecuted')->default(0);
            $table->integer('cases_defended')->default(0);
            $table->integer('cases_acquitted')->default(0);
            $table->integer('cases_sentenced')->default(0);
            $table->integer('cases_appealed')->default(0);

            
            $table->integer('surgeries_performed')->default(0);
            $table->integer('gender_reassignments_performed')->default(0);
            $table->integer('ngri_successes')->default(0);

            
            $table->integer('trades_won')->default(0);
            $table->integer('trades_lost')->default(0);
            $table->integer('launders_succeeded')->default(0);
            $table->integer('launders_failed')->default(0);

            
            $table->integer('pardons_issued')->default(0);
            $table->integer('officers_dismissed')->default(0);
            $table->integer('policies_enacted')->default(0);
            $table->integer('terms_served')->default(0);

            
            $table->integer('vehicles_repaired')->default(0);
            $table->integer('homes_inspected')->default(0);

            
            $table->integer('talent_over_educated_used')->default(0);
            $table->integer('talent_defense_in_depth_used')->default(0);
            $table->integer('talent_goal_of_all_life_used')->default(0);

            
            $table->bigInteger('earned_career')->default(0);
            $table->bigInteger('earned_actions')->default(0);
            $table->bigInteger('earned_business')->default(0);

            
            $table->jsonb('career_timeline')->default('[]');
            $table->jsonb('kill_log')->default('[]');

            
            
            //! case type will be useful for scaling by success
            $table->jsonb('case_type_counts')->default('{}');

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_histories');
    }
};
