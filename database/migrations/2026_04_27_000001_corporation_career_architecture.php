<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

 
return new class extends Migration
{
    public function up(): void
    {

        $retailCareer = DB::table('careers')->where('code', 'retail')->first();
        if ($retailCareer) {
            $corpCareer = DB::table('careers')->where('code', 'corporation')->first();
            if ($corpCareer) {
                DB::table('characters')
                    ->where('career_id', $retailCareer->id)
                    ->update([
                        'career_id'   => $corpCareer->id,
                    ]);
            }
            DB::table('career_ranks')->where('career_id', $retailCareer->id)->delete();
            DB::table('career_earns')->where('career_id', $retailCareer->id)->delete();
            DB::table('careers')->where('id', $retailCareer->id)->delete();
        }
    }

    public function down(): void
    {
        
    }
};
