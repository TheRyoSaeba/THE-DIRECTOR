<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('careers')
            ->where('code', 'service')
            ->update([
                'name' => 'Technician',
                'code' => 'technician',
                'description' => 'Grease under your nails, engines in your head',
            ]);

        $careerId = DB::table('careers')->where('code', 'technician')->value('id');

        if ($careerId) {
            DB::table('career_earns')->where('career_id', $careerId)->delete();

            DB::table('career_earns')->insert([
                [
                    'career_id' => $careerId,
                    'code' => 'technician_mechanic',
                    'title' => 'Service Vehicle ',
                    'min_rank' => 1,
                    'min_career_xp' => 0,
                    'rng_min' => -15,
                    'rng_max' => 20,
                    'payout_min' => 20,
                    'payout_max' => 45,
                    'xp_gain_min' => 3,
                    'xp_gain_max' => 6,
                    'stat_intelligence_min' => 0,
                    'stat_intelligence_max' => 0,
                    'stat_offense_min' => 0,
                    'stat_offense_max' => 0,
                    'stat_defense_min' => 0,
                    'stat_defense_max' => 0,
                    'stat_luck_min' => 0,
                    'stat_luck_max' => 0,
                    'stat_influence_min' => 0,
                    'stat_influence_max' => 0,
                    'success_message' => 'You replaced the brake pads on a sedan and earned ${payout} for the job.',
                    'failure_message' => 'You cross-threaded the lug nuts and the foreman sent you home without pay.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'career_id' => $careerId,
                    'code' => 'technician_automotive',
                    'title' => 'Perform Routine Diagnostics',
                    'min_rank' => 1,
                    'min_career_xp' => 50,
                    'rng_min' => -10,
                    'rng_max' => 185,
                    'payout_min' => 50,
                    'payout_max' => 110,
                    'xp_gain_min' => 8,
                    'xp_gain_max' => 16,
                    'stat_intelligence_min' => 0,
                    'stat_intelligence_max' => 0,
                    'stat_offense_min' => 0,
                    'stat_offense_max' => 0,
                    'stat_defense_min' => 0,
                    'stat_defense_max' => 0,
                    'stat_luck_min' => 0,
                    'stat_luck_max' => 0,
                    'stat_influence_min' => 0,
                    'stat_influence_max' => 0,
                    'success_message' => 'You diagnosed a misfiring engine using the OBD scanner and earned ${payout} in labor fees.',
                    'failure_message' => 'You misread the diagnostic codes and flooded the intake manifold — the shop manager docked your pay.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }
    }

    public function down(): void
    {
        $careerId = DB::table('careers')->where('code', 'technician')->value('id');

        if ($careerId) {
            DB::table('career_earns')->where('career_id', $careerId)->delete();

            DB::table('career_earns')->insert([
                [
                    'career_id' => $careerId,
                    'code' => 'service_password_reset',
                    'title' => 'Handle Password Resets',
                    'min_rank' => 1,
                    'min_career_xp' => 0,
                    'rng_min' => -20,
                    'rng_max' => 10,
                    'payout_min' => 18,
                    'payout_max' => 38,
                    'xp_gain_min' => 2,
                    'xp_gain_max' => 5,
                    'stat_intelligence_min' => 0,
                    'stat_intelligence_max' => 0,
                    'stat_offense_min' => 0,
                    'stat_offense_max' => 0,
                    'stat_defense_min' => 0,
                    'stat_defense_max' => 0,
                    'stat_luck_min' => 0,
                    'stat_luck_max' => 0,
                    'stat_influence_min' => 0,
                    'stat_influence_max' => 0,
                    'success_message' => 'You spent all day resetting passwords and telling people to restart their pc and earned ${payout}.',
                    'failure_message' => 'Your supervisor sent you home because you refused to help a man who was obviously being inappropriate on the phone and got sent home without pay!',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'career_id' => $careerId,
                    'code' => 'service_advanced_support',
                    'title' => 'Advanced Technical Support',
                    'min_rank' => 1,
                    'min_career_xp' => 50,
                    'rng_min' => -10,
                    'rng_max' => 185,
                    'payout_min' => 50,
                    'payout_max' => 100,
                    'xp_gain_min' => 8,
                    'xp_gain_max' => 15,
                    'stat_intelligence_min' => 0,
                    'stat_intelligence_max' => 0,
                    'stat_offense_min' => 0,
                    'stat_offense_max' => 0,
                    'stat_defense_min' => 0,
                    'stat_defense_max' => 0,
                    'stat_luck_min' => 0,
                    'stat_luck_max' => 0,
                    'stat_influence_min' => 0,
                    'stat_influence_max' => 0,
                    'success_message' => 'You managed to resolve a complicated VLAN issue and earned ${payout} in bonuses.',
                    'failure_message' => "You couldn't solve even a simple issue since those night IT classes failed you and you left without pay!",
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
        }

        DB::table('careers')
            ->where('code', 'technician')
            ->update([
                'name' => 'Customer Service',
                'code' => 'service',
                'description' => '24/7 of other people\'s problems',
            ]);
    }
};
