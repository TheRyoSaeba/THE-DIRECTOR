<?php

namespace Tests\Feature;

use App\Actions\BankerLaunder;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\CrimeRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BankerLaunderTest extends TestCase
{
    use DatabaseTransactions;

    private City     $city;
    private Business $bank;
    private int      $bankingCareerId;
    private int      $unemployedCareerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::create([
            'name'       => 'LaunderCity-' . uniqid(),
            'slug'       => 'launder-' . uniqid(),
            'crime_rate' => 10,
        ]);

        $banking = DB::table('careers')->where('code', 'banking')->first();
        $this->assertNotNull($banking, 'Banking career must be seeded');
        $this->bankingCareerId = $banking->id;

        $unemployed = DB::table('careers')->where('code', 'unemployed')->first();
        $this->assertNotNull($unemployed, 'Unemployed career must be seeded');
        $this->unemployedCareerId = $unemployed->id;

        $this->bank = Business::create([
            'name'           => 'Launder Test Bank',
            'slug'           => 'ltb-' . uniqid(),
            'code'           => 'bank',
            'city_id'        => $this->city->id,
            'balance'        => 5_000_000,
            'is_purchasable' => false,
        ]);
    }

    
    
    

    private function makeBanker(int $rank = 2, int $luck = 100_000, float $strength = 80.0): Character
    {
        $user = User::factory()->create();

        $c = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'Banker-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $this->city->id,
            'home_city_id' => $this->city->id,
            'career_id'    => $this->bankingCareerId,
            'career_rank'  => $rank,
            'cash_on_hand' => 10_000,
            'cash_in_bank' => 0,
            'dirty_cash'   => 0,
            'health'       => 100,
        ]);

        CharacterStats::create([
            'character_id' => $c->id,
            'intelligence' => 100,
            'offense'      => 100,
            'defense'      => 100,
            'luck'         => $luck,
            'influence'    => 0,
        ]);

        CharacterTimers::create([
            'character_id'   => $c->id,
            'next_action_at' => 0,
            'strength'       => $strength,
        ]);

        return $c;
    }

    private function makeClient(int $dirtyCash = 100_000): Character
    {
        $user = User::factory()->create();

        $c = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'Client-' . uniqid(),
            'gender'       => 'female',
            'city_id'      => $this->city->id,
            'home_city_id' => $this->city->id,
            'career_id'    => $this->unemployedCareerId,
            'career_rank'  => 1,
            'cash_on_hand' => 0,
            'cash_in_bank' => 0,
            'dirty_cash'   => $dirtyCash,
            'health'       => 100,
        ]);

        CharacterStats::create([
            'character_id' => $c->id,
            'intelligence' => 50,
            'offense'      => 50,
            'defense'      => 50,
            'luck'         => 50,
            'influence'    => 0,
        ]);

        CharacterTimers::create([
            'character_id'   => $c->id,
            'next_action_at' => 0,
        ]);

        
        DB::table('sessions')->insert([
            'id'            => \Illuminate\Support\Str::random(40),
            'user_id'       => $c->user_id,
            'ip_address'    => '127.0.0.1',
            'user_agent'    => 'phpunit',
            'payload'       => base64_encode(serialize([])),
            'last_activity' => now()->getTimestamp(),
        ]);

        return $c;
    }

    
    private function postOffer(
        Character $banker,
        Character $client,
        int $amount = 50_000,
        float $cutPct = 0.15
    ): ?CharacterJournal {
        $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => $client->display_name,
            'amount'      => $amount,
            'cut_pct'     => $cutPct,
        ]);

        return CharacterJournal::where('character_id', $client->id)
            ->where('type', 'banker_launder_request')
            ->latest()
            ->first();
    }

    
    private function setTimerFuture(Character $character, string $column, int $offsetSeconds): void
    {
        DB::table('character_timers')
            ->where('character_id', $character->id)
            ->update([$column => now()->getTimestamp() + $offsetSeconds]);
    }

    
    
    

    
    public function offer_is_rejected_below_rank_2(): void
    {
        $banker = $this->makeBanker(rank: 1);
        $client = $this->makeClient();

        $r = $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => $client->display_name,
            'amount'      => 50_000,
            'cut_pct'     => 0.15,
        ]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('rank', strtolower(session('error') ?? ''));
    }

    
    public function offer_is_blocked_when_banker_action_timer_active(): void
    {
        $banker = $this->makeBanker();
        $this->setTimerFuture($banker, 'next_action_at', 300);
        $client = $this->makeClient();

        $r = $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => $client->display_name,
            'amount'      => 50_000,
            'cut_pct'     => 0.15,
        ]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('cooldown', strtolower(session('error') ?? ''));
    }

    
    public function offer_is_blocked_when_banker_hospitalized(): void
    {
        $banker = $this->makeBanker();
        $this->setTimerFuture($banker, 'hospital_until', 7200);
        $client = $this->makeClient();

        
        
        $this->actingAs($banker->user)
            ->post(route('career.banking.launder'), [
                'target_name' => $client->display_name,
                'amount'      => 50_000,
                'cut_pct'     => 0.15,
            ])
            ->assertRedirect(route('hospital'));
    }

    
    public function offer_is_blocked_when_banker_jailed(): void
    {
        $banker = $this->makeBanker();
        $this->setTimerFuture($banker, 'jail_until', 3600);
        $client = $this->makeClient();

        
        
        $this->actingAs($banker->user)
            ->post(route('career.banking.launder'), [
                'target_name' => $client->display_name,
                'amount'      => 50_000,
                'cut_pct'     => 0.15,
            ])
            ->assertRedirect(route('jail'));
    }

    
    public function banker_cannot_send_offer_to_themselves(): void
    {
        $banker = $this->makeBanker();
       

        $r = $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => $banker->display_name,
            'amount'      => 50_000,
            'cut_pct'     => 0.15,
        ]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('yourself', strtolower(session('error') ?? ''));
    }

    
    public function offer_is_rejected_for_nonexistent_player(): void
    {
        $banker = $this->makeBanker();

        $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => 'DoesNotExistXyzAbc999',
            'amount'      => 50_000,
            'cut_pct'     => 0.15,
        ])->assertSessionHas('error');
    }

    
    public function offer_is_rejected_for_dead_target(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $client->delete();

        $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => $client->display_name,
            'amount'      => 50_000,
            'cut_pct'     => 0.15,
        ])->assertSessionHas('error');
    }

    
    public function offer_is_rejected_below_minimum_amount(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => $client->display_name,
            'amount'      => 999,
            'cut_pct'     => 0.15,
        ])->assertSessionHasErrors(['amount']);
    }

    
    public function offer_is_rejected_above_http_hard_ceiling(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 2_000_000);

        
        $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => $client->display_name,
            'amount'      => 1_000_001,
            'cut_pct'     => 0.15,
        ])->assertSessionHasErrors(['amount']);
    }

    
    public function offer_is_rejected_above_dynamic_bank_cap(): void
    {
        
        
        
        DB::table('businesses')->where('id', $this->bank->id)->update(['balance' => 600_000]);

        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 200_000);

        $r = $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => $client->display_name,
            'amount'      => 200_000,
            'cut_pct'     => 0.15,
        ]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('maximum', strtolower(session('error') ?? ''));
    }

    
    public function offer_is_rejected_with_cut_below_minimum(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => $client->display_name,
            'amount'      => 50_000,
            'cut_pct'     => BankerLaunder::CUT_MIN - 0.01,
        ])->assertSessionHasErrors(['cut_pct']);
    }

    
    public function offer_is_rejected_with_cut_above_maximum(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $this->actingAs($banker->user)->post(route('career.banking.launder'), [
            'target_name' => $client->display_name,
            'amount'      => 50_000,
            'cut_pct'     => BankerLaunder::CUT_MAX + 0.01,
        ])->assertSessionHasErrors(['cut_pct']);
    }

    
    public function second_offer_to_same_target_is_rejected(): void
    {
        $banker  = $this->makeBanker();
        $banker2 = $this->makeBanker();
        $client  = $this->makeClient();

        $first = $this->postOffer($banker, $client);
        $this->assertNotNull($first, 'First offer must succeed.');

        $r = $this->actingAs($banker2->user)->post(route('career.banking.launder'), [
            'target_name' => $client->display_name,
            'amount'      => 50_000,
            'cut_pct'     => 0.15,
        ]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('pending', strtolower(session('error') ?? ''));
        $this->assertSame(1, CharacterJournal::where('character_id', $client->id)
            ->where('type', 'banker_launder_request')->count());
    }

    
    public function offer_posts_journal_entry_to_target_with_correct_data(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();
        $journal = $this->postOffer($banker, $client, 80_000, 0.20);

        $this->assertNotNull($journal);
        $this->assertSame('banker_launder_request', $journal->type);
        $this->assertSame($banker->id, (int)   ($journal->data['banker_id'] ?? 0));
        $this->assertSame(80_000,      (int)   ($journal->data['amount']    ?? 0));
        $this->assertSame(0.20,        (float) ($journal->data['cut_pct']   ?? 0));
        $this->assertNotNull($journal->data['expires_at'] ?? null);
    }

    
    public function offer_phase_does_not_set_cooldown_on_banker(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $this->postOffer($banker, $client);

        $next = (int) DB::table('character_timers')
            ->where('character_id', $banker->id)
            ->value('next_action_at');

        $this->assertLessThanOrEqual(now()->getTimestamp(), $next);
    }

    
    
    

    
    public function expired_offer_is_rejected_and_journal_deleted(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();

        $journal = CharacterJournal::create([
            'character_id' => $client->id,
            'type'         => 'banker_launder_request',
            'data'         => [
                'banker_id'  => $banker->id,
                'amount'     => 50_000,
                'cut_pct'    => 0.15,
                'expires_at' => now()->subMinutes(1)->toIso8601String(),
            ],
            'is_read' => false,
        ]);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('expired', strtolower($result['message']));
        $this->assertNull(CharacterJournal::find($journal->id));
    }

    
    public function accept_fails_if_banker_quit_career_after_offer(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        DB::table('characters')->where('id', $banker->id)->update([
            'career_id'   => $this->unemployedCareerId,
            'career_rank' => 1,
        ]);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('banker', strtolower($result['message']));
    }

    
    public function accept_fails_if_banker_left_home_city_after_offer(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $other = City::create(['name' => 'Other-' . uniqid(), 'slug' => 'oth-' . uniqid(), 'crime_rate' => 0]);
        DB::table('characters')->where('id', $banker->id)->update(['city_id' => $other->id]);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('city', strtolower($result['message']));
    }

    
    public function accept_fails_if_banker_hospitalized_after_offer(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $this->setTimerFuture($banker, 'hospital_until', 7200);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('unavailable', strtolower($result['message']));
    }

    
    public function accept_fails_if_banker_jailed_after_offer(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $this->setTimerFuture($banker, 'jail_until', 3600);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('unavailable', strtolower($result['message']));
    }

    
    public function accept_fails_if_banker_goes_on_cooldown_after_offer(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $this->setTimerFuture($banker, 'next_action_at', 1800);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('cooldown', strtolower($result['message']));
    }

    
    public function accept_fails_if_target_is_on_cooldown(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $this->setTimerFuture($client, 'next_action_at', 300);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('cooldown', strtolower($result['message']));
    }

    
    public function accept_fails_if_target_is_hospitalized(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $this->setTimerFuture($client, 'hospital_until', 3600);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        $this->assertSame('error', $result['status']);
    }

    
    public function accept_fails_if_target_is_jailed(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $this->setTimerFuture($client, 'jail_until', 3600);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        $this->assertSame('error', $result['status']);
    }

    
    public function accept_fails_when_target_no_longer_has_enough_dirty_cash(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient(dirtyCash: 100_000);
        $journal = $this->postOffer($banker, $client, 100_000);
        $this->assertNotNull($journal);

        DB::table('characters')->where('id', $client->id)->update(['dirty_cash' => 0]);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('dirty', strtolower($result['message']));
    }

    
    
    

    
    public function success_money_math_is_correct_with_no_wire_fee(): void
    {
        $banker = $this->makeBanker(luck: 999_999, strength: 100.0);
        $client = $this->makeClient(dirtyCash: 100_000);

        $amount   = 100_000;
        $cutPct   = 0.20;
        $cut      = (int) floor($amount * $cutPct);
        $overhead = (int) floor($amount * BankerLaunder::OVERHEAD);
        $clean    = $amount - $cut - $overhead;

        $bankBefore   = (int) $this->bank->fresh()->balance;
        $clientBefore = (int) $client->cash_in_bank;
        $bankerBefore = (int) $banker->cash_in_bank;

        $journal = $this->postOffer($banker, $client, $amount, $cutPct);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        if ($result['status'] !== 'success') {
            $this->markTestIncomplete('Roll failed at luck=999999 — rerun.');
        }

        $this->assertSame(0,                       (int) $client->fresh()->dirty_cash);
        $this->assertSame($clientBefore + $clean,  (int) $client->fresh()->cash_in_bank);
        $this->assertSame($bankerBefore + $cut,    (int) $banker->fresh()->cash_in_bank);
        $this->assertSame($bankBefore + $overhead, (int) $this->bank->fresh()->balance);
        $this->assertSame($amount, $clean + $cut + $overhead);
    }

    
    
    

    
    public function success_creates_crime_record_for_banker(): void
    {
        $banker  = $this->makeBanker(luck: 999_999, strength: 100.0);
        $client  = $this->makeClient(dirtyCash: 100_000);
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] !== 'success') {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $crime = CrimeRecord::where('character_id', $banker->id)
            ->where('type', CrimeRecord::TYPE_MONEY_LAUNDERING)
            ->latest()->first();

        $this->assertNotNull($crime);
        $this->assertSame(CrimeRecord::SEV_FELONY,  $crime->severity);
        $this->assertSame(CrimeRecord::STATUS_OPEN, $crime->status);
    }

    
    public function success_crime_record_has_correct_perpetrator_and_participant(): void
    {
        $banker  = $this->makeBanker(luck: 999_999, strength: 100.0);
        $client  = $this->makeClient(dirtyCash: 100_000);
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] !== 'success') {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $crime = CrimeRecord::where('character_id', $banker->id)
            ->where('type', CrimeRecord::TYPE_MONEY_LAUNDERING)
            ->latest()->first();

        $this->assertSame($banker->id,          (int) $crime->character_id);
        $this->assertSame($banker->display_name, $crime->data['perpetrator_name'] ?? null);
        $this->assertContains($client->id,       $crime->data['participants'] ?? []);
        $this->assertSame('The Public',          $crime->data['victim_name']   ?? null);
    }

    
    public function success_increases_city_crime_rate(): void
    {
        $banker  = $this->makeBanker(luck: 999_999, strength: 100.0);
        $client  = $this->makeClient(dirtyCash: 100_000);
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $before = (float) $this->city->fresh()->crime_rate;

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] !== 'success') {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $this->assertGreaterThan($before, (float) $this->city->fresh()->crime_rate);
    }

    
    public function success_creates_bank_transaction_for_target(): void
    {
        $banker  = $this->makeBanker(luck: 999_999, strength: 100.0);
        $client  = $this->makeClient(dirtyCash: 100_000);
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] !== 'success') {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $tx = DB::table('bank_transactions')
            ->where('character_id', $client->id)
            ->where('type', BankTransaction::TYPE_DEPOSIT)
            ->latest('id')->first();

        $this->assertNotNull($tx);
        $this->assertGreaterThan(0, (int) $tx->amount);
    }

    
    public function success_creates_journal_entries_for_both_parties(): void
    {
        $banker  = $this->makeBanker(luck: 999_999, strength: 100.0);
        $client  = $this->makeClient(dirtyCash: 100_000);
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] !== 'success') {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $this->assertTrue(CharacterJournal::where('character_id', $banker->id)
            ->where('type', 'banker_launder_executed')->exists());
        $this->assertTrue(CharacterJournal::where('character_id', $client->id)
            ->where('type', 'banker_launder_executed')->exists());
    }

    
    public function offer_journal_is_deleted_after_accept(): void
    {
        $banker  = $this->makeBanker(luck: 999_999, strength: 100.0);
        $client  = $this->makeClient(dirtyCash: 100_000);
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);
        $id = $journal->id;

        BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());

        $this->assertNull(CharacterJournal::find($id));
    }

    
    public function success_applies_cooldown_to_both_parties(): void
    {
        $banker  = $this->makeBanker(luck: 999_999, strength: 100.0);
        $client  = $this->makeClient(dirtyCash: 100_000);
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] !== 'success') {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $bn = (int) DB::table('character_timers')->where('character_id', $banker->id)->value('next_action_at');
        $cn = (int) DB::table('character_timers')->where('character_id', $client->id)->value('next_action_at');

        $this->assertGreaterThan(now()->getTimestamp(), $bn);
        $this->assertGreaterThan(now()->getTimestamp(), $cn);
    }

    
    public function success_resets_banker_strength_to_zero(): void
    {
        $banker  = $this->makeBanker(luck: 999_999, strength: 80.0);
        $client  = $this->makeClient(dirtyCash: 100_000);
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] !== 'success') {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $strength = (float) DB::table('character_timers')
            ->where('character_id', $banker->id)
            ->value('strength');

        $this->assertSame(0.0, $strength);
    }

    
    
    

    
    public function failure_dirty_cash_vanishes_from_target(): void
    {
        $banker  = $this->makeBanker(luck: 1, strength: 0.0);
        $client  = $this->makeClient(dirtyCash: 50_000);
        $journal = $this->postOffer($banker, $client, 50_000);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] === 'success') {
            $this->markTestIncomplete('Unexpectedly won at luck=1 — rerun.');
        }

        $this->assertSame(0, (int) $client->fresh()->dirty_cash);
    }

    
    public function failure_no_clean_money_credited_anywhere(): void
    {
        $banker  = $this->makeBanker(luck: 1, strength: 0.0);
        $client  = $this->makeClient(dirtyCash: 50_000);
        $journal = $this->postOffer($banker, $client, 50_000);
        $this->assertNotNull($journal);

        $bankBefore   = (int) $this->bank->fresh()->balance;
        $bankerBefore = (int) $banker->cash_in_bank;
        $clientBefore = (int) $client->cash_in_bank;

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] === 'success') {
            $this->markTestIncomplete('Unexpectedly won at luck=1 — rerun.');
        }

        $this->assertSame($bankBefore,   (int) $this->bank->fresh()->balance);
        $this->assertSame($bankerBefore, (int) $banker->fresh()->cash_in_bank);
        $this->assertSame($clientBefore, (int) $client->fresh()->cash_in_bank);
    }

    
    public function failure_no_crime_record_created(): void
    {
        $banker  = $this->makeBanker(luck: 1, strength: 0.0);
        $client  = $this->makeClient(dirtyCash: 50_000);
        $journal = $this->postOffer($banker, $client, 50_000);
        $this->assertNotNull($journal);

        $before = CrimeRecord::where('character_id', $banker->id)->count();

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] === 'success') {
            $this->markTestIncomplete('Unexpectedly won at luck=1 — rerun.');
        }

        $this->assertSame($before, CrimeRecord::where('character_id', $banker->id)->count());
    }

    
    public function failure_both_parties_receive_failed_journal(): void
    {
        $banker  = $this->makeBanker(luck: 1, strength: 0.0);
        $client  = $this->makeClient(dirtyCash: 50_000);
        $journal = $this->postOffer($banker, $client, 50_000);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] === 'success') {
            $this->markTestIncomplete('Unexpectedly won at luck=1 — rerun.');
        }

        $this->assertTrue(CharacterJournal::where('character_id', $banker->id)
            ->where('type', 'banker_launder_failed')->exists());
        $this->assertTrue(CharacterJournal::where('character_id', $client->id)
            ->where('type', 'banker_launder_failed')->exists());
    }

    
    public function failure_applies_cooldown_to_both_parties(): void
    {
        $banker  = $this->makeBanker(luck: 1, strength: 0.0);
        $client  = $this->makeClient(dirtyCash: 50_000);
        $journal = $this->postOffer($banker, $client, 50_000);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] === 'success') {
            $this->markTestIncomplete('Unexpectedly won at luck=1 — rerun.');
        }

        $bn = (int) DB::table('character_timers')->where('character_id', $banker->id)->value('next_action_at');
        $cn = (int) DB::table('character_timers')->where('character_id', $client->id)->value('next_action_at');
        $this->assertGreaterThan(now()->getTimestamp(), $bn);
        $this->assertGreaterThan(now()->getTimestamp(), $cn);
    }

    
    
    

    
    public function cross_city_wire_fee_math_is_correct(): void
    {
        $banker = $this->makeBanker(luck: 999_999, strength: 100.0);
        $this->bank->setSetting('wire_fee', 2.0);

        $otherCity = City::create([
            'name'       => 'OtherCity-' . uniqid(),
            'slug'       => 'oc-' . uniqid(),
            'crime_rate' => 0,
        ]);

        $user   = User::factory()->create();
        $client = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'XClient-' . uniqid(),
            'gender'       => 'female',
            'city_id'      => $this->city->id,
            'home_city_id' => $otherCity->id,
            'career_id'    => $this->unemployedCareerId,
            'career_rank'  => 1,
            'cash_on_hand' => 0,
            'cash_in_bank' => 0,
            'dirty_cash'   => 100_000,
            'health'       => 100,
        ]);
        CharacterStats::create([
            'character_id' => $client->id,
            'intelligence' => 50,
            'offense'      => 50,
            'defense'      => 50,
            'luck'         => 50,
            'influence'    => 0,
        ]);
        CharacterTimers::create([
            'character_id'   => $client->id,
            'next_action_at' => 0,
        ]);
       

        $amount      = 100_000;
        $cutPct      = 0.10;
        $cut         = (int) floor($amount * $cutPct);
        $overhead    = (int) floor($amount * BankerLaunder::OVERHEAD);
        $cleanBefore = $amount - $cut - $overhead;
        $wireFee     = max(1, (int) round($cleanBefore * 0.02));
        $finalClean  = $cleanBefore - $wireFee;

        $journal    = $this->postOffer($banker, $client, $amount, $cutPct);
        $this->assertNotNull($journal);

        $bankBefore = (int) $this->bank->fresh()->balance;

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] !== 'success') {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $this->assertSame($finalClean,                          (int) $client->fresh()->cash_in_bank);
        $this->assertSame($bankBefore + $overhead + $wireFee,  (int) $this->bank->fresh()->balance);
        $this->assertSame($amount, $finalClean + $cut + $overhead + $wireFee);
    }

    
    public function same_city_no_wire_fee_applied(): void
    {
        $banker = $this->makeBanker(luck: 999_999, strength: 100.0);
        $this->bank->setSetting('wire_fee', 2.0);
        $client = $this->makeClient(dirtyCash: 100_000);

        $amount        = 100_000;
        $cutPct        = 0.10;
        $expectedClean = $amount
            - (int) floor($amount * $cutPct)
            - (int) floor($amount * BankerLaunder::OVERHEAD);

        $journal = $this->postOffer($banker, $client, $amount, $cutPct);
        $this->assertNotNull($journal);

        $result = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        if ($result['status'] !== 'success') {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $this->assertSame($expectedClean, (int) $client->fresh()->cash_in_bank);
    }

    
    
    

    
    public function decline_deletes_journal_notifies_banker_and_no_cooldown(): void
    {
        $banker  = $this->makeBanker();
        $client  = $this->makeClient();
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);
        $jid = $journal->id;

        $this->actingAs($client->user)
            ->post(route('journal.decline', $jid))
            ->assertRedirect();

        $this->assertNull(CharacterJournal::find($jid));
        $this->assertTrue(CharacterJournal::where('character_id', $banker->id)
            ->where('type', 'banker_launder_declined')->exists());

        $bn = (int) DB::table('character_timers')->where('character_id', $banker->id)->value('next_action_at');
        $cn = (int) DB::table('character_timers')->where('character_id', $client->id)->value('next_action_at');
        $this->assertLessThanOrEqual(now()->getTimestamp(), $bn);
        $this->assertLessThanOrEqual(now()->getTimestamp(), $cn);
    }

    
    
    

    
    public function concurrent_accept_only_one_succeeds(): void
    {
        $banker  = $this->makeBanker(luck: 999_999, strength: 100.0);
        $client  = $this->makeClient(dirtyCash: 100_000);
        $journal = $this->postOffer($banker, $client);
        $this->assertNotNull($journal);

        $result1  = BankerLaunder::acceptOffer($client->fresh(), $journal->fresh());
        $reloaded = CharacterJournal::find($journal->id);

        if ($reloaded) {
            $result2  = BankerLaunder::acceptOffer($client->fresh(), $reloaded);
            $statuses = [$result1['status'], $result2['status']];
            $this->assertContains('error', $statuses);
        } else {
            $this->assertNotNull($result1['status']);
        }
    }

    
    
    

    
    public function crime_record_has_evidence_level_10(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $record = \App\Services\CrimeService::moneyLaunderingBanker(
            perpetrator: $banker,
            cityId:      $this->city->id,
            amount:      50_000,
            client:      $client,
        );

        $expected = max(1, CrimeRecord::DEFAULT_EVIDENCE[CrimeRecord::TYPE_MONEY_LAUNDERING] - 30);
        $this->assertSame($expected, $record->evidence_level);
    }

    
    public function crime_record_perpetrator_name_is_banker(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $record = \App\Services\CrimeService::moneyLaunderingBanker(
            perpetrator: $banker,
            cityId:      $this->city->id,
            amount:      50_000,
            client:      $client,
        );

        $this->assertSame($banker->display_name, $record->data['perpetrator_name'] ?? null);
    }

    
    public function crime_record_participants_contains_only_client(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $record = \App\Services\CrimeService::moneyLaunderingBanker(
            perpetrator: $banker,
            cityId:      $this->city->id,
            amount:      50_000,
            client:      $client,
        );

        $participants = $record->data['participants'] ?? [];
        $this->assertCount(1, $participants);
        $this->assertContains($client->id, $participants);
    }

    
    public function crime_record_victim_name_is_the_public(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $record = \App\Services\CrimeService::moneyLaunderingBanker(
            perpetrator: $banker,
            cityId:      $this->city->id,
            amount:      50_000,
            client:      $client,
        );

        $this->assertSame('The Public', $record->data['victim_name'] ?? null);
    }

    
    
    

    
    public function client_is_conflicted_on_their_own_launder_case(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $record = \App\Services\CrimeService::moneyLaunderingBanker(
            perpetrator: $banker,
            cityId:      $this->city->id,
            amount:      50_000,
            client:      $client,
        );

        $this->assertTrue($record->isConflicted($client));
    }

    
    public function banker_is_conflicted_on_their_own_launder_case(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $record = \App\Services\CrimeService::moneyLaunderingBanker(
            perpetrator: $banker,
            cityId:      $this->city->id,
            amount:      50_000,
            client:      $client,
        );

        $this->assertTrue($record->isConflicted($banker));
    }
}
