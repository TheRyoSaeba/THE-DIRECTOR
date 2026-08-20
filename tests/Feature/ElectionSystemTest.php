<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\City;
use App\Models\Election;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class ElectionSystemTest extends TestCase
{
    use DatabaseTransactions;

    private int $politicsCareerId;
    private int $unemployedCareerId;
    private int $corporateCareerId;

    

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $politics   = DB::table('careers')->where('code', 'politics')->first();
        $unemployed = DB::table('careers')->where('code', 'unemployed')->first();
        $corporate  = DB::table('careers')->where('code', 'corporate')->orWhere('code', 'business')->first();

        $this->assertNotNull($politics,   'politics career must exist');
        $this->assertNotNull($unemployed, 'unemployed career must exist');

        $this->politicsCareerId   = $politics->id;
        $this->unemployedCareerId = $unemployed->id;
        
        $this->corporateCareerId  = $corporate?->id ?? $unemployed->id;
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

    private function makeEligibleCharacter(City $city, ?User $user = null): Character
    {
        $user ??= User::factory()->create();

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'Candidate-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $city->id,
            'home_city_id' => $city->id,
            'career_id'    => $this->politicsCareerId,
            'career_rank'  => Election::MIN_RANK_REQUIRED,
            'career_xp'    => 1000,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => Election::APPLICATION_FEE + 100_000,
            'cash_in_bank' => Election::CAMPAIGN_FUND_REQUIREMENT + 500_000,
        ]);

        CharacterStats::create(['character_id' => $char->id, 'influence' => 10]);

        return $char;
    }

    private function makeVoter(City $city, ?User $user = null): Character
    {
        $user ??= User::factory()->create();

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'Voter-' . uniqid(),
            'gender'       => 'female',
            'city_id'      => $city->id,
            'home_city_id' => $city->id,
            'career_id'    => $this->unemployedCareerId,
            'career_rank'  => 1,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => 500,
            'cash_in_bank' => 500,
        ]);

        CharacterStats::create(['character_id' => $char->id]);

        return $char;
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

        $char = Character::create([
            'user_id'          => $user->id,
            'display_name'     => 'CorpMember-' . uniqid(),
            'gender'           => 'male',
            'city_id'          => $city->id,
            'home_city_id'     => $city->id,
            'career_id'        => $this->politicsCareerId,
            'career_rank'      => Election::MIN_RANK_REQUIRED,
            'career_xp'        => 1000,
            'health'           => 100,
            'max_health'       => 100,
            'cash_on_hand'     => Election::APPLICATION_FEE + 100_000,
            'cash_in_bank'     => Election::CAMPAIGN_FUND_REQUIREMENT + 500_000,
            'corporation_id'   => $corpId,
        ]);

        CharacterStats::create(['character_id' => $char->id, 'influence' => 10]);

        return $char;
    }

    private function makeExpiredRegistration(City $city, int $cycle = 1): Election
    {
        return Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => $cycle,
            'status'             => 'registration',
            'registration_start' => now()->subDays(3),
            'registration_end'   => now()->subHour(),
        ]);
    }

    private function makeExpiredVoting(City $city, int $cycle = 1): Election
    {
        return Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => $cycle,
            'status'             => 'voting',
            'registration_start' => now()->subDays(4),
            'registration_end'   => now()->subDays(2),
            'voting_start'       => now()->subDays(2),
            'voting_end'         => now()->subHour(),
        ]);
    }

    private function registerCampaign(
        Election  $election,
        Character $candidate,
        int       $votes = 0,
        int       $fund  = 0,
    ): Campaign {
        return Campaign::create([
            'election_id'  => $election->id,
            'candidate_id' => $candidate->id,
            'city_id'      => $election->city_id,
            'manifesto'    => 'Test manifesto — ' . $candidate->display_name,
            'campaign_fund'=> $fund ?: Election::CAMPAIGN_FUND_REQUIREMENT,
            'votes'        => $votes,
            'status'       => 'active',
        ]);
    }

    
    
    

    public function test_start_for_city_creates_registration_election(): void
    {
        $city     = $this->makeCity('start');
        $election = Election::startForCity($city);

        $this->assertDatabaseHas('elections', [
            'city_id'      => $city->id,
            'status'       => 'registration',
            'cycle_number' => 1,
        ]);

        $this->assertNotNull($election->registration_start);
        $this->assertNotNull($election->registration_end);
        $this->assertNull($election->voting_start);
        $this->assertNull($election->voting_end);
        $this->assertTrue($election->registration_end->isFuture());
        $this->assertTrue($election->isRegistrationOpen());
    }

    public function test_cycle_number_increments_for_subsequent_elections(): void
    {
        $city = $this->makeCity('cycle');

        $e1 = Election::startForCity($city);
        $e1->update(['status' => 'completed']);

        $e2 = Election::startForCity($city);
        $e2->update(['status' => 'completed']);

        $e3 = Election::startForCity($city);

        $this->assertEquals(1, $e1->cycle_number);
        $this->assertEquals(2, $e2->cycle_number);
        $this->assertEquals(3, $e3->cycle_number);
    }

    public function test_registration_period_length_matches_constant(): void
    {
        $city     = $this->makeCity('period');
        $election = Election::startForCity($city);

        $expected = $election->registration_start
            ->copy()
            ->addDays(Election::REGISTRATION_PERIOD_DAYS);

        $this->assertTrue(
            $election->registration_end->eq($expected),
            'registration_end must be exactly REGISTRATION_PERIOD_DAYS after start',
        );
    }

    
    
    

    public function test_eligibility_passes_for_fully_qualified_character(): void
    {
        $city   = $this->makeCity('elig-pass');
        $char   = $this->makeEligibleCharacter($city);
        $errors = Election::checkCharacterEligibility($char, $city);

        $this->assertEmpty($errors, 'Expected no errors for a fully qualified candidate');
    }

    public function test_eligibility_fails_for_rank_below_minimum(): void
    {
        $city = $this->makeCity('elig-rank');
        $char = $this->makeEligibleCharacter($city);
        $char->update(['career_rank' => Election::MIN_RANK_REQUIRED - 1]);

        $errors = Election::checkCharacterEligibility($char->fresh(), $city);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString(
            'Rank ' . Election::MIN_RANK_REQUIRED,
            implode(' ', $errors),
        );
    }

    public function test_eligibility_fails_for_wrong_home_city(): void
    {
        $home  = $this->makeCity('elig-homea');
        $other = $this->makeCity('elig-homeb');
        $char  = $this->makeEligibleCharacter($home);

        $errors = Election::checkCharacterEligibility($char, $other);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('home city', implode(' ', $errors));
    }

    public function test_eligibility_fails_for_insufficient_cash_on_hand(): void
    {
        $city = $this->makeCity('elig-cash');
        $char = $this->makeEligibleCharacter($city);
        $char->update(['cash_on_hand' => Election::APPLICATION_FEE - 1]);

        $errors = Election::checkCharacterEligibility($char->fresh(), $city);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('application fee', implode(' ', $errors));
    }

    public function test_eligibility_fails_for_insufficient_bank_funds(): void
    {
        $city = $this->makeCity('elig-bank');
        $char = $this->makeEligibleCharacter($city);
        $char->update(['cash_in_bank' => Election::CAMPAIGN_FUND_REQUIREMENT - 1]);

        $errors = Election::checkCharacterEligibility($char->fresh(), $city);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('campaign fund', implode(' ', $errors));
    }

    public function test_eligibility_fails_if_already_serving_as_mayor(): void
    {
        $city = $this->makeCity('elig-mayor');
        $char = $this->makeEligibleCharacter($city);
        $city->setMayor($char);

        $errors = Election::checkCharacterEligibility($char, $city->fresh());

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('already serving as mayor', implode(' ', $errors));
    }

    
    public function test_eligibility_fails_for_corporation_member(): void
    {
        $city = $this->makeCity('elig-corp');
        $char = $this->makeCorporateMember($city);

        $errors = Election::checkCharacterEligibility($char->fresh(), $city);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('corporation', implode(' ', $errors));
    }

    public function test_eligibility_returns_all_errors_at_once_not_just_first(): void
    {
        $city = $this->makeCity('elig-multi');
        $char = $this->makeEligibleCharacter($city);

        
        $char->update([
            'career_rank'  => Election::MIN_RANK_REQUIRED - 1,
            'cash_on_hand' => 0,
        ]);

        $errors = Election::checkCharacterEligibility($char->fresh(), $city);

        $this->assertGreaterThanOrEqual(2, count($errors), 'Must collect all errors, not short-circuit on first');
    }

    
    
    

    public function test_can_apply_returns_true_for_eligible_candidate(): void
    {
        $city     = $this->makeCity('apply-ok');
        $char     = $this->makeEligibleCharacter($city);
        $election = Election::startForCity($city);

        $result = $election->canApply($char);

        $this->assertTrue($result['can_apply']);
        $this->assertEmpty($result['errors']);
    }

    public function test_can_apply_fails_when_registration_period_has_closed(): void
    {
        $city     = $this->makeCity('apply-closed');
        $char     = $this->makeEligibleCharacter($city);
        $election = $this->makeExpiredRegistration($city);

        $result = $election->canApply($char);

        $this->assertFalse($result['can_apply']);
        $this->assertStringContainsString(
            'Registration period is not open',
            implode(' ', $result['errors']),
        );
    }

    public function test_can_apply_fails_when_max_candidates_already_registered(): void
    {
        $city     = $this->makeCity('apply-full');
        $election = Election::startForCity($city);

        for ($i = 0; $i < Election::MAX_CANDIDATES; $i++) {
            $this->registerCampaign($election, $this->makeEligibleCharacter($city));
        }

        $result = $election->canApply($this->makeEligibleCharacter($city));

        $this->assertFalse($result['can_apply']);
        $this->assertStringContainsString('Maximum candidates', implode(' ', $result['errors']));
    }

    public function test_can_apply_fails_when_candidate_already_registered(): void
    {
        $city     = $this->makeCity('apply-dup');
        $char     = $this->makeEligibleCharacter($city);
        $election = Election::startForCity($city);

        $this->registerCampaign($election, $char);

        $result = $election->canApply($char);

        $this->assertFalse($result['can_apply']);
        $this->assertStringContainsString('already applied', implode(' ', $result['errors']));
    }

    public function test_can_apply_fails_for_corporation_member(): void
    {
        $city     = $this->makeCity('apply-corp');
        $election = Election::startForCity($city);
        $char     = $this->makeCorporateMember($city);

        $result = $election->canApply($char->fresh());

        $this->assertFalse($result['can_apply']);
        $this->assertStringContainsString('corporation', implode(' ', $result['errors']));
    }

    
    
    

    public function test_is_registration_open_returns_true_while_deadline_is_future(): void
    {
        $city = $this->makeCity('reg-open');
        $this->assertTrue(Election::startForCity($city)->isRegistrationOpen());
    }

    public function test_is_registration_open_returns_false_after_deadline(): void
    {
        $city = $this->makeCity('reg-closed');
        $this->assertFalse($this->makeExpiredRegistration($city)->isRegistrationOpen());
    }

    public function test_is_voting_open_returns_true_while_deadline_is_future(): void
    {
        $city     = $this->makeCity('vote-open');
        $election = Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(3),
            'registration_end'   => now()->subDays(1),
            'voting_start'       => now()->subHour(),
            'voting_end'         => now()->addDay(),
        ]);
        $this->assertTrue($election->isVotingOpen());
    }

    public function test_is_voting_open_returns_false_after_deadline(): void
    {
        $city = $this->makeCity('vote-closed');
        $this->assertFalse($this->makeExpiredVoting($city)->isVotingOpen());
    }

    
    
    

    public function test_start_voting_advances_status_when_registration_expired(): void
    {
        $city     = $this->makeCity('sv-advance');
        $election = $this->makeExpiredRegistration($city);

        $election->startVoting();
        $election->refresh();

        $this->assertEquals('voting', $election->status);
        $this->assertNotNull($election->voting_start);
        $this->assertNotNull($election->voting_end);
        $this->assertTrue($election->voting_end->isFuture());
    }

    public function test_start_voting_sets_correct_voting_duration(): void
    {
        $city     = $this->makeCity('sv-duration');
        $election = $this->makeExpiredRegistration($city);

        $election->startVoting();
        $election->refresh();

        $expected = $election->voting_start->copy()->addDays(Election::VOTING_PERIOD_DAYS);
        $this->assertTrue(
            $election->voting_end->eq($expected),
            'voting_end must be exactly VOTING_PERIOD_DAYS after voting_start',
        );
    }

    public function test_start_voting_does_nothing_while_registration_still_open(): void
    {
        $city     = $this->makeCity('sv-noop');
        $election = Election::startForCity($city);

        $election->startVoting();
        $election->refresh();

        $this->assertEquals('registration', $election->status);
        $this->assertNull($election->voting_start);
    }

    public function test_start_voting_does_nothing_if_already_in_voting_status(): void
    {
        $city     = $this->makeCity('sv-already-voting');
        $election = $this->makeExpiredVoting($city);

        $originalEnd = $election->voting_end->copy();

        $election->startVoting();
        $election->refresh();

        $this->assertEquals('voting', $election->status);
        $this->assertTrue($election->voting_end->eq($originalEnd));
    }

    
    
    

    public function test_finalize_crowns_candidate_with_highest_vote_score(): void
    {
        $city     = $this->makeCity('fin-winner');
        $election = $this->makeExpiredVoting($city);
        $winner   = $this->makeEligibleCharacter($city);
        $loser    = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $winner, votes: 10);
        $this->registerCampaign($election, $loser,  votes: 2);
        $election->update(['total_votes' => 12]);

        $election->finalize();
        $election->refresh();

        $this->assertEquals('completed', $election->status);
        $this->assertEquals($winner->id, $election->winner_id);
        $this->assertDatabaseHas('campaigns', ['candidate_id' => $winner->id, 'status' => 'won']);
        $this->assertDatabaseHas('campaigns', ['candidate_id' => $loser->id,  'status' => 'lost']);
    }

    public function test_finalize_sets_city_mayor_to_winner(): void
    {
        $city     = $this->makeCity('fin-mayor');
        $election = $this->makeExpiredVoting($city);
        $winner   = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $winner, votes: 5);
        $election->update(['total_votes' => 5]);

        $election->finalize();
        $city->refresh();

        $this->assertEquals($winner->id,               $city->mayor_id);
        $this->assertEquals($winner->display_name,     $city->mayor_display_name);
    }

    public function test_finalize_sets_winner_career_to_politics_at_rank_1(): void
    {
        $city     = $this->makeCity('fin-career');
        $election = $this->makeExpiredVoting($city);
        $winner   = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $winner, votes: 5);
        $election->update(['total_votes' => 5]);

        $election->finalize();

        $this->assertDatabaseHas('characters', [
            'id'          => $winner->id,
            'career_id'   => $this->politicsCareerId,
            'career_rank' => 1,
        ]);
    }

    public function test_finalize_sets_term_dates_matching_constant(): void
    {
        $city     = $this->makeCity('fin-term');
        $election = $this->makeExpiredVoting($city);
        $winner   = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $winner, votes: 1);
        $election->update(['total_votes' => 1]);

        $election->finalize();
        $election->refresh();

        $this->assertNotNull($election->term_start);
        $this->assertNotNull($election->term_end);
        $this->assertTrue($election->term_end->isFuture());

        $expectedEnd = $election->term_start->copy()->addDays(Election::TERM_DURATION_DAYS);
        $this->assertTrue(
            $election->term_end->eq($expectedEnd),
            'term_end must be exactly TERM_DURATION_DAYS after term_start',
        );
    }

    public function test_finalize_writes_journal_entries_for_all_candidates(): void
    {
        $city     = $this->makeCity('fin-journal');
        $election = $this->makeExpiredVoting($city);
        $winner   = $this->makeEligibleCharacter($city);
        $loser    = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $winner, votes: 7);
        $this->registerCampaign($election, $loser,  votes: 3);
        $election->update(['total_votes' => 10]);

        $election->finalize();

        $this->assertDatabaseHas('character_journals', ['character_id' => $winner->id, 'type' => 'election_result']);
        $this->assertDatabaseHas('character_journals', ['character_id' => $loser->id,  'type' => 'election_result']);
    }

    public function test_finalize_winner_journal_marks_won_true_and_includes_term_end(): void
    {
        $city     = $this->makeCity('fin-jrn-won');
        $election = $this->makeExpiredVoting($city);
        $winner   = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $winner, votes: 3);
        $election->update(['total_votes' => 3]);

        $election->finalize();

        $journal = DB::table('character_journals')
            ->where('character_id', $winner->id)
            ->where('type', 'election_result')
            ->first();

        $this->assertNotNull($journal);
        $data = json_decode($journal->data, true);
        $this->assertTrue($data['won']);
        $this->assertNotNull($data['term_end']);
    }

    public function test_finalize_loser_journal_marks_won_false_and_has_no_term_end(): void
    {
        $city     = $this->makeCity('fin-jrn-lost');
        $election = $this->makeExpiredVoting($city);
        $winner   = $this->makeEligibleCharacter($city);
        $loser    = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $winner, votes: 9);
        $this->registerCampaign($election, $loser,  votes: 1);
        $election->update(['total_votes' => 10]);

        $election->finalize();

        $journal = DB::table('character_journals')
            ->where('character_id', $loser->id)
            ->where('type', 'election_result')
            ->first();

        $this->assertNotNull($journal);
        $data = json_decode($journal->data, true);
        $this->assertFalse($data['won']);
        $this->assertNull($data['term_end']);
    }

    public function test_finalize_with_no_candidates_completes_without_a_mayor(): void
    {
        $city     = $this->makeCity('fin-empty');
        $election = $this->makeExpiredVoting($city);

        $election->finalize();
        $election->refresh();

        $this->assertEquals('completed', $election->status);
        $this->assertNull($election->winner_id);
        $this->assertNull($city->fresh()->mayor_id);
    }

    public function test_finalize_does_not_run_while_voting_is_still_open(): void
    {
        $city     = $this->makeCity('fin-open');
        $election = Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(3),
            'registration_end'   => now()->subDays(1),
            'voting_start'       => now()->subHour(),
            'voting_end'         => now()->addHours(6),
        ]);

        $winner = $this->makeEligibleCharacter($city);
        $this->registerCampaign($election, $winner, votes: 5);

        $election->finalize();
        $election->refresh();

        $this->assertEquals('voting', $election->status);
        $this->assertNull($election->winner_id);
    }

    public function test_finalize_is_idempotent_second_call_is_a_noop(): void
    {
        $city     = $this->makeCity('fin-idem');
        $election = $this->makeExpiredVoting($city);
        $winner   = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $winner, votes: 1);
        $election->update(['total_votes' => 1]);

        $election->finalize();
        $election->finalize(); 

        $this->assertEquals(
            1,
            Election::where('city_id', $city->id)->where('status', 'completed')->count(),
            'Exactly one completed election row expected',
        );

        $this->assertEquals(
            1,
            DB::table('character_journals')
                ->where('character_id', $winner->id)
                ->where('type', 'election_result')
                ->count(),
            'Exactly one journal entry expected for the winner',
        );
    }

    public function test_finalize_breaks_tie_by_campaign_fund_component(): void
    {
        $city     = $this->makeCity('fin-tie');
        $election = $this->makeExpiredVoting($city);
        $richer   = $this->makeEligibleCharacter($city);
        $poorer   = $this->makeEligibleCharacter($city);

        Campaign::create([
            'election_id'   => $election->id,
            'candidate_id'  => $richer->id,
            'city_id'       => $city->id,
            'manifesto'     => 'Big fund',
            'campaign_fund' => Election::CAMPAIGN_FUND_REQUIREMENT * 4,
            'votes'         => 5,
            'status'        => 'active',
        ]);
        Campaign::create([
            'election_id'   => $election->id,
            'candidate_id'  => $poorer->id,
            'city_id'       => $city->id,
            'manifesto'     => 'Small fund',
            'campaign_fund' => Election::CAMPAIGN_FUND_REQUIREMENT,
            'votes'         => 5,
            'status'        => 'active',
        ]);
        $election->update(['total_votes' => 10]);

        $election->finalize();
        $election->refresh();

        $this->assertEquals($richer->id, $election->winner_id);
    }

    public function test_finalize_vote_percentage_is_calculated_correctly(): void
    {
        $city     = $this->makeCity('fin-pct');
        $election = $this->makeExpiredVoting($city);
        $cA       = $this->makeEligibleCharacter($city);
        $cB       = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $cA, votes: 7);
        $this->registerCampaign($election, $cB, votes: 3);
        $election->update(['total_votes' => 10]);

        $election->finalize();

        $this->assertEquals(70.0, Campaign::where('candidate_id', $cA->id)->first()->vote_percentage);
        $this->assertEquals(30.0, Campaign::where('candidate_id', $cB->id)->first()->vote_percentage);
    }

    
    
    
    
    
    

    public function test_finalize_disqualifies_candidate_who_joined_corporation_mid_campaign(): void
    {
        $city     = $this->makeCity('fin-corp-disq');
        $election = $this->makeExpiredVoting($city);

        
        $user      = User::factory()->create();
        $candidate = $this->makeEligibleCharacter($city, $user);
        $campaign  = $this->registerCampaign($election, $candidate, votes: 10);

        
        $corpId = DB::table('corporations')->insertGetId([
            'name'       => 'CorpMidCampaign-' . uniqid(),
            'slug'       => 'corp-mid-' . uniqid(),
            'city_id'    => $city->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $candidate->update(['corporation_id' => $corpId]);

        $election->update(['total_votes' => 10]);
        $election->finalize();
        $election->refresh();

        
        $this->assertDatabaseHas('campaigns', [
            'id'     => $campaign->id,
            'status' => 'disqualified',
        ]);

        
        $this->assertNull($city->fresh()->mayor_id);

        
        $this->assertDatabaseHas('character_journals', [
            'character_id' => $candidate->id,
            'type'         => 'election_disqualified',
        ]);
    }

    public function test_finalize_clean_candidate_wins_when_rival_is_disqualified_for_corporation(): void
    {
        $city     = $this->makeCity('fin-corp-clean-win');
        $election = $this->makeExpiredVoting($city);

        $clean = $this->makeEligibleCharacter($city);
        $dirty = $this->makeEligibleCharacter($city);

        
        $this->registerCampaign($election, $clean, votes: 3);
        $dirtyCampaign = $this->registerCampaign($election, $dirty, votes: 10);

        $corpId = DB::table('corporations')->insertGetId([
            'name'       => 'CorpLate-' . uniqid(),
            'slug'       => 'corp-late-' . uniqid(),
            'city_id'    => $city->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dirty->update(['corporation_id' => $corpId]);

        $election->update(['total_votes' => 13]);
        $election->finalize();
        $election->refresh();

        
        $this->assertDatabaseHas('campaigns', ['id' => $dirtyCampaign->id, 'status' => 'disqualified']);

        
        $this->assertEquals($clean->id, $election->winner_id);
        $this->assertEquals($clean->id, $city->fresh()->mayor_id);
    }

    public function test_finalize_completes_with_no_mayor_when_all_candidates_disqualified(): void
    {
        $city     = $this->makeCity('fin-all-disq');
        $election = $this->makeExpiredVoting($city);

        $corpId = DB::table('corporations')->insertGetId([
            'name'       => 'CorpAll-' . uniqid(),
            'slug'       => 'corp-all-' . uniqid(),
            'city_id'    => $city->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $c1 = $this->makeEligibleCharacter($city);
        $c2 = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $c1, votes: 5);
        $this->registerCampaign($election, $c2, votes: 3);

        
        $c1->update(['corporation_id' => $corpId]);
        $c2->update(['corporation_id' => $corpId]);

        $election->update(['total_votes' => 8]);
        $election->finalize();
        $election->refresh();

        $this->assertEquals('completed', $election->status);
        $this->assertNull($election->winner_id);
        $this->assertNull($city->fresh()->mayor_id);
    }

    
    
    

    public function test_command_advances_expired_registration_to_voting(): void
    {
        $city     = $this->makeCity('cmd-advance');
        $election = $this->makeExpiredRegistration($city);

        $this->artisan('elections:process')->assertExitCode(0);

        $this->assertEquals('voting', $election->fresh()->status);
    }

    public function test_command_does_not_advance_election_with_open_registration(): void
    {
        $city     = $this->makeCity('cmd-no-advance');
        $election = Election::startForCity($city);

        $this->artisan('elections:process')->assertExitCode(0);

        $this->assertEquals('registration', $election->fresh()->status);
    }

    public function test_command_finalizes_expired_voting_and_sets_mayor(): void
    {
        $city     = $this->makeCity('cmd-finalize');
        $election = $this->makeExpiredVoting($city);
        $winner   = $this->makeEligibleCharacter($city);

        $this->registerCampaign($election, $winner, votes: 5);
        $election->update(['total_votes' => 5]);

        $this->artisan('elections:process')->assertExitCode(0);

        $election->refresh();
        $this->assertEquals('completed',  $election->status);
        $this->assertEquals($winner->id,  $election->winner_id);
        $this->assertEquals($winner->id,  $city->fresh()->mayor_id);
    }

    public function test_command_does_not_finalize_voting_still_open(): void
    {
        $city     = $this->makeCity('cmd-no-finalize');
        $election = Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(3),
            'registration_end'   => now()->subDays(1),
            'voting_start'       => now()->subHour(),
            'voting_end'         => now()->addHours(6),
        ]);

        $winner = $this->makeEligibleCharacter($city);
        $this->registerCampaign($election, $winner, votes: 3);

        $this->artisan('elections:process')->assertExitCode(0);

        $this->assertEquals('voting', $election->fresh()->status);
        $this->assertNull($city->fresh()->mayor_id);
    }

    public function test_command_expires_mayor_term_and_removes_mayor(): void
    {
        $city  = $this->makeCity('cmd-expire');
        $mayor = $this->makeEligibleCharacter($city);

        Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'completed',
            'registration_start' => now()->subDays(12),
            'registration_end'   => now()->subDays(10),
            'voting_start'       => now()->subDays(10),
            'voting_end'         => now()->subDays(9),
            'winner_id'          => $mayor->id,
            'total_votes'        => 1,
            'term_start'         => now()->subDays(9),
            'term_end'           => now()->subHour(), 
        ]);

        $city->setMayor($mayor);
        $this->assertNotNull($city->fresh()->mayor_id);

        $this->artisan('elections:process')->assertExitCode(0);

        $city->refresh();
        $this->assertNull($city->mayor_id, 'Mayor should be removed after term expiry');
        $this->assertNull($city->mayor_display_name);
    }

    public function test_command_does_not_expire_mayor_with_future_term(): void
    {
        $city  = $this->makeCity('cmd-no-expire');
        $mayor = $this->makeEligibleCharacter($city);

        Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'completed',
            'registration_start' => now()->subDays(10),
            'registration_end'   => now()->subDays(8),
            'voting_start'       => now()->subDays(8),
            'voting_end'         => now()->subDays(7),
            'winner_id'          => $mayor->id,
            'total_votes'        => 1,
            'term_start'         => now()->subDays(7),
            'term_end'           => now()->addDays(3), 
        ]);

        $city->setMayor($mayor);

        $this->artisan('elections:process')->assertExitCode(0);

        $this->assertEquals(
            $mayor->id,
            $city->fresh()->mayor_id,
            'Mayor should remain while term is active',
        );
    }

    
    
    

    public function test_apply_creates_campaign_and_deducts_fees(): void
    {
        $city   = $this->makeCity('http-apply');
        $user   = User::factory()->create();
        $char   = $this->makeEligibleCharacter($city, $user);

        $cashBefore = $char->cash_on_hand;
        $bankBefore = $char->cash_in_bank;

        $this->actingAs($user)
            ->post("/{$city->slug}/election/apply", ['manifesto' => str_repeat('A', 60)])
            ->assertRedirect()
            ->assertSessionHas('success');

        $char->refresh();
        $this->assertEquals($cashBefore - Election::APPLICATION_FEE,        $char->cash_on_hand);
        $this->assertEquals($bankBefore - Election::CAMPAIGN_FUND_REQUIREMENT, $char->cash_in_bank);

        $this->assertDatabaseHas('campaigns', [
            'candidate_id'  => $char->id,
            'campaign_fund' => Election::CAMPAIGN_FUND_REQUIREMENT,
            'status'        => 'active',
        ]);
    }

    public function test_apply_starts_a_new_election_when_none_exists(): void
    {
        $city = $this->makeCity('http-apply-new');
        $user = User::factory()->create();
        $this->makeEligibleCharacter($city, $user);

        $this->assertDatabaseMissing('elections', ['city_id' => $city->id]);

        $this->actingAs($user)
            ->post("/{$city->slug}/election/apply", ['manifesto' => str_repeat('B', 60)])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('elections', ['city_id' => $city->id, 'status' => 'registration']);
    }

    public function test_apply_is_rejected_when_manifesto_is_too_short(): void
    {
        $city = $this->makeCity('http-apply-short');
        $user = User::factory()->create();
        $this->makeEligibleCharacter($city, $user);

        $this->actingAs($user)
            ->post("/{$city->slug}/election/apply", ['manifesto' => 'Too short'])
            ->assertSessionHasErrors('manifesto');
    }

    public function test_apply_is_rejected_for_insufficient_cash_on_hand(): void
    {
        $city = $this->makeCity('http-apply-broke');
        $user = User::factory()->create();
        $char = $this->makeEligibleCharacter($city, $user);
        $char->update(['cash_on_hand' => Election::APPLICATION_FEE - 1]);

        $this->actingAs($user)
            ->post("/{$city->slug}/election/apply", ['manifesto' => str_repeat('C', 60)])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('campaigns', ['candidate_id' => $char->id]);
    }

    public function test_apply_is_rejected_for_duplicate_candidacy(): void
    {
        $city     = $this->makeCity('http-apply-dup');
        $user     = User::factory()->create();
        $char     = $this->makeEligibleCharacter($city, $user);
        $election = Election::startForCity($city);

        $this->registerCampaign($election, $char);

        $this->actingAs($user)
            ->post("/{$city->slug}/election/apply", ['manifesto' => str_repeat('D', 60)])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(1, Campaign::where('candidate_id', $char->id)->count(), 'No second campaign should be created');
    }

    public function test_apply_is_rejected_when_registration_is_closed(): void
    {
        $city     = $this->makeCity('http-apply-closed');
        $user     = User::factory()->create();
        $char     = $this->makeEligibleCharacter($city, $user);
        $election = $this->makeExpiredRegistration($city);

        $election->update(['status' => 'voting', 'voting_end' => now()->addDay()]);

        $this->actingAs($user)
            ->post("/{$city->slug}/election/apply", ['manifesto' => str_repeat('E', 60)])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    
    public function test_apply_is_rejected_for_corporation_member(): void
    {
        $city = $this->makeCity('http-apply-corp');
        $user = User::factory()->create();
        $char = $this->makeCorporateMember($city, $user);

        $this->actingAs($user)
            ->post("/{$city->slug}/election/apply", ['manifesto' => str_repeat('F', 60)])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('campaigns', ['candidate_id' => $char->id]);
    }

    
    
    

    public function test_vote_records_vote_and_increments_counts(): void
    {
        $city     = $this->makeCity('http-vote');
        $election = Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(3),
            'registration_end'   => now()->subDays(1),
            'voting_start'       => now()->subHour(),
            'voting_end'         => now()->addDay(),
            'total_votes'        => 0,
        ]);

        $candidateUser = User::factory()->create();
        $candidate     = $this->makeEligibleCharacter($city, $candidateUser);
        $campaign      = $this->registerCampaign($election, $candidate);

        $voterUser = User::factory()->create();
        $this->makeVoter($city, $voterUser);

        $this->actingAs($voterUser)
            ->post("/{$city->slug}/election/vote", ['campaign_id' => $campaign->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('campaign_votes', [
            'election_id' => $election->id,
            'campaign_id' => $campaign->id,
        ]);

        $this->assertEquals(1, $campaign->fresh()->votes);
        $this->assertEquals(1, $election->fresh()->total_votes);
    }

    public function test_vote_is_rejected_when_voter_already_voted(): void
    {
        $city     = $this->makeCity('http-vote-dup');
        $election = Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(3),
            'registration_end'   => now()->subDays(1),
            'voting_start'       => now()->subHour(),
            'voting_end'         => now()->addDay(),
            'total_votes'        => 0,
        ]);

        $candidate = $this->makeEligibleCharacter($city);
        $campaign  = $this->registerCampaign($election, $candidate);

        $voterUser = User::factory()->create();
        $voter     = $this->makeVoter($city, $voterUser);

        
        DB::table('campaign_votes')->insert([
            'election_id' => $election->id,
            'campaign_id' => $campaign->id,
            'voter_id'    => $voter->id,
            'created_at'  => now(),
        ]);
        $election->increment('total_votes');
        $campaign->increment('votes');

        $this->actingAs($voterUser)
            ->post("/{$city->slug}/election/vote", ['campaign_id' => $campaign->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(1, $campaign->fresh()->votes);
    }

    public function test_vote_is_rejected_for_non_home_city_resident(): void
    {
        $homeCity  = $this->makeCity('http-vote-home');
        $otherCity = $this->makeCity('http-vote-other');

        $election = Election::create([
            'city_id'            => $otherCity->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(3),
            'registration_end'   => now()->subDays(1),
            'voting_start'       => now()->subHour(),
            'voting_end'         => now()->addDay(),
            'total_votes'        => 0,
        ]);

        $candidate = $this->makeEligibleCharacter($otherCity);
        $campaign  = $this->registerCampaign($election, $candidate);

        
        $voterUser = User::factory()->create();
        $voter     = Character::create([
            'user_id'      => $voterUser->id,
            'display_name' => 'Visitor-' . uniqid(),
            'gender'       => 'female',
            'city_id'      => $otherCity->id,
            'home_city_id' => $homeCity->id,
            'career_id'    => $this->unemployedCareerId,
            'career_rank'  => 1,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => 500,
            'cash_in_bank' => 500,
        ]);
        CharacterStats::create(['character_id' => $voter->id]);

        $this->actingAs($voterUser)
            ->post("/{$otherCity->slug}/election/vote", ['campaign_id' => $campaign->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(0, $campaign->fresh()->votes);
    }

    public function test_vote_is_rejected_when_voting_is_not_open(): void
    {
        $city     = $this->makeCity('http-vote-closed');
        $election = $this->makeExpiredRegistration($city);

        $candidate = $this->makeEligibleCharacter($city);
        $campaign  = $this->registerCampaign($election, $candidate);

        $voterUser = User::factory()->create();
        $this->makeVoter($city, $voterUser);

        $this->actingAs($voterUser)
            ->post("/{$city->slug}/election/vote", ['campaign_id' => $campaign->id])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    
    public function test_candidate_cannot_vote_in_their_own_election(): void
    {
        $city     = $this->makeCity('http-vote-self');
        $election = Election::create([
            'city_id'            => $city->id,
            'cycle_number'       => 1,
            'status'             => 'voting',
            'registration_start' => now()->subDays(3),
            'registration_end'   => now()->subDays(1),
            'voting_start'       => now()->subHour(),
            'voting_end'         => now()->addDay(),
            'total_votes'        => 0,
        ]);

        $candidateUser = User::factory()->create();
        $candidate     = $this->makeEligibleCharacter($city, $candidateUser);
        $campaign      = $this->registerCampaign($election, $candidate);

        
        $rival        = $this->makeEligibleCharacter($city);
        $rivalCampaign = $this->registerCampaign($election, $rival);

        $this->actingAs($candidateUser)
            ->post("/{$city->slug}/election/vote", ['campaign_id' => $rivalCampaign->id])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(0, $rivalCampaign->fresh()->votes);
    }

    
    
    

    public function test_add_funds_increases_campaign_fund_and_deducts_bank(): void
    {
        $city     = $this->makeCity('http-funds');
        $user     = User::factory()->create();
        $char     = $this->makeEligibleCharacter($city, $user);
        $election = Election::startForCity($city);
        $campaign = $this->registerCampaign($election, $char);

        $bankBefore = $char->cash_in_bank;
        $fundBefore = $campaign->campaign_fund;
        $amount     = 100_000;

        $this->actingAs($user)
            ->post("/{$city->slug}/election/funds", ['campaign_id' => $campaign->id, 'amount' => $amount])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertEquals($fundBefore + $amount, $campaign->fresh()->campaign_fund);
        $this->assertEquals($bankBefore - $amount, $char->fresh()->cash_in_bank);
    }

    public function test_add_funds_rejected_when_bank_balance_is_insufficient(): void
    {
        $city     = $this->makeCity('http-funds-broke');
        $user     = User::factory()->create();
        $char     = $this->makeEligibleCharacter($city, $user);
        $election = Election::startForCity($city);
        $campaign = $this->registerCampaign($election, $char);

        $char->update(['cash_in_bank' => 50_000]);

        $this->actingAs($user)
            ->post("/{$city->slug}/election/funds", ['campaign_id' => $campaign->id, 'amount' => 100_000])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(Election::CAMPAIGN_FUND_REQUIREMENT, $campaign->fresh()->campaign_fund);
    }

    public function test_add_funds_rejected_for_campaign_owned_by_another_candidate(): void
    {
        $city     = $this->makeCity('http-funds-other');
        $ownerUser = User::factory()->create();
        $owner    = $this->makeEligibleCharacter($city, $ownerUser);
        $election = Election::startForCity($city);
        $campaign = $this->registerCampaign($election, $owner);

        $intruderUser = User::factory()->create();
        $this->makeEligibleCharacter($city, $intruderUser);

        $this->actingAs($intruderUser)
            ->post("/{$city->slug}/election/funds", ['campaign_id' => $campaign->id, 'amount' => 100_000])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(Election::CAMPAIGN_FUND_REQUIREMENT, $campaign->fresh()->campaign_fund);
    }

    public function test_add_funds_rejected_when_registration_is_over(): void
    {
        $city     = $this->makeCity('http-funds-closed');
        $user     = User::factory()->create();
        $char     = $this->makeEligibleCharacter($city, $user);
        $election = $this->makeExpiredRegistration($city);
        $campaign = $this->registerCampaign($election, $char);

        $election->update(['status' => 'voting', 'voting_end' => now()->addDay()]);

        $this->actingAs($user)
            ->post("/{$city->slug}/election/funds", ['campaign_id' => $campaign->id, 'amount' => 100_000])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    
    
    

    public function test_index_returns_200_with_active_election(): void
    {
        $city = $this->makeCity('http-index');
        $user = User::factory()->create();

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'IndexVoter-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $city->id,
            'home_city_id' => $city->id,
            'career_id'    => $this->unemployedCareerId,
            'career_rank'  => 1,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => 500,
            'cash_in_bank' => 500,
        ]);
        CharacterStats::create(['character_id' => $char->id]);
        Election::startForCity($city);

        $this->actingAs($user)->get("/{$city->slug}/election")->assertStatus(200);
    }

    public function test_index_returns_200_when_no_election_exists(): void
    {
        $city = $this->makeCity('http-index-empty');
        $user = User::factory()->create();

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'IndexEmpty-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $city->id,
            'home_city_id' => $city->id,
            'career_id'    => $this->unemployedCareerId,
            'career_rank'  => 1,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => 500,
            'cash_in_bank' => 500,
        ]);
        CharacterStats::create(['character_id' => $char->id]);

        $this->actingAs($user)->get("/{$city->slug}/election")->assertStatus(200);
    }
}
