<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\CareerRank;
use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\CrimeRecord;
use App\Models\DefenseOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class LegalSystemTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;
    private int  $lawCareerId;
    private int  $policeCareerId;
    private int  $unemployedCareerId;

    
    
    

    protected function setUp(): void
    {
        parent::setUp();

        
        $this->withoutMiddleware([
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);

        $this->city = City::create([
            'name'       => 'LegalTest-' . uniqid(),
            'slug'       => 'legaltest-' . uniqid(),
            'crime_rate' => 50,
        ]);

        $law = DB::table('careers')->whereRaw('LOWER(code) = ?', ['law'])->first();
        $this->assertNotNull($law, 'Law career seed is missing from the database.');
        $this->lawCareerId = $law->id;

        $police = DB::table('careers')->whereRaw('LOWER(code) = ?', ['police'])->first();
        $this->assertNotNull($police, 'Police career seed is missing from the database.');
        $this->policeCareerId = $police->id;

        $unemployed = DB::table('careers')->whereRaw('LOWER(code) = ?', ['unemployed'])->first();
        $this->assertNotNull($unemployed, 'Unemployed career seed is missing from the database.');
        $this->unemployedCareerId = $unemployed->id;
    }

    
    
    

    private function makeCharacter(
        int   $careerId,
        int   $rank         = 1,
        int   $careerXp     = 0,
        int   $intelligence = 50_000,
        int   $luck         = 50_000,
        ?City $city         = null,
        int   $cashOnHand   = 100_000,
        int   $cashInBank   = 0,
    ): Character {
        $city ??= $this->city;
        $user   = User::factory()->create();

        
        DB::table('sessions')->insert([
            'id'            => uniqid('sess_', true),
            'user_id'       => $user->id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'phpunit',
            'payload'       => '',
            'last_activity' => time(),
        ]);

        $char = Character::create([
            'user_id'             => $user->id,
            'display_name'        => 'TestChar-' . uniqid(),
            'gender'              => 'male',
            'city_id'             => $city->id,
            'home_city_id'        => $city->id,
            'career_id'           => $careerId,
            'career_rank'         => $rank,
            'career_xp'           => $careerXp,
            'health'              => 100,
            'max_health'          => 100,
            'cash_on_hand'        => $cashOnHand,
            'cash_in_bank'        => $cashInBank,
            'total_character_exp' => $careerXp,
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => $intelligence,
            'luck'         => $luck,
            'offense'      => 1_000,
            'defense'      => 1_000,
            'influence'    => 0,
        ]);

        CharacterTimers::create([
            'character_id'   => $char->id,
            'next_action_at' => 0,
        ]);

        return $char;
    }

    
    private function makeLawClerk(): Character    { return $this->makeCharacter($this->lawCareerId, 1); }
    private function makeAttorney(): Character    { return $this->makeCharacter($this->lawCareerId, 2); }
    private function makeJudge(): Character       { return $this->makeCharacter($this->lawCareerId, 3); }
    private function makeChiefJustice(): Character{ return $this->makeCharacter($this->lawCareerId, 4); }
    private function makeOfficer(): Character     { return $this->makeCharacter($this->policeCareerId, 2); }
    private function makeCommissioner(): Character{ return $this->makeCharacter($this->policeCareerId, 4); }
    private function makeDefendant(): Character   { return $this->makeCharacter($this->unemployedCareerId, 1); }

    
    private function resetTimer(Character $char): void
    {
        CharacterTimers::where('character_id', $char->id)
            ->update(['next_action_at' => 0]);
        $char->unsetRelation('timers');
    }

    private function seedPriorConvictions(Character $perp, int $count = 10): void
    {
        for ($i = 0; $i < $count; $i++) {
            CrimeRecord::create([
                'character_id'   => $perp->id,
                'city_id'        => $this->city->id,
                'type'           => CrimeRecord::TYPE_ASSAULT,
                'severity'       => CrimeRecord::SEV_FELONY,
                'status'         => CrimeRecord::STATUS_SENTENCED,
                'evidence_level' => 75,
                'sentence'       => ['fine' => 20_000, 'jail_seconds' => 0],
                'committed_at'   => now()->subDays(10 + $i),
                'charged_at'     => now()->subDays(9 + $i),
                'sentenced_at'   => now()->subDays(8 + $i),
                'data'           => [
                    'perpetrator_name' => strtolower($perp->display_name),
                    'suspect_names'    => [strtolower($perp->display_name)],
                    'victim_name'      => 'Prior Victim',
                    'participants'     => [],
                ],
            ]);
        }
    }

    
    
    

    private function makeReferredCase(
        Character $perp,
        string    $severity     = CrimeRecord::SEV_FELONY,
        array     $participants = [],
        string    $type         = CrimeRecord::TYPE_ASSAULT,
    ): CrimeRecord {
        $suspectNames = [strtolower($perp->display_name)];
        if (!empty($participants)) {
            $participantNames = Character::withTrashed()
                ->whereIn('id', $participants)
                ->pluck('display_name')
                ->map(fn($name) => strtolower($name))
                ->all();
            $suspectNames = array_values(array_unique([...$suspectNames, ...$participantNames]));
        }

        return CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => $type,
            'severity'       => $severity,
            'status'         => CrimeRecord::STATUS_REFERRED,
            'evidence_level' => 75,
            'committed_at'   => now()->subHours(6),
            'referred_at'    => now()->subHours(5),
            'data'           => [
                'perpetrator_name' => strtolower($perp->display_name),
                'suspect_names'    => $suspectNames,
                'victim_name'      => 'Test Victim',
                'participants'     => $participants,
            ],
        ]);
    }

    
    private function makeChargedCase(
        Character $perp,
        Character $prosecutor,
        string    $severity     = CrimeRecord::SEV_FELONY,
        array     $participants = [],
        ?\Carbon\Carbon $chargedAt = null,
        bool      $autoCharged  = false,
        int       $priorConvictions = 10,
    ): CrimeRecord {
        $ts   = $chargedAt ?? now()->subHours(2);
        $data = [
            'perpetrator_name' => strtolower($perp->display_name),
            'suspect_names'    => [strtolower($perp->display_name)],
            'victim_name'      => 'Test Victim',
            'participants'     => $participants,
        ];
        if ($autoCharged) {
            $data['auto_charged_reason'] = 'No prosecutor action within 1 hour.';
        }

        $record = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => $severity,
            'status'         => CrimeRecord::STATUS_CHARGED,
            'evidence_level' => 75,
            'prosecutor_id'  => $autoCharged ? null : $prosecutor->id,
            'committed_at'   => now()->subHours(8),
            'referred_at'    => now()->subHours(7),
            'charged_at'     => $ts,
            'data'           => $data,
        ]);

        $this->seedPriorConvictions($perp, $priorConvictions);

        return $record;
    }

    private function makeConvictedCase(
        Character $perp,
        Character $prosecutor,
        Character $judge,
        string    $severity     = CrimeRecord::SEV_FELONY,
        array     $participants = [],
        int       $priorConvictions = 10,
    ): CrimeRecord {
        $record = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => $severity,
            'status'         => CrimeRecord::STATUS_CONVICTED,
            'evidence_level' => 75,
            'prosecutor_id'  => $prosecutor->id,
            'judge_id'       => $judge->id,
            'committed_at'   => now()->subHours(10),
            'charged_at'     => now()->subHours(8),
            'data'           => [
                'perpetrator_name' => strtolower($perp->display_name),
                'suspect_names'    => [strtolower($perp->display_name)],
                'victim_name'      => 'Test Victim',
                'participants'     => $participants,
            ],
        ]);

        $this->seedPriorConvictions($perp, $priorConvictions);

        return $record;
    }

    private function makeSentencedCase(
        Character $perp,
        Character $prosecutor,
        Character $judge,
        string    $severity     = CrimeRecord::SEV_FELONY,
        array     $participants = [],
        array     $sentence     = ['fine' => 20_000, 'jail_seconds' => 3_600],
    ): CrimeRecord {
        return CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => $severity,
            'status'         => CrimeRecord::STATUS_SENTENCED,
            'evidence_level' => 75,
            'prosecutor_id'  => $prosecutor->id,
            'judge_id'       => $judge->id,
            'sentence'       => $sentence,
            'committed_at'   => now()->subHours(12),
            'charged_at'     => now()->subHours(10),
            'sentenced_at'   => now()->subHours(2),
            'data'           => [
                'perpetrator_name' => strtolower($perp->display_name),
                'suspect_names'    => [strtolower($perp->display_name)],
                'victim_name'      => 'Test Victim',
                'participants'     => $participants,
            ],
        ]);
    }

    private function makeAppealedCase(
        Character $perp,
        Character $prosecutor,
        Character $judge,
        string    $severity = CrimeRecord::SEV_FELONY,
    ): CrimeRecord {
        return CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => $severity,
            'status'         => CrimeRecord::STATUS_APPEALED,
            'evidence_level' => 75,
            'prosecutor_id'  => $prosecutor->id,
            'judge_id'       => $judge->id,
            'sentence'       => ['fine' => 20_000, 'jail_seconds' => 3_600],
            'committed_at'   => now()->subHours(14),
            'charged_at'     => now()->subHours(12),
            'sentenced_at'   => now()->subHours(4),
            'appealed_at'    => now()->subHours(1),
            'data'           => [
                'perpetrator_name' => strtolower($perp->display_name),
                'suspect_names'    => [strtolower($perp->display_name)],
                'victim_name'      => 'Test Victim',
            ],
        ]);
    }

    
    private function rank4Xp(int $careerId): int
    {
        $row = DB::table('career_ranks')
            ->where('career_id', $careerId)
            ->where('rank_level', 4)
            ->first();
        $this->assertNotNull($row, "rank_level 4 row missing for career {$careerId}");
        return (int) $row->xp_required;
    }

    
    
    

    public function test_police_officer_can_take_open_case(): void
    {
        $officer = $this->makeOfficer();
        $perp    = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_OPEN,
            'evidence_level' => 30,
            'committed_at'   => now()->subHours(2),
            'data'           => ['perpetrator_name' => strtolower($perp->display_name)],
        ]);

        $this->actingAs($officer->user)
            ->post(route('career.police.take', $case->id))
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $case->status);
        $this->assertSame($officer->id, $case->detective_id);
    }

    public function test_officer_cannot_take_second_case_while_investigating(): void
    {
        $officer = $this->makeOfficer();
        $perp    = $this->makeDefendant();

        
        CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_INVESTIGATING,
            'evidence_level' => 30,
            'detective_id'   => $officer->id,
            'committed_at'   => now()->subHours(2),
            'data'           => ['perpetrator_name' => strtolower($perp->display_name)],
        ]);

        $case2 = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_RUG_PULL,
            'severity'       => CrimeRecord::SEV_MISDEMEANOR,
            'status'         => CrimeRecord::STATUS_OPEN,
            'evidence_level' => 10,
            'committed_at'   => now()->subHours(1),
            'data'           => ['perpetrator_name' => strtolower($perp->display_name)],
        ]);

        $this->actingAs($officer->user)
            ->post(route('career.police.take', $case2->id))
            ->assertSessionHas('error', 'You are already investigating a case. Finish or abandon it first.');
    }

    public function test_officer_abandoning_non_capital_case_deletes_it(): void
    {
        $officer = $this->makeOfficer();
        $perp    = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_INVESTIGATING,
            'evidence_level' => 30,
            'detective_id'   => $officer->id,
            'committed_at'   => now()->subHours(2),
            'data'           => ['perpetrator_name' => strtolower($perp->display_name)],
        ]);

        $this->actingAs($officer->user)
            ->post(route('career.police.abandon'))
            ->assertSessionHas('success', 'You have abandoned this case and managed to shelve it somewhere no one can find it.');

        $this->assertDatabaseMissing('crime_records', [
            'id' => $case->id,
        ]);
    }

    public function test_officer_abandoning_capital_case_returns_it_to_queue(): void
    {
        $officer = $this->makeCharacter($this->policeCareerId, 3);
        $perp    = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_MURDER,
            'severity'       => CrimeRecord::SEV_CAPITAL,
            'status'         => CrimeRecord::STATUS_INVESTIGATING,
            'evidence_level' => 50,
            'detective_id'   => $officer->id,
            'committed_at'   => now()->subHours(2),
            'data'           => ['perpetrator_name' => strtolower($perp->display_name)],
        ]);

        $this->actingAs($officer->user)
            ->post(route('career.police.abandon'))
            ->assertSessionHas('success', "You managed to get yourself off the case, but you couldn't shelve it since it was too hot!");

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_OPEN, $case->status);
        $this->assertNull($case->detective_id);
    }

    public function test_officer_rank_gate_on_capital_case(): void
    {
        
        $sergeant = $this->makeCharacter($this->policeCareerId, 1);
        $perp     = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_MURDER,
            'severity'       => CrimeRecord::SEV_CAPITAL,
            'status'         => CrimeRecord::STATUS_OPEN,
            'evidence_level' => 40,
            'committed_at'   => now()->subHours(3),
            'data'           => ['perpetrator_name' => strtolower($perp->display_name)],
        ]);

        $this->actingAs($sergeant->user)
            ->post(route('career.police.take', $case->id))
            ->assertSessionHas('error', 'Your rank clearance does not cover this case severity.');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_OPEN, $case->status);
    }

    public function test_investigate_adds_evidence_and_sets_action_timer(): void
    {
        $officer = $this->makeOfficer();
        $perp    = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_INVESTIGATING,
            'evidence_level' => 20,
            'detective_id'   => $officer->id,
            'committed_at'   => now()->subHours(4),
            'data'           => [
                'perpetrator_name' => strtolower($perp->display_name),
                'suspect_names'    => [],
            ],
        ]);

        $this->actingAs($officer->user)
            ->post(route('career.police.investigate', $case->id), ['clue_type' => 'witness'])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertGreaterThan(20, $case->evidence_level);

        $timer = CharacterTimers::where('character_id', $officer->id)->first();
        $this->assertGreaterThan(0, $timer->next_action_at);
    }

    public function test_investigation_missing_suspect_cannot_gain_contradictory_evidence(): void
    {
        $officer = $this->makeOfficer();

        $case = CrimeRecord::create([
            'character_id'   => null,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_INVESTIGATING,
            'evidence_level' => 20,
            'detective_id'   => $officer->id,
            'committed_at'   => now()->subHours(4),
            'data'           => [
                'perpetrator_name' => 'hard deleted suspect',
                'suspect_names'    => [],
            ],
        ]);

        $this->actingAs($officer->user)
            ->post(route('career.police.investigate', $case->id), ['clue_type' => 'witness'])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(20, $case->evidence_level);
        $this->assertStringContainsString('no actionable lead', $case->data['clues']['witness']);
    }

    public function test_officer_can_refer_case_after_investigation(): void
    {
        $officer = $this->makeOfficer();
        $perp    = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_INVESTIGATING,
            'evidence_level' => 60,
            'detective_id'   => $officer->id,
            'committed_at'   => now()->subHours(4),
            'data'           => ['perpetrator_name' => strtolower($perp->display_name)],
        ]);

        $this->actingAs($officer->user)
            ->post(route('career.police.refer', $case->id), [
                'suspect_names' => [$perp->display_name],
            ])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $case->status);
        $this->assertContains(strtolower($perp->display_name), array_map('strtolower', $case->data['suspect_names']));
    }

    public function test_officer_cannot_refer_nonexistent_player_name(): void
    {
        $officer = $this->makeOfficer();
        $perp    = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $perp->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_INVESTIGATING,
            'evidence_level' => 50,
            'detective_id'   => $officer->id,
            'committed_at'   => now()->subHours(4),
            'data'           => ['perpetrator_name' => 'realname'],
        ]);

        $this->actingAs($officer->user)
            ->post(route('career.police.refer', $case->id), [
                'suspect_names' => ['ThisPersonDoesNotExist_' . uniqid()],
            ])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $case->status);
    }

    
    
    

    public function test_commissioner_can_dismiss_junior_officer(): void
    {
        $commissioner = $this->makeCommissioner();
        $junior       = $this->makeOfficer();

        $this->actingAs($commissioner->user)
            ->post(route('career.police.dismiss'), ['character_id' => $junior->id])
            ->assertSessionHas('success');

        $junior->refresh();
        $this->assertSame($this->unemployedCareerId, $junior->career_id);
        $this->assertTrue(
            CharacterJournal::where('character_id', $junior->id)
                ->where('type', 'dismissed_by')->exists()
        );
    }

    public function test_commissioner_cannot_dismiss_another_commissioner(): void
    {
        $commissioner1 = $this->makeCommissioner();
        $commissioner2 = $this->makeCommissioner();

        $this->actingAs($commissioner1->user)
            ->post(route('career.police.dismiss'), ['character_id' => $commissioner2->id])
            ->assertSessionHas('error', 'You cannot dismiss another Commissioner.');

        $commissioner2->refresh();
        $this->assertSame($this->policeCareerId, $commissioner2->career_id);
    }

    public function test_commissioner_cannot_dismiss_themselves(): void
    {
        $commissioner = $this->makeCommissioner();

        $this->actingAs($commissioner->user)
            ->post(route('career.police.dismiss'), ['character_id' => $commissioner->id])
            ->assertSessionHas('error', 'You cannot dismiss yourself, Commissioner.');
    }

    public function test_rank_2_officer_cannot_dismiss_anyone(): void
    {
        $officer = $this->makeOfficer();
        $target  = $this->makeCharacter($this->policeCareerId, 1);

        $this->actingAs($officer->user)
            ->post(route('career.police.dismiss'), ['character_id' => $target->id])
            ->assertSessionHas('error', 'Only the Commissioner can dismiss officers.');

        $target->refresh();
        $this->assertSame($this->policeCareerId, $target->career_id);
    }

    public function test_commissioner_cannot_dismiss_officer_from_different_city(): void
    {
        $commissioner = $this->makeCommissioner();
        $otherCity    = City::create(['name' => 'Other-' . uniqid(), 'slug' => 'other-' . uniqid(), 'crime_rate' => 10]);
        $foreign      = $this->makeCharacter($this->policeCareerId, 1, 0, 50_000, 50_000, $otherCity);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.dismiss'), ['character_id' => $foreign->id])
            ->assertSessionHas('error');

        $foreign->refresh();
        $this->assertSame($this->policeCareerId, $foreign->career_id);
    }

    
    
    

    public function test_police_commissioner_can_step_down_with_ready_successor(): void
    {
        $xp           = $this->rank4Xp($this->policeCareerId);
        $commissioner = $this->makeCommissioner();
        
        $this->makeCharacter($this->policeCareerId, 3, $xp);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('success');

        $commissioner->refresh();
        $this->assertSame($this->unemployedCareerId, $commissioner->career_id);
        
        $this->assertTrue(
            CharacterJournal::where('character_id', $commissioner->id)
                ->where('type', 'career_step_down')->exists()
        );
    }

    public function test_police_commissioner_blocked_without_successor(): void
    {
        $commissioner = $this->makeCommissioner();

        $this->actingAs($commissioner->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('error');

        $commissioner->refresh();
        $this->assertSame($this->policeCareerId, $commissioner->career_id);
    }

    public function test_police_rank_2_can_step_down_freely(): void
    {
        $officer = $this->makeOfficer(); 

        $this->actingAs($officer->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('success');

        $officer->refresh();
        $this->assertSame($this->unemployedCareerId, $officer->career_id);
    }

    
    
    

    public function test_lead_perpetrator_can_turn_themselves_in(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $perp       = $this->makeDefendant();
        $city       = $this->city;

        $case = $this->makeSentencedCase(
            $perp, $prosecutor, $judge,
            CrimeRecord::SEV_FELONY,
            [],
            ['fine' => 5_000, 'jail_seconds' => 0],
        );
        
        
        DB::table('crime_records')->where('id', $case->id)
            ->update(['sentenced_at' => now()->subHours(2)]);

        $cashBefore = $perp->cash_on_hand;

        $this->actingAs($perp->user)
            ->post(route('city.police.turn-in', ['city' => $city->slug]), ['case_id' => $case->id])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CLOSED, $case->status);

        $perp->refresh();
        $this->assertSame($cashBefore - 5_000, $perp->cash_on_hand);
    }

    public function test_co_conspirator_can_trigger_group_turn_in(): void
    {
        $prosecutor  = $this->makeAttorney();
        $judge       = $this->makeJudge();
        $lead        = $this->makeDefendant();
        $accomplice  = $this->makeDefendant();
        $city        = $this->city;

        $case = $this->makeSentencedCase(
            $lead, $prosecutor, $judge,
            CrimeRecord::SEV_FELONY,
            [$accomplice->id],
            ['fine' => 4_000, 'jail_seconds' => 0],
        );

        
        $this->actingAs($accomplice->user)
            ->post(route('city.police.turn-in', ['city' => $city->slug]), ['case_id' => $case->id])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CLOSED, $case->status);

        $lead->refresh();
        
        $this->assertSame(100_000 - 4_000, $lead->cash_on_hand);
    }

    public function test_turn_in_drains_cash_on_hand_then_bank(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $city       = $this->city;

        
        $perp = $this->makeCharacter(
            $this->unemployedCareerId, 1, 0, 50_000, 50_000,
            $this->city, 2_000, 10_000
        );

        $case = $this->makeSentencedCase(
            $perp, $prosecutor, $judge,
            CrimeRecord::SEV_FELONY, [],
            ['fine' => 6_000, 'jail_seconds' => 0],
        );

        $this->actingAs($perp->user)
            ->post(route('city.police.turn-in', ['city' => $city->slug]), ['case_id' => $case->id])
            ->assertSessionHas('success');

        $perp->refresh();
        $this->assertSame(0, $perp->cash_on_hand);
        $this->assertSame(6_000, $perp->cash_in_bank); 
    }

    public function test_cannot_turn_in_while_appeal_pending(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $perp       = $this->makeDefendant();
        $city       = $this->city;

        $case = $this->makeAppealedCase($perp, $prosecutor, $judge);

        $this->actingAs($perp->user)
            ->post(route('city.police.turn-in', ['city' => $city->slug]), ['case_id' => $case->id])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_APPEALED, $case->status);
    }

    public function test_cannot_turn_in_while_case_is_convicted_not_sentenced(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $perp       = $this->makeDefendant();
        $city       = $this->city;

        $case = $this->makeConvictedCase($perp, $prosecutor, $judge);

        $this->actingAs($perp->user)
            ->post(route('city.police.turn-in', ['city' => $city->slug]), ['case_id' => $case->id])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CONVICTED, $case->status);
    }

    public function test_turn_in_cross_city_blocked(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $perp       = $this->makeDefendant();

        $otherCity = City::create(['name' => 'Far-' . uniqid(), 'slug' => 'far-' . uniqid(), 'crime_rate' => 5]);
        $case = $this->makeSentencedCase($perp, $prosecutor, $judge);

        
        $this->actingAs($perp->user)
            ->post(route('city.police.turn-in', ['city' => $otherCity->slug]), ['case_id' => $case->id])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    public function test_unrelated_character_cannot_turn_in_someone_elses_case(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $perp       = $this->makeDefendant();
        $stranger   = $this->makeDefendant();
        $city       = $this->city;

        $case = $this->makeSentencedCase($perp, $prosecutor, $judge);

        $this->actingAs($stranger->user)
            ->post(route('city.police.turn-in', ['city' => $city->slug]), ['case_id' => $case->id])
            ->assertSessionHas('error', 'This is not your case.');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    
    
    

    public function test_arrest_target_list_empty_when_no_sentenced_cases(): void
    {
        $officer = $this->makeOfficer();

        
        $shape = (new \App\Actions\Arrest())->getShape($officer);
        $this->assertEmpty($shape['targets']);
    }

    public function test_arrest_blocked_within_embargo_window(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $officer    = $this->makeOfficer();
        $perp       = $this->makeDefendant();

        
        $case = $this->makeSentencedCase($perp, $prosecutor, $judge);
        DB::table('crime_records')->where('id', $case->id)
            ->update(['sentenced_at' => now()->subMinutes(30)]);

        $shape = (new \App\Actions\Arrest())->getShape($officer);
        
        $ids = collect($shape['targets'])->pluck('id');
        $this->assertFalse($ids->contains("{$case->id}:{$perp->id}"));
    }

    public function test_non_officer_cannot_use_arrest_route(): void
    {
        $stranger = $this->makeDefendant();

        $this->actingAs($stranger->user)
            ->post(route('career.police.actions.arrest'), ['target_id' => '1:1'])
            
            ->assertRedirect(route('dashboard'));
    }

    public function test_arrest_closes_record_when_all_parties_arrested(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $officer    = $this->makeCommissioner();
        $perp       = $this->makeDefendant();

        
        $case = $this->makeSentencedCase(
            $perp, $prosecutor, $judge,
            CrimeRecord::SEV_FELONY, [],
            ['fine' => 0, 'jail_seconds' => 3_600],
        );
        DB::table('crime_records')->where('id', $case->id)
            ->update(['sentenced_at' => now()->subHours(2)]);
        $case->refresh();

        
        
        $case->applySentenceToCharacter($perp);
        $fresh = CrimeRecord::find($case->id);
        $arrested = [(int) $perp->id];
        $fresh->update([
            'status'      => CrimeRecord::STATUS_CLOSED,
            'resolved_at' => now()->utc(),
            'data'        => array_merge($fresh->data ?? [], ['arrested_parties' => $arrested]),
        ]);

        $fresh->refresh();
        $this->assertSame(CrimeRecord::STATUS_CLOSED, $fresh->status);
    }

    public function test_arrest_leaves_record_open_when_only_some_parties_arrested(): void
    {
        $prosecutor  = $this->makeAttorney();
        $judge       = $this->makeJudge();
        $lead        = $this->makeDefendant();
        $accomplice  = $this->makeDefendant();

        $case = $this->makeSentencedCase(
            $lead, $prosecutor, $judge,
            CrimeRecord::SEV_FELONY,
            [$accomplice->id],
            ['fine' => 0, 'jail_seconds' => 1_800],
        );

        
        $data = $case->data ?? [];
        $data['arrested_parties'] = [$lead->id];
        $case->update(['data' => $data]);

        $case->refresh();
        
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    
    
    

    public function test_attorney_can_prosecute_referred_felony_case(): void
    {
        $attorney  = $this->makeAttorney();
        $defendant = $this->makeDefendant();
        $case      = $this->makeReferredCase($defendant);

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CHARGED, $case->status);
        $this->assertSame($attorney->id, $case->prosecutor_id);
        $this->assertNotNull($case->charged_at, 'charged_at must be set on prosecution');
    }

    public function test_charges_filed_notifies_all_participants(): void
    {
        $attorney    = $this->makeAttorney();
        $lead        = $this->makeDefendant();
        $accomplice1 = $this->makeDefendant();
        $accomplice2 = $this->makeDefendant();
        $accomplice3 = $this->makeDefendant();

        $case = $this->makeReferredCase(
            $lead, CrimeRecord::SEV_FELONY,
            [$accomplice1->id, $accomplice2->id, $accomplice3->id],
        );

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('success');

        foreach ([$lead->id, $accomplice1->id, $accomplice2->id, $accomplice3->id] as $pid) {
            $this->assertTrue(
                CharacterJournal::where('character_id', $pid)
                    ->where('type', 'criminal_charges_filed')->exists(),
                "Character {$pid} should have received a criminal_charges_filed journal"
            );
        }
    }

    public function test_co_conspirator_notification_does_not_hint_at_independent_defence(): void
    {
        $attorney   = $this->makeAttorney();
        $lead       = $this->makeDefendant();
        $accomplice = $this->makeDefendant();

        $case = $this->makeReferredCase($lead, CrimeRecord::SEV_FELONY, [$accomplice->id]);

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id));

        $journal = CharacterJournal::where('character_id', $accomplice->id)
            ->where('type', 'criminal_charges_filed')
            ->first();

        $this->assertNotNull($journal);
        $message = $journal->data['message'] ?? '';

        
        $this->assertStringNotContainsStringIgnoringCase(
            'A defence attorney may offer their services',
            $message,
            'Co-conspirator journal should not suggest they can independently retain counsel'
        );
        
        $this->assertStringContainsStringIgnoringCase(
            'lead defendant',
            $message,
            'Co-conspirator journal should reference that the lead defendant handles defence'
        );
    }

    public function test_rank_1_clerk_cannot_prosecute(): void
    {
        $clerk     = $this->makeLawClerk();
        $defendant = $this->makeDefendant();
        $case      = $this->makeReferredCase($defendant);

        $this->actingAs($clerk->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('error', 'Only an Attorney may file charges.');
    }

    public function test_attorney_cannot_prosecute_capital_case(): void
    {
        $attorney  = $this->makeAttorney();
        $defendant = $this->makeDefendant();
        $case      = $this->makeReferredCase($defendant, CrimeRecord::SEV_CAPITAL);

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $case->status);
    }

    public function test_attorney_conflicted_as_perpetrator_cannot_prosecute(): void
    {
        $attorney = $this->makeAttorney();
        $case     = $this->makeReferredCase($attorney);

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $case->status);
    }

    public function test_attorney_conflicted_as_participant_cannot_prosecute(): void
    {
        $attorney  = $this->makeAttorney();
        $lead      = $this->makeDefendant();
        
        $case = $this->makeReferredCase($lead, CrimeRecord::SEV_FELONY, [$attorney->id]);

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $case->status);
    }

    public function test_attorney_cannot_prosecute_case_in_different_city(): void
    {
        $attorney  = $this->makeAttorney();
        $defendant = $this->makeDefendant();
        $otherCity = City::create(['name' => 'Far-' . uniqid(), 'slug' => 'far-' . uniqid(), 'crime_rate' => 5]);

        $case = CrimeRecord::create([
            'character_id'   => $defendant->id,
            'city_id'        => $otherCity->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_REFERRED,
            'evidence_level' => 60,
            'committed_at'   => now()->subHours(5),
            'referred_at'    => now()->subHours(4),
            'data'           => ['perpetrator_name' => 'nobody', 'suspect_names' => ['nobody']],
        ]);

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('error', 'You can only prosecute cases in your home city.');
    }

    
    
    

    public function test_attorney_can_offer_defence_and_defence_request_journal_sent_to_lead_only(): void
    {
        $prosecutor  = $this->makeAttorney();
        $defender    = $this->makeAttorney();
        $lead        = $this->makeDefendant();
        $accomplice  = $this->makeDefendant();
        $case = $this->makeChargedCase($lead, $prosecutor, CrimeRecord::SEV_FELONY, [$accomplice->id]);

        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertNull($case->defense_id);
        $this->assertNull($case->data['defense_pending'] ?? null);

        $offer = DefenseOffer::where('crime_record_id', $case->id)->first();
        $this->assertNotNull($offer);
        $this->assertSame($defender->id, $offer->attorney_id);
        $this->assertSame($lead->id, $offer->defendant_id);
        $this->assertSame(DefenseOffer::STATUS_PENDING, $offer->status);

        
        $this->assertTrue(
            CharacterJournal::where('character_id', $lead->id)->where('type', 'defense_request')->exists(),
            'Lead defendant must receive defense_request journal'
        );
        $this->assertFalse(
            CharacterJournal::where('character_id', $accomplice->id)->where('type', 'defense_request')->exists(),
            'Co-conspirator must NOT receive defense_request journal'
        );
    }

    public function test_attorney_cannot_defend_capital_case(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender   = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor, CrimeRecord::SEV_CAPITAL);

        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertNull($case->defense_id);
    }

    public function test_prosecutor_cannot_also_defend_the_same_case(): void
    {
        $attorney  = $this->makeAttorney();
        $defendant = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $attorney);

        $this->actingAs($attorney->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500])
            ->assertSessionHas('error', 'You are the prosecutor on this case.');
    }

    public function test_auto_charged_case_cannot_accept_defence(): void
    {
        $defender  = $this->makeAttorney();
        $defendant = $this->makeDefendant();
        $prosecutor = $this->makeAttorney();
        $case = $this->makeChargedCase($defendant, $prosecutor, CrimeRecord::SEV_FELONY, [], null, true);

        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertNull($case->defense_id);
    }

    public function test_second_defence_offer_blocked_while_first_is_pending(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender1  = $this->makeAttorney();
        $defender2  = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        $this->actingAs($defender1->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500]);

        $this->actingAs($defender2->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 3_000])
            ->assertSessionHas('error', 'A defence offer is already pending on this case.');
    }

    public function test_second_attorney_can_override_stale_defence_offer_after_three_minutes(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender1  = $this->makeAttorney();
        $defender2  = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        $this->actingAs($defender1->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500]);

        $firstOffer = DefenseOffer::where('crime_record_id', $case->id)->firstOrFail();
        $firstOffer->update([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($defender2->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 3_000])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertNull($case->defense_id);
        $this->assertSame(DefenseOffer::STATUS_CANCELLED, $firstOffer->fresh()->status);
        $this->assertTrue(DefenseOffer::where('crime_record_id', $case->id)
            ->where('attorney_id', $defender2->id)
            ->where('status', DefenseOffer::STATUS_PENDING)
            ->exists());
    }

    public function test_defendant_can_decline_defence_offer(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender   = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500]);

        $offer = DefenseOffer::where('crime_record_id', $case->id)->firstOrFail();

        $journal = CharacterJournal::where('character_id', $defendant->id)
            ->where('type', 'defense_request')->first();
        $this->assertNotNull($journal, 'Defendant must receive a defense_request journal');

        $this->actingAs($defendant->user)
            ->post(route('journal.decline', $journal->id))
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertNull($case->defense_id);
        $this->assertNull($case->data['defense_pending'] ?? null);
        $this->assertSame(DefenseOffer::STATUS_DECLINED, $offer->fresh()->status);
        
        $this->assertTrue(
            CharacterJournal::where('character_id', $defender->id)
                ->where('type', 'defense_declined')->exists()
        );
    }

    public function test_defendant_with_insufficient_funds_cannot_accept_defence(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender   = $this->makeAttorney();
        
        $defendant  = $this->makeCharacter(
            $this->unemployedCareerId, 1, 0, 50_000, 50_000, $this->city, 0, 0
        );
        $case = $this->makeChargedCase($defendant, $prosecutor);

        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500]);

        $offer = DefenseOffer::where('crime_record_id', $case->id)->firstOrFail();

        $journal = CharacterJournal::where('character_id', $defendant->id)
            ->where('type', 'defense_request')->first();
        $this->assertNotNull($journal);

        $this->actingAs($defendant->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('error');

        
        $case->refresh();
        $this->assertNull($case->defense_id);
        $this->assertSame(DefenseOffer::STATUS_PENDING, $offer->fresh()->status);
    }

    
    
    

    public function test_judge_can_convict_and_sentence_uncontested_case_after_embargo(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict'      => 'convict',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
        $this->assertSame($judge->id, $case->judge_id);
        $this->assertSame(20_000, $case->sentence['fine']);
    }

    public function test_judge_verdict_blocked_before_one_hour_embargo(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor, chargedAt: now()->subMinutes(20));

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict'      => 'convict',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CHARGED, $case->status);
    }

    public function test_auto_charged_case_bypasses_one_hour_embargo(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();

        
        $case = $this->makeChargedCase(
            $defendant, $prosecutor, CrimeRecord::SEV_FELONY, [], now()->subMinutes(10), true
        );

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict'      => 'convict',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    public function test_judge_can_acquit_uncontested_case(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict'      => 'acquit',
                'fine'         => 0,
                'jail_seconds' => 0,
            ])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_ACQUITTED, $case->status);
    }

    public function test_prosecutor_cannot_judge_the_same_case(): void
    {
        $persecutor = $this->makeCharacter($this->lawCareerId, 3);
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $persecutor);

        $this->actingAs($persecutor->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict'      => 'convict',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('error', 'You prosecuted this case and cannot also judge it.');
    }

    public function test_rank_2_attorney_cannot_judge_verdict(): void
    {
        $prosecutor = $this->makeAttorney();
        $attorney2  = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        $this->actingAs($attorney2->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict'      => 'convict',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('error', 'Only a District Judge may act as judge on a case.');
    }

    public function test_judge_conflicted_as_perpetrator_cannot_verdict(): void
    {
        $judgePerp  = $this->makeCharacter($this->lawCareerId, 3);
        $prosecutor = $this->makeAttorney();
        $case = $this->makeChargedCase($judgePerp, $prosecutor);

        $this->actingAs($judgePerp->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict'      => 'acquit', 'fine' => 0, 'jail_seconds' => 0,
            ])
            ->assertSessionHas('error', 'You have a conflict of interest in this case.');
    }

    
    
    

    public function test_judge_can_sentence_convicted_case(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict'      => 'sentence',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
        $this->assertSame(20_000, $case->sentence['fine']);
        $this->assertSame(3_600, $case->sentence['jail_seconds']);
    }

    public function test_judge_cannot_assign_jail_time_without_repeat_conviction_record(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_FELONY, [], 0);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict'      => 'sentence',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CONVICTED, $case->status);
    }

    public function test_misdemeanor_fine_below_floor_rejected(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_MISDEMEANOR);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 500, 'jail_seconds' => 0,
            ])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CONVICTED, $case->status);
    }

    public function test_misdemeanor_fine_above_ceiling_rejected(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_MISDEMEANOR);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 50_000, 'jail_seconds' => 0,
            ])
            ->assertSessionHas('error');
    }

    public function test_felony_fine_above_ceiling_rejected(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_FELONY);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 200_000, 'jail_seconds' => 0,
            ])
            ->assertSessionHas('error');
    }

    public function test_capital_mandatory_jail_minimum_enforced(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_CAPITAL);

        
        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 50_000, 'jail_seconds' => 0,
            ])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CONVICTED, $case->status);
    }

    public function test_capital_jail_sentence_bypasses_repeat_conviction_gate(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_CAPITAL, [], 0);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 50_000, 'jail_seconds' => 1_000,
            ])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    public function test_capital_sentence_valid_within_bounds(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_CAPITAL);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 50_000, 'jail_seconds' => 21_600,
            ])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    public function test_capital_jail_above_maximum_rejected(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_CAPITAL);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 50_000, 'jail_seconds' => 99_999,
            ])
            ->assertSessionHas('error');
    }

    public function test_different_judge_cannot_sentence_case_convicted_by_another(): void
    {
        $prosecutor    = $this->makeAttorney();
        $convictJudge  = $this->makeJudge();
        $sentenceJudge = $this->makeJudge();
        $defendant     = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $convictJudge);

        $this->actingAs($sentenceJudge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 20_000, 'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CONVICTED, $case->status);
    }

    public function test_judge_can_acquit_convicted_case_mercy_override(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'acquit', 'fine' => 0, 'jail_seconds' => 0,
            ])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_ACQUITTED, $case->status);
    }

    public function test_all_participants_receive_sentencing_journal(): void
    {
        $prosecutor  = $this->makeAttorney();
        $judge       = $this->makeJudge();
        $lead        = $this->makeDefendant();
        $accomplice1 = $this->makeDefendant();
        $accomplice2 = $this->makeDefendant();
        $accomplice3 = $this->makeDefendant();
        $accomplice4 = $this->makeDefendant();

        
        $case = $this->makeConvictedCase(
            $lead, $prosecutor, $judge,
            CrimeRecord::SEV_FELONY,
            [$accomplice1->id, $accomplice2->id, $accomplice3->id, $accomplice4->id],
        );

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 20_000, 'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('success');

        foreach ([$lead->id, $accomplice1->id, $accomplice2->id, $accomplice3->id, $accomplice4->id] as $pid) {
            $this->assertTrue(
                CharacterJournal::where('character_id', $pid)->where('type', 'case_sentenced')->exists(),
                "Character {$pid} missing case_sentenced journal"
            );
        }
    }

    public function test_lead_defendant_sentencing_journal_mentions_appeal_right(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $lead       = $this->makeDefendant();
        $case = $this->makeConvictedCase($lead, $prosecutor, $judge, CrimeRecord::SEV_FELONY);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 20_000, 'jail_seconds' => 0,
            ]);

        $journal = CharacterJournal::where('character_id', $lead->id)
            ->where('type', 'case_sentenced')->first();
        $this->assertNotNull($journal);
        $this->assertTrue((bool) ($journal->data['can_appeal'] ?? false));
    }

    
    
    

    public function test_stale_referred_case_auto_charges_when_judge_loads_law_page(): void
    {
        $lead       = $this->makeDefendant();
        $accomplice = $this->makeDefendant();
        $judge      = $this->makeJudge();

        
        CrimeRecord::create([
            'character_id'   => $lead->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_REFERRED,
            'evidence_level' => 60,
            'committed_at'   => now()->subHours(10),
            'referred_at'    => now()->subHours(2),
            'data'           => [
                'perpetrator_name' => strtolower($lead->display_name),
                'suspect_names'    => [strtolower($lead->display_name), strtolower($accomplice->display_name)],
                'participants'     => [$accomplice->id],
            ],
        ]);

        $this->actingAs($judge->user)->get(route('career.law'));

        $this->assertTrue(
            CharacterJournal::where('character_id', $lead->id)->where('type', 'case_auto_charged')->exists(),
            'Lead should be notified of auto-charge'
        );
        $this->assertTrue(
            CharacterJournal::where('character_id', $accomplice->id)->where('type', 'case_auto_charged')->exists(),
            'Accomplice should also be notified of auto-charge'
        );
    }

    
    
    

    public function test_defendant_can_file_appeal_on_felony(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeSentencedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_FELONY);

        $this->actingAs($defendant->user)
            ->post(route('law.appeal', $case->id))
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_APPEALED, $case->status);
        $this->assertNotNull($case->appealed_at);
    }

    public function test_defendant_can_file_appeal_on_capital(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeSentencedCase(
            $defendant, $prosecutor, $judge,
            CrimeRecord::SEV_CAPITAL, [],
            ['fine' => 50_000, 'jail_seconds' => 10_800],
        );

        $this->actingAs($defendant->user)
            ->post(route('law.appeal', $case->id))
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_APPEALED, $case->status);
    }

    public function test_misdemeanor_cannot_be_appealed(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeSentencedCase(
            $defendant, $prosecutor, $judge, CrimeRecord::SEV_MISDEMEANOR, [],
            ['fine' => 1_000, 'jail_seconds' => 0],
        );

        $this->actingAs($defendant->user)
            ->post(route('law.appeal', $case->id))
            ->assertSessionHas('error', 'Misdemeanor convictions are final and cannot be appealed.');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    public function test_only_lead_defendant_can_file_appeal(): void
    {
        $prosecutor  = $this->makeAttorney();
        $judge       = $this->makeJudge();
        $lead        = $this->makeDefendant();
        $accomplice  = $this->makeDefendant();
        $outsider    = $this->makeDefendant();
        $case = $this->makeSentencedCase($lead, $prosecutor, $judge, CrimeRecord::SEV_FELONY, [$accomplice->id]);

        
        $this->actingAs($accomplice->user)
            ->post(route('law.appeal', $case->id))
            ->assertSessionHas('error', 'You can only appeal your own case.');

        
        $this->actingAs($outsider->user)
            ->post(route('law.appeal', $case->id))
            ->assertSessionHas('error', 'You can only appeal your own case.');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    public function test_defendant_cannot_appeal_twice(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeSentencedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_FELONY);

        $this->actingAs($defendant->user)->post(route('law.appeal', $case->id));

        
        $data           = $case->fresh()->data ?? [];
        $data['appeal'] = ['upheld' => true, 'chief_justice_id' => 999];
        $case->update(['status' => CrimeRecord::STATUS_SENTENCED, 'data' => $data]);

        $this->actingAs($defendant->user)
            ->post(route('law.appeal', $case->id))
            ->assertSessionHas('error');
    }

    public function test_chief_justice_can_uphold_appeal_sentence_remains(): void
    {
        $prosecutor   = $this->makeAttorney();
        $judge        = $this->makeJudge();
        $cj           = $this->makeChiefJustice();
        $defendant    = $this->makeDefendant();
        $case = $this->makeAppealedCase($defendant, $prosecutor, $judge);

        $this->actingAs($cj->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 1])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    public function test_chief_justice_can_overturn_appeal_case_acquitted(): void
    {
        $prosecutor   = $this->makeAttorney();
        $judge        = $this->makeJudge();
        $cj           = $this->makeChiefJustice();
        $defendant    = $this->makeDefendant();
        $case = $this->makeAppealedCase($defendant, $prosecutor, $judge);

        $this->actingAs($cj->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 0])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_ACQUITTED, $case->status);
    }

    public function test_cj_who_was_original_judge_cannot_rule_on_appeal(): void
    {
        $prosecutor = $this->makeAttorney();
        $cj         = $this->makeChiefJustice();
        $defendant  = $this->makeDefendant();

        
        $case = $this->makeAppealedCase($defendant, $prosecutor, $cj);

        $this->actingAs($cj->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 1])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_APPEALED, $case->status);
    }

    public function test_cj_who_was_prosecutor_cannot_rule_on_appeal(): void
    {
        $cj        = $this->makeChiefJustice();
        $judge     = $this->makeJudge();
        $defendant = $this->makeDefendant();

        $case = $this->makeAppealedCase($defendant, $cj, $judge);
        
        $case->update(['prosecutor_id' => $cj->id]);

        $this->actingAs($cj->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 1])
            ->assertSessionHas('error', 'You prosecuted this case and cannot rule on its appeal.');
    }

    public function test_rank_3_judge_cannot_resolve_appeal(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $judge2     = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeAppealedCase($defendant, $prosecutor, $judge);

        $this->actingAs($judge2->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 1])
            ->assertSessionHas('error', 'Only a Chief Justice may rule on appeals.');
    }

    
    
    

    public function test_three_chief_justices_allowed_in_same_city(): void
    {
        $xp = $this->rank4Xp($this->lawCareerId);

        $cj1 = $this->makeJudge();
        $cj1->update(['career_rank' => 4, 'career_xp' => $xp]);

        $cj2 = $this->makeJudge();
        $cj2->update(['career_rank' => 4, 'career_xp' => $xp]);

        
        $judge3 = $this->makeCharacter($this->lawCareerId, 3, $xp);

        $this->actingAs($judge3->user)
            ->post(route('career.promote'))
            ->assertRedirect();

        $judge3->refresh();
        $this->assertSame(4, (int) $judge3->career_rank);
    }

    public function test_fourth_cj_blocked_with_seats_full_error(): void
    {
        $xp = $this->rank4Xp($this->lawCareerId);

        foreach (range(1, 3) as $i) {
            $j = $this->makeJudge();
            $j->update(['career_rank' => 4, 'career_xp' => $xp]);
        }

        $judge4 = $this->makeCharacter($this->lawCareerId, 3, $xp);

        $this->actingAs($judge4->user)
            ->post(route('career.promote'))
            ->assertSessionHas('error', 'All Chief Justice seats in your city are currently filled. A seat must open before you can be elevated.');

        $judge4->refresh();
        $this->assertSame(3, (int) $judge4->career_rank);
    }

    
    
    

    public function test_cj_step_down_free_when_two_other_cjs_exist(): void
    {
        $xp  = $this->rank4Xp($this->lawCareerId);
        $cj1 = $this->makeChiefJustice();
        $cj2 = $this->makeChiefJustice();
        $cj3 = $this->makeChiefJustice();

        
        $this->actingAs($cj1->user)
            ->post(route('career.law.step-down'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');

        $cj1->refresh();
        $this->assertSame($this->unemployedCareerId, $cj1->career_id);
    }

    public function test_cj_step_down_free_with_one_other_cj_and_ready_successor(): void
    {
        $xp        = $this->rank4Xp($this->lawCareerId);
        $cj1       = $this->makeChiefJustice();
        $cj2       = $this->makeChiefJustice(); 
        $successor = $this->makeCharacter($this->lawCareerId, 3, $xp);

        
        $this->actingAs($cj1->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $cj1->refresh();
        $this->assertSame($this->unemployedCareerId, $cj1->career_id);
    }

    public function test_sole_cj_blocked_without_ready_successor(): void
    {
        $cj = $this->makeChiefJustice(); 

        $this->actingAs($cj->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('error');

        $cj->refresh();
        $this->assertSame($this->lawCareerId, $cj->career_id);
    }

    public function test_cj_step_down_blocked_when_not_in_home_city(): void
    {
        $xp      = $this->rank4Xp($this->lawCareerId);
        $away    = City::create(['name' => 'Away-' . uniqid(), 'slug' => 'away-' . uniqid(), 'crime_rate' => 5]);
        $cj      = $this->makeChiefJustice();
        $this->makeCharacter($this->lawCareerId, 3, $xp); 

        $cj->update(['city_id' => $away->id]); 

        $this->actingAs($cj->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('error');

        $cj->refresh();
        $this->assertSame($this->lawCareerId, $cj->career_id);
    }

    public function test_rank_2_attorney_can_step_down_freely(): void
    {
        $attorney = $this->makeAttorney();

        $this->actingAs($attorney->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $attorney->refresh();
        $this->assertSame($this->unemployedCareerId, $attorney->career_id);
    }

    public function test_rank_3_judge_can_step_down_freely(): void
    {
        $judge = $this->makeJudge();

        $this->actingAs($judge->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $judge->refresh();
        $this->assertSame($this->unemployedCareerId, $judge->career_id);
    }

    public function test_step_down_preserves_xp(): void
    {
        $xp       = $this->rank4Xp($this->lawCareerId);
        $cj       = $this->makeCharacter($this->lawCareerId, 4, 80_000);
        $this->makeCharacter($this->lawCareerId, 3, $xp); 

        $totalBefore = $cj->total_character_exp;

        $this->actingAs($cj->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $cj->refresh();
        $this->assertSame($totalBefore, $cj->total_character_exp, 'Step-down must not apply the XP penalty');
    }

    public function test_non_law_member_redirected_from_step_down(): void
    {
        $outsider = $this->makeDefendant();

        $this->actingAs($outsider->user)
            ->post(route('career.law.step-down'))
            ->assertRedirect(route('dashboard'));
    }

    
    
    

    public function test_cj_can_dismiss_law_clerk(): void
    {
        $cj    = $this->makeChiefJustice();
        $clerk = $this->makeLawClerk();

        $this->actingAs($cj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $clerk->id])
            ->assertSessionHas('success');

        $clerk->refresh();
        $this->assertSame($this->unemployedCareerId, $clerk->career_id);
        $this->assertTrue(
            CharacterJournal::where('character_id', $clerk->id)->where('type', 'dismissed_by')->exists()
        );
    }

    public function test_cj_can_dismiss_attorney(): void
    {
        $cj       = $this->makeChiefJustice();
        $attorney = $this->makeAttorney();

        $this->actingAs($cj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $attorney->id])
            ->assertSessionHas('success');

        $attorney->refresh();
        $this->assertSame($this->unemployedCareerId, $attorney->career_id);
    }

    public function test_cj_can_dismiss_rank_3_judge_when_not_only_successor(): void
    {
        $xp    = $this->rank4Xp($this->lawCareerId);
        $cj    = $this->makeChiefJustice();
        $judge = $this->makeJudge(); 

        
        $this->makeCharacter($this->lawCareerId, 3, $xp);

        $this->actingAs($cj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $judge->id])
            ->assertSessionHas('success');

        $judge->refresh();
        $this->assertSame($this->unemployedCareerId, $judge->career_id);
    }

    public function test_cj_cannot_dismiss_sole_eligible_successor(): void
    {
        $xp    = $this->rank4Xp($this->lawCareerId);
        $cj    = $this->makeChiefJustice(); 
        $judge = $this->makeCharacter($this->lawCareerId, 3, $xp); 

        $this->actingAs($cj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $judge->id])
            ->assertSessionHas('error');

        $judge->refresh();
        $this->assertSame($this->lawCareerId, $judge->career_id);
    }

    public function test_cj_cannot_dismiss_another_cj(): void
    {
        $cj1 = $this->makeChiefJustice();
        $cj2 = $this->makeChiefJustice();

        $this->actingAs($cj1->user)
            ->post(route('career.law.dismiss'), ['character_id' => $cj2->id])
            ->assertSessionHas('error', 'You cannot dismiss another Chief Justice.');

        $cj2->refresh();
        $this->assertSame($this->lawCareerId, $cj2->career_id);
    }

    public function test_cj_cannot_dismiss_themselves(): void
    {
        $cj = $this->makeChiefJustice();

        $this->actingAs($cj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $cj->id])
            ->assertSessionHas('error', 'You cannot dismiss yourself, Chief Justice.');
    }

    public function test_rank_3_judge_cannot_dismiss_anyone(): void
    {
        $judge  = $this->makeJudge();
        $target = $this->makeLawClerk();

        $this->actingAs($judge->user)
            ->post(route('career.law.dismiss'), ['character_id' => $target->id])
            ->assertSessionHas('error', 'Only the Chief Justice can dismiss members of the judiciary.');
    }

    public function test_cj_cannot_dismiss_member_from_other_city(): void
    {
        $cj        = $this->makeChiefJustice();
        $otherCity = City::create(['name' => 'Cross-' . uniqid(), 'slug' => 'cross-' . uniqid(), 'crime_rate' => 5]);
        $foreign   = $this->makeCharacter($this->lawCareerId, 1, 0, 50_000, 50_000, $otherCity);

        $this->actingAs($cj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $foreign->id])
            ->assertSessionHas('error');

        $foreign->refresh();
        $this->assertSame($this->lawCareerId, $foreign->career_id);
    }

    public function test_cj_cannot_dismiss_non_law_member(): void
    {
        $cj      = $this->makeChiefJustice();
        $outside = $this->makeDefendant();

        $this->actingAs($cj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $outside->id])
            ->assertSessionHas('error', 'That character is not a member of the judiciary.');
    }

    
    
    

    public function test_cj_can_quit_career_via_settings_with_xp_penalty(): void
    {
        $cj = $this->makeCharacter($this->lawCareerId, 4, 60_000);
        $totalBefore = $cj->total_character_exp;

        $this->actingAs($cj->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $cj->display_name])
            ->assertSessionHas('success');

        $cj->refresh();
        $this->assertSame($this->unemployedCareerId, $cj->career_id);
        $this->assertLessThan($totalBefore, $cj->total_character_exp, 'Settings quit-career must apply 15% XP penalty');
    }

    public function test_rank_2_attorney_can_quit_career_via_settings(): void
    {
        $attorney = $this->makeAttorney();

        $this->actingAs($attorney->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $attorney->display_name])
            ->assertSessionHas('success');

        $attorney->refresh();
        $this->assertSame($this->unemployedCareerId, $attorney->career_id);
    }

    public function test_quit_career_wrong_confirmation_name_fails_validation(): void
    {
        $cj = $this->makeChiefJustice();

        $this->actingAs($cj->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => 'CompletelyWrongName'])
            ->assertSessionHasErrors(['confirmation_name']);

        $cj->refresh();
        $this->assertSame($this->lawCareerId, $cj->career_id);
    }

    public function test_step_down_does_not_apply_xp_penalty(): void
    {
        $xp  = $this->rank4Xp($this->lawCareerId);
        $cj  = $this->makeCharacter($this->lawCareerId, 4, 80_000);
        $this->makeCharacter($this->lawCareerId, 3, $xp);

        $totalBefore = $cj->total_character_exp;

        $this->actingAs($cj->user)
            ->post(route('career.law.step-down'));

        $cj->refresh();
        $this->assertSame($totalBefore, $cj->total_character_exp, 'Step-down must preserve total_character_exp intact');
    }

    
    
    

    public function test_cj_quorum_step_down_sequence(): void
    {
        $xp  = $this->rank4Xp($this->lawCareerId);

        $cj1 = $this->makeChiefJustice();
        $cj2 = $this->makeChiefJustice();
        $cj3 = $this->makeChiefJustice();

        
        $this->actingAs($cj1->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $cj1->refresh();
        $this->assertSame($this->unemployedCareerId, $cj1->career_id);

        
        $this->actingAs($cj2->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('error');

        $cj2->refresh();
        $this->assertSame($this->lawCareerId, $cj2->career_id);

        
        $this->makeCharacter($this->lawCareerId, 3, $xp);

        
        $this->actingAs($cj2->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $cj2->refresh();
        $this->assertSame($this->unemployedCareerId, $cj2->career_id);
    }

    
    
    

    public function test_crime_record_is_conflicted_returns_true_for_perpetrator(): void
    {
        $perp = $this->makeDefendant();
        $case = $this->makeReferredCase($perp);

        $this->assertTrue($case->isConflicted($perp));
    }

    public function test_crime_record_is_conflicted_returns_true_for_participant(): void
    {
        $lead       = $this->makeDefendant();
        $accomplice = $this->makeDefendant();
        $case = $this->makeReferredCase($lead, CrimeRecord::SEV_FELONY, [$accomplice->id]);

        $this->assertTrue($case->isConflicted($accomplice));
    }

    public function test_crime_record_is_not_conflicted_for_unrelated_character(): void
    {
        $perp     = $this->makeDefendant();
        $stranger = $this->makeDefendant();
        $case = $this->makeReferredCase($perp);

        $this->assertFalse($case->isConflicted($stranger));
    }

    public function test_file_charges_blocked_for_capital_case_with_rank_2(): void
    {
        $attorney  = $this->makeAttorney();
        $defendant = $this->makeDefendant();
        $case      = $this->makeReferredCase($defendant, CrimeRecord::SEV_CAPITAL);

        $result = $case->fileCharges($attorney);

        $this->assertFalse($result, 'fileCharges() must return false for capital + rank-2 attorney');
        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $case->status);
    }

    public function test_appeal_blocked_on_misdemeanor_at_model_level(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeSentencedCase(
            $defendant, $prosecutor, $judge, CrimeRecord::SEV_MISDEMEANOR, [],
            ['fine' => 1_000, 'jail_seconds' => 0]
        );

        $this->assertFalse($case->appeal(), 'CrimeRecord::appeal() must return false for misdemeanor');
    }

    public function test_resolve_appeal_blocked_when_cj_is_original_judge(): void
    {
        $cj = $this->makeChiefJustice();
        $prosecutor = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeAppealedCase($defendant, $prosecutor, $cj);

        $this->assertFalse(
            $case->resolveAppeal($cj, true),
            'resolveAppeal() must return false when CJ was the original judge'
        );
    }

    public function test_sentence_bounds_returns_safe_default_for_unknown_severity(): void
    {
        $bounds = CrimeRecord::sentenceBounds('unknown_severity');
        $this->assertSame(100, $bounds['min_fine']);
        $this->assertSame(100, $bounds['max_fine']);
    }

    
    
    

    public function test_prosecuting_already_charged_case_returns_error(): void
    {
        $attorney1 = $this->makeAttorney();
        $attorney2 = $this->makeAttorney();
        $defendant = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $attorney1);

        
        $this->actingAs($attorney2->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('error', 'This case is no longer available for prosecution.');
    }

    public function test_sentencing_already_sentenced_case_returns_error(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeSentencedCase($defendant, $prosecutor, $judge);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict' => 'sentence', 'fine' => 20_000, 'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('error', 'This case is not awaiting sentencing.');
    }

    public function test_appeal_on_non_sentenced_case_returns_error(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_FELONY);

        $this->actingAs($defendant->user)
            ->post(route('law.appeal', $case->id))
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CONVICTED, $case->status);
    }

    public function test_charged_at_is_stored_in_utc_and_is_a_carbon_instance(): void
    {
        $attorney  = $this->makeAttorney();
        $defendant = $this->makeDefendant();
        $case      = $this->makeReferredCase($defendant);

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id));

        $case->refresh();
        $this->assertNotNull($case->charged_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $case->charged_at);
    }
}
