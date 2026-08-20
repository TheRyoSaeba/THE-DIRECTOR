<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('achievements')->insertOrIgnore([
            [
                'slug' => 'elected_mayor',
                'name' => 'People\'s Choice',
                'icon' => '.',
                'icon_url' => 'https://images.thedirector.app/Achievements/themayor.png',
                'description' => 'Exclusive Title Awarded by the Director for winning a mayoral election.',
                'is_secret' => false,
                'trigger_type' => null,
                'trigger_value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'managing_director',
                'name' => 'Chief Executive Officer',
                'icon' => '.',
                'icon_url' => 'https://images.thedirector.app/Achievements/theexecutive.png',
                'description' => 'Exclusive Title Awarded by the Director for becoming the CEO of a corporation.',
                'is_secret' => false,
                'trigger_type' => null,
                'trigger_value' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('achievements')
            ->whereIn('slug', ['elected_mayor', 'managing_director'])
            ->delete();
    }
};
