<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Career;
use App\Models\CareerRank;
use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CrimeRecord;
use App\Models\Election;
use App\Models\Campaign;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class CareerTransitionTest extends TestCase
{
    use DatabaseTransactions;

    

    private City $city;

    private int $unemployedId;
    private int $policeId;
    private int $lawId;
    private int $politicsId;
    private int $corporationId;
    private int $bankingId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::create([
            'name'       => 'CareerCity-' . uniqid(),
            'slug'       => 'career-' . uniqid(),
            'crime_rate' => 20,
        ]);

        foreach (['unemployed', 'police', 'law', 'politics', 'corporation', 'banking'] as $code) {
            $row = DB::table('careers')->whereRaw('LOWER(code) = ?', [$code])->first();
            $this->assertNotNull($row, "Career '{$code}' must be seeded in the DB");
            $prop = $code . 'Id';
            $this->$prop = $row->id;
        }
    }

    

    private function makeCharacter(
        int $careerId,
        int $rank       = 1,
        int $careerXp   = 0,
        int $totalExp   = 10_000,
        int $cashOnHand = 50_000,
        int $cashInBank = 100_000,
    ): Character {
        $char = Character::create([
            'user_id'             => User::factory()->create()->id,
            'display_name'        => 'TC-' . uniqid(),
            'gender'              => 'male',
            'city_id'             => $this->city->id,
            'home_city_id'        => $this->city->id,
            'career_id'           => $careerId,
            'career_rank'         => $rank,
            'career_xp'           => $careerXp,
            'total_character_exp' => $totalExp,
            'health'              => 100,
            'max_health'          => 100,
            'cash_on_hand'        => $cashOnHand,
            'cash_in_bank'        => $cashInBank,
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => 500,
            'luck'         => 500,
            'offense'      => 500,
            'defense'      => 500,
            'influence'    => 50,
        ]);

        CharacterTimers::create(['character_id' => $char->id]);

        return $char;
    }

    
    private function makeOfficer(int $rank = 1, int $xp = 0): Character
    {
        return $this->makeCharacter($this->policeId, $rank, $xp);
    }

    
    private function makeLawMember(int $rank = 1, int $xp = 0): Character
    {
        return $this->makeCharacter($this->lawId, $rank, $xp);
    }

    
    private function makeReadySuperintendent(): Character
    {
        $rank4 = CareerRank::where('career_id', $this->policeId)->where('rank_level', 4)->first();
        $xp    = $rank4 ? $rank4->xp_required : 100_000;
        return $this->makeOfficer(3, $xp);
    }

    
    private function makeReadyDistrictJudge(): Character
    {
        $rank4 = CareerRank::where('career_id', $this->lawId)->where('rank_level', 4)->first();
        $xp    = $rank4 ? $rank4->xp_required : 100_000;
        return $this->makeLawMember(3, $xp);
    }

    private function makeBusiness(string $code, ?int $ownerId = null): Business
    {
        return Business::create([
            'city_id'        => $this->city->id,
            'code'           => $code,
            'name'           => ucfirst($code) . '-' . uniqid(),
            'slug'           => $code . '-' . uniqid(),
            'is_purchasable' => $ownerId === null,
            'is_active'      => true,
            'base_price'     => 1_000_000,
            'balance'        => 0,
            'sort_order'     => 1,
            'owner_id'       => $ownerId,
        ]);
    }

    
    private function giveCompletedPoliceAcademy(Character $char): void
    {
        DB::table('characters')->where('id', $char->id)->update([
            'degrees' => json_encode([
                'police_academy' => [
                    'city_id'      => $this->city->id,
                    'cycles'       => 99,
                    'completed_at' => now()->toIso8601String(),
                ],
            ]),
        ]);
        $char->refresh();
    }

    
    private function giveFinanceDegree(Character $char): void
    {
        DB::table('characters')->where('id', $char->id)->update([
            'degrees' => json_encode([
                'finance' => ['completed_at' => now()->toIso8601String(), 'cycles' => 99, 'city_id' => $this->city->id],
            ]),
        ]);
        DB::table('character_stats')->where('character_id', $char->id)->update(['influence' => 100]);
        $char->refresh();
    }

    
    private function giveLawDegree(Character $char): void
    {
        DB::table('characters')->where('id', $char->id)->update([
            'degrees' => json_encode([
                'law' => ['completed_at' => now()->toIso8601String(), 'cycles' => 99, 'city_id' => $this->city->id],
            ]),
        ]);
        $char->refresh();
    }

    private function setAsMayor(Character $char): void
    {
        $this->city->update(['mayor_id' => $char->id, 'mayor_display_name' => $char->display_name]);
    }

    
    
    

    public function test_quitCareer_sets_unemployed_career_rank1_xp0(): void
    {
        $char = $this->makeOfficer(2, 50_000);
        $char->quitCareer();
        $char->refresh();

        $this->assertSame($this->unemployedId, $char->career_id);
        $this->assertSame(1, $char->career_rank);
        $this->assertSame(0, $char->career_xp);
    }

    public function test_quitCareer_without_preserveExp_deducts_15pct_total_exp(): void
    {
        $char = $this->makeCharacter($this->policeId, totalExp: 10_000);
        $char->quitCareer(preserveExp: false);
        $char->refresh();

        $this->assertSame((int) (10_000 * 0.85), $char->total_character_exp);
    }

    public function test_quitCareer_with_preserveExp_keeps_total_exp_intact(): void
    {
        $char = $this->makeCharacter($this->policeId, totalExp: 10_000);
        $char->quitCareer(preserveExp: true);
        $char->refresh();

        $this->assertSame(10_000, $char->total_character_exp);
    }

    public function test_quitCareer_commissioner_releases_police_hq(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);
        $hq           = $this->makeBusiness('police', $commissioner->id);

        $commissioner->quitCareer();

        $hq->refresh();
        $this->assertNull($hq->owner_id);
        $this->assertTrue($hq->is_purchasable);
    }

    public function test_quitCareer_rank2_officer_does_not_release_police_hq(): void
    {
        $officer = $this->makeOfficer(2, 30_000);
        $hq      = $this->makeBusiness('police', $officer->id);

        $officer->quitCareer();

        $hq->refresh();
        
        $this->assertSame($officer->id, (int) $hq->owner_id);
    }

    public function test_quitCareer_always_releases_city_hall_regardless_of_career(): void
    {
        $char     = $this->makeCharacter($this->unemployedId);
        $cityHall = $this->makeBusiness('city-hall', $char->id);

        $char->quitCareer();

        $cityHall->refresh();
        $this->assertNull($cityHall->owner_id);
        $this->assertTrue($cityHall->is_purchasable);
    }

    
    
    

    public function test_startCareer_sets_new_career_rank1_xp500(): void
    {
        $char = $this->makeCharacter($this->unemployedId);
        $char->startCareer('law');
        $char->refresh();

        $this->assertSame($this->lawId, $char->career_id);
        $this->assertSame(1,   $char->career_rank);
        $this->assertSame(500, $char->career_xp);
    }

    public function test_startCareer_for_commissioner_releases_police_hq(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);
        $hq           = $this->makeBusiness('police', $commissioner->id);

        $commissioner->startCareer('law');

        $hq->refresh();
        $this->assertNull($hq->owner_id);
        $this->assertTrue($hq->is_purchasable);
    }

    public function test_startCareer_unknown_code_returns_false_and_leaves_career_unchanged(): void
    {
        $char = $this->makeCharacter($this->unemployedId);
        $result = $char->startCareer('not_a_real_career');

        $this->assertFalse($result);
        $char->refresh();
        $this->assertSame($this->unemployedId, $char->career_id);
    }

    
    
    

    public function test_settings_force_quit_works_for_police_rank1(): void
    {
        $char = $this->makeOfficer(1, 5_000);

        $this->actingAs($char->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $char->display_name])
            ->assertSessionHas('success');

        $char->refresh();
        $this->assertSame($this->unemployedId, $char->career_id);
    }

    public function test_settings_force_quit_works_for_police_commissioner_rank4(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);

        $this->actingAs($commissioner->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $commissioner->display_name])
            ->assertSessionHas('success');

        $commissioner->refresh();
        $this->assertSame($this->unemployedId, $commissioner->career_id);
    }

    public function test_settings_force_quit_releases_police_hq_for_commissioner(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);
        $hq           = $this->makeBusiness('police', $commissioner->id);

        $this->actingAs($commissioner->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $commissioner->display_name])
            ->assertSessionHas('success');

        $hq->refresh();
        $this->assertNull($hq->owner_id);
        $this->assertTrue($hq->is_purchasable);
    }

    public function test_settings_force_quit_works_for_law_attorney_rank2(): void
    {
        $attorney = $this->makeLawMember(2, 20_000);

        $this->actingAs($attorney->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $attorney->display_name])
            ->assertSessionHas('success');

        $attorney->refresh();
        $this->assertSame($this->unemployedId, $attorney->career_id);
    }

    public function test_settings_force_quit_works_for_law_scj_rank4(): void
    {
        $scj = $this->makeLawMember(4, 200_000);

        $this->actingAs($scj->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $scj->display_name])
            ->assertSessionHas('success');

        $scj->refresh();
        $this->assertSame($this->unemployedId, $scj->career_id);
    }

    public function test_settings_force_quit_deducts_15pct_exp_for_police(): void
    {
        $char = $this->makeCharacter($this->policeId, totalExp: 20_000);

        $this->actingAs($char->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $char->display_name])
            ->assertSessionHas('success');

        $char->refresh();
        $this->assertSame((int) (20_000 * 0.85), $char->total_character_exp);
    }

    public function test_settings_force_quit_deducts_15pct_exp_for_law(): void
    {
        $char = $this->makeCharacter($this->lawId, totalExp: 20_000);

        $this->actingAs($char->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $char->display_name])
            ->assertSessionHas('success');

        $char->refresh();
        $this->assertSame((int) (20_000 * 0.85), $char->total_character_exp);
    }

    public function test_settings_force_quit_is_blocked_while_serving_as_mayor(): void
    {
        $char = $this->makeCharacter($this->politicsId, rank: 1);
        $this->setAsMayor($char);

        $this->actingAs($char->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $char->display_name])
            ->assertSessionHasErrors();

        $char->refresh();
        $this->assertSame($this->politicsId, $char->career_id, 'Career must be unchanged');
    }

    public function test_settings_force_quit_is_blocked_while_in_corporation(): void
    {
        $char = $this->makeCharacter($this->corporationId);
        $corp = Corporation::create([
            'name' => 'Corp-' . uniqid(), 'ceo_id' => $char->id,
            'home_city_id' => $this->city->id, 'slush_fund' => 0, 'strength' => 100,
        ]);
        DB::table('characters')->where('id', $char->id)->update(['corporation_id' => $corp->id]);

        $this->actingAs($char->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $char->display_name])
            ->assertSessionHas('error');

        $char->refresh();
        $this->assertSame($this->corporationId, $char->career_id);
    }

    public function test_settings_force_quit_rejects_wrong_confirmation_name(): void
    {
        $char = $this->makeOfficer();

        $this->actingAs($char->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => 'CompletelyWrongName'])
            ->assertSessionHasErrors('confirmation_name');

        $char->refresh();
        $this->assertSame($this->policeId, $char->career_id);
    }

    
    
    

    public function test_police_step_down_rank1_succeeds_and_preserves_xp(): void
    {
        $officer = $this->makeCharacter($this->policeId, 1, 5_000, totalExp: 15_000);
        $this->makeBusiness('police'); 

        $this->actingAs($officer->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('success');

        $officer->refresh();
        $this->assertSame($this->unemployedId, $officer->career_id);
        $this->assertSame(15_000, $officer->total_character_exp, 'Step-down must preserve total exp');
    }

    public function test_police_step_down_rank3_succeeds_and_preserves_xp(): void
    {
        $super = $this->makeCharacter($this->policeId, 3, 80_000, totalExp: 25_000);
        $this->makeBusiness('police');

        $this->actingAs($super->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('success');

        $super->refresh();
        $this->assertSame($this->unemployedId, $super->career_id);
        $this->assertSame(25_000, $super->total_character_exp);
    }

    public function test_police_commissioner_step_down_blocked_without_ready_successor(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);
        

        $this->actingAs($commissioner->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('error');

        $commissioner->refresh();
        $this->assertSame($this->policeId, $commissioner->career_id, 'Career must be unchanged');
    }

    public function test_police_commissioner_step_down_succeeds_with_ready_successor(): void
    {
        $commissioner = $this->makeCharacter($this->policeId, 4, 200_000, totalExp: 30_000);
        $this->makeReadySuperintendent(); 

        $this->actingAs($commissioner->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('success');

        $commissioner->refresh();
        $this->assertSame($this->unemployedId, $commissioner->career_id);
        $this->assertSame(30_000, $commissioner->total_character_exp, 'Step-down must preserve XP');
    }

    public function test_police_commissioner_step_down_releases_hq_when_successful(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);
        $hq           = $this->makeBusiness('police', $commissioner->id);
        $this->makeReadySuperintendent();

        $this->actingAs($commissioner->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('success');

        $hq->refresh();
        $this->assertNull($hq->owner_id);
        $this->assertTrue($hq->is_purchasable);
    }

    public function test_police_step_down_blocked_outside_home_city(): void
    {
        $otherCity = City::create(['name' => 'Away-' . uniqid(), 'slug' => 'away-' . uniqid(), 'crime_rate' => 0]);
        $commissioner = $this->makeOfficer(4, 200_000);
        $this->makeReadySuperintendent();

        
        DB::table('characters')->where('id', $commissioner->id)->update(['city_id' => $otherCity->id]);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('error');

        $commissioner->refresh();
        $this->assertSame($this->policeId, $commissioner->career_id);
    }

    
    
    

    public function test_commissioner_dismiss_sets_target_to_unemployed(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);
        $officer      = $this->makeOfficer(2, 30_000);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.dismiss'), ['character_id' => $officer->id])
            ->assertSessionHas('success');

        $officer->refresh();
        $this->assertSame($this->unemployedId, $officer->career_id);
    }

    public function test_commissioner_dismiss_preserves_target_total_exp(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);
        $officer      = $this->makeCharacter($this->policeId, 2, 30_000, totalExp: 20_000);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.dismiss'), ['character_id' => $officer->id])
            ->assertSessionHas('success');

        $officer->refresh();
        $this->assertSame(20_000, $officer->total_character_exp, 'Dismissal must preserve XP');
    }

    public function test_commissioner_cannot_dismiss_another_commissioner(): void
    {
        $commissioner1 = $this->makeOfficer(4, 200_000);
        $commissioner2 = $this->makeOfficer(4, 200_000);

        $this->actingAs($commissioner1->user)
            ->post(route('career.police.dismiss'), ['character_id' => $commissioner2->id])
            ->assertSessionHas('error');

        $commissioner2->refresh();
        $this->assertSame($this->policeId, $commissioner2->career_id);
    }

    public function test_commissioner_cannot_dismiss_self(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.dismiss'), ['character_id' => $commissioner->id])
            ->assertSessionHas('error');
    }

    public function test_non_commissioner_cannot_dismiss(): void
    {
        $inspector = $this->makeOfficer(2, 30_000);
        $target    = $this->makeOfficer(1, 0);

        $this->actingAs($inspector->user)
            ->post(route('career.police.dismiss'), ['character_id' => $target->id])
            ->assertSessionHas('error');

        $target->refresh();
        $this->assertSame($this->policeId, $target->career_id);
    }

    public function test_dismiss_officer_abandons_their_active_investigation(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);
        $detective    = $this->makeOfficer(2, 30_000);

        
        $case = CrimeRecord::create([
            'character_id'   => $this->makeCharacter($this->unemployedId)->id,
            'city_id'        => $this->city->id,
            'type'           => CrimeRecord::TYPE_ASSAULT,
            'severity'       => CrimeRecord::SEV_MISDEMEANOR,
            'evidence_level' => 0,
            'status'         => CrimeRecord::STATUS_INVESTIGATING,
            'detective_id'   => $detective->id,
            'data'           => [],
            'committed_at'   => now(),
        ]);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.dismiss'), ['character_id' => $detective->id])
            ->assertSessionHas('success');

        $case->refresh();
        $this->assertSame(CrimeRecord::STATUS_OPEN, $case->status,
            'Case must be returned to OPEN when detective is dismissed');
        $this->assertNull($case->detective_id,
            'detective_id must be cleared when officer is dismissed');
    }

    
    
    

    public function test_law_attorney_step_down_succeeds_and_preserves_xp(): void
    {
        $attorney = $this->makeCharacter($this->lawId, 2, 20_000, totalExp: 18_000);

        $this->actingAs($attorney->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $attorney->refresh();
        $this->assertSame($this->unemployedId, $attorney->career_id);
        $this->assertSame(18_000, $attorney->total_character_exp);
    }

    public function test_law_scj_step_down_blocked_without_successor(): void
    {
        $scj = $this->makeLawMember(4, 200_000);
        

        $this->actingAs($scj->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('error');

        $scj->refresh();
        $this->assertSame($this->lawId, $scj->career_id);
    }

    public function test_law_scj_step_down_succeeds_with_ready_district_judge(): void
    {
        $scj = $this->makeCharacter($this->lawId, 4, 200_000, totalExp: 30_000);
        $this->makeReadyDistrictJudge();

        $this->actingAs($scj->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $scj->refresh();
        $this->assertSame($this->unemployedId, $scj->career_id);
        $this->assertSame(30_000, $scj->total_character_exp, 'Step-down must preserve XP');
    }

    public function test_law_scj_step_down_succeeds_with_two_other_cjs(): void
    {
        $scj   = $this->makeCharacter($this->lawId, 4, 200_000, totalExp: 30_000);
        $other1 = $this->makeLawMember(4, 200_000);
        $other2 = $this->makeLawMember(4, 200_000);
        

        $this->actingAs($scj->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $scj->refresh();
        $this->assertSame($this->unemployedId, $scj->career_id);
    }

    public function test_law_step_down_blocked_outside_home_city(): void
    {
        $otherCity = City::create(['name' => 'LawAway-' . uniqid(), 'slug' => 'lawaway-' . uniqid(), 'crime_rate' => 0]);
        $scj = $this->makeLawMember(4, 200_000);
        $this->makeReadyDistrictJudge();

        DB::table('characters')->where('id', $scj->id)->update(['city_id' => $otherCity->id]);

        $this->actingAs($scj->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('error');

        $scj->refresh();
        $this->assertSame($this->lawId, $scj->career_id);
    }

    
    
    

    public function test_scj_dismiss_sets_target_to_unemployed(): void
    {
        $scj      = $this->makeLawMember(4, 200_000);
        $attorney = $this->makeLawMember(2, 20_000);

        $this->actingAs($scj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $attorney->id])
            ->assertSessionHas('success');

        $attorney->refresh();
        $this->assertSame($this->unemployedId, $attorney->career_id);
    }

    public function test_scj_dismiss_preserves_target_total_exp(): void
    {
        
        $scj      = $this->makeLawMember(4, 200_000);
        $attorney = $this->makeCharacter($this->lawId, 2, 20_000, totalExp: 22_000);

        $this->actingAs($scj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $attorney->id])
            ->assertSessionHas('success');

        $attorney->refresh();
        $this->assertSame(22_000, $attorney->total_character_exp,
            'Law dismissal must preserve XP — was previously broken (used preserveExp: false)');
    }

    public function test_scj_cannot_dismiss_another_scj(): void
    {
        $scj1 = $this->makeLawMember(4, 200_000);
        $scj2 = $this->makeLawMember(4, 200_000);

        $this->actingAs($scj1->user)
            ->post(route('career.law.dismiss'), ['character_id' => $scj2->id])
            ->assertSessionHas('error');

        $scj2->refresh();
        $this->assertSame($this->lawId, $scj2->career_id);
    }

    public function test_scj_cannot_dismiss_self(): void
    {
        $scj = $this->makeLawMember(4, 200_000);

        $this->actingAs($scj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $scj->id])
            ->assertSessionHas('error');
    }

    public function test_non_scj_cannot_dismiss_law_members(): void
    {
        $attorney1 = $this->makeLawMember(2, 20_000);
        $attorney2 = $this->makeLawMember(2, 20_000);

        $this->actingAs($attorney1->user)
            ->post(route('career.law.dismiss'), ['character_id' => $attorney2->id])
            ->assertSessionHas('error');

        $attorney2->refresh();
        $this->assertSame($this->lawId, $attorney2->career_id);
    }

    public function test_scj_cannot_dismiss_only_eligible_successor(): void
    {
        
        
        $scj       = $this->makeLawMember(4, 200_000);
        $successor = $this->makeReadyDistrictJudge();

        $this->actingAs($scj->user)
            ->post(route('career.law.dismiss'), ['character_id' => $successor->id])
            ->assertSessionHas('error');

        $successor->refresh();
        $this->assertSame($this->lawId, $successor->career_id,
            'The only eligible successor must be protected from dismissal');
    }

    
    
    

    public function test_mayor_cannot_quit_career_via_settings(): void
    {
        $mayor = $this->makeCharacter($this->politicsId);
        $this->setAsMayor($mayor);

        $this->actingAs($mayor->user)
            ->post(route('settings.quit-career'), ['confirmation_name' => $mayor->display_name])
            ->assertSessionHasErrors();

        $mayor->refresh();
        $this->assertSame($this->politicsId, $mayor->career_id, 'Mayor career must be unchanged');
    }

    public function test_mayor_cannot_start_new_career_via_university(): void
    {
        $mayor = $this->makeCharacter($this->politicsId);
        $this->setAsMayor($mayor);
        $this->giveLawDegree($mayor);

        $university = $this->makeBusiness('university');

        $this->actingAs($mayor->user)
            ->post(route('city.university.start-career', $this->city), ['degree_code' => 'law'])
            ->assertSessionHas('error');

        $mayor->refresh();
        $this->assertSame($this->politicsId, $mayor->career_id);
    }

    public function test_mayor_cannot_graduate_into_police(): void
    {
        $mayor = $this->makeCharacter($this->politicsId);
        $this->setAsMayor($mayor);
        $this->giveCompletedPoliceAcademy($mayor);
        $this->makeBusiness('police');

        $this->actingAs($mayor->user)
            ->post(route('city.police.graduate', $this->city))
            ->assertSessionHas('error');

        $mayor->refresh();
        $this->assertSame($this->politicsId, $mayor->career_id);
    }

    public function test_mayor_cannot_found_corporation(): void
    {
        $mayor = $this->makeCharacter($this->politicsId, cashOnHand: 3_000_000, cashInBank: 3_000_000);
        $this->setAsMayor($mayor);
        $this->giveFinanceDegree($mayor);

        $this->actingAs($mayor->user)
            ->post(route('corporation.found'), ['name' => 'MayorCorp'])
            ->assertSessionHas('error');

        $mayor->refresh();
        $this->assertNull($mayor->corporation_id);
    }

    public function test_mayor_cannot_accept_corporation_invite(): void
    {
        $ceo  = $this->makeCharacter($this->corporationId);
        $corp = Corporation::create([
            'name' => 'InvCorp-' . uniqid(), 'ceo_id' => $ceo->id,
            'home_city_id' => $this->city->id, 'slush_fund' => 0, 'strength' => 100,
        ]);
        DB::table('characters')->where('id', $ceo->id)->update(['corporation_id' => $corp->id]);

        $mayor = $this->makeCharacter($this->politicsId);
        $this->setAsMayor($mayor);

        $journal = CharacterJournal::create([
            'character_id' => $mayor->id,
            'type'         => 'corporation_invite_request',
            'data'         => ['corporation_id' => $corp->id, 'corporation_name' => $corp->name],
            'is_read'      => false,
        ]);

        $this->actingAs($mayor->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('error');

        $mayor->refresh();
        $this->assertNull($mayor->corporation_id, 'Mayor must not be able to join a corporation');
    }

    public function test_term_expiry_removes_mayor_clears_city_hall_and_politics_career(): void
    {
        $mayor    = $this->makeCharacter($this->politicsId);
        $cityHall = $this->makeBusiness('city-hall', $mayor->id);
        $this->setAsMayor($mayor);

        Election::create([
            'city_id'            => $this->city->id,
            'cycle_number'       => 1,
            'status'             => 'completed',
            'registration_start' => now()->subDays(12),
            'registration_end'   => now()->subDays(10),
            'voting_start'       => now()->subDays(10),
            'voting_end'         => now()->subDays(9),
            'winner_id'          => $mayor->id,
            'total_votes'        => 1,
            'term_start'         => now()->subDays(9),
            'term_end'           => now()->subMinutes(5), 
        ]);

        
        $this->artisan('elections:process')->assertExitCode(0);

        $this->city->refresh();
        $this->assertNull($this->city->mayor_id,
            'mayor_id must be null after term expiry');
        $this->assertNull($this->city->mayor_display_name,
            'mayor_display_name must be null after term expiry');

        $cityHall->refresh();
        $this->assertNull($cityHall->owner_id,
            'City Hall ownership must be cleared when mayor term expires');
        $this->assertTrue($cityHall->is_purchasable);

        $mayor->refresh();
        $this->assertSame($this->unemployedId, $mayor->career_id,
            'Politics career must be removed on term expiry');
    }

    public function test_page_visit_triggers_term_expiry_via_advanceElectionState(): void
    {
        
        $mayor    = $this->makeCharacter($this->politicsId);
        $cityHall = $this->makeBusiness('city-hall', $mayor->id);
        $this->setAsMayor($mayor);

        Election::create([
            'city_id'            => $this->city->id,
            'cycle_number'       => 1,
            'status'             => 'completed',
            'registration_start' => now()->subDays(12),
            'registration_end'   => now()->subDays(10),
            'voting_start'       => now()->subDays(10),
            'voting_end'         => now()->subDays(9),
            'winner_id'          => $mayor->id,
            'total_votes'        => 1,
            'term_start'         => now()->subDays(9),
            'term_end'           => now()->subMinutes(5),
        ]);

        $visitor = $this->makeCharacter($this->unemployedId);
        $this->actingAs($visitor->user)
            ->get(route('city.election.index', $this->city->slug))
            ->assertSuccessful();

        $this->city->refresh();
        $this->assertNull($this->city->mayor_id,
            'Page-visit advanceElectionState must remove expired mayor');

        $cityHall->refresh();
        $this->assertNull($cityHall->owner_id);
    }

    
    
    

    public function test_police_officer_blocked_from_founding_corporation(): void
    {
        $officer = $this->makeCharacter($this->policeId, cashOnHand: 3_000_000, cashInBank: 3_000_000);
        $this->giveFinanceDegree($officer);

        $this->actingAs($officer->user)
            ->post(route('corporation.found'), ['name' => 'CopCorp'])
            ->assertSessionHas('error');

        $officer->refresh();
        $this->assertNull($officer->corporation_id);
        $this->assertSame($this->policeId, $officer->career_id);
    }

    public function test_law_member_blocked_from_founding_corporation(): void
    {
        $attorney = $this->makeCharacter($this->lawId, cashOnHand: 3_000_000, cashInBank: 3_000_000);
        $this->giveFinanceDegree($attorney);

        $this->actingAs($attorney->user)
            ->post(route('corporation.found'), ['name' => 'LawCorp'])
            ->assertSessionHas('error');

        $attorney->refresh();
        $this->assertNull($attorney->corporation_id);
        $this->assertSame($this->lawId, $attorney->career_id);
    }

    public function test_police_officer_blocked_from_accepting_corporation_invite(): void
    {
        $ceo  = $this->makeCharacter($this->corporationId);
        $corp = Corporation::create([
            'name' => 'CopInvCorp-' . uniqid(), 'ceo_id' => $ceo->id,
            'home_city_id' => $this->city->id, 'slush_fund' => 0, 'strength' => 100,
        ]);
        DB::table('characters')->where('id', $ceo->id)->update(['corporation_id' => $corp->id]);

        $officer = $this->makeOfficer(2, 30_000);

        $journal = CharacterJournal::create([
            'character_id' => $officer->id,
            'type'         => 'corporation_invite_request',
            'data'         => ['corporation_id' => $corp->id, 'corporation_name' => $corp->name],
            'is_read'      => false,
        ]);

        $this->actingAs($officer->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('error');

        $officer->refresh();
        $this->assertNull($officer->corporation_id, 'Police cannot join corporation');
        $this->assertSame($this->policeId, $officer->career_id);
    }

    public function test_law_member_blocked_from_accepting_corporation_invite(): void
    {
        $ceo  = $this->makeCharacter($this->corporationId);
        $corp = Corporation::create([
            'name' => 'LawInvCorp-' . uniqid(), 'ceo_id' => $ceo->id,
            'home_city_id' => $this->city->id, 'slush_fund' => 0, 'strength' => 100,
        ]);
        DB::table('characters')->where('id', $ceo->id)->update(['corporation_id' => $corp->id]);

        $attorney = $this->makeLawMember(2, 20_000);

        $journal = CharacterJournal::create([
            'character_id' => $attorney->id,
            'type'         => 'corporation_invite_request',
            'data'         => ['corporation_id' => $corp->id, 'corporation_name' => $corp->name],
            'is_read'      => false,
        ]);

        $this->actingAs($attorney->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('error');

        $attorney->refresh();
        $this->assertNull($attorney->corporation_id, 'Law member cannot join corporation');
        $this->assertSame($this->lawId, $attorney->career_id);
    }

    
    
    

    public function test_university_start_career_blocked_for_active_police_officer(): void
    {
        $officer = $this->makeOfficer(2, 30_000);
        $this->giveLawDegree($officer);
        $this->makeBusiness('university');

        $this->actingAs($officer->user)
            ->post(route('city.university.start-career', $this->city), ['degree_code' => 'law'])
            ->assertSessionHas('error');

        $officer->refresh();
        $this->assertSame($this->policeId, $officer->career_id);
    }

    public function test_university_start_career_blocked_for_active_law_member(): void
    {
        $attorney = $this->makeLawMember(2, 20_000);
        $this->makeBusiness('university');

        
        DB::table('characters')->where('id', $attorney->id)->update([
            'degrees' => json_encode([
                'finance' => ['completed_at' => now()->toIso8601String(), 'cycles' => 99, 'city_id' => $this->city->id],
            ]),
        ]);

        $this->actingAs($attorney->user)
            ->post(route('city.university.start-career', $this->city), ['degree_code' => 'finance'])
            ->assertSessionHas('error');

        $attorney->refresh();
        $this->assertSame($this->lawId, $attorney->career_id);
    }

    public function test_university_start_career_blocked_for_mayor(): void
    {
        $mayor = $this->makeCharacter($this->politicsId);
        $this->setAsMayor($mayor);
        $this->giveLawDegree($mayor);
        $this->makeBusiness('university');

        $this->actingAs($mayor->user)
            ->post(route('city.university.start-career', $this->city), ['degree_code' => 'law'])
            ->assertSessionHas('error');

        $mayor->refresh();
        $this->assertSame($this->politicsId, $mayor->career_id);
    }

    
    
    

    public function test_police_hq_graduate_blocked_for_active_law_member(): void
    {
        $attorney = $this->makeLawMember(2, 20_000);
        $this->giveCompletedPoliceAcademy($attorney);
        $this->makeBusiness('police');

        $this->actingAs($attorney->user)
            ->post(route('city.police.graduate', $this->city))
            ->assertSessionHas('error');

        $attorney->refresh();
        $this->assertSame($this->lawId, $attorney->career_id);
    }

    public function test_police_hq_graduate_blocked_for_corporation_member(): void
    {
        $corpMember = $this->makeCharacter($this->corporationId);
        $corp       = Corporation::create([
            'name' => 'GradCorp-' . uniqid(), 'ceo_id' => $corpMember->id,
            'home_city_id' => $this->city->id, 'slush_fund' => 0, 'strength' => 100,
        ]);
        DB::table('characters')->where('id', $corpMember->id)->update(['corporation_id' => $corp->id]);
        $this->giveCompletedPoliceAcademy($corpMember);
        $this->makeBusiness('police');

        $this->actingAs($corpMember->user)
            ->post(route('city.police.graduate', $this->city))
            ->assertSessionHas('error');

        $corpMember->refresh();
        $this->assertSame($this->corporationId, $corpMember->career_id);
    }

    
    
    

    public function test_corporation_quit_detaches_without_quitting_career(): void
    {
        $ceo    = $this->makeCharacter($this->corporationId);
        $member = $this->makeCharacter($this->corporationId);

        $corp = Corporation::create([
            'name' => 'QuitCorp-' . uniqid(), 'ceo_id' => $ceo->id,
            'home_city_id' => $this->city->id, 'slush_fund' => 0, 'strength' => 100,
        ]);
        DB::table('characters')->where('id', $ceo->id)->update(['corporation_id' => $corp->id]);
        DB::table('characters')->where('id', $member->id)->update([
            'corporation_id' => $corp->id, 'corporation_position' => 'cfo',
        ]);

        $this->actingAs($member->user)
            ->post(route('career.corporation.quit'))
            ->assertSessionHas('success');

        $member->refresh();
        $this->assertNull($member->corporation_id);
        $this->assertNull($member->corporation_position);
        $this->assertSame($this->corporationId, $member->career_id);
    }

    public function test_corporation_quit_preserves_total_exp(): void
    {
        $ceo    = $this->makeCharacter($this->corporationId);
        $member = $this->makeCharacter($this->corporationId, totalExp: 25_000);

        $corp = Corporation::create([
            'name' => 'ExpCorp-' . uniqid(), 'ceo_id' => $ceo->id,
            'home_city_id' => $this->city->id, 'slush_fund' => 0, 'strength' => 100,
        ]);
        DB::table('characters')->where('id', $ceo->id)->update(['corporation_id' => $corp->id]);
        DB::table('characters')->where('id', $member->id)->update(['corporation_id' => $corp->id]);

        $this->actingAs($member->user)
            ->post(route('career.corporation.quit'))
            ->assertSessionHas('success');

        $member->refresh();
        $this->assertSame(25_000, $member->total_character_exp,
            'Corp quit must preserve total exp');
    }

    public function test_corporation_kick_detaches_without_quitting_career(): void
    {
        $ceo    = $this->makeCharacter($this->corporationId, rank: 4, careerXp: 200_000);
        $member = $this->makeCharacter($this->corporationId);

        $corp = Corporation::create([
            'name' => 'KickCorp-' . uniqid(), 'ceo_id' => $ceo->id,
            'home_city_id' => $this->city->id, 'slush_fund' => 0, 'strength' => 100,
        ]);
        DB::table('characters')->where('id', $ceo->id)->update(['corporation_id' => $corp->id]);
        DB::table('characters')->where('id', $member->id)->update([
            'corporation_id' => $corp->id, 'corporation_position' => 'vp',
        ]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.kick'), ['member_id' => $member->id])
            ->assertSessionHas('success');

        $member->refresh();
        $this->assertNull($member->corporation_id);
        $this->assertNull($member->corporation_position);
        $this->assertSame($this->corporationId, $member->career_id);
    }

    
    
    

    public function test_election_win_gives_politics_career_and_releases_police_hq(): void
    {
        
        
        
        
        
        
        
        $commissioner = $this->makeOfficer(4, 200_000);

        $electionCity = City::create([
            'name' => 'ElecCity-' . uniqid(), 'slug' => 'elec-' . uniqid(), 'crime_rate' => 0,
        ]);
        DB::table('characters')->where('id', $commissioner->id)
            ->update(['home_city_id' => $electionCity->id, 'city_id' => $electionCity->id]);
        $commissioner->refresh();

        
        $hq = Business::create([
            'city_id'        => $electionCity->id,
            'code'           => 'police',
            'name'           => 'Police-' . uniqid(),
            'slug'           => 'police-' . uniqid(),
            'is_purchasable' => false,
            'is_active'      => true,
            'base_price'     => 1_000_000,
            'balance'        => 0,
            'sort_order'     => 1,
            'owner_id'       => $commissioner->id,
        ]);

        $election = Election::create([
            'city_id'            => $electionCity->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(4),
            'registration_end'   => now()->subDays(2),
            'voting_start'       => now()->subDays(2),
            'voting_end'         => now()->subHour(),
            'total_votes'        => 1,
        ]);

        Campaign::create([
            'election_id'   => $election->id,
            'candidate_id'  => $commissioner->id,
            'city_id'       => $electionCity->id,
            'manifesto'     => 'Law and order.',
            'campaign_fund' => 2_000_000,
            'votes'         => 1,
            'status'        => 'active',
        ]);

        $election->finalize();

        $commissioner->refresh();
        $this->assertSame($this->politicsId, $commissioner->career_id,
            'Election winner must receive politics career');

        $electionCity->refresh();
        $this->assertSame($commissioner->id, $electionCity->mayor_id);
        $this->assertSame($commissioner->display_name, $electionCity->mayor_display_name);

        $hq->refresh();
        $this->assertNull($hq->owner_id,
            'Police HQ must be released when commissioner wins election');
        $this->assertTrue($hq->is_purchasable);
    }

    
    
    

    public function test_quitCareer_releases_investigating_case(): void
    {
        $officer = $this->makeOfficer(2);

        $record = CrimeRecord::create([
            'character_id'  => null,
            'city_id'       => $this->city->id,
            'type'          => CrimeRecord::TYPE_ASSAULT,
            'severity'      => CrimeRecord::SEV_MISDEMEANOR,
            'evidence_level'=> 40,
            'status'        => CrimeRecord::STATUS_INVESTIGATING,
            'detective_id'  => $officer->id,
            'committed_at'  => now(),
        ]);

        $officer->quitCareer(false);

        $record->refresh();
        $this->assertSame(CrimeRecord::STATUS_OPEN, $record->status,
            'Case must revert to open when detective quits career');
        $this->assertNull($record->detective_id,
            'detective_id must be cleared when detective quits career');
    }

    public function test_dismissal_releases_investigating_case(): void
    {
        
        
        
        $commissioner = $this->makeOfficer(4, 200_000);
        $this->makeBusiness('police', $commissioner->id);

        $officer = $this->makeOfficer(1);

        $record = CrimeRecord::create([
            'character_id'  => null,
            'city_id'       => $this->city->id,
            'type'          => CrimeRecord::TYPE_ASSAULT,
            'severity'      => CrimeRecord::SEV_MISDEMEANOR,
            'evidence_level'=> 30,
            'status'        => CrimeRecord::STATUS_INVESTIGATING,
            'detective_id'  => $officer->id,
            'committed_at'  => now(),
        ]);

        $this->actingAs($commissioner->user)
            ->post(route('police.dismiss'), ['character_id' => $officer->id])
            ->assertSessionHas('success');

        $record->refresh();
        $this->assertSame(CrimeRecord::STATUS_OPEN, $record->status,
            'Dismissed officer case must revert to open');
        $this->assertNull($record->detective_id);
    }

    public function test_settings_quit_releases_investigating_case(): void
    {
        $officer = $this->makeOfficer(2);

        $record = CrimeRecord::create([
            'character_id'  => null,
            'city_id'       => $this->city->id,
            'type'          => CrimeRecord::TYPE_ASSAULT,
            'severity'      => CrimeRecord::SEV_FELONY,
            'evidence_level'=> 55,
            'status'        => CrimeRecord::STATUS_INVESTIGATING,
            'detective_id'  => $officer->id,
            'committed_at'  => now(),
        ]);

        $this->actingAs($officer->user)
            ->post(route('settings.quit-career'), ['character_name' => $officer->display_name])
            ->assertSessionHas('success');

        $record->refresh();
        $this->assertSame(CrimeRecord::STATUS_OPEN, $record->status,
            'Settings quit must release the active case');
        $this->assertNull($record->detective_id);
    }

    public function test_police_step_down_releases_investigating_case(): void
    {
        
        
        $officer = $this->makeOfficer(2);

        $record = CrimeRecord::create([
            'character_id'  => null,
            'city_id'       => $this->city->id,
            'type'          => CrimeRecord::TYPE_ASSAULT,
            'severity'      => CrimeRecord::SEV_MISDEMEANOR,
            'evidence_level'=> 40,
            'status'        => CrimeRecord::STATUS_INVESTIGATING,
            'detective_id'  => $officer->id,
            'committed_at'  => now(),
        ]);

        $officer->quitCareer(true);

        $record->refresh();
        $this->assertSame(CrimeRecord::STATUS_OPEN, $record->status,
            'Step-down must release the active case');
        $this->assertNull($record->detective_id);
    }

    public function test_non_investigating_cases_are_not_touched_on_quit(): void
    {
        
        
        $officer = $this->makeOfficer(2);

        $referred = CrimeRecord::create([
            'character_id'  => null,
            'city_id'       => $this->city->id,
            'type'          => CrimeRecord::TYPE_ASSAULT,
            'severity'      => CrimeRecord::SEV_MISDEMEANOR,
            'evidence_level'=> 40,
            'status'        => CrimeRecord::STATUS_REFERRED,
            'detective_id'  => $officer->id,
            'committed_at'  => now(),
        ]);

        $officer->quitCareer(false);

        $referred->refresh();
        $this->assertSame(CrimeRecord::STATUS_REFERRED, $referred->status,
            'Referred case must not be reset — it is already in the Law queue');
        
        $this->assertSame($officer->id, $referred->detective_id);
    }

    
    
    

    public function test_death_clears_mayor_and_removes_city_hall(): void
    {
        $mayor    = $this->makeCharacter($this->politicsId);
        $cityHall = $this->makeBusiness('city-hall', $mayor->id);
        $this->setAsMayor($mayor);

        $mayor->kill('Assassination', 'Died in the line of duty');

        $this->city->refresh();
        $this->assertNull($this->city->mayor_id,
            'Death must remove the character from mayor_id');

        $cityHall->refresh();
        $this->assertNull($cityHall->owner_id,
            'Death must release City Hall ownership');
    }

    public function test_death_clears_police_career(): void
    {
        $commissioner = $this->makeOfficer(4, 200_000);
        $hq           = $this->makeBusiness('police', $commissioner->id);

        $commissioner->kill('Combat', 'Fell in battle');

        $commissioner->refresh();
        
        $raw = DB::table('characters')->where('id', $commissioner->id)->first();
        $this->assertSame($this->unemployedId, $raw->career_id,
            'Deceased officer must have unemployed career');

        $hq->refresh();
        $this->assertNull($hq->owner_id,
            'Death must release police HQ from a commissioner');
    }
}
