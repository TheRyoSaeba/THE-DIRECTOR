<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration 
{
    public function up(): void
    {
        $careerId = DB::table('careers')->where('code', 'technician')->value('id');

        if ($careerId) {
            DB::table('career_ranks')->insert([
                'career_id' => $careerId,
                'rank_level' => 2,
                'rank_name' => 'Engineer',
                'xp_required' => 2000,
                'avatar_url' => 'https://images.thedirector.app/Careers/engineer.png',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('career_earns')
                ->where('career_id', $careerId)
                ->where('code', 'technician_automotive')
                ->update([
                'code' => 'technician_engineer',
                'min_rank' => 2,
                'min_career_xp' => 2000,
                'title' => 'Conduct Site Inspections',
                'payout_min' => 50,
                'payout_max' => 110,
                'rng_min' => 0,
                'rng_max' => 3500,
                'xp_gain_min' => 8,
                'xp_gain_max' => 16,
                'stat_intelligence_min' => 0,
                'stat_intelligence_max' => 1,
                'stat_offense_min' => 0,
                'stat_offense_max' => 1,
                'stat_defense_min' => 0,
                'stat_defense_max' => 2,
                'stat_luck_min' => 0,
                'stat_luck_max' => 1,
                'stat_influence_min' => 0,
                'stat_influence_max' => 0,
                'success_message' => 'You signed off on the structural blueprints for a new residential development and earned ${payout} in consulting fees.',
                'failure_message' => 'You failed to spot a major stress fracture during a routine site inspection — no pay today.',
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $careerId = DB::table('careers')->where('code', 'technician')->value('id');

        if ($careerId) {
            DB::table('career_ranks')
                ->where('career_id', $careerId)
                ->where('rank_level', 2)
                ->delete();

            DB::table('career_earns')
                ->where('career_id', $careerId)
                ->where('code', 'technician_automotive')
                ->update([
                'min_rank' => 1,
                'min_career_xp' => 50,
                'title' => 'Perform Routine Diagnostics',
                'payout_min' => 50,
                'payout_max' => 110,
                'xp_gain_min' => 8,
                'xp_gain_max' => 16,
                'success_message' => 'You diagnosed a misfiring engine using the OBD scanner and earned ${payout} in labor fees.',
                'failure_message' => 'You misread the diagnostic codes and flooded the intake manifold — the shop manager docked your pay.',
                'updated_at' => now(),
            ]);
        }
    }
};
