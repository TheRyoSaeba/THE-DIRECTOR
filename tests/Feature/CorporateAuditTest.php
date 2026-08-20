<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CrimeRecord;
use App\Models\Election;
use App\Models\MayorTerm;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorporateAuditTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function makeCity(string $label): City
    {
        return City::create([
            'name' => 'AuditCity-' . $label . '-' . uniqid(),
            'slug' => 'audit-city-' . $label . '-' . uniqid(),
            'crime_rate' => 60,
        ]);
    }

    private function careerId(string $code): int
    {
        $career = Career::findByCode($code);
        $this->assertNotNull($career, "{$code} career must exist");

        return $career->id;
    }

    private function makeCharacter(
        City $city,
        string $careerCode,
        int $rank = 1,
        int $careerXp = 0,
        int $cashOnHand = 100_000,
        int $statTotal = 2_000,
    ): Character {
        $user = User::factory()->create();

        $character = Character::create([
            'user_id' => $user->id,
            'display_name' => 'AuditChar-' . uniqid(),
            'gender' => 'male',
            'city_id' => $city->id,
            'home_city_id' => $city->id,
            'career_id' => $this->careerId($careerCode),
            'career_rank' => $rank,
            'career_xp' => $careerXp,
            'total_character_exp' => $careerXp,
            'health' => 100,
            'max_health' => 100,
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => 0,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        CharacterStats::create([
            'character_id' => $character->id,
            'influence' => 50,
            'intelligence' => $statTotal,
            'offense' => $statTotal,
            'defense' => $statTotal,
            'luck' => $statTotal,
        ]);

        CharacterTimers::create([
            'character_id' => $character->id,
            'next_action_at' => 0,
            'strength' => 100,
        ]);

        return $character;
    }

    private function makeMayor(City $city): Character
    {
        $mayor = $this->makeCharacter($city, 'politics', rank: 1, careerXp: 1_000);
        $city->setMayor($mayor);

        return $mayor;
    }

    private function makeCommissioner(City $city): Character
    {
        return $this->makeCharacter(
            city: $city,
            careerCode: 'police',
            rank: 4,
            careerXp: 200_000,
            statTotal: 250_000,
        );
    }

    private function makeActiveTerm(
        City $city,
        Character $mayor,
        int $lawBudget = 50,
        int $corpRegBudget = 25,
        int $cityFunds = 500_000,
    ): MayorTerm {
        $election = Election::create([
            'city_id' => $city->id,
            'cycle_number' => 1,
            'status' => 'completed',
            'registration_start' => now()->subDays(10),
            'registration_end' => now()->subDays(8),
            'voting_start' => now()->subDays(8),
            'voting_end' => now()->subDays(7),
            'winner_id' => $mayor->id,
            'total_votes' => 1,
        ]);

        $term = MayorTerm::createForElection($city, $mayor, $election);
        $term->update([
            'city_funds' => $cityFunds,
            'budget_law' => $lawBudget,
            'budget_corp_reg' => $corpRegBudget,
            'budget_services' => max(0, 100 - $lawBudget - $corpRegBudget),
            'budget_bonds' => 0,
        ]);

        return $term->fresh();
    }

    private function makeCorporation(City $city, int $slushFund): Corporation
    {
        $ceo = $this->makeCharacter($city, 'corporation', rank: 4, careerXp: 100_000);

        $corp = Corporation::create([
            'name' => 'Audit Corp-' . uniqid(),
            'home_city_id' => $city->id,
            'founder_id' => $ceo->id,
            'ceo_id' => $ceo->id,
            'slush_fund' => $slushFund,
        ]);

        $corp->attachMember($ceo);

        return $corp->fresh();
    }

    private function addPendingAudit(MayorTerm $term): void
    {
        $log = $term->actions_log ?? [];
        $log['audits'] = array_merge($log['audits'] ?? [], [[
            'status' => 'pending',
            'initiated_at' => now()->getTimestamp(),
            'initiated_period' => $term->period,
        ]]);

        DB::table('mayor_terms')
            ->where('id', $term->id)
            ->update(['actions_log' => json_encode($log)]);

        $term->refresh();
    }

    public function test_mayor_enables_regulation_orders_audit_and_commissioner_executes_it(): void
    {
        $city = $this->makeCity('ordered');
        $mayor = $this->makeMayor($city);
        $term = $this->makeActiveTerm($city, $mayor, lawBudget: 50, corpRegBudget: 25, cityFunds: 500_000);
        $corp = $this->makeCorporation($city, slushFund: 100_000);
        $commissioner = $this->makeCommissioner($city);

        $this->actingAs($mayor->user)
            ->post(route('career.politics.audit'))
            ->assertSessionHas('error', 'Corporate regulation must be enabled first.');

        $this->actingAs($mayor->user)
            ->post(route('career.politics.enable-corp-reg'))
            ->assertSessionHas('success');

        $term->refresh();
        $this->assertTrue($term->corp_regulation_active);
        $this->assertTrue($city->fresh()->corp_regulation_active);

        $this->actingAs($mayor->user)
            ->post(route('career.politics.audit'))
            ->assertSessionHas('success');

        $term->refresh();
        $this->assertTrue($term->auditInProgress());

        $recordsBefore = CrimeRecord::count();

        $this->actingAs($commissioner->user)
            ->post(route('career.police.actions.corporate-audit'))
            ->assertRedirect();

        $term->refresh();
        $record = CrimeRecord::where('corporation_id', $corp->id)->latest('id')->first();
        $nextAction = (int) DB::table('character_timers')
            ->where('character_id', $commissioner->id)
            ->value('next_action_at');

        $this->assertFalse($term->auditInProgress());
        $this->assertGreaterThan($recordsBefore, CrimeRecord::count());
        $this->assertNotNull($record);
        $this->assertSame(CrimeRecord::TYPE_TAX_EVASION, $record->type);
        $this->assertSame($commissioner->id, $record->detective_id);
        $this->assertGreaterThan(now()->getTimestamp(), $nextAction);
    }

    public function test_corporate_audit_closes_without_target_and_sets_cooldown(): void
    {
        $city = $this->makeCity('no-target');
        $mayor = $this->makeMayor($city);
        $term = $this->makeActiveTerm($city, $mayor, lawBudget: 50);
        $commissioner = $this->makeCommissioner($city);
        $this->addPendingAudit($term);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.actions.corporate-audit'))
            ->assertSessionHas('error', 'No corporation with reportable funds was found in the city. The audit has been closed.');

        $term->refresh();
        $nextAction = (int) DB::table('character_timers')
            ->where('character_id', $commissioner->id)
            ->value('next_action_at');

        $this->assertFalse($term->auditInProgress());
        $this->assertSame(0, CrimeRecord::where('city_id', $city->id)->count());
        $this->assertGreaterThan(now()->getTimestamp(), $nextAction);
    }

    public function test_commissioner_needs_mayor_order_and_law_budget_to_execute_audit(): void
    {
        $city = $this->makeCity('blocked');
        $mayor = $this->makeMayor($city);
        $term = $this->makeActiveTerm($city, $mayor, lawBudget: 50);
        $commissioner = $this->makeCommissioner($city);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.actions.corporate-audit'))
            ->assertSessionHas('error', 'No audit has been ordered by the Mayor.');

        $term->update(['budget_law' => 25]);
        $term->refresh();
        $this->addPendingAudit($term);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.actions.corporate-audit'))
            ->assertSessionHas('error', 'Your police budget is not high enough to conduct an audit.');

        $this->assertTrue($term->fresh()->auditInProgress());
    }
}
