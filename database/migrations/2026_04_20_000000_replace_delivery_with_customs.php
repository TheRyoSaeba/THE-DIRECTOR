<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        
        DB::table('careers')
            ->where('code', 'delivery')
            ->update([
                'code'        => 'customs',
                'name'        => 'Customs',
                'description' => 'I\'m sure you\'ll do fine with this level of authority.',
            ]);

        $careerId = DB::table('careers')->where('code', 'customs')->value('id');

        
        DB::table('career_ranks')
            ->where('career_id', $careerId)
            ->where('rank_level', 1)
            ->update([
                'rank_name' => 'Customs Agent',
                'avatar_url'  => 'https://images.thedirector.app/Careers/CustomsAgent.png',

            ]);

        
        DB::table('career_ranks')->insert([
            'career_id'   => $careerId,
            'rank_level'  => 2,
            'rank_name'   => 'Customs Supervisor',
            'xp_required' => 2000,
            'avatar_url'  => 'https://images.thedirector.app/Careers/CustomsSupervisor.png',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        
        DB::table('career_earns')
            ->where('career_id', $careerId)
            ->where('code', 'delivery_local')
            ->update([
                'code'            => 'customs_inspection',
                'title'           => 'Baggage Inspection',
                'success_message' => 'You spent the shift pulling bags off the belt and running them through secondary screening at the airport checkpoint and earned ${payout}.',
                'failure_message' => 'You flagged the wrong passenger for a random search and their lawyer was already on the phone before you finished. Your supervisor sent you home without pay!',
            ]);

        
        DB::table('career_earns')
            ->where('career_id', $careerId)
            ->where('code', 'delivery_express')
            ->update([
                'code'            => 'customs_contraband',
                'title'           => 'Contraband Investigation',
                'stat_intelligence_min' => 0,
                'stat_intelligence_max' => 2,
                'stat_offense_min' => 0,
                'stat_offense_max' => 1,
                'stat_defense_min' => 0,
                'stat_defense_max' => 1,
                'stat_luck_min' => 0,
                'stat_luck_max' => 1,
                'success_message' => 'You led a coordinated inspection of flagged shipments at the airport cargo terminal, cataloguing seized contraband for prosecution and earned ${payout}!',
                'failure_message' => 'Your investigation came up empty after a tip turned out to be bogus - You went home with no pay!',
            ]);
    }

    public function down(): void
    {
        $careerId = DB::table('careers')->where('code', 'customs')->value('id');

        if (!$careerId) {
            return;
        }

        DB::table('careers')
            ->where('id', $careerId)
            ->update([
                'code'        => 'delivery',
                'name'        => 'Delivery Driver',
                'description' => 'Hours delivering packages no one needs',
            ]);

        DB::table('career_ranks')
            ->where('career_id', $careerId)
            ->where('rank_level', 1)
            ->update([
                'rank_name' => 'Driver',
            ]);

        
        DB::table('career_ranks')
            ->where('career_id', $careerId)
            ->where('rank_level', 2)
            ->delete();

        DB::table('career_earns')
            ->where('career_id', $careerId)
            ->where('code', 'customs_inspection')
            ->update([
                'code'            => 'delivery_local',
                'title'           => 'Deliver Local Package',
                'success_message' => 'You drove around all day delivering packages and escaping dogs and earned ${payout}.',
                'failure_message' => 'Someone complained about you and your supervisor decided to send you home without pay!',
            ]);

        DB::table('career_earns')
            ->where('career_id', $careerId)
            ->where('code', 'customs_contraband')
            
            ->update([
                'code'            => 'delivery_express',
                'title'           => 'Express Cross-Town Delivery',
                'success_message' => 'You spent all day delivering one day mail to people who didn\'t need it today and earned ${payout}!',
                'failure_message' => 'Your supervisors decided to cut people today in response to increased wages and you got sent home without pay!',
            ]);
    }
};
