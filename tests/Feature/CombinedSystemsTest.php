<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\CareerRank;
use App\Models\Character;
use App\Models\CharacterHistory;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\CrimeRecord;
use App\Models\Leaderboard;
use App\Models\MayorTerm;
use App\Models\User;
use App\Services\CrimeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class CombinedSystemsTest extends TestCase
{
    use DatabaseTransactions;

    

    private City $city;
    private int  $unemployedId;
    private int  $policeId;
    private int  $lawId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::create([
            'name'       => 'TestCity-' . uniqid(),
            'slug'       => 'test-city-' . uniqid(),
            'crime_rate' => 50,
        ]);

        $this->unemployedId = DB::table('careers')->where('code', 'unemployed')->value('id');
        $this->policeId     = DB::table('careers')->where('code', 'police')->value('id');
        $this->lawId        = DB::table('careers')->where('code', 'law')->value('id');

        $this->assertNotNull($this->unemployedId, 'unemployed career must be seeded');
        $this->assertNotNull($this->policeId,     'police career must be seeded');
        $this->assertNotNull($this->lawId,        'law career must be seeded');
    }

    

    private function makeCharacter(
        int    $careerId = 0,
        int    $rank     = 1,
        int    $cash     = 100_000,
        int    $xp       = 50_000,
        int    $totalXp  = 100_000,
        int    $offense  = 10_000,
        int    $defense  = 8_000,
        int    $intel    = 12_000,
        int    $luck     = 6_000,
        int    $influence = 0,
    ): Character {
        $user = User::factory()->create();

        $char = Character::create([
            'user_id'             => $user->id,
            'display_name'        => 'TC-' . uniqid(),
            'gender'              => 'male',
            'city_id'             => $this->city->id,
            'home_city_id'        => $this->city->id,
            'career_id'           => $careerId ?: $this->unemployedId,
            'career_rank'         => $rank,
            'career_xp'           => $xp,
            'total_character_exp' => $totalXp,
            'health'              => 100,
            'max_health'          => 100,
            'cash_on_hand'        => $cash,
            'cash_in_bank'        => $cash,
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'offense'      => $offense,
            'defense'      => $defense,
            'intelligence' => $intel,
            'luck'         => $luck,
            'influence'    => $influence,
        ]);

        CharacterTimers::create(['character_id' => $char->id]);

        return $char;
    }

    
    
    

    public function test_character_history_add_history_creates_row_on_first_call(): void
    {
        $char = $this->makeCharacter();
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::addHistory($char, 'attacks_landed');

        $h = DB::table('character_histories')->where('character_id', $char->id)->first();
        $this->assertNotNull($h);
        $this->assertSame(1, (int) $h->attacks_landed);
        $this->assertSame(0, (int) $h->kills, 'untouched columns must remain 0');
    }

    public function test_character_history_add_history_increments_without_duplicate_rows(): void
    {
        $char = $this->makeCharacter();
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::addHistory($char, 'attacks_landed');
        CharacterHistory::addHistory($char, 'attacks_landed');
        CharacterHistory::addHistory($char, 'attacks_landed');

        $this->assertSame(
            1,
            (int) DB::table('character_histories')->where('character_id', $char->id)->count(),
            'upsert must never create duplicate rows',
        );

        $h = DB::table('character_histories')->where('character_id', $char->id)->first();
        $this->assertSame(3, (int) $h->attacks_landed);
    }

    public function test_character_history_add_history_custom_amount(): void
    {
        $char = $this->makeCharacter();
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::addHistory($char, 'earned_career', 50_000);
        CharacterHistory::addHistory($char, 'earned_career', 25_000);

        $h = DB::table('character_histories')->where('character_id', $char->id)->first();
        $this->assertSame(75_000, (int) $h->earned_career);
    }

    public function test_character_history_add_history_multiple_columns_independent(): void
    {
        $char = $this->makeCharacter();
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::addHistory($char, 'earned_business', 10_000);
        CharacterHistory::addHistory($char, 'earned_business', 5_000);
        CharacterHistory::addHistory($char, 'earned_actions', 2_500);

        $h = DB::table('character_histories')->where('character_id', $char->id)->first();
        $this->assertSame(15_000, (int) $h->earned_business);
        $this->assertSame(2_500,  (int) $h->earned_actions);
        $this->assertSame(0,      (int) $h->earned_career, 'earned_career untouched');
    }

    public function test_character_history_append_kill_writes_jsonb_array(): void
    {
        $char = $this->makeCharacter();
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::appendKill($char, 'VictimAlpha');
        CharacterHistory::appendKill($char, 'VictimBeta');

        $h   = DB::table('character_histories')->where('character_id', $char->id)->first();
        $log = json_decode($h->kill_log, true);

        $this->assertIsArray($log);
        $this->assertCount(2, $log);
        $this->assertSame('VictimAlpha', $log[0]['target']);
        $this->assertSame('VictimBeta',  $log[1]['target']);
        $this->assertArrayHasKey('at', $log[0], 'each kill entry must have an at timestamp');
    }

    public function test_character_history_append_career_event_writes_jsonb_array(): void
    {
        $char = $this->makeCharacter();
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::appendCareerEvent($char, [
            'type'        => 'career_started',
            'career_name' => 'Police',
            'career_code' => 'police',
            'rank_name'   => 'Sergeant',
        ]);
        CharacterHistory::appendCareerEvent($char, [
            'type'        => 'promoted',
            'career_name' => 'Police',
            'career_code' => 'police',
            'old_rank'    => 'Sergeant',
            'new_rank'    => 'Inspector',
        ]);

        $h      = DB::table('character_histories')->where('character_id', $char->id)->first();
        $events = json_decode($h->career_timeline, true);

        $this->assertIsArray($events);
        $this->assertCount(2, $events);
        $this->assertSame('career_started', $events[0]['type']);
        $this->assertSame('promoted',       $events[1]['type']);
        $this->assertSame('Inspector',      $events[1]['new_rank']);
        $this->assertArrayHasKey('at', $events[0], 'career event must have at timestamp injected');
    }

    public function test_character_history_add_history_case_type_increments_jsonb_map(): void
    {
        $char = $this->makeCharacter();
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::addHistoryCaseType($char, 'murder');
        CharacterHistory::addHistoryCaseType($char, 'murder');
        CharacterHistory::addHistoryCaseType($char, 'assault');

        $h      = DB::table('character_histories')->where('character_id', $char->id)->first();
        $counts = json_decode($h->case_type_counts, true);

        $this->assertIsArray($counts);
        $this->assertSame(2, $counts['murder']);
        $this->assertSame(1, $counts['assault']);
        $this->assertArrayNotHasKey('kidnapping', $counts, 'never-written types must not appear');
    }

    public function test_character_history_for_settings_page_returns_all_required_keys(): void
    {
        $char = $this->makeCharacter();
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::addHistory($char, 'attacks_landed', 3);
        CharacterHistory::addHistory($char, 'earned_career', 75_000);
        CharacterHistory::addHistory($char, 'earned_business', 15_000);
        CharacterHistory::addHistory($char, 'earned_actions', 2_500);

        $data = CharacterHistory::forSettingsPage($char);

        $this->assertArrayHasKey('combat', $data);
        $this->assertArrayHasKey('finance', $data);
        $this->assertArrayHasKey('career_history', $data);
        $this->assertArrayHasKey('career_activity', $data);
        $this->assertArrayHasKey('talents', $data);

        $this->assertSame(3,      $data['combat']['attacks_landed']);
        $this->assertSame(75_000, $data['finance']['earned_career']);
        $this->assertSame(15_000, $data['finance']['earned_business']);
        $this->assertSame(2_500,  $data['finance']['earned_actions']);
    }

    public function test_character_history_row_survives_character_soft_delete(): void
    {
        $char = $this->makeCharacter();
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::addHistory($char, 'attacks_landed');
        $this->assertTrue(DB::table('character_histories')->where('character_id', $char->id)->exists());

        
        DB::table('characters')->where('id', $char->id)->update(['deleted_at' => now()]);

        $this->assertTrue(
            DB::table('character_histories')->where('character_id', $char->id)->exists(),
            'history row must not cascade when character is soft-deleted',
        );
    }

    public function test_character_history_one_row_per_character_invariant(): void
    {
        $char = $this->makeCharacter();
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::addHistory($char, 'attacks_landed');
        CharacterHistory::addHistory($char, 'kills');
        CharacterHistory::addHistory($char, 'earned_career', 10_000);
        CharacterHistory::appendKill($char, 'SomeVictim');
        CharacterHistory::appendCareerEvent($char, ['type' => 'career_started', 'career_name' => 'Police', 'career_code' => 'police', 'rank_name' => 'Staff']);
        CharacterHistory::addHistoryCaseType($char, 'murder');

        $this->assertSame(
            1,
            (int) DB::table('character_histories')->where('character_id', $char->id)->count(),
            'all writes must converge to exactly one row per character',
        );
    }

    public function test_character_history_has_goal_of_all_life_reads_times_hit_column(): void
    {
        $char = $this->makeCharacter(totalXp: 999_999);
        DB::table('character_histories')->where('character_id', $char->id)->delete();

        CharacterHistory::addHistory($char, 'attacks_landed'); 
        DB::table('characters')->where('id', $char->id)->update(['total_character_exp' => 999_999]);
        $char->refresh();

        DB::table('character_histories')->where('character_id', $char->id)->update(['times_hit' => 2]);
        $char->refresh();
        $this->assertFalse(
            $char->hasGoalOfAllLifeRequirements(),
            'times_hit=2 must NOT qualify (needs >= 3)',
        );

        DB::table('character_histories')->where('character_id', $char->id)->update(['times_hit' => 3]);
        $char->refresh();
        $this->assertTrue(
            $char->hasGoalOfAllLifeRequirements(),
            'times_hit=3 with high XP must qualify',
        );
    }

    public function test_character_history_schema_has_all_expected_columns(): void
    {
        $cols = DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'character_histories' ORDER BY ordinal_position",
        );
        $colNames = array_column($cols, 'column_name');

        $required = [
            'character_id', 'attacks_landed', 'attacks_missed', 'kills', 'instant_kills',
            'critical_hits', 'gbh_landed', 'gbh_missed', 'times_hit',
            'cases_investigated', 'cases_closed', 'arrests_made',
            'cases_prosecuted', 'cases_defended', 'cases_acquitted', 'cases_sentenced', 'cases_appealed',
            'surgeries_performed', 'gender_reassignments_performed', 'ngri_successes',
            'trades_won', 'trades_lost', 'launders_succeeded', 'launders_failed',
            'pardons_issued', 'officers_dismissed', 'policies_enacted', 'terms_served',
            'vehicles_repaired',
            'earned_career', 'earned_actions', 'earned_business',
            'career_timeline', 'kill_log', 'case_type_counts',
            'created_at', 'updated_at',
        ];

        foreach ($required as $col) {
            $this->assertContains($col, $colNames, "character_histories must have column '{$col}'");
        }

        
        foreach (['category', 'type', 'data', 'occurred_at'] as $stale) {
            $this->assertNotContains($stale, $colNames, "stale column '{$stale}' must not exist");
        }
    }

    
    
    

    public function test_leaderboard_snapshot_on_death_creates_row_for_qualifying_character(): void
    {
        $char = $this->makeCharacter(
            offense: 50_000, defense: 40_000, intel: 60_000, luck: 30_000,
            influence: 100, totalXp: 500_000, xp: 200_000,
        );

        Leaderboard::snapshotOnDeath($char->id);

        $row = DB::table('leaderboards')->where('character_id', $char->id)->first();
        $this->assertNotNull($row, 'high-stats character must land on the leaderboard');
        $this->assertTrue((bool) $row->is_historical);
        $this->assertNotNull($row->died_at);
        $this->assertGreaterThanOrEqual(1,  $row->rating);
        $this->assertLessThanOrEqual(99, $row->rating);
        $this->assertSame($char->display_name, $row->display_name);
    }

    public function test_leaderboard_snapshot_on_death_no_op_for_below_median_character(): void
    {
        
        $weak = $this->makeCharacter(
            offense: 1, defense: 1, intel: 1, luck: 1, influence: 0, totalXp: 1, xp: 1,
        );

        Leaderboard::snapshotOnDeath($weak->id);

        $row = DB::table('leaderboards')->where('character_id', $weak->id)->first();
        $this->assertNull($row, 'below-median character must NOT be added to leaderboard');
    }

    public function test_leaderboard_snapshot_upserts_existing_current_row_to_historical(): void
    {
        $char = $this->makeCharacter(
            offense: 50_000, defense: 40_000, intel: 60_000, luck: 30_000,
            influence: 100, totalXp: 500_000,
        );

        
        DB::table('leaderboards')->insert([
            'character_id'   => $char->id,
            'display_name'   => $char->display_name,
            'avatar_url'     => null,
            'glow_color'     => 'cyan',
            'career_name'    => 'Test',
            'rank_name'      => 'Staff',
            'home_city_name' => 'City',
            'kills'          => 0,
            'total_earns'    => 0,
            'rating'         => 10,
            'is_historical'  => false,
            'died_at'        => null,
            'snapshotted_at' => now(),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        Leaderboard::snapshotOnDeath($char->id);

        $this->assertSame(
            1,
            (int) DB::table('leaderboards')->where('character_id', $char->id)->count(),
            'upsert must not create a second row',
        );
        $row = DB::table('leaderboards')->where('character_id', $char->id)->first();
        $this->assertTrue((bool) $row->is_historical);
        $this->assertNotSame(10, $row->rating, 'rating must be recomputed, not left as 10');
    }

    public function test_leaderboard_clear_on_revival_removes_row(): void
    {
        $char = $this->makeCharacter(
            offense: 50_000, defense: 40_000, intel: 60_000, luck: 30_000,
            influence: 100, totalXp: 500_000,
        );

        Leaderboard::snapshotOnDeath($char->id);
        $this->assertTrue(
            DB::table('leaderboards')->where('character_id', $char->id)->exists(),
        );

        Leaderboard::clearOnRevival($char->id);
        $this->assertFalse(
            DB::table('leaderboards')->where('character_id', $char->id)->exists(),
            'row must be deleted on revival',
        );
    }

    public function test_leaderboard_clear_on_revival_is_safe_noop_when_no_row(): void
    {
        
        Leaderboard::clearOnRevival(99_999_999);
        $this->assertTrue(true, 'no exception expected');
    }

    public function test_leaderboard_cron_cannot_flip_historical_row_back_to_current(): void
    {
        $char = $this->makeCharacter(
            offense: 50_000, defense: 40_000, intel: 60_000, luck: 30_000,
            influence: 100, totalXp: 500_000,
        );

        Leaderboard::snapshotOnDeath($char->id);
        $beforeCron = (bool) DB::table('leaderboards')->where('character_id', $char->id)->value('is_historical');
        $this->assertTrue($beforeCron);

        
        DB::statement("
            INSERT INTO leaderboards (
                character_id, display_name, avatar_url, glow_color,
                career_name, rank_name, home_city_name, kills, total_earns, rating,
                is_historical, died_at, snapshotted_at, created_at, updated_at
            ) VALUES (?, ?, null, 'cyan', 'Test', 'Staff', 'City', 0, 0, 55, false, null, now(), now(), now())
            ON CONFLICT (character_id) WHERE character_id IS NOT NULL
            DO UPDATE SET
                is_historical  = EXCLUDED.is_historical,
                rating         = EXCLUDED.rating,
                snapshotted_at = EXCLUDED.snapshotted_at,
                updated_at     = EXCLUDED.updated_at
            WHERE leaderboards.is_historical = false
        ", [$char->id, $char->display_name]);

        $afterCron = (bool) DB::table('leaderboards')->where('character_id', $char->id)->value('is_historical');
        $this->assertTrue($afterCron, 'WHERE guard must prevent cron from overwriting a historical row');
    }

    public function test_leaderboard_row_survives_character_hard_delete_with_null_fk(): void
    {
        $char = $this->makeCharacter(
            offense: 50_000, defense: 40_000, intel: 60_000, luck: 30_000,
            influence: 100, totalXp: 500_000,
        );
        $charId = $char->id;
        $name   = $char->display_name;

        Leaderboard::snapshotOnDeath($charId);
        $this->assertNotNull(DB::table('leaderboards')->where('character_id', $charId)->first());

        
        DB::table('character_stats')->where('character_id', $charId)->delete();
        DB::table('character_timers')->where('character_id', $charId)->delete();
        DB::table('characters')->where('id', $charId)->delete();

        $orphan = DB::table('leaderboards')
            ->where('display_name', $name)
            ->whereNull('character_id')
            ->first();

        $this->assertNotNull($orphan, 'leaderboard row must persist after character hard-delete');
        $this->assertNull($orphan->character_id, 'character_id must be NULLed by SET NULL FK');
        $this->assertSame($name, $orphan->display_name, 'display_name must survive');
    }

    public function test_leaderboard_born_at_captures_character_created_at(): void
    {
        $char = $this->makeCharacter(
            offense: 50_000, defense: 40_000, intel: 60_000, luck: 30_000,
            influence: 100, totalXp: 500_000,
        );

        Leaderboard::snapshotOnDeath($char->id);

        $row = DB::table('leaderboards')->where('character_id', $char->id)->first();
        $this->assertNotNull($row->born_at, 'born_at must be populated');
    }

    
    
    

    private function makeLawChar(int $rank): Character
    {
        return $this->makeCharacter(careerId: $this->lawId, rank: $rank, xp: 999_999);
    }

    private function makePoliceChar(int $rank): Character
    {
        return $this->makeCharacter(careerId: $this->policeId, rank: $rank, xp: 999_999);
    }

    public function test_law_pipeline_full_felony_conviction_cycle(): void
    {
        $perp       = $this->makeCharacter();
        $victim     = $this->makeCharacter();
        $detective  = $this->makePoliceChar(2);
        $prosecutor = $this->makeLawChar(2);
        $defender   = $this->makeLawChar(2);
        $judge      = $this->makeLawChar(3);

        
        $crime = CrimeService::assault($perp, $victim, $this->city->id, CrimeRecord::SEV_FELONY);
        $this->assertSame(CrimeRecord::STATUS_OPEN, $crime->status);
        $this->assertGreaterThan(0, $crime->evidence_level);
        $this->assertArrayHasKey('victim_id', $crime->data);

        
        $ok = $crime->assignDetective($detective);
        $crime->refresh();
        $this->assertTrue($ok);
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $crime->status);
        $this->assertSame($detective->id, $crime->detective_id);

        
        $ok = $crime->refer($detective, [$perp->display_name]);
        $crime->refresh();
        $this->assertTrue($ok);
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $crime->status);
        $this->assertContains($perp->display_name, $crime->data['suspect_names']);
        $this->assertNotNull($crime->referred_at);

        
        $ok = $crime->fileCharges($prosecutor);
        $crime->refresh();
        $this->assertTrue($ok);
        $this->assertSame(CrimeRecord::STATUS_CHARGED, $crime->status);
        $this->assertSame($prosecutor->id, $crime->prosecutor_id);
        $this->assertNotNull($crime->charged_at);

        
        $this->assertFalse($crime->assignDefense($prosecutor));

        
        $ok = $crime->assignDefense($defender);
        $crime->refresh();
        $this->assertTrue($ok);
        $this->assertSame($defender->id, $crime->defense_id);

        
        $other = $this->makeLawChar(2);
        $this->assertFalse($crime->assignDefense($other));

        
        $ok = $crime->resolveFromDefense($defender, false);
        $crime->refresh();
        $this->assertTrue($ok);
        $this->assertSame(CrimeRecord::STATUS_CONVICTED, $crime->status);

        
        $this->assertFalse($crime->applySentence($defender, ['fine' => 500, 'jail_seconds' => 0]));

        
        $ok = $crime->applySentence($judge, ['fine' => 5_000, 'jail_seconds' => 3_600]);
        $crime->refresh();
        $this->assertTrue($ok);
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $crime->status);
        $this->assertSame(5_000, (int) ($crime->sentence['fine'] ?? 0));
        $this->assertSame(3_600, (int) ($crime->sentence['jail_seconds'] ?? 0));
        $this->assertNotNull($crime->sentenced_at);
    }

    public function test_law_pipeline_apply_sentence_to_character_deducts_fine_and_jails(): void
    {
        $perp      = $this->makeCharacter(cash: 100_000);
        $victim    = $this->makeCharacter();
        $detective = $this->makePoliceChar(2);
        $prosecutor = $this->makeLawChar(2);
        $defender  = $this->makeLawChar(2);
        $judge     = $this->makeLawChar(3);

        $crime = CrimeService::assault($perp, $victim, $this->city->id, CrimeRecord::SEV_FELONY);
        $crime->assignDetective($detective);
        $crime->refer($detective, [$perp->display_name]);
        $crime->fileCharges($prosecutor);
        $crime->assignDefense($defender);
        $crime->resolveFromDefense($defender, false);
        $crime->applySentence($judge, ['fine' => 5_000, 'jail_seconds' => 3_600]);

        $cashBefore = (int) $perp->cash_on_hand;
        $crime->applySentenceToCharacter($perp);
        $perp->refresh();

        $this->assertSame($cashBefore - 5_000, (int) $perp->cash_on_hand, 'fine must be deducted');
        $perp->load('timers');
        $this->assertTrue($perp->timers?->jail_until?->isFuture(), 'jail timer must be set');
    }

    public function test_law_pipeline_felony_can_be_appealed_and_upheld_by_chief_justice(): void
    {
        $perp       = $this->makeCharacter();
        $victim     = $this->makeCharacter();
        $detective  = $this->makePoliceChar(2);
        $prosecutor = $this->makeLawChar(2);
        $defender   = $this->makeLawChar(2);
        $judge      = $this->makeLawChar(3);
        $cj         = $this->makeLawChar(4);

        $crime = CrimeService::assault($perp, $victim, $this->city->id, CrimeRecord::SEV_FELONY);
        $crime->assignDetective($detective);
        $crime->refer($detective, [$perp->display_name]);
        $crime->fileCharges($prosecutor);
        $crime->assignDefense($defender);
        $crime->resolveFromDefense($defender, false);
        $crime->applySentence($judge, ['fine' => 1_000, 'jail_seconds' => 0]);

        $this->assertTrue($crime->appeal());
        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_APPEALED, $crime->status);
        $this->assertNotNull($crime->appealed_at);

        
        $this->assertFalse($crime->resolveAppeal($judge, true));

        
        $ok = $crime->resolveAppeal($cj, true);
        $crime->refresh();
        $this->assertTrue($ok);
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $crime->status, 'upheld appeal reinstates sentence');
        $this->assertArrayHasKey('chief_justice_id', $crime->data['appeal'] ?? []);
    }

    public function test_law_pipeline_defence_wins_leads_to_acquittal(): void
    {
        $perp       = $this->makeCharacter();
        $victim     = $this->makeCharacter();
        $detective  = $this->makePoliceChar(2);
        $prosecutor = $this->makeLawChar(2);
        $defender   = $this->makeLawChar(2);

        $crime = CrimeService::assault($perp, $victim, $this->city->id, CrimeRecord::SEV_FELONY);
        $crime->assignDetective($detective);
        $crime->refer($detective, [$perp->display_name]);
        $crime->fileCharges($prosecutor);
        $crime->assignDefense($defender);
        $crime->resolveFromDefense($defender, true); 
        $crime->refresh();

        $this->assertSame(CrimeRecord::STATUS_ACQUITTED, $crime->status);
        $this->assertNotNull($crime->resolved_at);
    }

    public function test_law_pipeline_misdemeanor_cannot_be_appealed(): void
    {
        $perp       = $this->makeCharacter();
        $victim     = $this->makeCharacter();
        $detective  = $this->makePoliceChar(2);
        $prosecutor = $this->makeLawChar(2);
        $judge      = $this->makeLawChar(3);

        $crime = CrimeService::rugPull($perp, $victim, $this->city->id, 500);
        $this->assertSame(CrimeRecord::SEV_MISDEMEANOR, $crime->severity);

        $crime->assignDetective($detective);
        $crime->refer($detective, [$perp->display_name]);
        $crime->fileCharges($prosecutor);
        $crime->convict($judge);
        $crime->applySentence($judge, ['fine' => 200, 'jail_seconds' => 0]);

        $this->assertFalse($crime->appeal(), 'misdemeanor must not be appealable');
    }

    public function test_law_pipeline_double_action_replay_attacks_are_blocked(): void
    {
        $perp       = $this->makeCharacter();
        $victim     = $this->makeCharacter();
        $detective  = $this->makePoliceChar(2);
        $prosecutor = $this->makeLawChar(2);
        $defender   = $this->makeLawChar(2);
        $judge      = $this->makeLawChar(3);
        $cj         = $this->makeLawChar(4);

        $crime = CrimeService::assault($perp, $victim, $this->city->id, CrimeRecord::SEV_FELONY);
        $crime->assignDetective($detective);
        $crime->refer($detective, [$perp->display_name]);
        $crime->fileCharges($prosecutor);

        $this->assertFalse($crime->fileCharges($prosecutor), 'double fileCharges blocked');

        $crime->assignDefense($defender);
        $this->assertFalse($crime->assignDefense($defender), 'double assignDefense blocked');

        $crime->resolveFromDefense($defender, false);
        $this->assertFalse($crime->convict($judge), 'convict after resolveFromDefense blocked');

        $crime->applySentence($judge, ['fine' => 500, 'jail_seconds' => 0]);
        $this->assertFalse($crime->applySentence($judge, ['fine' => 1_000, 'jail_seconds' => 0]), 'double applySentence blocked');

        $crime->appeal();
        $this->assertFalse($crime->appeal(), 'double appeal blocked');

        $crime->resolveAppeal($cj, true);
        $this->assertFalse($crime->resolveAppeal($cj, false), 'double resolveAppeal blocked');
    }

    public function test_law_pipeline_conflict_of_interest_guards(): void
    {
        $perp       = $this->makeCharacter();
        $victim     = $this->makeCharacter();
        $detective  = $this->makePoliceChar(2);
        $prosecutor = $this->makeLawChar(2);

        $crime = CrimeService::assault($perp, $victim, $this->city->id, CrimeRecord::SEV_FELONY);
        $crime->assignDetective($detective);
        $crime->refer($detective, [$perp->display_name]);

        $this->assertTrue($crime->isConflicted($victim),   'victim must be conflicted');
        $this->assertTrue($crime->isConflicted($perp),     'perp must be conflicted');
        $this->assertFalse($crime->isConflicted($detective), 'detective must NOT be conflicted');

        $crime->fileCharges($prosecutor);
        $this->assertFalse($crime->assignDefense($prosecutor), 'prosecutor cannot defend same case');
    }

    public function test_law_pipeline_rank_gating(): void
    {
        $perp   = $this->makeCharacter();
        $victim = $this->makeCharacter();

        $murder   = CrimeService::murder($perp, $victim, $this->city->id);
        $misdem   = CrimeService::rugPull($perp, $victim, $this->city->id, 100);

        $this->assertFalse($murder->isAccessibleAtPoliceRank(2), 'murder blocked at police rank 2');
        $this->assertTrue($murder->isAccessibleAtPoliceRank(3),  'murder accessible at police rank 3');
        $this->assertTrue($misdem->isAccessibleAtPoliceRank(1),  'misdemeanor accessible at police rank 1');

        $this->assertFalse($murder->isAccessibleAtLawRank(2), 'murder blocked at law rank 2');
        $this->assertTrue($murder->isAccessibleAtLawRank(3),  'murder accessible at law rank 3');
        $this->assertTrue($murder->isAccessibleAtLawRank(4),  'murder accessible at law rank 4');

        $murder->delete();
        $misdem->delete();
    }

    public function test_law_pipeline_evidence_add_and_remove_with_clamping(): void
    {
        $perp   = $this->makeCharacter();
        $victim = $this->makeCharacter();

        $crime = CrimeService::assault($perp, $victim, $this->city->id, CrimeRecord::SEV_FELONY);
        $base  = $crime->evidence_level;

        $crime->addEvidence(20, 'Found fingerprints');
        $crime->refresh();
        $this->assertSame(min(100, $base + 20), $crime->evidence_level);
        $notes = $crime->data['investigation_notes'] ?? [];
        $this->assertNotEmpty($notes);
        $this->assertSame('Found fingerprints', $notes[0]['note'] ?? '');

        $crime->addEvidence(200); 
        $crime->refresh();
        $this->assertSame(100, $crime->evidence_level);

        $crime->removeEvidence(9_999); 
        $crime->refresh();
        $this->assertGreaterThanOrEqual(0, $crime->evidence_level);

        $crime->delete();
    }

    public function test_law_pipeline_close_is_terminal(): void
    {
        $perp       = $this->makeCharacter();
        $victim     = $this->makeCharacter();
        $detective  = $this->makePoliceChar(2);
        $prosecutor = $this->makeLawChar(2);
        $defender   = $this->makeLawChar(2);

        $crime = CrimeService::assault($perp, $victim, $this->city->id, CrimeRecord::SEV_FELONY);
        $crime->assignDetective($detective);
        $crime->refer($detective, [$perp->display_name]);
        $crime->fileCharges($prosecutor);
        $crime->close();
        $crime->refresh();

        $this->assertSame(CrimeRecord::STATUS_CLOSED, $crime->status);
        $this->assertFalse($crime->fileCharges($prosecutor), 'closed case cannot be charged');
        $this->assertFalse($crime->assignDefense($defender),  'closed case cannot take defense');
        $this->assertFalse($crime->appeal(),                  'closed case cannot be appealed');
    }

    public function test_law_pipeline_validate_appeal_accuracy_correct_conviction(): void
    {
        $perp       = $this->makeCharacter();
        $victim     = $this->makeCharacter();
        $detective  = $this->makePoliceChar(2);
        $prosecutor = $this->makeLawChar(2);
        $defender   = $this->makeLawChar(2);
        $judge      = $this->makeLawChar(3);
        $cj         = $this->makeLawChar(4);

        $crime = CrimeService::assault($perp, $victim, $this->city->id, CrimeRecord::SEV_FELONY);
        $crime->assignDetective($detective);
        $crime->refer($detective, [$perp->display_name]);
        $crime->fileCharges($prosecutor);
        $crime->assignDefense($defender);
        $crime->resolveFromDefense($defender, false);
        $crime->applySentence($judge, ['fine' => 1_000, 'jail_seconds' => 0]);
        $crime->appeal();
        $crime->resolveAppeal($cj, true); 

        $this->assertTrue(
            $crime->validateAppealAccuracy($cj, true),
            'CJ was correct to uphold a guilty perp',
        );
    }

    public function test_law_pipeline_validate_appeal_accuracy_wrong_conviction_penalises_xp(): void
    {
        $perp       = $this->makeCharacter();
        $victim     = $this->makeCharacter();
        $detective  = $this->makePoliceChar(2);
        $prosecutor = $this->makeLawChar(2);
        $defender   = $this->makeLawChar(2);
        $judge      = $this->makeLawChar(3);
        $cj         = $this->makeLawChar(4);

        $crime = CrimeService::assault($perp, $victim, $this->city->id, CrimeRecord::SEV_FELONY);
        $crime->assignDetective($detective);
        $crime->refer($detective, [$perp->display_name]);
        $crime->fileCharges($prosecutor);
        $crime->assignDefense($defender);
        $crime->resolveFromDefense($defender, false);
        $crime->applySentence($judge, ['fine' => 1_000, 'jail_seconds' => 0]);
        $crime->appeal();

        $cjXpBefore = (int) $cj->career_xp;
        $crime->resolveAppeal($cj, false); 

        $wasCorrect = $crime->validateAppealAccuracy($cj, false);
        $this->assertFalse($wasCorrect, 'CJ was WRONG to overturn a guilty perp');
        $cj->refresh();
        $this->assertLessThan($cjXpBefore, (int) $cj->career_xp, 'wrong CJ must be penalised XP');
    }

    
    
    

    public function test_mayor_term_constants_match_spec(): void
    {
        $this->assertSame(500_000, MayorTerm::STARTING_FUNDS);
        $this->assertSame(100,     MayorTerm::STARTING_ASSEMBLY);
        $this->assertSame(4,       MayorTerm::PERIODS_PER_TERM);
        $this->assertSame(10 * 3600, MayorTerm::PARDON_COOLDOWN_SECONDS);
    }

    public function test_mayor_term_bond_yield_formula(): void
    {
        
        $this->assertEqualsWithDelta(20.0, MayorTerm::calculateBondYield(0),   0.001);
        $this->assertEqualsWithDelta(15.0, MayorTerm::calculateBondYield(20),  0.001);
        $this->assertEqualsWithDelta(10.0, MayorTerm::calculateBondYield(40),  0.001);
        $this->assertEqualsWithDelta(4.0,  MayorTerm::calculateBondYield(64),  0.001, 'floor at 4% before 64%');
        $this->assertEqualsWithDelta(4.0,  MayorTerm::calculateBondYield(100), 0.001, 'floor holds at 100% crime');
    }

    public function test_mayor_term_services_multiplier(): void
    {
        $term = new MayorTerm();

        $term->budget_services = 50;
        $this->assertSame(1.5, $term->servicesMultiplier(), '>= 50 → 1.5');

        $term->budget_services = 25;
        $this->assertSame(1.0, $term->servicesMultiplier(), '25–49 → 1.0');

        $term->budget_services = 24;
        $this->assertSame(0.8, $term->servicesMultiplier(), '< 25 → 0.8');
    }

    public function test_mayor_term_law_passive_drift(): void
    {
        $term = new MayorTerm();

        $term->budget_law = 100;
        $this->assertSame(-10.0, $term->lawPassiveDrift());

        $term->budget_law = 75;
        $this->assertSame(-5.0, $term->lawPassiveDrift());

        $term->budget_law = 50;
        $this->assertSame(-2.5, $term->lawPassiveDrift());

        $term->budget_law = 25;
        $this->assertSame(0.0, $term->lawPassiveDrift());

        $term->budget_law = 0;
        $this->assertSame(0.4, $term->lawPassiveDrift(), 'no law → crime drifts UP');
    }

    public function test_mayor_term_is_valid_budget_requires_exactly_100(): void
    {
        $term = new MayorTerm([
            'budget_law'      => 25,
            'budget_corp_reg' => 25,
            'budget_services' => 25,
            'budget_bonds'    => 25,
        ]);
        $this->assertTrue($term->isValidBudget());

        $term->budget_bonds = 24;
        $this->assertFalse($term->isValidBudget(), '99 must fail');

        $term->budget_bonds = 26;
        $this->assertFalse($term->isValidBudget(), '101 must fail');
    }

    public function test_mayor_term_assembly_delta_constants_match_spec(): void
    {
        $this->assertSame(3,   MayorTerm::DELTA_POSITIVE_PERIOD);
        $this->assertSame(-15, MayorTerm::DELTA_NEGATIVE_PERIOD);
        $this->assertSame(-5,  MayorTerm::DELTA_HIGH_CRIME);
        $this->assertSame(-10, MayorTerm::DELTA_BOND_DEFAULT);
        $this->assertSame(-8,  MayorTerm::DELTA_SUPPRESSION);
        $this->assertSame(-8,  MayorTerm::DELTA_SUPPRESSION_PERIODIC);
        $this->assertSame(4,   MayorTerm::DELTA_AUDIT_SUCCESS);
        $this->assertSame(2,   MayorTerm::DELTA_CLEAN_PERIOD);
        $this->assertSame(5,   MayorTerm::DELTA_BOND_MATURED);
    }

    public function test_mayor_term_adjust_assembly_clamps_to_zero_floor(): void
    {
        $city    = $this->city;
        $user    = User::factory()->create();
        $mayor   = $this->makeCharacter();
        $career  = Career::findByCode('unemployed');

        $election = \App\Models\Election::create([
            'city_id'    => $city->id,
            'status'     => 'completed',
            'started_at' => now(),
            'ended_at'   => now(),
        ]);

        $term = MayorTerm::create([
            'city_id'        => $city->id,
            'character_id'   => $mayor->id,
            'election_id'    => $election->id,
            'period'         => 1,
            'started_at'     => now(),
            'city_funds'     => 500_000,
            'assembly_score' => 5,
            'budget_law'     => 25,
            'budget_corp_reg'=> 25,
            'budget_services'=> 25,
            'budget_bonds'   => 25,
            'ledger'         => [],
            'actions_log'    => [],
        ]);

        $new = $term->adjustAssembly(-10);
        $this->assertSame(0, $new, 'adjustAssembly must clamp at 0, not go negative');

        $term->refresh();
        $this->assertSame(0, $term->assembly_score);
    }

    public function test_mayor_term_adjust_assembly_clamps_to_100_ceiling(): void
    {
        $mayor    = $this->makeCharacter();
        $election = \App\Models\Election::create([
            'city_id'    => $this->city->id,
            'status'     => 'completed',
            'started_at' => now(),
            'ended_at'   => now(),
        ]);

        $term = MayorTerm::create([
            'city_id'         => $this->city->id,
            'character_id'    => $mayor->id,
            'election_id'     => $election->id,
            'period'          => 1,
            'started_at'      => now(),
            'city_funds'      => 500_000,
            'assembly_score'  => 98,
            'budget_law'      => 25,
            'budget_corp_reg' => 25,
            'budget_services' => 25,
            'budget_bonds'    => 25,
            'ledger'          => [],
            'actions_log'     => [],
        ]);

        $new = $term->adjustAssembly(+10);
        $this->assertSame(100, $new, 'adjustAssembly must clamp at 100, not exceed');
    }

    public function test_mayor_term_deduct_funds_fails_when_insufficient(): void
    {
        $mayor    = $this->makeCharacter();
        $election = \App\Models\Election::create([
            'city_id'    => $this->city->id,
            'status'     => 'completed',
            'started_at' => now(),
            'ended_at'   => now(),
        ]);

        $term = MayorTerm::create([
            'city_id'         => $this->city->id,
            'character_id'    => $mayor->id,
            'election_id'     => $election->id,
            'period'          => 1,
            'started_at'      => now(),
            'city_funds'      => 10_000,
            'assembly_score'  => 100,
            'budget_law'      => 25,
            'budget_corp_reg' => 25,
            'budget_services' => 25,
            'budget_bonds'    => 25,
            'ledger'          => [],
            'actions_log'     => [],
        ]);

        $this->assertFalse($term->deductFunds(50_000), 'deductFunds must return false when insufficient');
        $term->refresh();
        $this->assertSame(10_000, $term->city_funds, 'city_funds must be unchanged on failed deduction');
    }

    public function test_mayor_term_prosperity_scenario_assembly_stays_healthy(): void
    {
        
        $drain  = MayorTerm::STARTING_FUNDS; 
        $asm    = MayorTerm::STARTING_ASSEMBLY;
        $crime  = 20.0;

        $deltaPerPeriod = MayorTerm::DELTA_POSITIVE_PERIOD   
                        + MayorTerm::DELTA_BOND_MATURED       
                        + MayorTerm::DELTA_CLEAN_PERIOD;      

        for ($p = 1; $p <= 4; $p++) {
            $asm = max(0, min(100, $asm + $deltaPerPeriod));
        }

        $this->assertGreaterThan(0,   $asm, 'prosperity path must survive all 4 periods');
        $this->assertSame(100, $asm,  'prosperity path with bond + clean keeps assembly at max 100');
    }

    public function test_mayor_term_suppression_compound_assembly_loss(): void
    {
        
        $asm = MayorTerm::STARTING_ASSEMBLY;
        $asm += 2 * MayorTerm::DELTA_SUPPRESSION; 

        $this->assertSame(84, $asm, '2 × (−8) live = 84');

        
        $delta = MayorTerm::DELTA_POSITIVE_PERIOD
               + (2 * MayorTerm::DELTA_SUPPRESSION_PERIODIC);  

        $asm = max(0, min(100, $asm + $delta));
        $this->assertSame(71, $asm, 'P1 with 2 suppressions: 84 + (-13) = 71');
        $this->assertGreaterThan(0, $asm, 'term must survive P1 despite suppressions');
    }

    public function test_mayor_term_bond_default_assembly_boundary(): void
    {
        
        $this->assertSame(
            0,
            max(0, 10 + MayorTerm::DELTA_BOND_DEFAULT),
            'assembly=10 + bond_default(-10) must trigger removal (reaches 0)',
        );
        $this->assertSame(
            1,
            max(0, 11 + MayorTerm::DELTA_BOND_DEFAULT),
            'assembly=11 + bond_default survives (reaches 1)',
        );
    }
}
