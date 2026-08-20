<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Career;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\CityHallAide;
use App\Models\CrimeRecord;
use App\Models\Election;
use App\Models\MayorTerm;
use App\Models\User;
use App\Http\Controllers\CityHallController;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class MayorSystemTest extends TestCase
{
    use DatabaseTransactions;

    private int $politicsCareerId;
    private int $policeCareerId;
    private int $lawCareerId;
    private int $unemployedCareerId;

    protected function setUp(): void
    {
        try {
            parent::setUp();
        } catch (\PDOException $e) {
            if (
                str_contains($e->getMessage(), 'server closed the connection') ||
                str_contains($e->getMessage(), 'SSL SYSCALL') ||
                str_contains($e->getMessage(), 'terminating connection')
            ) {
                DB::reconnect();
                parent::setUp();
            } else {
                throw $e;
            }
        }
        Cache::flush();

        $politics   = DB::table('careers')->where('code', 'politics')->first();
        $police     = DB::table('careers')->where('code', 'police')->first();
        $law        = DB::table('careers')->where('code', 'law')->first();
        $unemployed = DB::table('careers')->where('code', 'unemployed')->first();

        $this->assertNotNull($politics, 'politics career must exist');
        $this->assertNotNull($unemployed, 'unemployed career must exist');

        $this->politicsCareerId   = $politics->id;
        $this->policeCareerId     = $police?->id ?? 1;
        $this->lawCareerId        = $law?->id ?? 1;
        $this->unemployedCareerId = $unemployed->id;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } catch (\PDOException $e) {
            if (
                !str_contains($e->getMessage(), 'terminating connection') &&
                !str_contains($e->getMessage(), 'SSL SYSCALL') &&
                !str_contains($e->getMessage(), 'server closed the connection')
            ) {
                throw $e;
            }
            DB::reconnect();
        }
    }

    private function makeCity(string $label = 'test'): City
    {
        return City::create([
            'name'       => 'TestCity-' . $label,
            'slug'       => 'test-' . $label . '-' . uniqid(),
            'crime_rate' => 50,
        ]);
    }

    private function makeEligibleCandidate(City $city, ?User $user = null): Character
    {
        $user ??= $this->retryFactory(fn() => User::factory()->create());

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'Candidate-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $city->id,
            'home_city_id' => $city->id,
            'career_id'    => $this->politicsCareerId,
            'career_rank'  => Election::MIN_RANK_REQUIRED,
            'career_xp'    => 100_000,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => Election::APPLICATION_FEE + 100_000,
            'cash_in_bank' => Election::CAMPAIGN_FUND_REQUIREMENT + 500_000,
        ]);

        CharacterStats::create(['character_id' => $char->id, 'influence' => 50]);
        CharacterTimers::create(['character_id' => $char->id]);

        return $char;
    }

    private function makeMayor(City $city, ?User $user = null): Character
    {
        $user ??= $this->retryFactory(fn() => User::factory()->create());

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'Mayor-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $city->id,
            'home_city_id' => $city->id,
            'career_id'    => $this->politicsCareerId,
            'career_rank'  => 1,
            'career_xp'    => 1000,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => 500_000,
            'cash_in_bank' => 1_000_000,
        ]);

        CharacterStats::create(['character_id' => $char->id, 'influence' => 100]);
        CharacterTimers::create(['character_id' => $char->id]);

        $city->setMayor($char);

        return $char;
    }

    private function makeActiveTerm(City $city, Character $mayor): MayorTerm
    {
        $election = Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'completed',
            'registration_start' => now()->subDays(10),
            'registration_end'   => now()->subDays(8),
            'voting_start'       => now()->subDays(8),
            'voting_end'         => now()->subDays(7),
            'winner_id'          => $mayor->id,
            'total_votes'        => 1,
        ]);

        return MayorTerm::create([
            'city_id'        => $city->id,
            'character_id'   => $mayor->id,
            'election_id'    => $election->id,
            'started_at'     => now(),
            'period'         => 1,
            'city_funds'     => 500_000,
            'assembly_score' => 100,
            'budget_law'     => 25,
            'budget_corp_reg'=> 25,
            'budget_services'=> 25,
            'budget_bonds'   => 25,
            'ledger'         => [],
            'actions_log'    => [
                'suppressions' => [],
                'pardons'      => [],
                'dismissals'   => [],
                'audits'       => [],
                'bond'         => null,
                'period_income'=> ['tax' => 0, 'fine' => 0, 'bond' => 0, 'audit' => 0, 'other' => 0],
                'period_drain' => 0,
                'last_drained_at' => now()->getTimestamp(),
                'pending_drift'   => 0.0,
                'bond_settled_this_period' => null,
                'policies' => [
                    'income_tax_rate'      => 5,
                    'corporate_tax_rate'   => 0,
                    'corp_regulation_active' => false,
                    'bonds_active'         => false,
                    'death_sentence_active'=> false,
                ],
            ],
        ]);
    }

    private function retryFactory(callable $fn, int $attempts = 3): mixed
    {
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $fn();
            } catch (\PDOException $e) {
                if (
                    str_contains($e->getMessage(), 'server closed the connection') ||
                    str_contains($e->getMessage(), 'SSL SYSCALL') ||
                    str_contains($e->getMessage(), 'terminating connection')
                ) {
                    DB::reconnect();
                    $last = $e;
                    continue;
                }
                throw $e;
            }
        }
        throw $last;
    }

    private function makeResident(City $city, ?User $user = null, ?int $careerId = null): Character
    {
        $user ??= $this->retryFactory(fn() => User::factory()->create());

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'Resident-' . uniqid(),
            'gender'       => 'female',
            'city_id'      => $city->id,
            'home_city_id' => $city->id,
            'career_id'    => $careerId ?? $this->unemployedCareerId,
            'career_rank'  => 1,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => 10_000,
            'cash_in_bank' => 10_000,
        ]);

        CharacterStats::create(['character_id' => $char->id]);
        CharacterTimers::create(['character_id' => $char->id]);

        return $char;
    }

    private function makePardonableRecord(City $city, Character $character): CrimeRecord
    {
        return CrimeRecord::create([
            'city_id' => $city->id,
            'character_id' => $character->id,
            'type' => CrimeRecord::TYPE_RUG_PULL,
            'severity' => CrimeRecord::SEV_MISDEMEANOR,
            'status' => CrimeRecord::STATUS_SENTENCED,
            'committed_at' => now()->subDay(),
            'data' => [],
        ]);
    }

    private function makeVoter(City $city, ?User $user = null): Character
    {
        return $this->makeResident($city, $user);
    }

    private function registerCampaign(Election $election, Character $candidate, int $votes = 0): Campaign
    {
        return Campaign::create([
            'election_id'   => $election->id,
            'candidate_id'  => $candidate->id,
            'city_id'       => $election->city_id,
            'manifesto'     => str_repeat('A', 60),
            'campaign_fund' => Election::CAMPAIGN_FUND_REQUIREMENT,
            'votes'         => $votes,
            'status'        => 'active',
        ]);
    }

    private function makeExpiredVoting(City $city): Election
    {
        return Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(4),
            'registration_end'   => now()->subDays(2),
            'voting_start'       => now()->subDays(2),
            'voting_end'         => now()->subHour(),
        ]);
    }

    private function makeCorporateMember(City $city, ?User $user = null): Character
    {
        $user ??= User::factory()->create();

        $corpId = DB::table('corporations')->insertGetId([
            'name'       => 'Corp-' . uniqid(),
            'slug'       => 'corp-' . uniqid(),
            'city_id'    => $city->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $char = $this->makeResident($city, $user);
        $char->update(['corporation_id' => $corpId]);

        return $char->fresh();
    }

    
    
    

    public function test_full_term_lifecycle_from_election_to_new_election(): void
    {
        $city = $this->makeCity('lifecycle');
        $candidate = $this->makeEligibleCandidate($city);
        $voter = $this->makeVoter($city);

        $election = Election::startForCity($city);
        $this->assertEquals('registration', $election->status);

        $campaign = $this->registerCampaign($election, $candidate);
        $this->assertDatabaseHas('campaigns', ['candidate_id' => $candidate->id, 'status' => 'active']);

        $election->update([
            'status'           => 'voting',
            'voting_start'     => now()->subDay(),
            'voting_end'       => now()->subHour(),
            'registration_end' => now()->subDays(2),
            'total_votes'      => 1,
        ]);

        $election->finalize();
        $election->refresh();

        $this->assertEquals('completed', $election->status);
        $this->assertEquals($candidate->id, $election->winner_id);
        $this->assertEquals($candidate->id, $city->fresh()->mayor_id);

        $term = MayorTerm::where('city_id', $city->id)
            ->where('character_id', $candidate->id)
            ->whereNull('ended_at')
            ->first();
        $this->assertNotNull($term);
        $this->assertEquals(1, $term->period);

        \App\Services\MayorService::removeMayor($term, $city, MayorTerm::END_TERM_COMPLETE);

        $this->assertNotNull($term->fresh()->ended_at);
        $this->assertNull($city->fresh()->mayor_id);

        $this->assertDatabaseHas('elections', [
            'city_id' => $city->id,
            'status'  => 'registration',
            'cycle_number' => 2,
        ]);
    }

    public function test_term_tick_increments_period_and_drains_funds(): void
    {
        $city = $this->makeCity('tick');
        $mayor = $this->makeMayor($city);
        
        
        $term = $this->makeOverdueTerm($city, $mayor);
        $term->update(['city_funds' => 500_000, 'assembly_score' => 100]);

        $initialPeriod = $term->period;
        $initialFunds  = $term->city_funds;

        \App\Services\MayorService::tick($term, $city);

        $term->refresh();

        $this->assertEquals($initialPeriod + 1, $term->period);
        $this->assertLessThanOrEqual($initialFunds, $term->city_funds);
    }

    
    
    

    public function test_mayor_death_ends_term_and_starts_election(): void
    {
        $city = $this->makeCity('death');
        $mayor = $this->makeMayor($city);
        $term = $this->makeActiveTerm($city, $mayor);

        CityHallAide::create([
            'city_id'       => $city->id,
            'mayor_term_id' => $term->id,
            'character_id'  => $this->makeResident($city)->id,
            'display_name'  => 'Aide One',
        ]);

        $mayor->kill('test', 'Testing mayor death');

        $this->assertSoftDeleted('characters', ['id' => $mayor->id]);
        $this->assertNotNull($term->fresh()->ended_at);
        $this->assertNull($city->fresh()->mayor_id);
        $this->assertEquals(0, CityHallAide::where('mayor_term_id', $term->id)->count());
        $this->assertDatabaseHas('elections', ['city_id' => $city->id, 'status' => 'registration']);
    }

    public function test_mayor_death_without_active_term_still_starts_election(): void
    {
        $city = $this->makeCity('death-no-term');
        $mayor = $this->makeMayor($city);

        $mayor->kill('test', 'Testing');

        $this->assertNull($city->fresh()->mayor_id);
        $this->assertDatabaseHas('elections', ['city_id' => $city->id]);
    }

    
    
    

    public function test_relocation_blocked_for_active_mayor(): void
    {
        $cityA = $this->makeCity('loc-a');
        $cityB = $this->makeCity('loc-b');
        $mayor = $this->makeMayor($cityA);
        $this->makeActiveTerm($cityA, $mayor);
        $mayor->update(['city_id' => $cityB->id]);

        $response = $this->actingAs($mayor->user)
            ->post("/{$cityB->slug}/city-hall/relocate");

        $response->assertSessionHas('error');
    }

    public function test_relocation_blocked_for_active_aide(): void
    {
        $cityA = $this->makeCity('loc-aide-a');
        $cityB = $this->makeCity('loc-aide-b');
        $mayor = $this->makeMayor($cityA);
        $term = $this->makeActiveTerm($cityA, $mayor);
        $aide = $this->makeResident($cityA);

        CityHallAide::create([
            'city_id'       => $cityA->id,
            'mayor_term_id' => $term->id,
            'character_id'  => $aide->id,
            'display_name'  => $aide->display_name,
        ]);

        $aide->update(['city_id' => $cityB->id]);

        $response = $this->actingAs($aide->user)
            ->post("/{$cityB->slug}/city-hall/relocate");

        $response->assertSessionHas('error');
    }

    public function test_relocation_blocked_for_police_officer(): void
    {
        $cityA = $this->makeCity('loc-police-a');
        $cityB = $this->makeCity('loc-police-b');

        $officer = Character::create([
            'user_id'      => User::factory()->create()->id,
            'display_name' => 'Officer-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $cityB->id,
            'home_city_id' => $cityA->id,
            'career_id'    => $this->policeCareerId,
            'career_rank'  => 2,
            'health'       => 100,
            'max_health'   => 100,
        ]);
        CharacterStats::create(['character_id' => $officer->id]);
        CharacterTimers::create(['character_id' => $officer->id]);

        $response = $this->actingAs($officer->user)
            ->post("/{$cityB->slug}/city-hall/relocate");

        $response->assertSessionHas('error');
    }

    public function test_relocation_blocked_for_lawyer(): void
    {
        $cityA = $this->makeCity('loc-law-a');
        $cityB = $this->makeCity('loc-law-b');

        $lawyer = Character::create([
            'user_id'      => User::factory()->create()->id,
            'display_name' => 'Lawyer-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $cityB->id,
            'home_city_id' => $cityA->id,
            'career_id'    => $this->lawCareerId,
            'career_rank'  => 2,
            'health'       => 100,
            'max_health'   => 100,
        ]);
        CharacterStats::create(['character_id' => $lawyer->id]);
        CharacterTimers::create(['character_id' => $lawyer->id]);

        $response = $this->actingAs($lawyer->user)
            ->post("/{$cityB->slug}/city-hall/relocate");

        $response->assertSessionHas('error');
    }

    public function test_relocation_blocked_for_active_campaign_candidate(): void
    {
        $cityA = $this->makeCity('loc-camp-a');
        $cityB = $this->makeCity('loc-camp-b');

        $candidate = Character::create([
            'user_id'      => User::factory()->create()->id,
            'display_name' => 'Candidate-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $cityB->id,
            'home_city_id' => $cityA->id,
            'career_id'    => $this->politicsCareerId,
            'career_rank'  => Election::MIN_RANK_REQUIRED,
            'career_xp'    => 100_000,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => Election::APPLICATION_FEE + 100_000,
            'cash_in_bank' => Election::CAMPAIGN_FUND_REQUIREMENT + 500_000,
        ]);
        CharacterStats::create(['character_id' => $candidate->id, 'influence' => 50]);
        CharacterTimers::create(['character_id' => $candidate->id]);

        $election = Election::create([
            'city_id'            => $cityB->id,
            'cycle_number'       => 1,
            'status'             => 'registration',
            'registration_start' => now(),
            'registration_end'   => now()->addDays(2),
            'voting_start'       => now()->addDays(2),
            'voting_end'         => now()->addDays(3),
        ]);

        Campaign::create([
            'election_id'   => $election->id,
            'candidate_id'  => $candidate->id,
            'city_id'       => $cityB->id,
            'manifesto'     => 'Test',
            'campaign_fund' => Election::CAMPAIGN_FUND_REQUIREMENT,
            'status'        => 'active',
        ]);

        $response = $this->actingAs($candidate->user)
            ->post("/{$cityB->slug}/city-hall/relocate");

        $response->assertSessionHas('error');
    }

    public function test_relocation_succeeds_for_eligible_resident(): void
    {
        $cityA = $this->makeCity('loc-ok-a');
        $cityB = $this->makeCity('loc-ok-b');
        $resident = $this->makeResident($cityA);
        $resident->update(['city_id' => $cityB->id]);

        $response = $this->actingAs($resident->user)
            ->post("/{$cityB->slug}/city-hall/relocate");

        $response->assertSessionHas('success');
        $this->assertEquals(
            $cityB->id,
            $resident->fresh()->timers->pending_relocation_city_id
        );
    }

    
    
    

    public function test_mayor_can_approve_relocation(): void
    {
        $city = $this->makeCity('approve');
        $mayor = $this->makeMayor($city);
        $this->makeActiveTerm($city, $mayor);
        $applicant = $this->makeResident($city);

        DB::table('character_timers')
            ->where('character_id', $applicant->id)
            ->update(['pending_relocation_city_id' => $city->id]);

        $response = $this->actingAs($mayor->user)
            ->post("/{$city->slug}/city-hall/relocate/{$applicant->id}/approve");

        $response->assertSessionHas('success');
        $this->assertEquals($city->id, $applicant->fresh()->home_city_id);
        $this->assertNull($applicant->fresh()->timers->pending_relocation_city_id);
    }

    public function test_aide_can_approve_relocation(): void
    {
        $city = $this->makeCity('approve-aide');
        $mayor = $this->makeMayor($city);
        $term = $this->makeActiveTerm($city, $mayor);
        $aide = $this->makeResident($city);

        CityHallAide::create([
            'city_id'       => $city->id,
            'mayor_term_id' => $term->id,
            'character_id'  => $aide->id,
            'display_name'  => $aide->display_name,
        ]);

        $applicant = $this->makeResident($city);
        DB::table('character_timers')
            ->where('character_id', $applicant->id)
            ->update(['pending_relocation_city_id' => $city->id]);

        $response = $this->actingAs($aide->user)
            ->post("/{$city->slug}/city-hall/relocate/{$applicant->id}/approve");

        $response->assertSessionHas('success');
        $this->assertEquals($city->id, $applicant->fresh()->home_city_id);
    }

    public function test_mayor_can_deny_relocation(): void
    {
        $city = $this->makeCity('deny');
        $mayor = $this->makeMayor($city);
        $this->makeActiveTerm($city, $mayor);
        
        $homeCity  = $this->makeCity('deny-home');
        $applicant = $this->makeResident($homeCity);
        $applicant->update(['city_id' => $city->id]); 

        DB::table('character_timers')
            ->where('character_id', $applicant->id)
            ->update(['pending_relocation_city_id' => $city->id]);

        $response = $this->actingAs($mayor->user)
            ->post("/{$city->slug}/city-hall/relocate/{$applicant->id}/deny");

        $response->assertSessionHas('success');
        $this->assertNull($applicant->fresh()->timers->pending_relocation_city_id);
        $this->assertEquals($homeCity->id, $applicant->fresh()->home_city_id);
    }

    public function test_non_mayor_cannot_approve_relocation(): void
    {
        $city = $this->makeCity('approve-unauth');
        $mayor = $this->makeMayor($city);
        $this->makeActiveTerm($city, $mayor);
        
        $homeCity  = $this->makeCity('approve-unauth-home');
        $applicant = $this->makeResident($homeCity);
        $applicant->update(['city_id' => $city->id]);
        $rando = $this->makeResident($city);

        DB::table('character_timers')
            ->where('character_id', $applicant->id)
            ->update(['pending_relocation_city_id' => $city->id]);

        $response = $this->actingAs($rando->user)
            ->post("/{$city->slug}/city-hall/relocate/{$applicant->id}/approve");

        $response->assertSessionHas('error');
        
        $this->assertEquals($homeCity->id, $applicant->fresh()->home_city_id);
    }

    
    
    

    public function test_pardon_requires_sufficient_funds(): void
    {
        $city = $this->makeCity('pardon-funds');
        $mayor = $this->makeMayor($city);
        $term = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 10_000]);

        $prisoner = $this->makeResident($city);
        $this->makePardonableRecord($city, $prisoner);
        $prisoner->jail(7 * 86400);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/pardon', ['character_id' => $prisoner->id]);

        $response->assertSessionHas('error');
        $this->assertTrue($prisoner->fresh()->isJailed());
    }

    public function test_pardon_releases_prisoner(): void
    {
        $city = $this->makeCity('pardon-ok');
        $mayor = $this->makeMayor($city);
        $term = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 500_000]);

        $prisoner = $this->makeResident($city);
        $record = $this->makePardonableRecord($city, $prisoner);
        $prisoner->jail(7 * 86400);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/pardon', ['character_id' => $prisoner->id]);

        $response->assertSessionHas('success');
        $this->assertFalse($prisoner->fresh()->isJailed());
        $this->assertNull($record->fresh());
        $this->assertSame(0, CrimeRecord::convictionCount($prisoner->id));
    }

    public function test_pardon_cooldown_prevents_spam(): void
    {
        $city = $this->makeCity('pardon-cooldown');
        $mayor = $this->makeMayor($city);
        $term = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 500_000]);

        $prisoner = $this->makeResident($city);
        $this->makePardonableRecord($city, $prisoner);
        $prisoner->jail(7 * 86400);

        $this->actingAs($mayor->user)
            ->post('/career/politics/pardon', ['character_id' => $prisoner->id]);

        $prisoner->jail(7 * 86400);
        $this->makePardonableRecord($city, $prisoner);

        $this->travel(9)->hours();

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/pardon', ['character_id' => $prisoner->id]);

        $response->assertSessionHas('error');
    }

    
    
    

    public function test_term_ends_on_schedule(): void
    {
        $city = $this->makeCity('term-end');
        $mayor = $this->makeMayor($city);
        $term = $this->makeActiveTerm($city, $mayor);

        \App\Services\MayorService::removeMayor($term, $city, MayorTerm::END_TERM_COMPLETE);

        $this->assertNotNull($term->fresh()->ended_at);
        $this->assertNull($city->fresh()->mayor_id);
    }

    public function test_assembly_collapse_ends_term_early(): void
    {
        $city  = $this->makeCity('collapse');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['assembly_score' => 0]);

        
        
        $log = $term->actions_log;
        $log['last_drained_at'] = now()->subSeconds(120)->getTimestamp();
        DB::table('mayor_terms')->where('id', $term->id)->update(['actions_log' => json_encode($log)]);
        $term->refresh();

        \App\Services\MayorService::tick($term, $city);

        $this->assertNotNull($term->fresh()->ended_at);
        $this->assertEquals(MayorTerm::END_ASSEMBLY, $term->fresh()->end_reason);
    }

    
    
    

    public function test_relocation_blocked_by_cooldown(): void
    {
        $cityA = $this->makeCity('cool-a');
        $cityB = $this->makeCity('cool-b');
        $resident = $this->makeResident($cityA);
        $resident->update(['city_id' => $cityB->id]);

        DB::table('character_timers')
            ->where('character_id', $resident->id)
            ->update(['last_relocation_at' => now()->subDays(3)->getTimestamp()]);

        $response = $this->actingAs($resident->user)
            ->post("/{$cityB->slug}/city-hall/relocate");

        $response->assertSessionHas('error');
        $this->assertStringContainsString('wait', strtolower(session('error')));
    }

    public function test_relocation_auto_approves_after_12_hours_without_mayor(): void
    {
        $cityA = $this->makeCity('auto-a');
        $cityB = $this->makeCity('auto-b');
        $resident = $this->makeResident($cityA);
        $resident->update(['city_id' => $cityB->id]);

        DB::table('character_timers')
            ->where('character_id', $resident->id)
            ->update([
                'pending_relocation_city_id' => $cityB->id,
                'last_relocation_at'         => now()->subHours(13)->getTimestamp(),
            ]);

        $this->actingAs($resident->user)
            ->get("/{$cityB->slug}/city-hall");

        $this->assertEquals($cityB->id, $resident->fresh()->home_city_id);
        $this->assertNull($resident->fresh()->timers->pending_relocation_city_id);
    }

    public function test_relocation_blocked_if_already_pending_to_same_city(): void
    {
        $cityA = $this->makeCity('pending-a');
        $cityB = $this->makeCity('pending-b');
        $resident = $this->makeResident($cityA);
        $resident->update(['city_id' => $cityB->id]);

        DB::table('character_timers')
            ->where('character_id', $resident->id)
            ->update(['pending_relocation_city_id' => $cityB->id]);

        $response = $this->actingAs($resident->user)
            ->post("/{$cityB->slug}/city-hall/relocate");

        $response->assertSessionHas('error');
        $this->assertStringContainsString('already', strtolower(session('error')));
    }

    public function test_relocation_blocked_if_already_home_city(): void
    {
        $city = $this->makeCity('home');
        $resident = $this->makeResident($city);

        $response = $this->actingAs($resident->user)
            ->post("/{$city->slug}/city-hall/relocate");

        $response->assertSessionHas('error');
        $this->assertStringContainsString('already', strtolower(session('error')));
    }

    public function test_relocation_approval_starts_cooldown(): void
    {
        $city = $this->makeCity('cooldown-start');
        $mayor = $this->makeMayor($city);
        $this->makeActiveTerm($city, $mayor);
        $applicant = $this->makeResident($this->makeCity('applicant-home'));

        DB::table('character_timers')
            ->where('character_id', $applicant->id)
            ->update(['pending_relocation_city_id' => $city->id]);

        $before = $applicant->timers->last_relocation_at;

        $this->actingAs($mayor->user)
            ->post("/{$city->slug}/city-hall/relocate/{$applicant->id}/approve");

        $after = $applicant->fresh()->timers->last_relocation_at;
        $this->assertGreaterThan($before ?? 0, $after);
    }

    public function test_relocation_denial_resets_pending_but_not_home_city(): void
    {
        $city = $this->makeCity('deny-reset');
        $mayor = $this->makeMayor($city);
        $this->makeActiveTerm($city, $mayor);
        $applicantHome = $this->makeCity('applicant-home-deny');
        $applicant = $this->makeResident($applicantHome);

        DB::table('character_timers')
            ->where('character_id', $applicant->id)
            ->update(['pending_relocation_city_id' => $city->id]);

        $this->actingAs($mayor->user)
            ->post("/{$city->slug}/city-hall/relocate/{$applicant->id}/deny");

        $this->assertEquals($applicantHome->id, $applicant->fresh()->home_city_id);
        $this->assertNull($applicant->fresh()->timers->pending_relocation_city_id);
        $this->assertEquals(0, $applicant->fresh()->timers->last_relocation_at);
    }

    public function test_aides_removed_when_term_ends(): void
    {
        $city = $this->makeCity('aide-end');
        $mayor = $this->makeMayor($city);
        $term = $this->makeActiveTerm($city, $mayor);
        $aide = $this->makeResident($city);

        CityHallAide::create([
            'city_id'       => $city->id,
            'mayor_term_id' => $term->id,
            'character_id'  => $aide->id,
            'display_name'  => $aide->display_name,
        ]);

        \App\Services\MayorService::removeMayor($term, $city, MayorTerm::END_TERM_COMPLETE);

        $this->assertEquals(0, CityHallAide::where('mayor_term_id', $term->id)->count());
    }

    
    
    

    
    private function makeOverdueTerm(City $city, Character $mayor, int $periodsBack = 1): MayorTerm
    {
        $startedAt = now()->subSeconds(MayorTerm::PERIOD_SECONDS * $periodsBack + 60);

        $election = \App\Models\Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'completed',
            'registration_start' => $startedAt->copy()->subDays(10),
            'registration_end'   => $startedAt->copy()->subDays(8),
            'voting_start'       => $startedAt->copy()->subDays(8),
            'voting_end'         => $startedAt->copy()->subDays(7),
            'winner_id'          => $mayor->id,
            'total_votes'        => 1,
        ]);

        return MayorTerm::create([
            'city_id'        => $city->id,
            'character_id'   => $mayor->id,
            'election_id'    => $election->id,
            'started_at'     => $startedAt,
            'period'         => 1,
            'city_funds'     => MayorTerm::STARTING_FUNDS,
            'assembly_score' => MayorTerm::STARTING_ASSEMBLY,
            'budget_law'     => 25,
            'budget_corp_reg'=> 25,
            'budget_services'=> 25,
            'budget_bonds'   => 25,
            'last_period_at' => null,
            'ledger'         => [],
            'actions_log'    => [
                'suppressions'             => [],
                'pardons'                  => [],
                'dismissals'               => [],
                'audits'                   => [],
                'bond'                     => null,
                'period_income'            => ['tax' => 0, 'fine' => 0, 'bond' => 0, 'audit' => 0, 'other' => 0],
                'period_drain'             => 0,
                'last_drained_at'          => now()->subSeconds(MayorTerm::PERIOD_SECONDS + 60)->getTimestamp(),
                'pending_drift'            => 0.0,
                'bond_settled_this_period' => null,
                'policies'                 => [
                    'income_tax_rate'        => 5,
                    'corporate_tax_rate'     => 0,
                    'corp_regulation_active' => false,
                    'bonds_active'           => false,
                    'death_sentence_active'  => false,
                ],
            ],
        ]);
    }

    public function test_full_four_period_term_advances_and_ends(): void
    {
        $city  = $this->makeCity('4period');
        $mayor = $this->makeMayor($city);

        
        $term = $this->makeOverdueTerm($city, $mayor);

        
        
        for ($p = 1; $p <= MayorTerm::PERIODS_PER_TERM; $p++) {
            $result = \App\Services\MayorService::tick($term, $city);

            if ($p < MayorTerm::PERIODS_PER_TERM) {
                $this->assertNotNull($result, "tick() returned null prematurely at period {$p}");
                $term->refresh();
                $this->assertEquals($p + 1, $term->period, "period should advance to " . ($p + 1));

                
                DB::table('mayor_terms')
                    ->where('id', $term->id)
                    ->update(['last_period_at' => now()->subSeconds(MayorTerm::PERIOD_SECONDS + 60)->getTimestamp()]);
                $term->refresh();
            }
        }

        
        $this->assertNull($result, 'tick() should return null after term completes');
        $fresh = MayorTerm::find($term->id);
        $this->assertNotNull($fresh->ended_at);
        $this->assertEquals(MayorTerm::END_TERM_COMPLETE, $fresh->end_reason);
        $this->assertNull($city->fresh()->mayor_id);
    }

    public function test_ledger_gets_one_entry_per_period(): void
    {
        $city  = $this->makeCity('ledger');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);

        
        for ($p = 1; $p <= 3; $p++) {
            \App\Services\MayorService::tick($term, $city);
            $term->refresh();
            DB::table('mayor_terms')
                ->where('id', $term->id)
                ->update(['last_period_at' => now()->subSeconds(MayorTerm::PERIOD_SECONDS + 60)->getTimestamp()]);
            $term->refresh();
        }

        $this->assertCount(3, $term->ledger);
        $this->assertEquals(1, $term->ledger[0]['period']);
        $this->assertEquals(2, $term->ledger[1]['period']);
        $this->assertEquals(3, $term->ledger[2]['period']);
    }

    public function test_period_income_tally_resets_each_period(): void
    {
        $city  = $this->makeCity('tally-reset');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);

        
        $term->addFunds(50_000, 'tax');
        $term->refresh();
        $this->assertEquals(50_000, $term->actions_log['period_income']['tax']);

        
        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        $this->assertEquals(0, $term->actions_log['period_income']['tax'],
            'period_income[tax] must reset to 0 after period checkpoint');
    }

    
    
    

    public function test_positive_net_period_adds_assembly(): void
    {
        $city  = $this->makeCity('asm-pos');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);
        $term->update(['assembly_score' => 50, 'city_funds' => 500_000]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 0]);

        
        $term->addFunds(10_000, 'tax');

        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        
        $this->assertGreaterThanOrEqual(50 + MayorTerm::DELTA_POSITIVE_PERIOD, $term->assembly_score);
    }

    public function test_negative_net_period_drains_assembly(): void
    {
        $city  = $this->makeCity('asm-neg');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);
        $term->update(['assembly_score' => 80, 'city_funds' => 500_000]);
        
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 90]);

        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        
        $this->assertLessThan(80, $term->assembly_score);
    }

    public function test_clean_period_bonus_applied(): void
    {
        $city  = $this->makeCity('clean-bonus');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);
        $term->update(['assembly_score' => 70, 'city_funds' => 500_000]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 0]);

        
        $term->addFunds(20_000, 'tax');

        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        
        $this->assertGreaterThanOrEqual(75, $term->assembly_score);
    }

    public function test_suppression_immediate_assembly_hit(): void
    {
        $city  = $this->makeCity('supp-hit');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['assembly_score' => 60]);

        $result = \App\Services\MayorService::applySuppressionAssemblyHit($term, $city);

        $this->assertNotNull($result);
        $this->assertEquals(60 + MayorTerm::DELTA_SUPPRESSION, $term->fresh()->assembly_score);
    }

    public function test_suppression_assembly_hit_can_collapse_term(): void
    {
        $city  = $this->makeCity('supp-collapse');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        
        $term->update(['assembly_score' => abs(MayorTerm::DELTA_SUPPRESSION)]);

        $result = \App\Services\MayorService::applySuppressionAssemblyHit($term, $city);

        $this->assertNull($result, 'term should end when assembly collapses on suppression hit');
        $this->assertNotNull(MayorTerm::find($term->id)->ended_at);
    }

    public function test_dismissal_in_prior_period_does_not_block_clean_bonus(): void
    {
        $city  = $this->makeCity('clean-fix');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);
        $term->update(['assembly_score' => 70, 'city_funds' => 500_000]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 0]);

        
        $term->logAction('dismissals', [
            'type'   => 'officer',
            'at'     => now()->getTimestamp(),
            'period' => 1,
        ]);

        
        DB::table('mayor_terms')->where('id', $term->id)->update(['period' => 2]);
        $term->refresh();

        
        $term->addFunds(20_000, 'tax');
        DB::table('mayor_terms')
            ->where('id', $term->id)
            ->update(['last_period_at' => now()->subSeconds(MayorTerm::PERIOD_SECONDS + 60)->getTimestamp()]);
        $term->refresh();

        $scoreBefore = $term->assembly_score;
        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        
        
        $this->assertGreaterThanOrEqual($scoreBefore + MayorTerm::DELTA_POSITIVE_PERIOD + MayorTerm::DELTA_CLEAN_PERIOD, $term->assembly_score);
    }

    public function test_dismissal_in_current_period_blocks_clean_bonus(): void
    {
        $city  = $this->makeCity('dirty-period');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);
        $term->update(['assembly_score' => 70, 'city_funds' => 500_000]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 0]);

        
        $term->logAction('dismissals', [
            'type'   => 'officer',
            'at'     => now()->getTimestamp(),
            'period' => 1,
        ]);
        $term->addFunds(20_000, 'tax');
        $term->refresh();

        $scoreBefore = $term->assembly_score;
        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        
        
        $expectedWithClean    = $scoreBefore + MayorTerm::DELTA_POSITIVE_PERIOD + MayorTerm::DELTA_CLEAN_PERIOD;
        $expectedWithoutClean = $scoreBefore + MayorTerm::DELTA_POSITIVE_PERIOD;
        $this->assertLessThan($expectedWithClean, $term->assembly_score);
        $this->assertGreaterThanOrEqual($expectedWithoutClean, $term->assembly_score);
    }

    
    
    

    public function test_bond_maturity_credits_funds_and_boosts_assembly(): void
    {
        $city  = $this->makeCity('bond-mature');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 10]);

        $principal = 100_000;
        $yieldPct  = MayorTerm::calculateBondYield(10);
        $profit    = (int) floor($principal * $yieldPct / 100);
        $total     = $principal + $profit;

        
        
        
        
        
        DB::table('mayor_terms')->where('id', $term->id)->update([
            'city_funds'      => 200_000,
            'assembly_score'  => 60,
            'budget_bonds'    => 50,
            'budget_services' => 25,
            'budget_law'      => 25,
            'budget_corp_reg' => 0,
        ]);
        $term->refresh();

        
        $log = $term->actions_log;
        $log['last_drained_at'] = now()->subSeconds(120)->getTimestamp();
        DB::table('mayor_terms')->where('id', $term->id)->update(['actions_log' => json_encode($log)]);
        
        
        
        $term->refresh();

        
        $term->setBond([
            'principal'      => $principal,
            'yield_pct'      => $yieldPct,
            'matures_at'     => now()->subMinute()->getTimestamp(),
            'issued_at'      => now()->subDay()->getTimestamp(),
            'crime_at_issue' => 10,
        ]);
        $term->refresh();

        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        $this->assertNull($term->getActiveBond(), 'bond should be cleared after maturity');
        
        
        $this->assertGreaterThanOrEqual(200_000 + $total - 500, $term->city_funds);
        $this->assertEquals(60 + MayorTerm::DELTA_BOND_MATURED, $term->assembly_score);
    }

    public function test_bond_defaults_on_high_crime_and_hits_assembly(): void
    {
        $city  = $this->makeCity('bond-default');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 70]);

        $term->setBond([
            'principal'      => 100_000,
            'yield_pct'      => 15.0,
            'matures_at'     => now()->addHours(12)->getTimestamp(),
            'issued_at'      => now()->subHours(12)->getTimestamp(),
            'crime_at_issue' => 20,
        ]);
        $term->update(['assembly_score' => 80]);

        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        $this->assertNull($term->getActiveBond(), 'bond should be cleared after default');
        $this->assertEquals(80 + MayorTerm::DELTA_BOND_DEFAULT, $term->assembly_score);
    }

    public function test_bond_defaults_when_bonds_budget_drops_below_50(): void
    {
        $city  = $this->makeCity('bond-budget');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 10]);

        $term->setBond([
            'principal'      => 50_000,
            'yield_pct'      => 15.0,
            'matures_at'     => now()->addHours(12)->getTimestamp(),
            'issued_at'      => now()->subHours(12)->getTimestamp(),
            'crime_at_issue' => 10,
        ]);
        
        $term->update(['budget_bonds' => 25, 'assembly_score' => 70]);

        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        $this->assertNull($term->getActiveBond());
        $this->assertEquals(70 + MayorTerm::DELTA_BOND_DEFAULT, $term->assembly_score);
    }

    
    
    

    public function test_enable_corp_reg_deducts_funds_and_sets_policy_atomically(): void
    {
        $city  = $this->makeCity('corp-reg');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 500_000, 'budget_corp_reg' => 50]);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/enable-corp-reg');

        $response->assertSessionHas('success');
        $term->refresh();
        $this->assertEquals(500_000 - MayorTerm::COST_ENABLE_CORP_REG, $term->city_funds);
        $this->assertTrue($term->getPolicies()['corp_regulation_active']);
        $this->assertTrue($city->fresh()->corp_regulation_active);
    }

    public function test_enable_corp_reg_fails_with_insufficient_funds(): void
    {
        $city  = $this->makeCity('corp-reg-broke');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 1_000, 'budget_corp_reg' => 50]);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/enable-corp-reg');

        $response->assertSessionHas('error');
        $this->assertFalse($term->fresh()->getPolicies()['corp_regulation_active']);
    }

    public function test_issue_bond_deducts_funds_and_stores_bond_atomically(): void
    {
        $city  = $this->makeCity('bond-issue');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 500_000, 'budget_bonds' => 75]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 20]);

        $principal = MayorTerm::BOND_MIN;
        $response  = $this->actingAs($mayor->user)
            ->post('/career/politics/bond', ['principal' => $principal]);

        $response->assertSessionHas('success');
        $term->refresh();
        $this->assertEquals(500_000 - $principal, $term->city_funds);
        $this->assertNotNull($term->getActiveBond());
        $this->assertEquals($principal, $term->getActiveBond()['principal']);
    }

    public function test_issue_bond_fails_if_bond_already_active(): void
    {
        $city  = $this->makeCity('bond-dupe');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 500_000, 'budget_bonds' => 75]);

        $term->setBond([
            'principal'      => 50_000,
            'yield_pct'      => 15.0,
            'matures_at'     => now()->addDay()->getTimestamp(),
            'issued_at'      => now()->getTimestamp(),
            'crime_at_issue' => 10,
        ]);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/bond', ['principal' => MayorTerm::BOND_MIN]);

        $response->assertSessionHas('error');
    }

    public function test_death_sentence_deducts_funds_and_sets_policy_atomically(): void
    {
        $city  = $this->makeCity('death-sen');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 500_000]);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/death-sentence');

        $response->assertSessionHas('success');
        $term->refresh();
        $this->assertEquals(500_000 - MayorTerm::COST_DEATH_SENTENCE, $term->city_funds);
        $this->assertTrue($term->getPolicies()['death_sentence_active']);
        $this->assertTrue($city->fresh()->death_sentence_active);
    }

    public function test_death_sentence_cannot_be_enacted_twice(): void
    {
        $city  = $this->makeCity('death-dupe');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 1_000_000]);
        $term->setPolicy('death_sentence_active', true);
        $city->syncPoliciesFromTerm($term);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/death-sentence');

        $response->assertSessionHas('error');
        $this->assertEquals(1_000_000, $term->fresh()->city_funds);
    }

    
    
    

    public function test_budget_must_sum_to_100(): void
    {
        $city  = $this->makeCity('budget-sum');
        $mayor = $this->makeMayor($city);
        $this->makeActiveTerm($city, $mayor);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/budget', [
                'budget_law'      => 30,
                'budget_corp_reg' => 30,
                'budget_services' => 30,
                'budget_bonds'    => 30, 
            ]);

        $response->assertSessionHas('error');
    }

    public function test_budget_saves_when_valid(): void
    {
        $city  = $this->makeCity('budget-ok');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/budget', [
                'budget_law'      => 50,
                'budget_corp_reg' => 20,
                'budget_services' => 20,
                'budget_bonds'    => 10,
            ]);

        $response->assertSessionHas('success');
        $term->refresh();
        $this->assertEquals(50, $term->budget_law);
        $this->assertEquals(10, $term->budget_bonds);
    }

    public function test_corp_tax_locked_when_corp_reg_budget_below_25(): void
    {
        $city  = $this->makeCity('corp-tax-lock');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->setPolicy('corp_regulation_active', true);
        $term->setPolicy('corporate_tax_rate', 5);
        $city->syncPoliciesFromTerm($term);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/budget', [
                'budget_law'      => 50,
                'budget_corp_reg' => 20, 
                'budget_services' => 20,
                'budget_bonds'    => 10,
            ]);

        $response->assertSessionHas('success');
        $term->refresh();
        $this->assertEquals(0, $term->getPolicies()['corporate_tax_rate'],
            'corporate_tax_rate must be zeroed when corp_reg budget drops below 25%');
    }

    
    
    

    public function test_live_drain_reduces_city_funds_proportional_to_crime(): void
    {
        $city  = $this->makeCity('drain');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 500_000]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 50]);

        
        $log                    = $term->actions_log;
        $log['last_drained_at'] = now()->subSeconds(MayorTerm::PERIOD_SECONDS)->getTimestamp();
        DB::table('mayor_terms')->where('id', $term->id)->update(['actions_log' => json_encode($log)]);
        $term->refresh();

        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        
        $expectedDrain = 500 * 50;
        $this->assertLessThanOrEqual(500_000 - $expectedDrain, $term->city_funds,
            'city_funds should decrease by ~$25,000 at crime_rate=50 over one period');
    }

    public function test_live_drain_skipped_if_last_drained_less_than_60_seconds_ago(): void
    {
        $city  = $this->makeCity('drain-skip');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 500_000]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 100]);

        
        $log                    = $term->actions_log;
        $log['last_drained_at'] = now()->subSeconds(30)->getTimestamp();
        DB::table('mayor_terms')->where('id', $term->id)->update(['actions_log' => json_encode($log)]);
        $term->refresh();

        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        $this->assertEquals(500_000, $term->city_funds, 'funds must not change when drain tick is skipped');
    }

    
    
    

    
    
    

    public function test_resign_ends_term_clears_city_and_starts_election(): void
    {
        $city  = $this->makeCity('resign');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/resign');

        $response->assertRedirect(route('dashboard'));
        $this->assertNotNull($term->fresh()->ended_at);
        $this->assertEquals(MayorTerm::END_RESIGNED, $term->fresh()->end_reason);
        $this->assertNull($city->fresh()->mayor_id);
        $this->assertDatabaseHas('elections', ['city_id' => $city->id, 'status' => 'registration']);
    }

    public function test_resign_mid_term_removes_all_aides(): void
    {
        $city  = $this->makeCity('resign-aides');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);

        for ($i = 0; $i < 3; $i++) {
            CityHallAide::create([
                'city_id'       => $city->id,
                'mayor_term_id' => $term->id,
                'character_id'  => $this->makeResident($city)->id,
                'display_name'  => "Aide {$i}",
            ]);
        }
        $this->assertEquals(3, CityHallAide::where('mayor_term_id', $term->id)->count());

        $this->actingAs($mayor->user)->post('/career/politics/resign');

        $this->assertEquals(0, CityHallAide::where('mayor_term_id', $term->id)->count());
    }

    public function test_term_complete_preserves_career_xp_but_resign_does_not(): void
    {
        
        $city1  = $this->makeCity('xp-complete');
        $mayor1 = $this->makeMayor($city1);
        $term1  = $this->makeActiveTerm($city1, $mayor1);
        $mayor1->update(['career_xp' => 50_000, 'total_character_exp' => 50_000]);

        \App\Services\MayorService::removeMayor($term1, $city1, MayorTerm::END_TERM_COMPLETE);
        
        $this->assertEquals(50_000, $mayor1->fresh()->total_character_exp);

        
        $city2  = $this->makeCity('xp-removed');
        $mayor2 = $this->makeMayor($city2);
        $term2  = $this->makeActiveTerm($city2, $mayor2);
        $mayor2->update(['career_xp' => 50_000, 'total_character_exp' => 50_000]);

        \App\Services\MayorService::removeMayor($term2, $city2, MayorTerm::END_REMOVED);
        
        $this->assertEquals((int) floor(50_000 * 0.85), $mayor2->fresh()->total_character_exp);
    }

    public function test_city_policies_reset_to_defaults_when_mayor_removed(): void
    {
        $city  = $this->makeCity('policy-reset');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);

        
        $term->setPolicy('income_tax_rate', 20);
        $term->setPolicy('corp_regulation_active', true);
        $term->setPolicy('bonds_active', true);
        $term->setPolicy('death_sentence_active', true);
        $city->syncPoliciesFromTerm($term);

        \App\Services\MayorService::removeMayor($term, $city, MayorTerm::END_ASSEMBLY);
        $fresh = $city->fresh();

        
        $this->assertEquals(5, $fresh->income_tax_rate);
        $this->assertFalse($fresh->corp_regulation_active);
        $this->assertFalse($fresh->bonds_active);
        $this->assertFalse($fresh->death_sentence_active);
        $this->assertNull($fresh->mayor_id);
    }

    public function test_periodic_suppression_penalty_reduces_assembly_at_period_end(): void
    {
        $city  = $this->makeCity('supp-periodic');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);
        $term->update(['assembly_score' => 80, 'city_funds' => 500_000]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 0]);

        
        $log = $term->actions_log;
        $log['suppressions'][] = ['case_id' => 1, 'at' => now()->getTimestamp(), 'period' => 1];
        DB::table('mayor_terms')->where('id', $term->id)->update(['actions_log' => json_encode($log)]);
        
        
        $term->refresh();
        $term->addFunds(20_000, 'tax'); 
        $term->refresh();

        $scoreBefore = $term->assembly_score;
        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        
        
        $expectedDelta = MayorTerm::DELTA_POSITIVE_PERIOD + (1 * MayorTerm::DELTA_SUPPRESSION_PERIODIC);
        $this->assertEquals($scoreBefore + $expectedDelta, $term->assembly_score);
    }

    public function test_two_suppressions_double_periodic_penalty(): void
    {
        $city  = $this->makeCity('supp-2x');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);
        $term->update(['assembly_score' => 80, 'city_funds' => 500_000]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 0]);

        
        $log = $term->actions_log;
        for ($i = 0; $i < 2; $i++) {
            $log['suppressions'][] = ['case_id' => $i + 1, 'at' => now()->getTimestamp(), 'period' => 1];
        }
        DB::table('mayor_terms')->where('id', $term->id)->update(['actions_log' => json_encode($log)]);
        
        $term->refresh();
        $term->addFunds(20_000, 'tax');
        $term->refresh();

        $scoreBefore = $term->assembly_score;
        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        
        $expectedDelta = MayorTerm::DELTA_POSITIVE_PERIOD
            + (2 * MayorTerm::DELTA_SUPPRESSION_PERIODIC);
        $this->assertEquals($scoreBefore + $expectedDelta, $term->assembly_score);
    }

    public function test_progressive_assembly_collapse_over_multiple_bad_periods(): void
    {
        $city  = $this->makeCity('progressive');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);
        
        $term->update(['assembly_score' => 25, 'city_funds' => 500_000]);
        
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 80]);

        
        
        $collapsed = false;
        for ($p = 1; $p <= MayorTerm::PERIODS_PER_TERM; $p++) {
            $result = \App\Services\MayorService::tick($term, $city);
            if ($result === null) {
                $collapsed = true;
                break;
            }
            $term->refresh();
            
            
            
            
            
            $log = $term->actions_log;
            $log['last_drained_at'] = now()->subSeconds(MayorTerm::PERIOD_SECONDS + 60)->getTimestamp();
            DB::table('mayor_terms')
                ->where('id', $term->id)
                ->update([
                    'last_period_at' => now()->subSeconds(MayorTerm::PERIOD_SECONDS + 60)->getTimestamp(),
                    'actions_log'    => json_encode($log),
                ]);
            $term->refresh();
        }

        $this->assertTrue($collapsed, 'High-crime negative-net term should eventually collapse');
        $this->assertNotNull(MayorTerm::find($term->id)->ended_at);
        $this->assertEquals(MayorTerm::END_ASSEMBLY, MayorTerm::find($term->id)->end_reason);
    }

    public function test_assembly_score_capped_at_100(): void
    {
        $city  = $this->makeCity('asm-cap');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['assembly_score' => 99]);

        
        $term->adjustAssembly(10);

        $this->assertEquals(100, $term->fresh()->assembly_score);
    }

    public function test_assembly_score_cannot_go_below_zero(): void
    {
        $city  = $this->makeCity('asm-floor');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['assembly_score' => 2]);

        
        $newScore = $term->adjustAssembly(-50);

        $this->assertEquals(0, $newScore);
        $this->assertEquals(0, $term->fresh()->assembly_score);
    }

    public function test_deduct_funds_returns_false_when_insufficient(): void
    {
        $city  = $this->makeCity('funds-guard');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 1_000]);

        $result = $term->deductFunds(50_000);

        $this->assertFalse($result);
        $this->assertEquals(1_000, $term->fresh()->city_funds);
    }

    public function test_deduct_funds_never_makes_funds_negative(): void
    {
        $city  = $this->makeCity('funds-floor');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 100]);

        
        $log                    = $term->actions_log;
        $log['last_drained_at'] = now()->subSeconds(MayorTerm::PERIOD_SECONDS)->getTimestamp();
        DB::table('mayor_terms')->where('id', $term->id)->update([
            'actions_log' => json_encode($log),
            'assembly_score' => 50,          
        ]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 100]);
        $term->refresh();

        \App\Services\MayorService::tick($term, $city);
        $term->refresh();

        
        $this->assertGreaterThanOrEqual(0, $term->city_funds);
    }

    public function test_dismiss_officer_via_http_increases_crime_rate(): void
    {
        $city  = $this->makeCity('dismiss-cr');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 500_000]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 30]);

        $officer = $this->makeResident($city, null, $this->policeCareerId);
        $officer->update(['career_rank' => 2, 'home_city_id' => $city->id]);

        $this->actingAs($mayor->user)
            ->post('/career/politics/dismiss-officer', ['character_id' => $officer->id]);

        
        $this->assertGreaterThan(30, (float) $city->fresh()->crime_rate);
    }

    public function test_suppress_reduces_assembly_immediately_and_increases_crime(): void
    {
        $city  = $this->makeCity('supp-cr');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 500_000, 'assembly_score' => 80]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 20]);

        $crimeRecord = DB::table('crime_records')->insertGetId([
            'city_id'    => $city->id,
            'character_id' => $this->makeResident($city)->id,
            'type'       => 'theft',
            'severity'   => 'minor',
            'status'     => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($mayor->user)
            ->post('/career/politics/suppress', ['case_id' => $crimeRecord]);

        $term->refresh();
        
        $this->assertEquals(80 + MayorTerm::DELTA_SUPPRESSION, $term->assembly_score);
        
        $this->assertGreaterThan(20, (float) $city->fresh()->crime_rate);
        
        $this->assertEquals(500_000 - MayorTerm::COST_SUPPRESS, $term->city_funds);
    }

    public function test_audit_in_progress_blocks_second_audit(): void
    {
        $city  = $this->makeCity('audit-block');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 1_000_000, 'budget_corp_reg' => 50]);
        $term->setPolicy('corp_regulation_active', true);

        
        $this->actingAs($mayor->user)->post('/career/politics/audit');
        $term->refresh();
        $this->assertTrue($term->auditInProgress());

        $fundsAfterFirst = $term->city_funds;

        
        $response = $this->actingAs($mayor->user)->post('/career/politics/audit');
        $response->assertSessionHas('error');
        $term->refresh();
        
        $this->assertEquals($fundsAfterFirst, $term->city_funds);
    }

    public function test_mayor_dying_mid_term_ends_term_clears_city_starts_election(): void
    {
        $city  = $this->makeCity('mayor-die');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $aide  = $this->makeResident($city);

        CityHallAide::create([
            'city_id'       => $city->id,
            'mayor_term_id' => $term->id,
            'character_id'  => $aide->id,
            'display_name'  => $aide->display_name,
        ]);

        
        $mayor->kill('combat', 'Assassinated mid-term');

        $this->assertSoftDeleted('characters', ['id' => $mayor->id]);
        $fresh = MayorTerm::find($term->id);
        $this->assertNotNull($fresh->ended_at);
        $this->assertEquals(MayorTerm::END_REMOVED, $fresh->end_reason);
        $this->assertNull($city->fresh()->mayor_id);
        
        $this->assertEquals(0, CityHallAide::where('mayor_term_id', $term->id)->count());
        
        $this->assertDatabaseHas('elections', ['city_id' => $city->id, 'status' => 'registration']);
    }

    public function test_assembly_collapse_from_bond_default_mid_term(): void
    {
        $city  = $this->makeCity('bond-collapse');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        
        $term->update(['assembly_score' => abs(MayorTerm::DELTA_BOND_DEFAULT)]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 70]); 

        $term->setBond([
            'principal'      => 100_000,
            'yield_pct'      => 15.0,
            'matures_at'     => now()->addHours(12)->getTimestamp(),
            'issued_at'      => now()->subHours(12)->getTimestamp(),
            'crime_at_issue' => 20,
        ]);
        
        $log = $term->actions_log;
        $log['last_drained_at'] = now()->subSeconds(120)->getTimestamp();
        DB::table('mayor_terms')->where('id', $term->id)->update(['actions_log' => json_encode($log)]);
        $term->refresh();

        $result = \App\Services\MayorService::tick($term, $city);

        
        $this->assertNull($result, 'term should end when bond default collapses assembly');
        $this->assertNotNull(MayorTerm::find($term->id)->ended_at);
    }

    public function test_suppression_assembly_collapse_end_reason_is_assembly(): void
    {
        $city  = $this->makeCity('supp-reason');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        
        $term->update(['city_funds' => 500_000, 'assembly_score' => abs(MayorTerm::DELTA_SUPPRESSION)]);

        $crimeRecord = DB::table('crime_records')->insertGetId([
            'city_id'      => $city->id,
            'character_id' => $this->makeResident($city)->id,
            'type'         => 'theft',
            'severity'     => 'minor',
            'status'       => 'open',
            'committed_at' => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/suppress', ['case_id' => $crimeRecord]);

        
        $response->assertRedirect(route('dashboard'));
        $this->assertNotNull(MayorTerm::find($term->id)->ended_at);
        $this->assertEquals(MayorTerm::END_ASSEMBLY, MayorTerm::find($term->id)->end_reason);
    }

    public function test_suppression_has_no_term_cap(): void
    {
        $city  = $this->makeCity('supp-max');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);
        $term->update(['city_funds' => 1_000_000]);

        
        $log = $term->actions_log;
        for ($i = 0; $i < 2; $i++) {
            $log['suppressions'][] = ['case_id' => $i + 1, 'at' => now()->getTimestamp(), 'period' => 1];
        }
        DB::table('mayor_terms')->where('id', $term->id)->update(['actions_log' => json_encode($log)]);
        $term->refresh();

        $crimeRecord = DB::table('crime_records')->insertGetId([
            'city_id'         => $city->id,
            'character_id'    => $mayor->id,
            'type'            => 'theft',
            'severity'        => 'minor',
            'status'          => 'open',
            'committed_at'    => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/suppress', ['case_id' => $crimeRecord]);

        $response->assertSessionHas('success');
        $this->assertSame(3, MayorTerm::find($term->id)->suppressionCount());
    }

    
    
    

    
    public function test_period_retainer_awards_xp_and_cash_on_clean_period(): void
    {
        $city  = $this->makeCity('retainer-ok');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);

        
        $startXp   = 5_000;
        $startCash = 100_000;
        $mayor->update([
            'career_xp'           => $startXp,
            'total_character_exp' => $startXp,
            'cash_on_hand'        => $startCash,
        ]);
        
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 0]);
        $term->update(['assembly_score' => 100, 'city_funds' => 500_000]);
        $term->addFunds(10_000, 'tax'); 

        $result = \App\Services\MayorService::tick($term, $city);

        
        $this->assertNotNull($result, 'tick() must not end the term on a clean period advance');

        $mayor->refresh();
        $this->assertEquals(
            $startXp + MayorTerm::RETAINER_XP,
            $mayor->career_xp,
            'career_xp must increase by RETAINER_XP after a successful period'
        );
        $this->assertEquals(
            $startXp + MayorTerm::RETAINER_XP,
            $mayor->total_character_exp,
            'total_character_exp must also increase by RETAINER_XP'
        );
        $this->assertEquals(
            $startCash + MayorTerm::RETAINER_CASH,
            $mayor->cash_on_hand,
            'cash_on_hand must increase by RETAINER_CASH after a successful period'
        );
    }

    
    public function test_period_retainer_not_awarded_on_assembly_collapse(): void
    {
        $city  = $this->makeCity('retainer-collapse');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);

        $startCash = 50_000;
        $startXp   = 1_000;
        $mayor->update(['cash_on_hand' => $startCash, 'career_xp' => $startXp, 'total_character_exp' => $startXp]);

        
        $term->update(['assembly_score' => 1, 'city_funds' => 500_000]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 80]);

        $result = \App\Services\MayorService::tick($term, $city);

        $this->assertNull($result, 'assembly collapse must return null');
        $mayor->refresh();

        
        $this->assertEquals($startCash, $mayor->cash_on_hand,
            'no cash retainer on assembly collapse');
        $this->assertEquals($startXp, $mayor->career_xp,
            'no xp retainer on assembly collapse');
    }

    
    public function test_period_retainer_not_awarded_on_term_completion(): void
    {
        $city  = $this->makeCity('retainer-complete');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);

        
        DB::table('mayor_terms')->where('id', $term->id)->update(['period' => MayorTerm::PERIODS_PER_TERM]);
        $term->refresh();

        $startCash = 50_000;
        $startXp   = 1_000;
        $mayor->update(['cash_on_hand' => $startCash, 'career_xp' => $startXp, 'total_character_exp' => $startXp]);

        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 0]);
        $term->update(['assembly_score' => 100, 'city_funds' => 500_000]);
        $term->addFunds(10_000, 'tax');

        $result = \App\Services\MayorService::tick($term, $city);

        $this->assertNull($result, 'tick() must return null after term completion');
        $this->assertEquals(
            MayorTerm::END_TERM_COMPLETE,
            MayorTerm::find($term->id)->end_reason
        );

        $mayor->refresh();
        $this->assertEquals($startCash, $mayor->cash_on_hand,
            'no cash retainer on term completion');
        $this->assertEquals($startXp, $mayor->career_xp,
            'no xp retainer on term completion');
    }

    
    public function test_period_retainer_accumulates_across_multiple_periods(): void
    {
        $city  = $this->makeCity('retainer-multi');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeOverdueTerm($city, $mayor);

        $startCash = 0;
        $startXp   = 0;
        $mayor->update(['cash_on_hand' => $startCash, 'career_xp' => $startXp, 'total_character_exp' => $startXp]);
        DB::table('cities')->where('id', $city->id)->update(['crime_rate' => 0]);
        $term->update(['assembly_score' => 100, 'city_funds' => 500_000]);

        $periodsCompleted = 0;
        for ($p = 1; $p <= 3; $p++) {
            $term->addFunds(10_000, 'tax');
            $result = \App\Services\MayorService::tick($term, $city);
            $this->assertNotNull($result, "tick() must not end term at period {$p}");
            $periodsCompleted++;

            $term->refresh();
            DB::table('mayor_terms')
                ->where('id', $term->id)
                ->update(['last_period_at' => now()->subSeconds(MayorTerm::PERIOD_SECONDS + 60)->getTimestamp()]);
            $term->refresh();
        }

        $mayor->refresh();
        $this->assertEquals(
            $startCash + ($periodsCompleted * MayorTerm::RETAINER_CASH),
            $mayor->cash_on_hand,
            "cash_on_hand must reflect {$periodsCompleted} retainer payments"
        );
        $this->assertEquals(
            $startXp + ($periodsCompleted * MayorTerm::RETAINER_XP),
            $mayor->career_xp,
            "career_xp must reflect {$periodsCompleted} retainer payments"
        );
    }

    
    
    

    
    public function test_assembly_collapse_removal_deducts_10_influence(): void
    {
        $city  = $this->makeCity('inf-kick');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);

        $startInfluence = 50.0;
        CharacterStats::where('character_id', $mayor->id)->update(['influence' => $startInfluence]);

        \App\Services\MayorService::removeMayor($term, $city, MayorTerm::END_ASSEMBLY);

        $newInfluence = CharacterStats::where('character_id', $mayor->id)->value('influence');
        $this->assertEquals(
            $startInfluence - MayorTerm::INFLUENCE_PENALTY_KICKED,
            (float) $newInfluence,
            'Assembly collapse must deduct INFLUENCE_PENALTY_KICKED influence'
        );
    }

    
    public function test_force_removal_deducts_10_influence(): void
    {
        $city  = $this->makeCity('inf-force');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);

        $startInfluence = 30.0;
        CharacterStats::where('character_id', $mayor->id)->update(['influence' => $startInfluence]);

        \App\Services\MayorService::removeMayor($term, $city, MayorTerm::END_REMOVED);

        $newInfluence = (float) CharacterStats::where('character_id', $mayor->id)->value('influence');
        $this->assertEquals(
            $startInfluence - MayorTerm::INFLUENCE_PENALTY_KICKED,
            $newInfluence,
            'Force removal must deduct INFLUENCE_PENALTY_KICKED influence'
        );
    }

    
    public function test_resign_deducts_3_influence(): void
    {
        $city  = $this->makeCity('inf-resign');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);

        $startInfluence = 20.0;
        CharacterStats::where('character_id', $mayor->id)->update(['influence' => $startInfluence]);

        $response = $this->actingAs($mayor->user)
            ->post('/career/politics/resign');

        $response->assertRedirect(route('dashboard'));

        $newInfluence = (float) CharacterStats::where('character_id', $mayor->id)->value('influence');
        $this->assertEquals(
            $startInfluence - MayorTerm::INFLUENCE_PENALTY_RESIGNED,
            $newInfluence,
            'Voluntary resign must deduct INFLUENCE_PENALTY_RESIGNED influence'
        );
    }

    
    public function test_term_completion_has_no_influence_penalty(): void
    {
        $city  = $this->makeCity('inf-complete');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);

        $startInfluence = 40.0;
        CharacterStats::where('character_id', $mayor->id)->update(['influence' => $startInfluence]);

        \App\Services\MayorService::removeMayor($term, $city, MayorTerm::END_TERM_COMPLETE);

        $newInfluence = (float) CharacterStats::where('character_id', $mayor->id)->value('influence');
        $this->assertEquals(
            $startInfluence,
            $newInfluence,
            'Clean term completion must not deduct any influence'
        );
    }

    
    public function test_influence_penalty_clamped_to_zero(): void
    {
        $city  = $this->makeCity('inf-floor');
        $mayor = $this->makeMayor($city);
        $term  = $this->makeActiveTerm($city, $mayor);

        
        $startInfluence = (float) MayorTerm::INFLUENCE_PENALTY_KICKED - 5.0;
        CharacterStats::where('character_id', $mayor->id)->update(['influence' => $startInfluence]);

        \App\Services\MayorService::removeMayor($term, $city, MayorTerm::END_ASSEMBLY);

        $newInfluence = (float) CharacterStats::where('character_id', $mayor->id)->value('influence');
        $this->assertEquals(0.0, $newInfluence, 'removeInfluence must clamp to 0, never go negative');
    }
}
