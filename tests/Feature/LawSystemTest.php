<?php

namespace Tests\Feature;

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


class LawSystemTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;
    private int  $lawCareerId;
    private int  $unemployedCareerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->city = City::create([
            'name'       => 'LawTestCity-' . uniqid(),
            'slug'       => 'lawtestcity-' . uniqid(),
            'crime_rate' => 50,
        ]);

        $lawCareer = DB::table('careers')->whereRaw('LOWER(code) = ?', ['law'])->first();
        $this->assertNotNull($lawCareer, 'Law career must exist in DB');
        $this->lawCareerId = $lawCareer->id;

        $unemployedCareer = DB::table('careers')->whereRaw('LOWER(code) = ?', ['unemployed'])->first();
        $this->assertNotNull($unemployedCareer, 'Unemployed career must exist in DB');
        $this->unemployedCareerId = $unemployedCareer->id;
    }

    
    
    

    private function makeCharacter(
        int  $careerId,
        int  $rank            = 1,
        int  $careerXp        = 0,
        int  $intelligence    = 50_000,
        int  $luck            = 50_000,
        ?City $city           = null,
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
            'user_id'      => $user->id,
            'display_name' => 'LawChar-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $city->id,
            'home_city_id' => $city->id,
            'career_id'    => $careerId,
            'career_rank'  => $rank,
            'career_xp'    => $careerXp,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => 100_000,
            'cash_in_bank' => 0,
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

    private function makeAttorney(int $intelligence = 50_000, int $luck = 50_000): Character
    {
        return $this->makeCharacter($this->lawCareerId, 2, 0, $intelligence, $luck);
    }

    private function makeJudge(): Character
    {
        return $this->makeCharacter($this->lawCareerId, 3);
    }

    private function makeChiefJustice(): Character
    {
        return $this->makeCharacter($this->lawCareerId, 4);
    }

    private function makeDefendant(): Character
    {
        return $this->makeCharacter($this->unemployedCareerId, 0);
    }

    private function resetActionTimer(Character $character): void
    {
        CharacterTimers::where('character_id', $character->id)
            ->update(['next_action_at' => 0]);
        $character->unsetRelation('timers');
    }

    private function seedPriorConvictions(Character $perpetrator, int $count = 10): void
    {
        for ($i = 0; $i < $count; $i++) {
            CrimeRecord::create([
                'character_id'   => $perpetrator->id,
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
                    'perpetrator_name' => strtolower($perpetrator->display_name),
                    'suspect_names'    => [strtolower($perpetrator->display_name)],
                    'victim_name'      => 'Prior Victim',
                    'participants'     => [],
                ],
            ]);
        }
    }

    
    private function makeReferredCase(
        Character $perpetrator,
        string    $severity    = CrimeRecord::SEV_FELONY,
        array     $participants = [],
    ): CrimeRecord {
        $suspectNames = [strtolower($perpetrator->display_name)];
        if (!empty($participants)) {
            $participantNames = Character::withTrashed()
                ->whereIn('id', $participants)
                ->pluck('display_name')
                ->map(fn($name) => strtolower($name))
                ->all();
            $suspectNames = array_values(array_unique([...$suspectNames, ...$participantNames]));
        }

        return CrimeRecord::create([
            'character_id'  => $perpetrator->id,
            'city_id'       => $this->city->id,
            'type'          => CrimeRecord::TYPE_ASSAULT,
            'severity'      => $severity,
            'status'        => CrimeRecord::STATUS_REFERRED,
            'evidence_level'=> 75,
            'committed_at'  => now()->subHours(5),
            'referred_at'   => now()->subHours(4),
            'data'          => [
                'perpetrator_name' => strtolower($perpetrator->display_name),
                'suspect_names'    => $suspectNames,
                'victim_name'      => 'Test Victim',
                'participants'     => $participants,
            ],
        ]);
    }

    
    private function makeChargedCase(
        Character $perpetrator,
        Character $prosecutor,
        string    $severity    = CrimeRecord::SEV_FELONY,
        array     $participants = [],
        ?string   $chargedAt   = null,
        int       $priorConvictions = 10,
    ): CrimeRecord {
        $record = CrimeRecord::create([
            'character_id'  => $perpetrator->id,
            'city_id'       => $this->city->id,
            'type'          => CrimeRecord::TYPE_ASSAULT,
            'severity'      => $severity,
            'status'        => CrimeRecord::STATUS_CHARGED,
            'evidence_level'=> 75,
            'prosecutor_id' => $prosecutor->id,
            'committed_at'  => now()->subHours(6),
            'referred_at'   => now()->subHours(5),
            'charged_at'    => $chargedAt ? now()->parse($chargedAt) : now()->subHours(2),
            'data'          => [
                'perpetrator_name' => strtolower($perpetrator->display_name),
                'suspect_names'    => [strtolower($perpetrator->display_name)],
                'victim_name'      => 'Test Victim',
                'participants'     => $participants,
            ],
        ]);

        
        
        if ($chargedAt === null) {
            DB::table('crime_records')->where('id', $record->id)->update(['updated_at' => now()->subHours(2)]);
            $record = $record->fresh();
        }

        $this->seedPriorConvictions($perpetrator, $priorConvictions);

        return $record;
    }

    
    private function makeConvictedCase(
        Character $perpetrator,
        Character $prosecutor,
        Character $judge,
        string    $severity   = CrimeRecord::SEV_FELONY,
        array     $participants = [],
        int       $priorConvictions = 10,
    ): CrimeRecord {
        $record = CrimeRecord::create([
            'character_id'  => $perpetrator->id,
            'city_id'       => $this->city->id,
            'type'          => CrimeRecord::TYPE_ASSAULT,
            'severity'      => $severity,
            'status'        => CrimeRecord::STATUS_CONVICTED,
            'evidence_level'=> 75,
            'prosecutor_id' => $prosecutor->id,
            'judge_id'      => $judge->id,
            'committed_at'  => now()->subHours(8),
            'charged_at'    => now()->subHours(6),
            'data'          => [
                'perpetrator_name' => strtolower($perpetrator->display_name),
                'suspect_names'    => [strtolower($perpetrator->display_name)],
                'victim_name'      => 'Test Victim',
                'participants'     => $participants,
            ],
        ]);

        
        DB::table('crime_records')->where('id', $record->id)->update(['updated_at' => now()->subHours(2)]);

        $this->seedPriorConvictions($perpetrator, $priorConvictions);

        return $record->fresh();
    }

    
    private function makeSentencedCase(
        Character $perpetrator,
        Character $prosecutor,
        Character $judge,
        string    $severity   = CrimeRecord::SEV_FELONY,
    ): CrimeRecord {
        return CrimeRecord::create([
            'character_id'  => $perpetrator->id,
            'city_id'       => $this->city->id,
            'type'          => CrimeRecord::TYPE_ASSAULT,
            'severity'      => $severity,
            'status'        => CrimeRecord::STATUS_SENTENCED,
            'evidence_level'=> 75,
            'prosecutor_id' => $prosecutor->id,
            'judge_id'      => $judge->id,
            'sentence'      => ['fine' => 20_000, 'jail_seconds' => 3_600],
            'committed_at'  => now()->subHours(10),
            'charged_at'    => now()->subHours(8),
            'sentenced_at'  => now()->subHours(1),
            'data'          => [
                'perpetrator_name' => strtolower($perpetrator->display_name),
                'suspect_names'    => [strtolower($perpetrator->display_name)],
                'victim_name'      => 'Test Victim',
            ],
        ]);
    }

    
    
    

    public function test_attorney_can_prosecute_referred_case(): void
    {
        $attorney = $this->makeAttorney();
        $defendant = $this->makeDefendant();
        $case = $this->makeReferredCase($defendant);

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CHARGED, $case->status);
        $this->assertSame($attorney->id, $case->prosecutor_id);
    }

    public function test_prosecution_uses_referred_suspects_for_charges(): void
    {
        $attorney = $this->makeAttorney();
        $actualPerpetrator = $this->makeDefendant();
        $namedSuspect = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id' => $actualPerpetrator->id,
            'city_id' => $this->city->id,
            'type' => CrimeRecord::TYPE_ASSAULT,
            'severity' => CrimeRecord::SEV_FELONY,
            'status' => CrimeRecord::STATUS_REFERRED,
            'evidence_level' => 75,
            'committed_at' => now()->subHours(5),
            'referred_at' => now()->subHours(4),
            'data' => [
                'perpetrator_name' => strtolower($actualPerpetrator->display_name),
                'suspect_names' => [strtolower($namedSuspect->display_name)],
                'victim_name' => 'Test Victim',
                'participants' => [],
            ],
        ]);

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('success');

        $case->refresh();

        $this->assertSame(CrimeRecord::STATUS_CHARGED, $case->status);
        $this->assertSame($namedSuspect->id, $case->character_id);
        $this->assertSame($actualPerpetrator->id, $case->data['actual_character_id']);
        $this->assertSame([$namedSuspect->id], $case->data['charged_suspect_ids']);
        $this->assertTrue(
            CharacterJournal::where('character_id', $namedSuspect->id)
                ->where('type', 'criminal_charges_filed')
                ->where('data->crime_record_id', $case->id)
                ->exists()
        );
        $this->assertFalse(
            CharacterJournal::where('character_id', $actualPerpetrator->id)
                ->where('type', 'criminal_charges_filed')
                ->where('data->crime_record_id', $case->id)
                ->exists()
        );
    }

    public function test_referred_targets_drive_court_cycle(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender = $this->makeAttorney();
        $actualLead = $this->makeDefendant();
        $actualParticipant = $this->makeDefendant();
        $chargedLead = $this->makeDefendant();
        $chargedParticipant = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id' => $actualLead->id,
            'city_id' => $this->city->id,
            'type' => CrimeRecord::TYPE_ASSAULT,
            'severity' => CrimeRecord::SEV_FELONY,
            'status' => CrimeRecord::STATUS_REFERRED,
            'evidence_level' => 75,
            'committed_at' => now()->subHours(5),
            'referred_at' => now()->subHours(4),
            'data' => [
                'perpetrator_name' => strtolower($actualLead->display_name),
                'suspect_names' => [strtolower($chargedLead->display_name), strtolower($chargedParticipant->display_name)],
                'suspect_ids' => [$chargedLead->id, $chargedParticipant->id],
                'victim_name' => 'Test Victim',
                'participants' => [$actualParticipant->id],
            ],
        ]);

        $this->actingAs($prosecutor->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('success');

        $case->refresh();

        $this->assertSame($chargedLead->id, $case->character_id);
        $this->assertSame([$chargedParticipant->id], $case->data['participants']);
        $this->assertSame($actualLead->id, $case->data['actual_character_id']);
        $this->assertSame([$actualParticipant->id], $case->data['actual_participants']);
        $this->assertSame([$chargedLead->id, $chargedParticipant->id], $case->data['charged_suspect_ids']);

        foreach ([$chargedLead->id, $chargedParticipant->id] as $id) {
            $this->assertTrue(
                CharacterJournal::where('character_id', $id)
                    ->where('type', 'criminal_charges_filed')
                    ->where('data->crime_record_id', $case->id)
                    ->exists()
            );
        }

        foreach ([$actualLead->id, $actualParticipant->id] as $id) {
            $this->assertFalse(
                CharacterJournal::where('character_id', $id)
                    ->where('type', 'criminal_charges_filed')
                    ->where('data->crime_record_id', $case->id)
                    ->exists()
            );
        }

        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500])
            ->assertSessionHas('success');

        $offer = DefenseOffer::where('crime_record_id', $case->id)->firstOrFail();
        $this->assertSame($chargedLead->id, $offer->defendant_id);
        $this->assertTrue(
            CharacterJournal::where('character_id', $chargedLead->id)
                ->where('type', 'defense_request')
                ->where('data->case_id', $case->id)
                ->exists()
        );
        $this->assertFalse(
            CharacterJournal::where('character_id', $actualLead->id)
                ->where('type', 'defense_request')
                ->where('data->case_id', $case->id)
                ->exists()
        );
    }

    public function test_accuracy_uses_hidden_actual_participants(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge = $this->makeJudge();
        $actualLead = $this->makeDefendant();
        $actualParticipant = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id' => $actualLead->id,
            'city_id' => $this->city->id,
            'type' => CrimeRecord::TYPE_ASSAULT,
            'severity' => CrimeRecord::SEV_FELONY,
            'status' => CrimeRecord::STATUS_REFERRED,
            'evidence_level' => 75,
            'committed_at' => now()->subHours(5),
            'referred_at' => now()->subHours(4),
            'data' => [
                'perpetrator_name' => strtolower($actualLead->display_name),
                'suspect_names' => [strtolower($actualParticipant->display_name)],
                'suspect_ids' => [$actualParticipant->id],
                'victim_name' => 'Test Victim',
                'participants' => [$actualParticipant->id],
            ],
        ]);

        $this->actingAs($prosecutor->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('success');

        $case->refresh();
        $case->forceFill(['charged_at' => now()->subHours(2)])->save();

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict' => 'convict',
                'fine' => 1_000,
                'jail_seconds' => 0,
            ])
            ->assertSessionHas('success');

        $judge->refresh();
        $this->assertGreaterThan(100, $judge->career_xp);

        $journal = CharacterJournal::where('character_id', $judge->id)
            ->where('type', 'case_rewarded')
            ->where('data->case_id', $case->id)
            ->first();

        $this->assertNotNull($journal);
        $this->assertStringNotContainsString($actualLead->display_name, $journal->data['message'] ?? '');
    }

    public function test_turn_in_uses_charged_parties_not_hidden_actual_parties(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge = $this->makeJudge();
        $actualLead = $this->makeDefendant();
        $chargedLead = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id' => $actualLead->id,
            'city_id' => $this->city->id,
            'type' => CrimeRecord::TYPE_ASSAULT,
            'severity' => CrimeRecord::SEV_FELONY,
            'status' => CrimeRecord::STATUS_REFERRED,
            'evidence_level' => 75,
            'committed_at' => now()->subHours(5),
            'referred_at' => now()->subHours(4),
            'data' => [
                'perpetrator_name' => strtolower($actualLead->display_name),
                'suspect_names' => [strtolower($chargedLead->display_name)],
                'suspect_ids' => [$chargedLead->id],
                'victim_name' => 'Test Victim',
                'participants' => [],
            ],
        ]);

        $this->actingAs($prosecutor->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('success');

        $case->refresh();
        $case->forceFill(['charged_at' => now()->subHours(2)])->save();

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict' => 'convict',
                'fine' => 1_000,
                'jail_seconds' => 0,
            ])
            ->assertSessionHas('success');

        $this->actingAs($actualLead->user)
            ->post(route('city.police.turn-in', $this->city), ['case_id' => $case->id])
            ->assertSessionHas('error', 'This is not your case.');

        $this->actingAs($chargedLead->user)
            ->post(route('city.police.turn-in', $this->city), ['case_id' => $case->id])
            ->assertSessionHas('success');

        $actualLead->refresh();
        $chargedLead->refresh();
        $case->refresh();

        $this->assertSame(100_000, (int) $actualLead->cash_on_hand);
        $this->assertSame(99_000, (int) $chargedLead->cash_on_hand);
        $this->assertSame(CrimeRecord::STATUS_CLOSED, $case->status);
    }

    public function test_rank_1_law_clerk_cannot_prosecute(): void
    {
        $clerk = $this->makeCharacter($this->lawCareerId, 1);
        $defendant = $this->makeDefendant();
        $case = $this->makeReferredCase($defendant);

        $this->actingAs($clerk->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('error', 'Only an Attorney may file charges.');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $case->status);
    }

    public function test_attorney_cannot_prosecute_if_conflicted_as_perpetrator(): void
    {
        $attorney = $this->makeAttorney();
        
        $case = $this->makeReferredCase($attorney);

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $case->id))
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $case->status);
    }

    public function test_attorney_cannot_prosecute_case_in_different_city(): void
    {
        $otherCity = City::create([
            'name' => 'OtherCity-' . uniqid(), 'slug' => 'othercity-' . uniqid(), 'crime_rate' => 10,
        ]);
        $attorney  = $this->makeAttorney();
        $defendant = $this->makeDefendant();

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

    
    
    

    public function test_attorney_can_offer_defense_on_charged_case(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender   = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertNull($case->defense_id);
        $this->assertNull($case->data['defense_pending'] ?? null);

        $offer = DefenseOffer::where('crime_record_id', $case->id)->first();
        $this->assertNotNull($offer);
        $this->assertSame($defender->id, $offer->attorney_id);
        $this->assertSame($defendant->id, $offer->defendant_id);
        $this->assertSame(2_500, $offer->fee);
        $this->assertSame(DefenseOffer::STATUS_PENDING, $offer->status);
    }

    public function test_attorney_cannot_defend_their_own_prosecution(): void
    {
        $attorney  = $this->makeAttorney();
        $defendant = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $attorney);

        $this->actingAs($attorney->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500])
            ->assertSessionHas('error', 'You are the prosecutor on this case.');
    }

    public function test_defense_pending_blocks_second_defense_offer_initially(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender1  = $this->makeAttorney();
        $defender2  = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        
        $this->actingAs($defender1->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500])
            ->assertSessionHas('success');

        
        $this->actingAs($defender2->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 3_000])
            ->assertSessionHas('error', 'A defence offer is already pending on this case.');

        $case->refresh();
        $this->assertNull($case->defense_id);
        $this->assertTrue(DefenseOffer::where('crime_record_id', $case->id)
            ->where('attorney_id', $defender1->id)
            ->where('status', DefenseOffer::STATUS_PENDING)
            ->exists());
    }

    public function test_secondary_attorney_can_override_defense_offer_after_three_minutes(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender1  = $this->makeAttorney();
        $defender2  = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        
        $this->actingAs($defender1->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500])
            ->assertSessionHas('success');

        $firstOffer = DefenseOffer::where('crime_record_id', $case->id)->firstOrFail();
        $firstOffer->update([
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
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
            ->exists(), 'Second attorney should have overridden the stale offer.');
    }

    public function test_defendant_can_decline_defense_offer(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender   = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        
        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500]);

        $case->refresh();
        $offer = DefenseOffer::where('crime_record_id', $case->id)->firstOrFail();
        $this->assertSame(DefenseOffer::STATUS_PENDING, $offer->status);

        $journal = CharacterJournal::where('character_id', $defendant->id)
            ->where('type', 'defense_request')
            ->first();
        $this->assertNotNull($journal, 'Defendant should receive a defense_request journal');

        
        $this->actingAs($defendant->user)
            ->post(route('journal.decline', $journal->id))
            ->assertSessionHas('success');

        $case->refresh();
        
        $this->assertNull($case->defense_id);
        $this->assertNull($case->data['defense_pending'] ?? null);
        $this->assertSame(DefenseOffer::STATUS_DECLINED, $offer->fresh()->status);
    }

    public function test_defendant_accepting_defense_offer_retains_attorney_without_running_trial(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender   = $this->makeAttorney();
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 5_000])
            ->assertSessionHas('success');

        $offer = DefenseOffer::where('crime_record_id', $case->id)->firstOrFail();
        $journal = CharacterJournal::where('character_id', $defendant->id)
            ->where('type', 'defense_request')
            ->firstOrFail();

        $this->actingAs($defendant->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('success');

        $case->refresh();
        $defender->refresh();
        $defendant->refresh();

        $this->assertSame(CrimeRecord::STATUS_CHARGED, $case->status);
        $this->assertSame($defender->id, $case->defense_id);
        $this->assertSame(DefenseOffer::STATUS_ACCEPTED, $offer->fresh()->status);
        $this->assertSame(105_000, $defender->cash_on_hand);
        $this->assertSame(95_000, $defendant->cash_on_hand);
        $this->assertTrue(
            CharacterJournal::where('character_id', $defender->id)
                ->where('type', 'defense_retained')
                ->where('data->case_id', $case->id)
                ->exists(),
            'The defense attorney needs a retained-counsel journal when the defendant accepts.'
        );
    }

    public function test_attorney_executes_accepted_defense_offer_from_defense_docket(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender   = $this->makeAttorney(500_000, 500_000);
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);

        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 5_000])
            ->assertSessionHas('success');

        $offer = DefenseOffer::where('crime_record_id', $case->id)->firstOrFail();
        $journal = CharacterJournal::where('character_id', $defendant->id)
            ->where('type', 'defense_request')
            ->firstOrFail();

        $this->actingAs($defendant->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('success');

        $this->actingAs($defender->user)
            ->post(route('career.law.defense.execute', $offer->id))
            ->assertRedirect();

        $case->refresh();
        $offer->refresh();

        $this->assertContains($case->status, [
            CrimeRecord::STATUS_ACQUITTED,
            CrimeRecord::STATUS_CONVICTED,
        ]);
        $this->assertSame(DefenseOffer::STATUS_EXECUTED, $offer->status);
        $this->assertNotNull($offer->executed_at);
        $this->assertFalse(
            CharacterJournal::where('character_id', $defender->id)
                ->whereIn('type', ['defense_outcome', 'defense_accepted'])
                ->where('data->case_id', $case->id)
                ->exists(),
            'The attorney already receives the execute-defence response; do not create duplicate verdict journals.'
        );
        $this->assertTrue(
            CharacterJournal::where('character_id', $defendant->id)
                ->where('type', 'case_outcome')
                ->where('data->case_id', $case->id)
                ->exists()
        );
    }

    public function test_defense_acquittal_does_not_reward_or_punish_chain_accuracy(): void
    {
        $detective  = $this->makeAttorney();
        $prosecutor = $this->makeAttorney();
        $defender   = $this->makeAttorney(500_000, 500_000);
        $defendant  = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor);
        $case->update([
            'detective_id' => $detective->id,
            'prosecutor_id' => null,
        ]);

        $detectiveCashBefore = (int) $detective->cash_on_hand;
        $detectiveXpBefore = (int) $detective->career_xp;

        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 5_000])
            ->assertSessionHas('success');

        $offer = DefenseOffer::where('crime_record_id', $case->id)->firstOrFail();
        $journal = CharacterJournal::where('character_id', $defendant->id)
            ->where('type', 'defense_request')
            ->firstOrFail();

        $this->actingAs($defendant->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('success');

        $this->actingAs($defender->user)
            ->post(route('career.law.defense.execute', $offer->id))
            ->assertSessionHas('success');

        $case->refresh();
        $detective->refresh();

        $this->assertSame(CrimeRecord::STATUS_ACQUITTED, $case->status);
        $this->assertSame($detectiveCashBefore, (int) $detective->cash_on_hand);
        $this->assertSame($detectiveXpBefore, (int) $detective->career_xp);
        $this->assertFalse(
            CharacterJournal::where('character_id', $detective->id)
                ->where('type', 'case_rewarded')
                ->where('data->case_id', $case->id)
                ->exists()
        );
    }

    
    
    

    public function test_judge_can_convict_uncontested_case_after_one_hour(): void
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
    }

    public function test_judge_blocked_before_one_hour_has_elapsed(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        
        $case = $this->makeChargedCase($defendant, $prosecutor, chargedAt: now()->subMinutes(30)->toIso8601String());

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

    public function test_prosecutor_cannot_also_judge_the_case(): void
    {
        $prosecutorJudge = $this->makeCharacter($this->lawCareerId, 3);
        $defendant       = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutorJudge);

        $this->actingAs($prosecutorJudge->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict'      => 'convict',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('error', 'You prosecuted this case and cannot also judge it.');
    }

    public function test_rank_2_cannot_issue_verdict(): void
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

    public function test_conviction_count_includes_sentenced_participants_and_pending_appeals(): void
    {
        $lead       = $this->makeDefendant();
        $accomplice = $this->makeDefendant();

        CrimeRecord::create([
            'character_id'   => $lead->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_SENTENCED,
            'evidence_level' => 75,
            'sentence'       => ['fine' => 20_000, 'jail_seconds' => 0],
            'committed_at'   => now()->subHours(10),
            'charged_at'     => now()->subHours(8),
            'sentenced_at'   => now()->subHours(1),
            'data'           => [
                'perpetrator_name' => strtolower($lead->display_name),
                'suspect_names'    => [strtolower($lead->display_name), strtolower($accomplice->display_name)],
                'victim_name'      => 'Test Victim',
                'participants'     => [$accomplice->id],
            ],
        ]);

        CrimeRecord::create([
            'character_id'   => $accomplice->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_APPEALED,
            'evidence_level' => 75,
            'sentence'       => ['fine' => 20_000, 'jail_seconds' => 0],
            'committed_at'   => now()->subHours(20),
            'charged_at'     => now()->subHours(18),
            'sentenced_at'   => now()->subHours(12),
            'appealed_at'    => now()->subHour(),
            'data'           => [
                'perpetrator_name' => strtolower($accomplice->display_name),
                'suspect_names'    => [strtolower($accomplice->display_name)],
                'victim_name'      => 'Test Victim',
                'participants'     => [],
            ],
        ]);

        $this->assertSame(1, CrimeRecord::convictionCount($lead->id));
        $this->assertSame(2, CrimeRecord::convictionCount($accomplice->id));
    }

    public function test_sentence_rejects_fine_below_misdemeanor_minimum(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_MISDEMEANOR);

        
        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict'      => 'sentence',
                'fine'         => 500,
                'jail_seconds' => 0,
            ])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CONVICTED, $case->status);
    }

    public function test_sentence_rejects_fine_above_misdemeanor_maximum(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_MISDEMEANOR);

        
        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict'      => 'sentence',
                'fine'         => 50_000,
                'jail_seconds' => 0,
            ])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CONVICTED, $case->status);
    }

    public function test_capital_sentence_enforces_mandatory_minimum_jail(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeConvictedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_CAPITAL);

        
        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict'      => 'sentence',
                'fine'         => 50_000,
                'jail_seconds' => 0,
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
                'verdict'      => 'sentence',
                'fine'         => 50_000,
                'jail_seconds' => 1_000,
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
                'verdict'      => 'sentence',
                'fine'         => 50_000,
                'jail_seconds' => 21_600,
            ])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    public function test_different_judge_cannot_sentence_case_convicted_by_another_judge(): void
    {
        $prosecutor  = $this->makeAttorney();
        $convictJudge = $this->makeJudge();
        $sentenceJudge = $this->makeJudge();
        $defendant   = $this->makeDefendant();
        
        $case = $this->makeConvictedCase($defendant, $prosecutor, $convictJudge);

        $this->actingAs($sentenceJudge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict'      => 'sentence',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('error');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CONVICTED, $case->status);
    }

    
    
    

    public function test_all_co_conspirators_notified_when_sentence_issued(): void
    {
        $prosecutor  = $this->makeAttorney();
        $judge       = $this->makeJudge();
        $lead        = $this->makeDefendant();
        $accomplice1 = $this->makeDefendant();
        $accomplice2 = $this->makeDefendant();

        $case = $this->makeConvictedCase(
            $lead,
            $prosecutor,
            $judge,
            CrimeRecord::SEV_FELONY,
            [$accomplice1->id, $accomplice2->id],
        );

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict'      => 'sentence',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('success');

        
        $this->assertTrue(
            CharacterJournal::where('character_id', $lead->id)->where('type', 'case_sentenced')->exists()
        );
        
        $this->assertTrue(
            CharacterJournal::where('character_id', $accomplice1->id)->where('type', 'case_sentenced')->exists()
        );
        $this->assertTrue(
            CharacterJournal::where('character_id', $accomplice2->id)->where('type', 'case_sentenced')->exists()
        );
    }

    public function test_referred_case_participants_all_notified_when_auto_charged(): void
    {
        $lead       = $this->makeDefendant();
        $accomplice = $this->makeDefendant();

        
        CrimeRecord::create([
            'character_id'   => $lead->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_REFERRED,
            'evidence_level' => 65,
            'committed_at'   => now()->subHours(10),
            'referred_at'    => now()->subHours(4),
            'data'           => [
                'perpetrator_name' => strtolower($lead->display_name),
                'suspect_names'    => [strtolower($lead->display_name), strtolower($accomplice->display_name)],
                'participants'     => [$accomplice->id],
            ],
        ]);

        
        $judge = $this->makeJudge();
        $this->actingAs($judge->user)->get(route('career.law'));

        $this->assertTrue(
            CharacterJournal::where('character_id', $lead->id)->where('type', 'case_auto_charged')->exists()
        );
        $this->assertTrue(
            CharacterJournal::where('character_id', $accomplice->id)->where('type', 'case_auto_charged')->exists()
        );
    }

    public function test_multi_participant_conviction_sentence_respects_all_participants(): void
    {
        $prosecutor  = $this->makeAttorney();
        $judge       = $this->makeJudge();
        $lead        = $this->makeDefendant();
        $accomplice  = $this->makeDefendant();

        $case = $this->makeConvictedCase(
            $lead,
            $prosecutor,
            $judge,
            CrimeRecord::SEV_FELONY,
            [$accomplice->id],
        );

        
        $this->assertContains($accomplice->id, $case->data['participants']);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $case->id), [
                'verdict'      => 'sentence',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('success');

        
        foreach ([$lead->id, $accomplice->id] as $pid) {
            $this->assertTrue(
                CharacterJournal::where('character_id', $pid)->where('type', 'case_sentenced')->exists(),
                "Character {$pid} should have a case_sentenced journal"
            );
        }
    }

    
    
    

    public function test_defendant_can_file_appeal_on_felony_sentence(): void
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

    public function test_defendant_cannot_appeal_misdemeanor_sentence(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeSentencedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_MISDEMEANOR);

        $this->actingAs($defendant->user)
            ->post(route('law.appeal', $case->id))
            ->assertSessionHas('error', 'Misdemeanor convictions are final and cannot be appealed.');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    public function test_defendant_cannot_appeal_twice(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        $case = $this->makeSentencedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_FELONY);

        
        $this->actingAs($defendant->user)
            ->post(route('law.appeal', $case->id));

        $case->refresh();
        
        $data           = $case->data ?? [];
        $data['appeal'] = ['upheld' => true, 'chief_justice_id' => 99];
        $case->update(['data' => $data, 'status' => CrimeRecord::STATUS_SENTENCED]);

        
        $this->actingAs($defendant->user)
            ->post(route('law.appeal', $case->id))
            ->assertSessionHas('error');
    }

    public function test_only_the_defendant_can_file_an_appeal(): void
    {
        $prosecutor   = $this->makeAttorney();
        $judge        = $this->makeJudge();
        $defendant    = $this->makeDefendant();
        $otherPerson  = $this->makeDefendant();
        $case = $this->makeSentencedCase($defendant, $prosecutor, $judge, CrimeRecord::SEV_FELONY);

        $this->actingAs($otherPerson->user)
            ->post(route('law.appeal', $case->id))
            ->assertSessionHas('error', 'You can only appeal your own case.');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    
    
    

    public function test_chief_justice_can_uphold_appeal(): void
    {
        $prosecutor    = $this->makeAttorney();
        $judge         = $this->makeJudge();
        $chiefJustice  = $this->makeChiefJustice();
        $defendant     = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $defendant->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_APPEALED,
            'evidence_level' => 75,
            'prosecutor_id'  => $prosecutor->id,
            'judge_id'       => $judge->id,  
            'sentence'       => ['fine' => 20_000, 'jail_seconds' => 3_600],
            'committed_at'   => now()->subHours(12),
            'sentenced_at'   => now()->subHours(2),
            'appealed_at'    => now()->subHour(),
            'data'           => [
                'perpetrator_name' => strtolower($defendant->display_name),
                'suspect_names'    => [strtolower($defendant->display_name)],
            ],
        ]);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 1])
            ->assertSessionHas('success');

        $case->refresh();
        
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
    }

    public function test_chief_justice_can_overturn_appeal(): void
    {
        $prosecutor    = $this->makeAttorney();
        $judge         = $this->makeJudge();
        $chiefJustice  = $this->makeChiefJustice();
        $defendant     = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $defendant->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_APPEALED,
            'evidence_level' => 75,
            'prosecutor_id'  => $prosecutor->id,
            'judge_id'       => $judge->id,
            'sentence'       => ['fine' => 20_000, 'jail_seconds' => 3_600],
            'committed_at'   => now()->subHours(12),
            'sentenced_at'   => now()->subHours(2),
            'appealed_at'    => now()->subHour(),
            'data'           => [
                'perpetrator_name' => strtolower($defendant->display_name),
                'suspect_names'    => [strtolower($defendant->display_name)],
            ],
        ]);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 0])
            ->assertSessionHas('success');

        $case->refresh();
        
        $this->assertSame(CrimeRecord::STATUS_ACQUITTED, $case->status);
    }

    public function test_chief_justice_cannot_resolve_appeal_on_case_they_judged(): void
    {
        $prosecutor   = $this->makeAttorney();
        $defendant    = $this->makeDefendant();
        $chiefJustice = $this->makeChiefJustice();

        
        $case = CrimeRecord::create([
            'character_id'   => $defendant->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_CAPITAL,
            'status'         => CrimeRecord::STATUS_APPEALED,
            'evidence_level' => 75,
            'prosecutor_id'  => $prosecutor->id,
            'judge_id'       => $chiefJustice->id,  
            'sentence'       => ['fine' => 50_000, 'jail_seconds' => 10_800],
            'committed_at'   => now()->subHours(12),
            'sentenced_at'   => now()->subHours(2),
            'appealed_at'    => now()->subHour(),
            'data'           => ['perpetrator_name' => 'x', 'suspect_names' => ['x']],
        ]);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 1])
            ->assertSessionHas('error', 'You cannot rule on a case you have already adjudicated.');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_APPEALED, $case->status);
    }

    public function test_rank_3_judge_cannot_resolve_appeal(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $judge2     = $this->makeJudge();
        $defendant  = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $defendant->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_APPEALED,
            'evidence_level' => 75,
            'prosecutor_id'  => $prosecutor->id,
            'judge_id'       => $judge->id,
            'sentence'       => ['fine' => 20_000, 'jail_seconds' => 3_600],
            'committed_at'   => now()->subHours(12),
            'sentenced_at'   => now()->subHours(2),
            'appealed_at'    => now()->subHour(),
            'data'           => ['perpetrator_name' => 'x', 'suspect_names' => ['x']],
        ]);

        
        $this->actingAs($judge2->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 1])
            ->assertSessionHas('error', 'Only a Chief Justice may rule on appeals.');
    }

    
    
    

    public function test_chief_justice_can_step_down_with_ready_successor(): void
    {
        $rank4Data = DB::table('career_ranks')
            ->where('career_id', $this->lawCareerId)
            ->where('rank_level', 4)
            ->first();
        $this->assertNotNull($rank4Data, 'rank_4 data must exist for law career');

        $successor    = $this->makeCharacter($this->lawCareerId, 3, (int) $rank4Data->xp_required);
        $chiefJustice = $this->makeChiefJustice();

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $chiefJustice->refresh();
        $this->assertSame($this->unemployedCareerId, $chiefJustice->career_id);
        $this->assertTrue(
            CharacterJournal::where('character_id', $chiefJustice->id)
                ->where('type', 'career_step_down')
                ->exists()
        );
    }

    public function test_chief_justice_step_down_preserves_xp(): void
    {
        $rank4Data = DB::table('career_ranks')
            ->where('career_id', $this->lawCareerId)
            ->where('rank_level', 4)
            ->first();

        $successor    = $this->makeCharacter($this->lawCareerId, 3, (int) $rank4Data->xp_required);
        $chiefJustice = $this->makeCharacter($this->lawCareerId, 4, 99_999);

        $xpBefore = $chiefJustice->career_xp;

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $chiefJustice->refresh();
        
        $this->assertGreaterThanOrEqual($xpBefore, $chiefJustice->total_character_exp);
    }

    public function test_chief_justice_cannot_step_down_without_successor(): void
    {
        $chiefJustice = $this->makeChiefJustice();
        

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('error');

        $chiefJustice->refresh();
        $this->assertSame($this->lawCareerId, $chiefJustice->career_id);
    }

    public function test_chief_justice_cannot_step_down_when_not_in_home_city(): void
    {
        $rank4Data = DB::table('career_ranks')
            ->where('career_id', $this->lawCareerId)
            ->where('rank_level', 4)
            ->first();

        $otherCity    = City::create([
            'name' => 'Away-' . uniqid(), 'slug' => 'away-' . uniqid(), 'crime_rate' => 10,
        ]);
        $successor    = $this->makeCharacter($this->lawCareerId, 3, (int) $rank4Data->xp_required);
        $chiefJustice = $this->makeChiefJustice();
        
        $chiefJustice->update(['city_id' => $otherCity->id]);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('error');

        $chiefJustice->refresh();
        $this->assertSame($this->lawCareerId, $chiefJustice->career_id);
    }

    public function test_non_law_member_cannot_call_law_step_down(): void
    {
        $outsider = $this->makeCharacter($this->unemployedCareerId);

        $this->actingAs($outsider->user)
            ->post(route('career.law.step-down'))
            
            ->assertRedirect(route('dashboard'));
    }

    
    
    

    public function test_chief_justice_can_dismiss_rank_1_member(): void
    {
        $chiefJustice = $this->makeChiefJustice();
        $clerk        = $this->makeCharacter($this->lawCareerId, 1);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.dismiss'), ['character_id' => $clerk->id])
            ->assertSessionHas('success');

        $clerk->refresh();
        $this->assertSame($this->unemployedCareerId, $clerk->career_id);
        $this->assertTrue(
            CharacterJournal::where('character_id', $clerk->id)
                ->where('type', 'dismissed_by')
                ->exists()
        );
    }

    public function test_chief_justice_can_dismiss_rank_3_judge(): void
    {
        $chiefJustice = $this->makeChiefJustice();
        $judge        = $this->makeJudge();

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.dismiss'), ['character_id' => $judge->id])
            ->assertSessionHas('success');

        $judge->refresh();
        $this->assertSame($this->unemployedCareerId, $judge->career_id);
    }

    public function test_chief_justice_cannot_dismiss_another_chief_justice(): void
    {
        $chiefJustice1 = $this->makeChiefJustice();
        $chiefJustice2 = $this->makeChiefJustice();

        $this->actingAs($chiefJustice1->user)
            ->post(route('career.law.dismiss'), ['character_id' => $chiefJustice2->id])
            ->assertSessionHas('error', 'You cannot dismiss another Chief Justice.');

        $chiefJustice2->refresh();
        $this->assertSame($this->lawCareerId, $chiefJustice2->career_id);
    }

    public function test_chief_justice_cannot_dismiss_themselves(): void
    {
        $chiefJustice = $this->makeChiefJustice();

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.dismiss'), ['character_id' => $chiefJustice->id])
            ->assertSessionHas('error', 'You cannot dismiss yourself, Chief Justice.');
    }

    public function test_rank_3_judge_cannot_dismiss_anyone(): void
    {
        $judge  = $this->makeJudge();
        $target = $this->makeCharacter($this->lawCareerId, 1);

        $this->actingAs($judge->user)
            ->post(route('career.law.dismiss'), ['character_id' => $target->id])
            ->assertSessionHas('error', 'Only the Chief Justice can dismiss members of the judiciary.');

        $target->refresh();
        $this->assertSame($this->lawCareerId, $target->career_id);
    }

    public function test_chief_justice_cannot_dismiss_member_from_different_city(): void
    {
        $chiefJustice = $this->makeChiefJustice();

        $otherCity   = City::create([
            'name' => 'CrossCity-' . uniqid(), 'slug' => 'crosscity-' . uniqid(), 'crime_rate' => 10,
        ]);
        $foreignClerk = $this->makeCharacter($this->lawCareerId, 1, 0, 50_000, 50_000, $otherCity);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.dismiss'), ['character_id' => $foreignClerk->id])
            ->assertSessionHas('error');

        $foreignClerk->refresh();
        $this->assertSame($this->lawCareerId, $foreignClerk->career_id);
    }

    public function test_dismiss_targets_only_law_career_members(): void
    {
        $chiefJustice = $this->makeChiefJustice();
        $outsider     = $this->makeCharacter($this->unemployedCareerId);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.dismiss'), ['character_id' => $outsider->id])
            ->assertSessionHas('error', 'That character is not a member of the judiciary.');
    }

    
    
    

    public function test_chief_justice_can_quit_career_via_settings(): void
    {
        $chiefJustice = $this->makeCharacter($this->lawCareerId, 4, 50_000);
        $xpBefore     = $chiefJustice->total_character_exp;

        $this->actingAs($chiefJustice->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => $chiefJustice->display_name,
            ])
            ->assertSessionHas('success');

        $chiefJustice->refresh();
        $this->assertSame($this->unemployedCareerId, $chiefJustice->career_id);
        
        $this->assertLessThan($xpBefore, $chiefJustice->total_character_exp);
    }

    public function test_rank_2_attorney_can_quit_career_via_settings(): void
    {
        $attorney = $this->makeAttorney();

        $this->actingAs($attorney->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => $attorney->display_name,
            ])
            ->assertSessionHas('success');

        $attorney->refresh();
        $this->assertSame($this->unemployedCareerId, $attorney->career_id);
    }

    public function test_quit_career_blocked_by_wrong_confirmation_name(): void
    {
        $chiefJustice = $this->makeChiefJustice();

        $this->actingAs($chiefJustice->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => 'WrongName',
            ])
            ->assertSessionHasErrors(['confirmation_name']);

        $chiefJustice->refresh();
        $this->assertSame($this->lawCareerId, $chiefJustice->career_id);
    }

    
    
    

    public function test_conflicted_character_cannot_judge_their_own_crime_case(): void
    {
        
        $judgePerp  = $this->makeCharacter($this->lawCareerId, 3);
        $prosecutor = $this->makeAttorney();
        $case = $this->makeChargedCase($judgePerp, $prosecutor);

        $this->actingAs($judgePerp->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict'      => 'acquit',
                'fine'         => 0,
                'jail_seconds' => 0,
            ])
            ->assertSessionHas('error', 'You have a conflict of interest in this case.');
    }

    public function test_chief_justice_conflicted_as_prosecutor_cannot_rule_on_appeal(): void
    {
        $chiefJustice = $this->makeChiefJustice();
        $judge        = $this->makeJudge();
        $defendant    = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $defendant->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_APPEALED,
            'evidence_level' => 75,
            'prosecutor_id'  => $chiefJustice->id,  
            'judge_id'       => $judge->id,
            'sentence'       => ['fine' => 20_000, 'jail_seconds' => 3_600],
            'committed_at'   => now()->subHours(12),
            'sentenced_at'   => now()->subHours(2),
            'appealed_at'    => now()->subHour(),
            'data'           => ['perpetrator_name' => 'x', 'suspect_names' => ['x']],
        ]);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 1])
            ->assertSessionHas('error', 'You prosecuted this case and cannot rule on its appeal.');
    }

    public function test_judge_can_override_defense_after_one_hour_since_charged_at(): void
    {
        $prosecutor = $this->makeAttorney();
        $defender   = $this->makeAttorney();
        $judge      = $this->makeJudge();
        $defendant  = $this->makeDefendant();
        
        
        $case = $this->makeChargedCase($defendant, $prosecutor, chargedAt: now()->subHours(2)->toIso8601String());

        
        $this->actingAs($defender->user)
            ->post(route('career.law.defend', $case->id), ['fee' => 2_500]);

        $offer = DefenseOffer::where('crime_record_id', $case->id)->firstOrFail();

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict'      => 'convict',
                'fine'         => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $case->status);
        $this->assertNull($case->data['defense_pending'] ?? null);
        $this->assertSame(DefenseOffer::STATUS_CANCELLED, $offer->fresh()->status);
        

        $this->assertFalse(DefenseOffer::where('crime_record_id', $case->id)->active()->exists());
    }

    public function test_judge_cannot_clear_their_own_prior_defense_assignment(): void
    {
        $prosecutor = $this->makeAttorney();
        $judge = $this->makeJudge();
        $defendant = $this->makeDefendant();
        $case = $this->makeChargedCase($defendant, $prosecutor, chargedAt: now()->subHours(2)->toIso8601String());
        $case->update(['defense_id' => $judge->id]);

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $case->id), [
                'verdict' => 'convict',
                'fine' => 20_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('error', 'You defended this case and cannot also judge it.');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_CHARGED, $case->status);
        $this->assertSame($judge->id, $case->defense_id);
    }

    public function test_can_have_three_chief_justices_in_law_career(): void
    {
        $lawCareer = \App\Models\Career::findByCode('law');
        $rank4Requirements = \App\Models\CareerRank::findForCharacter($lawCareer->id, 4);

        $cj1 = $this->makeJudge();
        $cj1->update(['career_rank' => 4, 'career_xp' => $rank4Requirements->xp_required]);

        $cj2 = $this->makeJudge();
        $cj2->update(['career_rank' => 4, 'career_xp' => $rank4Requirements->xp_required]);

        $judge3 = $this->makeJudge();
        $judge3->update(['career_xp' => $rank4Requirements->xp_required]);

        
        $this->actingAs($judge3->user)
            ->post(route('career.promote'))
            ->assertRedirect();
        
        $judge3->refresh();
        $this->assertSame(4, (int) $judge3->career_rank);

        $judge4 = $this->makeJudge();
        $judge4->update(['career_xp' => $rank4Requirements->xp_required]);

        
        $this->actingAs($judge4->user)
            ->post(route('career.promote'))
            ->assertSessionHas('error', 'This position is currently being held by your boss.');
    }

    public function test_chief_justice_step_down_quorum_logic(): void
    {
        $lawCareer = \App\Models\Career::findByCode('law');
        $rank4Requirements = \App\Models\CareerRank::findForCharacter($lawCareer->id, 4);

        $cj1 = $this->makeJudge();
        $cj1->update(['career_rank' => 4, 'career_xp' => $rank4Requirements->xp_required]);

        $cj2 = $this->makeJudge();
        $cj2->update(['career_rank' => 4, 'career_xp' => $rank4Requirements->xp_required]);

        $cj3 = $this->makeJudge();
        $cj3->update(['career_rank' => 4, 'career_xp' => $rank4Requirements->xp_required]);

        
        $this->actingAs($cj1->user)
            ->post(route('career.law.step-down'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');

        $cj2->refresh();
        
        
        $this->actingAs($cj2->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('error', 'There must be at least 2 Chief Justices available to handle appeals. You cannot step down until a qualified successor is prepared to take your place.');

        
        $successor = $this->makeJudge();
        $successor->update(['career_xp' => $rank4Requirements->xp_required]);

        
        $this->actingAs($cj2->user)
            ->post(route('career.law.step-down'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');
    }

    public function test_chief_justice_conflicted_as_original_judge_cannot_rule_on_appeal(): void
    {
        $chiefJustice = $this->makeChiefJustice();
        $defendant    = $this->makeDefendant();

        $case = CrimeRecord::create([
            'character_id'   => $defendant->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'status'         => CrimeRecord::STATUS_APPEALED,
            'evidence_level' => 75,
            'prosecutor_id'  => $this->makeAttorney()->id,
            'judge_id'       => $chiefJustice->id, 
            'sentence'       => ['fine' => 20_000, 'jail_seconds' => 3_600],
            'committed_at'   => now()->subHours(12),
            'sentenced_at'   => now()->subHours(2),
            'appealed_at'    => now()->subHour(),
            'data'           => ['perpetrator_name' => 'x', 'suspect_names' => ['x']],
        ]);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.resolve-appeal', $case->id), ['upheld' => 1])
            ->assertSessionHas('error', 'You were the original judge on this case and cannot rule on its appeal.');
    }
}
