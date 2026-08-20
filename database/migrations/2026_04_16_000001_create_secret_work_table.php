<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up()
    {
        // ? Insert the new "Ransomware Attack" job.
        // ? It is not tied to any specific career (career_id = null) and has no rank requirement.
        // ? The unlock condition (all degrees) will be enforced in the controller.
        DB::table('career_earns')->insert([
            'career_id' => 13,
            'code' => 'ransomware_attack',
            'title' => 'Ransomware Attack',
            'min_rank' => 1,
            'min_career_xp' => 0,
            'rng_min' => 0,
            'rng_max' => 0,
            'payout_min' => 200,
            'payout_max' => 500,
            'xp_gain_min' => 1,
            'xp_gain_max' => 2,
            'stat_intelligence_min' => 5,
            'stat_intelligence_max' => 10,
            'stat_offense_min' => 3,
            'stat_offense_max' => 5,
            'stat_defense_min' => 0,
            'stat_defense_max' => 3,
            'stat_luck_min' => 0,
            'stat_luck_max' => 3,
            'stat_influence_min' => 0,
            'stat_influence_max' => 0,
            'success_message' => 'You deployed your own custom ransomware so deadly it makes wannacry look like a skid project unto an unsuspecting hospital. The victims paid ${payout} in dirty money to restore their data.',
            'failure_message' => 'Your ransomware was detected early and neutralized by security researchers. You gained nothing but wasted your time.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down()
    {
        DB::table('career_earns')->where('code', 'ransomware_attack')->delete();
    }
};