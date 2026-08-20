<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CrimeRecord;
use App\Models\User;
use App\Services\CrimeService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class CrimeSystemTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;
    private int  $policeCareerId;
    private int  $lawCareerId;

    

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::create([
            'name'       => 'TestCity-' . uniqid(),
            'slug'       => 'testcity-' . uniqid(),
            'crime_rate' => 50,
        ]);

        $policeCareer = DB::table('careers')->whereRaw('LOWER(code) = ?', ['police'])->first();
        $lawCareer    = DB::table('careers')->whereRaw('LOWER(code) = ?', ['law'])->first();
        $this->assertNotNull($policeCareer, 'Police career must exist in DB');
        $this->assertNotNull($lawCareer,    'Law career must exist in DB');
        $this->policeCareerId = $policeCareer->id;
        $this->lawCareerId    = $lawCareer->id;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } catch (\PDOException $e) {
            if (
                ! str_contains($e->getMessage(), 'terminating connection') &&
                ! str_contains($e->getMessage(), 'SSL SYSCALL') &&
                ! str_contains($e->getMessage(), 'server closed the connection')
            ) {
                throw $e;
            }
            DB::reconnect();
        }
    }

    

    private function makeCharacter(
        int $careerId,
        int $rank         = 1,
        int $careerXp     = 0,
        int $intelligence = 1_000,
        int $luck         = 1_000,
        int $offense      = 1_000,
        int $defense      = 1_000,
        int $cashOnHand   = 50_000,
        int $cashInBank   = 100_000,
    ): Character {
        $user = User::factory()->create();

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'TestChar-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $this->city->id,
            'home_city_id' => $this->city->id,
            'career_id'    => $careerId,
            'career_rank'  => $rank,
            'career_xp'    => $careerXp,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => $cashInBank,
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => $intelligence,
            'luck'         => $luck,
            'offense'      => $offense,
            'defense'      => $defense,
            'influence'    => 0,
        ]);

        CharacterTimers::create([
            'character_id'   => $char->id,
            'next_action_at' => 0,
        ]);

        return $char;
    }

    
    private function makePerpetrator(): Character
    {
        return $this->makeCharacter($this->policeCareerId);
    }

    private function makeDetective(int $rank = 2): Character
    {
        return $this->makeCharacter($this->policeCareerId, $rank, careerXp: 30_000);
    }

    private function makeAttorney(): Character
    {
        return $this->makeCharacter($this->lawCareerId, 2, intelligence: 5_000, luck: 2_000);
    }

    private function makeJudge(): Character
    {
        return $this->makeCharacter($this->lawCareerId, 3);
    }

    private function makeChiefJustice(): Character
    {
        return $this->makeCharacter($this->lawCareerId, 4);
    }

    

    
    private function resetActionTimer(Character $character): void
    {
        CharacterTimers::where('character_id', $character->id)
            ->update(['next_action_at' => 0]);
    }

    
    private function passDefenceWindow(CrimeRecord $crime): void
    {
        CrimeRecord::where('id', $crime->id)
            ->update(['updated_at' => now()->subHours(2)]);
        $crime->refresh();
    }

    
    private function makeTestCorporation(Character $ceo): Corporation
    {
        return Corporation::create([
            'name'         => 'TestCorp-' . uniqid(),
            'ceo_id'       => $ceo->id,
            'home_city_id' => $this->city->id,
            'slush_fund'   => 0,
            'strength'     => 100,
        ]);
    }

    

    
    private function makeReferredCase(
        string $severity = CrimeRecord::SEV_FELONY
    ): array {
        $perp      = $this->makePerpetrator();
        $detective = $this->makeDetective();
        $crime     = CrimeService::assault($perp, $this->makePerpetrator(), $this->city->id, $severity);

        $this->actingAs($detective->user)
            ->post(route('career.police.take', $crime->id));

        $this->resetActionTimer($detective);

        $this->actingAs($detective->user)
            ->post(route('career.police.refer', $crime->id), [
                'suspect_names' => [$perp->display_name],
            ]);

        $crime->refresh();
        $this->assertSame(
            CrimeRecord::STATUS_REFERRED,
            $crime->status,
            'Pipeline builder: makeReferredCase expected STATUS_REFERRED'
        );

        return [$detective, $crime, $perp];
    }

    
    private function makeChargedCase(
        string $severity = CrimeRecord::SEV_FELONY
    ): array {
        [$detective, $crime, $perp] = $this->makeReferredCase($severity);
        $prosecutor = $this->makeAttorney();

        $this->actingAs($prosecutor->user)
            ->post(route('career.law.prosecute', $crime->id));

        $crime->refresh();
        $this->assertSame(
            CrimeRecord::STATUS_CHARGED,
            $crime->status,
            'Pipeline builder: makeChargedCase expected STATUS_CHARGED'
        );

        return [$detective, $crime, $prosecutor, $perp];
    }

    
    private function makeSentencedCase(
        string $severity = CrimeRecord::SEV_FELONY,
        int    $fine      = 5_000,
        int    $jailSecs  = 3_600,
    ): CrimeRecord {
        [, $crime] = $this->makeChargedCase($severity);
        $this->passDefenceWindow($crime);

        $judge = $this->makeJudge();
        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $crime->id), [
                'verdict'      => 'convict',
                'fine'         => $fine,
                'jail_seconds' => $jailSecs,
            ]);

        $crime->refresh();
        $this->assertSame(
            CrimeRecord::STATUS_SENTENCED,
            $crime->status,
            'Pipeline builder: makeSentencedCase expected STATUS_SENTENCED'
        );

        return $crime;
    }

    
    private function makeAppealedCase(): CrimeRecord
    {
        $crime   = $this->makeSentencedCase(CrimeRecord::SEV_FELONY);
        $convict = Character::find($crime->character_id);

        $this->actingAs($convict->user)
            ->post(route('law.appeal', $crime->id))
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame(
            CrimeRecord::STATUS_APPEALED,
            $crime->status,
            'Pipeline builder: makeAppealedCase expected STATUS_APPEALED'
        );

        return $crime;
    }

    
    
    

    public function test_detective_can_take_open_case(): void
    {
        $detective = $this->makeDetective();
        $crime     = CrimeService::assault(
            $this->makePerpetrator(),
            $this->makePerpetrator(),
            $this->city->id
        );

        $this->actingAs($detective->user)
            ->post(route('career.police.take', $crime->id))
            ->assertRedirect();

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $crime->status);
        $this->assertSame($detective->id, $crime->detective_id);
    }

    public function test_detective_cannot_take_second_case_while_active(): void
    {
        $detective = $this->makeDetective();
        $crime1    = CrimeService::assault($this->makePerpetrator(), $this->makePerpetrator(), $this->city->id);
        $crime2    = CrimeService::assault($this->makePerpetrator(), $this->makePerpetrator(), $this->city->id);

        $this->actingAs($detective->user)->post(route('career.police.take', $crime1->id));

        $this->actingAs($detective->user)
            ->post(route('career.police.take', $crime2->id))
            ->assertSessionHas('error');

        $crime2->refresh();
        $this->assertNull($crime2->detective_id);
    }

    public function test_detective_abandoning_non_capital_case_deletes_it(): void
    {
        $detective = $this->makeDetective();
        $crime     = CrimeService::assault($this->makePerpetrator(), $this->makePerpetrator(), $this->city->id);

        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));

        $this->actingAs($detective->user)
            ->post(route('career.police.abandon'))
            ->assertSessionHas('success', 'You have abandoned this case and managed to shelve it somewhere no one can find it.');

        $this->assertDatabaseMissing('crime_records', [
            'id' => $crime->id,
        ]);
    }

    
    
    

    public function test_detective_can_refer_case_with_single_suspect(): void
    {
        $perp      = $this->makePerpetrator();
        $detective = $this->makeDetective();
        $crime     = CrimeService::assault($perp, $this->makePerpetrator(), $this->city->id);

        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));
        $this->resetActionTimer($detective);

        $this->actingAs($detective->user)
            ->post(route('career.police.refer', $crime->id), [
                'suspect_names' => [$perp->display_name],
            ])
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $crime->status);
        $this->assertContains($perp->display_name, $crime->data['suspect_names']);
    }

    public function test_detective_can_refer_case_with_multiple_suspect_names(): void
    {
        $detective = $this->makeDetective();
        $perp1     = $this->makePerpetrator();
        $perp2     = $this->makePerpetrator();
        $perp3     = $this->makePerpetrator();
        $crime     = CrimeService::assault($perp1, $this->makePerpetrator(), $this->city->id);

        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));
        $this->resetActionTimer($detective);

        
        $names = [$perp1->display_name, $perp2->display_name, $perp3->display_name];
        $this->actingAs($detective->user)
            ->post(route('career.police.refer', $crime->id), ['suspect_names' => $names])
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame($names, $crime->data['suspect_names']);
    }

    
    
    

    public function test_perpetrator_is_blocked_from_taking_their_own_case(): void
    {
        
        
        $perp  = $this->makeDetective(1); 
        $crime = CrimeService::assault($perp, $this->makePerpetrator(), $this->city->id);

        $this->actingAs($perp->user)
            ->post(route('career.police.take', $crime->id))
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertNull($crime->detective_id);
    }

    public function test_perpetrator_cannot_prosecute_their_own_case(): void
    {
        [$detective, $crime, $perp] = $this->makeReferredCase();

        
        Character::where('id', $perp->id)->update([
            'career_id'   => $this->lawCareerId,
            'career_rank' => 2,
        ]);
        $perp->refresh();

        $this->actingAs($perp->user)
            ->post(route('career.law.prosecute', $crime->id))
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertNull($crime->prosecutor_id);
    }

    
    
    

    public function test_attorney_can_prosecute_referred_case(): void
    {
        [, $crime] = $this->makeReferredCase();
        $attorney  = $this->makeAttorney();

        $this->actingAs($attorney->user)
            ->post(route('career.law.prosecute', $crime->id))
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_CHARGED, $crime->status);
        $this->assertSame($attorney->id, $crime->prosecutor_id);
    }

    
    
    

    public function test_judge_is_blocked_within_one_hour_defence_window(): void
    {
        
        [, $crime] = $this->makeChargedCase();
        $judge = $this->makeJudge();

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $crime->id), [
                'verdict'      => 'convict',
                'fine'         => 1_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_CHARGED, $crime->status);
    }

    public function test_judge_can_convict_and_sentence_uncontested_case(): void
    {
        [, $crime] = $this->makeChargedCase();
        $this->passDefenceWindow($crime);
        $judge = $this->makeJudge();

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $crime->id), [
                'verdict'      => 'convict',
                'fine'         => 5_000,
                'jail_seconds' => 3_600,
            ])
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $crime->status);
        $this->assertSame(5_000, $crime->sentence['fine']);
        $this->assertSame(3_600, $crime->sentence['jail_seconds']);
        $this->assertNotNull($crime->sentenced_at);
    }

    public function test_judge_can_acquit_uncontested_case(): void
    {
        [, $crime] = $this->makeChargedCase();
        $this->passDefenceWindow($crime);
        $judge = $this->makeJudge();

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-verdict', $crime->id), ['verdict' => 'acquit'])
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_ACQUITTED, $crime->status);
    }

    public function test_prosecutor_cannot_also_judge_the_same_case(): void
    {
        [, $crime, $prosecutor] = $this->makeChargedCase();
        $this->passDefenceWindow($crime);

        
        Character::where('id', $prosecutor->id)->update(['career_rank' => 3]);

        $this->actingAs($prosecutor->user)
            ->post(route('career.law.judge-verdict', $crime->id), [
                'verdict'      => 'convict',
                'fine'         => 1_000,
                'jail_seconds' => 1_800,
            ])
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_CHARGED, $crime->status);
    }

    
    
    

    public function test_judge_can_sentence_a_convicted_case(): void
    {
        
        
        [, $crime] = $this->makeChargedCase();
        $crime->update(['status' => CrimeRecord::STATUS_CONVICTED]);

        $judge = $this->makeJudge();

        $this->actingAs($judge->user)
            ->post(route('career.law.judge-sentence', $crime->id), [
                'verdict'      => 'sentence',
                'fine'         => 10_000,
                'jail_seconds' => 7_200,
            ])
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $crime->status);
        $this->assertSame(10_000, $crime->sentence['fine']);
        $this->assertSame(7_200,  $crime->sentence['jail_seconds']);
    }

    
    
    

    public function test_sentenced_perpetrator_can_turn_themselves_in(): void
    {
        $crime   = $this->makeSentencedCase();
        $convict = Character::find($crime->character_id);

        $this->actingAs($convict->user)
            ->post(route('city.police.turn-in', $this->city), ['case_id' => $crime->id])
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_CLOSED, $crime->status);
        $this->assertNotNull($crime->resolved_at);
    }

    public function test_turn_in_deducts_fine_from_cash_on_hand(): void
    {
        $crime   = $this->makeSentencedCase(fine: 5_000, jailSecs: 0);
        $convict = Character::find($crime->character_id);
        $before  = (int) $convict->cash_on_hand;

        $this->actingAs($convict->user)
            ->post(route('city.police.turn-in', $this->city), ['case_id' => $crime->id]);

        $convict->refresh();
        $this->assertSame($before - 5_000, (int) $convict->cash_on_hand);
    }

    public function test_turn_in_sets_jail_timer(): void
    {
        $crime   = $this->makeSentencedCase(fine: 0, jailSecs: 3_600);
        $convict = Character::find($crime->character_id);

        $this->actingAs($convict->user)
            ->post(route('city.police.turn-in', $this->city), ['case_id' => $crime->id]);

        $convict->refresh();
        $this->assertTrue(
            $convict->timers?->jail_until?->isFuture(),
            'jail_until should be set in the future after turn-in with jail time'
        );
    }

    public function test_unrelated_character_cannot_turn_in_someone_elses_case(): void
    {
        $crime    = $this->makeSentencedCase();
        $stranger = $this->makePerpetrator();

        $this->actingAs($stranger->user)
            ->post(route('city.police.turn-in', $this->city), ['case_id' => $crime->id])
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $crime->status);
    }

    
    
    

    public function test_misdemeanor_conviction_cannot_be_appealed(): void
    {
        $crime   = $this->makeSentencedCase(CrimeRecord::SEV_MISDEMEANOR);
        $convict = Character::find($crime->character_id);

        $this->actingAs($convict->user)
            ->post(route('law.appeal', $crime->id))
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $crime->status);
    }

    public function test_felony_conviction_can_be_appealed(): void
    {
        $crime   = $this->makeSentencedCase(CrimeRecord::SEV_FELONY);
        $convict = Character::find($crime->character_id);

        $this->actingAs($convict->user)
            ->post(route('law.appeal', $crime->id))
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_APPEALED, $crime->status);
        $this->assertNotNull($crime->appealed_at);
    }

    public function test_chief_justice_can_uphold_appeal(): void
    {
        $crime = $this->makeAppealedCase();
        $cj    = $this->makeChiefJustice();

        $this->actingAs($cj->user)
            ->post(route('career.law.resolve-appeal', $crime->id), ['upheld' => true])
            ->assertSessionHas('success');

        $crime->refresh();
        
        $this->assertSame(CrimeRecord::STATUS_CLOSED, $crime->status);
    }

    public function test_chief_justice_can_overturn_appeal(): void
    {
        $crime = $this->makeAppealedCase();
        $cj    = $this->makeChiefJustice();

        $this->actingAs($cj->user)
            ->post(route('career.law.resolve-appeal', $crime->id), ['upheld' => false])
            ->assertSessionHas('success');

        $crime->refresh();
        
        $this->assertSame(CrimeRecord::STATUS_ACQUITTED, $crime->status);
    }

    public function test_sentencing_judge_cannot_resolve_their_own_cases_appeal(): void
    {
        [, $crime] = $this->makeChargedCase();
        $this->passDefenceWindow($crime);

        $judge = $this->makeJudge();
        $this->actingAs($judge->user)->post(route('career.law.judge-verdict', $crime->id), [
            'verdict'      => 'convict',
            'fine'         => 1_000,
            'jail_seconds' => 3_600,
        ]);
        $crime->refresh();

        $convict = Character::find($crime->character_id);
        $this->actingAs($convict->user)->post(route('law.appeal', $crime->id));
        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_APPEALED, $crime->status);

        
        Character::where('id', $judge->id)->update(['career_rank' => 4]);

        $this->actingAs($judge->user)
            ->post(route('career.law.resolve-appeal', $crime->id), ['upheld' => true])
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_APPEALED, $crime->status);
    }

    
    
    

    public function test_investment_fraud_stores_all_participant_ids_and_victim_name(): void
    {
        $ceo     = $this->makePerpetrator();
        $member1 = $this->makePerpetrator();
        $member2 = $this->makePerpetrator();
        $corp    = $this->makeTestCorporation($ceo);

        $crime = CrimeService::investmentFraud(
            $ceo,
            $corp,
            [$ceo->id, $member1->id, $member2->id],
            $this->city->id,
            500_000,
        );

        $this->assertSame($ceo->id, $crime->character_id);
        $this->assertSame([$ceo->id, $member1->id, $member2->id], $crime->data['participants']);
        
        $this->assertSame('bank depositors', $crime->data['victim_name'] ?? null);
        $this->assertSame(3, count($crime->data['participants']));
    }

    public function test_all_participants_receive_sentencing_journal(): void
    {
        $perp    = $this->makePerpetrator();
        $coPerp  = $this->makePerpetrator();

        
        $crime = CrimeService::kidnapping(
            $perp,
            [$perp->id, $coPerp->id],
            $this->makePerpetrator(),
            $this->city->id,
            100_000,
        );

        $detective  = $this->makeDetective();
        $prosecutor = $this->makeAttorney();
        $judge      = $this->makeJudge();

        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));
        $this->resetActionTimer($detective);
        $this->actingAs($detective->user)->post(route('career.police.refer', $crime->id), [
            'suspect_names' => [$perp->display_name, $coPerp->display_name],
        ]);
        $crime->refresh();

        $this->actingAs($prosecutor->user)->post(route('career.law.prosecute', $crime->id));
        $crime->refresh();
        $this->passDefenceWindow($crime);

        $this->actingAs($judge->user)->post(route('career.law.judge-verdict', $crime->id), [
            'verdict'      => 'convict',
            'fine'         => 2_000,
            'jail_seconds' => 1_800,
        ]);
        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $crime->status);

        
        foreach ([$perp->id, $coPerp->id] as $pid) {
            $this->assertTrue(
                CharacterJournal::where('character_id', $pid)
                    ->where('type', 'case_sentenced')
                    ->whereRaw("(data->>'case_id')::int = ?", [$crime->id])
                    ->exists(),
                "Character {$pid} is missing a case_sentenced journal for case #{$crime->id}"
            );
        }
    }

    public function test_co_conspirator_can_turn_in_at_police_hq(): void
    {
        $perp   = $this->makePerpetrator();
        $coPerp = $this->makePerpetrator();

        $crime = CrimeService::kidnapping(
            $perp,
            [$perp->id, $coPerp->id],
            $this->makePerpetrator(),
            $this->city->id,
            100_000,
        );

        
        $crime->update([
            'status'       => CrimeRecord::STATUS_SENTENCED,
            'sentenced_at' => now()->utc(),
            'sentence'     => ['fine' => 1_000, 'jail_seconds' => 0],
        ]);

        $this->actingAs($coPerp->user)
            ->post(route('city.police.turn-in', $this->city), ['case_id' => $crime->id])
            ->assertSessionHas('success');
    }

    
    
    

    public function test_rug_pull_has_no_participants(): void
    {
        $crime = CrimeService::rugPull(
            $this->makePerpetrator(),
            $this->makePerpetrator(),
            $this->city->id,
            500
        );

        $this->assertSame([], $crime->data['participants'] ?? []);
        $this->assertSame(CrimeRecord::SEV_MISDEMEANOR, $crime->severity);
    }

    public function test_assault_has_no_participants(): void
    {
        $crime = CrimeService::assault(
            $this->makePerpetrator(),
            $this->makePerpetrator(),
            $this->city->id
        );

        $this->assertSame([], $crime->data['participants'] ?? []);
    }

    
    
    

    public function test_money_laundering_victim_name_stored_in_data_jsonb(): void
    {
        $crime = CrimeService::moneyLaundering(
            $this->makePerpetrator(),
            $this->city->id,
            5_000,
            'Test Shop',
            false
        );

        
        $this->assertSame(
            'The Public',
            $crime->data['victim_name'] ?? null,
            'victim_name must be stored in the data JSONB column for the formatters to read it'
        );
    }

    public function test_money_laundering_with_banker_stores_participant(): void
    {
        $perp   = $this->makePerpetrator();
        $banker = $this->makePerpetrator();

        $crime = CrimeService::moneyLaundering(
            $perp,
            $this->city->id,
            5_000,
            'Test Shop',
            false,
            $banker
        );

        $this->assertContains($banker->id, $crime->data['participants'] ?? []);
        $this->assertSame($banker->display_name, $crime->data['banker_name'] ?? null);
    }

    
    
    

    
    public function test_perpetrator_name_never_in_police_formatted_payload(): void
    {
        [$detective] = $this->makeReferredCase();

        $response = $this->actingAs($detective->user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get(route('career.police'));

        $response->assertOk();
        $data  = json_decode($response->getContent(), true);
        $props = $data['props'] ?? [];

        $currentCase = $props['current_case'] ? [$props['current_case']] : [];

        $allCases = array_merge(
            $props['open_cases']  ?? [],
            $currentCase,
            $props['my_cases']    ?? [],
            $props['crime_log']   ?? [],
        );

        foreach ($allCases as $case) {
            $this->assertArrayNotHasKey(
                'perpetrator_name',
                $case,
                "perpetrator_name must never appear in any police case payload (case id={$case['id']})"
            );
        }
    }

    
    public function test_investigation_notes_are_null_for_uninvolved_law_attorney(): void
    {
        [$detective, $crime] = $this->makeReferredCase();

        
        $crime->update([
            'data' => array_merge($crime->data ?? [], [
                'investigation_notes' => [
                    ['note' => 'Witness said name starts with J', 'at' => now()->toIso8601String()],
                ],
            ]),
        ]);

        
        $uninvolved = $this->makeAttorney();

        $response = $this->actingAs($uninvolved->user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->get(route('career.law'));

        $response->assertOk();
        $data     = json_decode($response->getContent(), true);
        $referred = $data['props']['referred_cases'] ?? [];
        $match    = collect($referred)->firstWhere('id', $crime->id);

        
        if ($match !== null) {
            $this->assertNull(
                $match['investigation_notes'],
                'investigation_notes must be null for attorneys not assigned to this case'
            );
        } else {
            
            $this->assertTrue(true);
        }
    }

    
    
    

    
    public function test_detective_cannot_name_victim_as_suspect(): void
    {
        $perp      = $this->makePerpetrator();
        $victim    = $this->makePerpetrator();
        $detective = $this->makeDetective();
        $crime     = CrimeService::assault($perp, $victim, $this->city->id);

        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));
        $this->resetActionTimer($detective);

        $this->actingAs($detective->user)
            ->post(route('career.police.refer', $crime->id), [
                'suspect_names' => [$victim->display_name],
            ])
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $crime->status,
            'Case must remain INVESTIGATING when victim is listed as suspect');
    }

    
    public function test_mixed_payload_with_victim_is_fully_rejected(): void
    {
        $perp      = $this->makePerpetrator();
        $victim    = $this->makePerpetrator();
        $other     = $this->makePerpetrator();
        $detective = $this->makeDetective();
        $crime     = CrimeService::assault($perp, $victim, $this->city->id);

        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));
        $this->resetActionTimer($detective);

        $this->actingAs($detective->user)
            ->post(route('career.police.refer', $crime->id), [
                'suspect_names' => [$other->display_name, $victim->display_name],
            ])
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $crime->status);
    }

    
    public function test_detective_cannot_name_themselves_as_suspect(): void
    {
        $perp      = $this->makePerpetrator();
        $detective = $this->makeDetective();
        $crime     = CrimeService::assault($perp, $this->makePerpetrator(), $this->city->id);

        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));
        $this->resetActionTimer($detective);

        $this->actingAs($detective->user)
            ->post(route('career.police.refer', $crime->id), [
                'suspect_names' => [$detective->display_name],
            ])
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $crime->status);
    }

    
    public function test_duplicate_suspect_names_are_silently_deduplicated(): void
    {
        $perp      = $this->makePerpetrator();
        $detective = $this->makeDetective();
        $crime     = CrimeService::assault($perp, $this->makePerpetrator(), $this->city->id);

        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));
        $this->resetActionTimer($detective);

        
        $this->actingAs($detective->user)
            ->post(route('career.police.refer', $crime->id), [
                'suspect_names' => [$perp->display_name, $perp->display_name, $perp->display_name],
            ])
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame(
            [$perp->display_name],
            $crime->data['suspect_names'],
            'Duplicate names must be collapsed to a single entry'
        );
    }

    
    public function test_nonexistent_suspect_name_is_rejected(): void
    {
        $detective = $this->makeDetective();
        $crime     = CrimeService::assault($this->makePerpetrator(), $this->makePerpetrator(), $this->city->id);

        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));
        $this->resetActionTimer($detective);

        $this->actingAs($detective->user)
            ->post(route('career.police.refer', $crime->id), [
                'suspect_names' => ['DefinitelyNotARealCharacter'],
            ])
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $crime->status);
    }

    
    public function test_detective_from_foreign_city_cannot_refer(): void
    {
        $foreignCity = City::create([
            'name'       => 'ForeignCity-' . uniqid(),
            'slug'       => 'foreign-' . uniqid(),
            'crime_rate' => 50,
        ]);

        $perp      = $this->makePerpetrator(); 
        $crime     = CrimeService::assault($perp, $this->makePerpetrator(), $this->city->id);

        
        $foreignDetective = $this->makeCharacter($this->policeCareerId, 2, careerXp: 30_000);
        Character::where('id', $foreignDetective->id)
            ->update(['home_city_id' => $foreignCity->id]);
        $foreignDetective->refresh();

        
        $crime->update([
            'status'      => CrimeRecord::STATUS_INVESTIGATING,
            'detective_id' => $foreignDetective->id,
        ]);

        $this->resetActionTimer($foreignDetective);

        $this->actingAs($foreignDetective->user)
            ->post(route('career.police.refer', $crime->id), [
                'suspect_names' => [$perp->display_name],
            ])
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $crime->status,
            'Case must remain INVESTIGATING when detective is from a foreign city');
    }

    
    public function test_rank_1_officer_cannot_take_felony_case(): void
    {
        $cadet = $this->makeDetective(1); 
        $crime = CrimeService::assault(
            $this->makePerpetrator(),
            $this->makePerpetrator(),
            $this->city->id,
            CrimeRecord::SEV_FELONY
        );

        $this->actingAs($cadet->user)
            ->post(route('career.police.take', $crime->id))
            ->assertSessionHas('error');

        $crime->refresh();
        $this->assertNull($crime->detective_id);
    }

    
    public function test_rank_1_officer_can_take_misdemeanor_case(): void
    {
        $cadet = $this->makeDetective(1); 
        $crime = CrimeService::rugPull(
            $this->makePerpetrator(),
            $this->makePerpetrator(),
            $this->city->id,
            500
        );

        $this->actingAs($cadet->user)
            ->post(route('career.police.take', $crime->id))
            ->assertSessionHas('success');

        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $crime->status);
        $this->assertSame($cadet->id, $crime->detective_id);
    }

    
    
    

    public function test_applySentenceToCharacter_deducts_fine_from_cash_on_hand_first(): void
    {
        $char  = $this->makeCharacter($this->policeCareerId, cashOnHand: 10_000, cashInBank: 5_000);
        $crime = CrimeRecord::create([
            'character_id'   => $char->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'evidence_level' => 50,
            'status'         => CrimeRecord::STATUS_SENTENCED,
            'sentence'       => ['fine' => 8_000, 'jail_seconds' => 0],
            'sentenced_at'   => now(),
            'data'           => [],
            'committed_at'   => now(),
        ]);

        $crime->applySentenceToCharacter($char);
        $char->refresh();

        $this->assertSame(2_000, (int) $char->cash_on_hand, 'Fine must drain cash_on_hand first');
        $this->assertSame(5_000, (int) $char->cash_in_bank, 'Bank untouched when cash covers fine');
    }

    public function test_applySentenceToCharacter_spills_into_bank_when_cash_insufficient(): void
    {
        $char  = $this->makeCharacter($this->policeCareerId, cashOnHand: 2_000, cashInBank: 10_000);
        $crime = CrimeRecord::create([
            'character_id'   => $char->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'evidence_level' => 50,
            'status'         => CrimeRecord::STATUS_SENTENCED,
            'sentence'       => ['fine' => 5_000, 'jail_seconds' => 0],
            'sentenced_at'   => now(),
            'data'           => [],
            'committed_at'   => now(),
        ]);

        $crime->applySentenceToCharacter($char);
        $char->refresh();

        $this->assertSame(0,     (int) $char->cash_on_hand, 'Cash on hand must be zeroed');
        $this->assertSame(7_000, (int) $char->cash_in_bank, 'Remainder must come from bank');
    }

    public function test_applySentenceToCharacter_sets_jail_timer(): void
    {
        $char  = $this->makeCharacter($this->policeCareerId);
        $crime = CrimeRecord::create([
            'character_id'   => $char->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'evidence_level' => 50,
            'status'         => CrimeRecord::STATUS_SENTENCED,
            'sentence'       => ['fine' => 0, 'jail_seconds' => 3_600],
            'sentenced_at'   => now(),
            'data'           => [],
            'committed_at'   => now(),
        ]);

        $crime->applySentenceToCharacter($char);
        $char->load('timers');

        $this->assertTrue($char->timers->jail_until->isFuture(), 'jail_until must be in the future');
    }

    public function test_applySentenceToCharacter_does_not_set_jail_when_zero_seconds(): void
    {
        $char  = $this->makeCharacter($this->policeCareerId);
        $crime = CrimeRecord::create([
            'character_id'   => $char->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_FELONY,
            'evidence_level' => 50,
            'status'         => CrimeRecord::STATUS_SENTENCED,
            'sentence'       => ['fine' => 100, 'jail_seconds' => 0],
            'sentenced_at'   => now(),
            'data'           => [],
            'committed_at'   => now(),
        ]);

        $crime->applySentenceToCharacter($char);
        $char->load('timers');

        $this->assertFalse($char->isJailed(), 'Zero jail_seconds must not set a jail timer');
    }

    
    
    

    public function test_full_pipeline_open_to_sentenced_uncontested(): void
    {
        $perp      = $this->makePerpetrator();
        $detective = $this->makeDetective();
        $attorney  = $this->makeAttorney();
        $judge     = $this->makeJudge();

        $crime = CrimeService::assault($perp, $this->makePerpetrator(), $this->city->id, CrimeRecord::SEV_FELONY);
        $this->assertSame(CrimeRecord::STATUS_OPEN, $crime->status);

        $this->actingAs($detective->user)->post(route('career.police.take', $crime->id));
        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_INVESTIGATING, $crime->status);

        $this->resetActionTimer($detective);
        $this->actingAs($detective->user)->post(route('career.police.refer', $crime->id), [
            'suspect_names' => [$perp->display_name],
        ]);
        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $crime->status);

        $this->actingAs($attorney->user)->post(route('career.law.prosecute', $crime->id));
        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_CHARGED, $crime->status);

        $this->passDefenceWindow($crime);

        $this->actingAs($judge->user)->post(route('career.law.judge-verdict', $crime->id), [
            'verdict'      => 'convict',
            'fine'         => 5_000,
            'jail_seconds' => 1_800,
        ]);
        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $crime->status);
        $this->assertSame(5_000, $crime->sentence['fine']);
        $this->assertSame(1_800, $crime->sentence['jail_seconds']);
    }

    public function test_full_pipeline_sentenced_to_closed_via_turn_in(): void
    {
        $crime   = $this->makeSentencedCase(fine: 3_000, jailSecs: 0);
        $convict = Character::find($crime->character_id);
        $before  = (int) $convict->cash_on_hand;

        $this->actingAs($convict->user)
            ->post(route('city.police.turn-in', $this->city), ['case_id' => $crime->id])
            ->assertSessionHas('success');

        $crime->refresh();
        $convict->refresh();

        $this->assertSame(CrimeRecord::STATUS_CLOSED, $crime->status);
        $this->assertSame($before - 3_000, (int) $convict->cash_on_hand);
    }

    public function test_full_pipeline_with_appeal_upheld(): void
    {
        $crime   = $this->makeSentencedCase(CrimeRecord::SEV_FELONY);
        $convict = Character::find($crime->character_id);
        $cj      = $this->makeChiefJustice();

        $this->actingAs($convict->user)->post(route('law.appeal', $crime->id));
        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_APPEALED, $crime->status);

        $this->actingAs($cj->user)
            ->post(route('career.law.resolve-appeal', $crime->id), ['upheld' => true]);
        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_CLOSED, $crime->status);
    }

    public function test_full_pipeline_with_appeal_overturned(): void
    {
        $crime   = $this->makeSentencedCase(CrimeRecord::SEV_FELONY);
        $convict = Character::find($crime->character_id);
        $cj      = $this->makeChiefJustice();

        $this->actingAs($convict->user)->post(route('law.appeal', $crime->id));
        $crime->refresh();

        $this->actingAs($cj->user)
            ->post(route('career.law.resolve-appeal', $crime->id), ['upheld' => false]);
        $crime->refresh();
        $this->assertSame(CrimeRecord::STATUS_ACQUITTED, $crime->status);
    }
}
