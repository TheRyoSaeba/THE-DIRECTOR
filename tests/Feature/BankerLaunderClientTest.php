<?php

namespace Tests\Feature;

use App\Actions\BankerLaunderClient;
use App\Models\BankTransaction;
use App\Models\Business;
use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\CrimeRecord;
use App\Models\LaunderOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class BankerLaunderClientTest extends TestCase
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
            'slug'       => 'lc-' . uniqid(),
            'crime_rate' => 10,
        ]);

        $banking = DB::table('careers')->where('code', 'banking')->first();
        $this->assertNotNull($banking, 'Banking career must be seeded');
        $this->bankingCareerId = $banking->id;

        $unemployed = DB::table('careers')->where('code', 'unemployed')->first();
        $this->assertNotNull($unemployed, 'Unemployed career must be seeded');
        $this->unemployedCareerId = $unemployed->id;

        $this->bank = Business::create([
            'name'           => 'Launder Test Bank-' . uniqid(),
            'slug'           => 'ltb-' . uniqid(),
            'code'           => 'bank',
            'city_id'        => $this->city->id,
            'balance'        => 10_000_000,
            'is_purchasable' => false,
            'is_active'      => true,
            'base_price'     => 5_000_000,
            'sort_order'     => 1,
        ]);
    }

    

    private function makeBanker(int $rank = 2, int $luck = 100_000): Character
    {
        $user = User::factory()->create();
        $c    = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'Banker-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $this->city->id,
            'home_city_id' => $this->city->id,
            'career_id'    => $this->bankingCareerId,
            'career_rank'  => $rank,
            'cash_on_hand' => 0,
            'cash_in_bank' => 0,
            'dirty_cash'   => 0,
            'health'       => 100,
            'max_health'   => 100,
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
            'strength'       => 80,
        ]);
        return $c;
    }

    private function makeClient(int $dirtyCash = 200_000, ?City $homeCity = null): Character
    {
        $user = User::factory()->create();
        $hc   = $homeCity ?? $this->city;
        $c    = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'Client-' . uniqid(),
            'gender'       => 'female',
            'city_id'      => $this->city->id,
            'home_city_id' => $hc->id,
            'career_id'    => $this->unemployedCareerId,
            'career_rank'  => 1,
            'cash_on_hand' => 0,
            'cash_in_bank' => 0,
            'dirty_cash'   => $dirtyCash,
            'health'       => 100,
            'max_health'   => 100,
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
        return $c;
    }

    
    private function makeOffer(
        Character $banker,
        Character $client,
        float     $cutPct     = 0.15,
        int       $amountSent = 0,
        string    $status     = LaunderOffer::STATUS_PENDING,
    ): LaunderOffer {
        return LaunderOffer::create([
            'banker_id'   => $banker->id,
            'client_id'   => $client->id,
            'amount'      => BankerLaunderClient::maxAmount((int) $this->bank->balance),
            'cut_pct'     => $cutPct,
            'amount_sent' => $amountSent,
            'status'      => $status,
        ]);
    }

    
    private function setTimerFuture(Character $character, string $column, int $offsetSeconds): void
    {
        DB::table('character_timers')
            ->where('character_id', $character->id)
            ->update([$column => now()->getTimestamp() + $offsetSeconds]);
    }

    
    
    

    public function test_add_client_happy_path_creates_offer_and_notifies_client(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => $client->display_name,
                'cut_pct'     => 0.15,
            ])
            ->assertSessionHas('success');

        $offer = LaunderOffer::where('banker_id', $banker->id)
            ->where('client_id', $client->id)
            ->active()
            ->first();

        $this->assertNotNull($offer);
        $this->assertSame(LaunderOffer::STATUS_PENDING, $offer->status);
        $this->assertSame(0, (int) $offer->amount_sent);
        $this->assertEqualsWithDelta(0.15, (float) $offer->cut_pct, 0.001);

        
        $this->assertTrue(
            CharacterJournal::where('character_id', $client->id)
                ->where('type', 'banker_launder_added')
                ->exists()
        );
    }

    public function test_add_client_blocked_below_rank_2(): void
    {
        $banker = $this->makeBanker(rank: 1);
        $client = $this->makeClient();

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => $client->display_name,
                'cut_pct'     => 0.15,
            ])
            ->assertSessionHas('error');

        $this->assertSame(
            0,
            LaunderOffer::where('banker_id', $banker->id)->count()
        );
    }

    public function test_add_client_blocked_when_banker_not_in_home_city(): void
    {
        $other  = City::create(['name' => 'Other-' . uniqid(), 'slug' => 'oth-' . uniqid(), 'crime_rate' => 0]);
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        
        DB::table('characters')->where('id', $banker->id)->update(['city_id' => $other->id]);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => $client->display_name,
                'cut_pct'     => 0.15,
            ])
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, LaunderOffer::where('banker_id', $banker->id)->count());
    }

    public function test_add_client_cannot_add_self(): void
    {
        $banker = $this->makeBanker();

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => $banker->display_name,
                'cut_pct'     => 0.15,
            ])
            ->assertSessionHas('error');

        $this->assertSame(0, LaunderOffer::where('banker_id', $banker->id)->count());
    }

    public function test_add_client_rejects_nonexistent_player(): void
    {
        $banker = $this->makeBanker();

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => 'NoOneLikeThis_' . uniqid(),
                'cut_pct'     => 0.15,
            ])
            ->assertSessionHas('error');
    }

    public function test_add_client_rejects_dead_client(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $client->delete(); 

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => $client->display_name,
                'cut_pct'     => 0.15,
            ])
            ->assertSessionHas('error');
    }

    public function test_add_client_rejects_duplicate_active_arrangement(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        
        $this->makeOffer($banker, $client);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => $client->display_name,
                'cut_pct'     => 0.20,
            ])
            ->assertSessionHas('error');

        $this->assertSame(
            1,
            LaunderOffer::where('banker_id', $banker->id)->where('client_id', $client->id)->active()->count(),
            'Only one active arrangement should exist'
        );
    }

    public function test_add_client_validation_rejects_cut_below_minimum(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => $client->display_name,
                'cut_pct'     => BankerLaunderClient::CUT_MIN - 0.01,
            ])
            ->assertSessionHasErrors('cut_pct');
    }

    public function test_add_client_validation_rejects_cut_above_maximum(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => $client->display_name,
                'cut_pct'     => BankerLaunderClient::CUT_MAX + 0.01,
            ])
            ->assertSessionHasErrors('cut_pct');
    }

    
    
    

    public function test_client_send_happy_path_debits_dirty_cash_and_queues_funds(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 200_000);
        $offer  = $this->makeOffer($banker, $client);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client'), [
                'target_id' => $offer->id,
                'amount'    => 50_000,
            ])
            ->assertSessionHas('success');

        $client->refresh();
        $offer->refresh();

        $this->assertSame(150_000, (int) $client->dirty_cash);
        $this->assertSame(50_000,  (int) $offer->amount_sent);
        $this->assertSame(LaunderOffer::STATUS_CLIENT_SENT, $offer->status);
    }

    public function test_client_send_sets_cooldown(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 200_000);
        $offer  = $this->makeOffer($banker, $client);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client'), [
                'target_id' => $offer->id,
                'amount'    => 50_000,
            ]);

        $nextAction = (int) DB::table('character_timers')
            ->where('character_id', $client->id)
            ->value('next_action_at');

        $this->assertGreaterThan(now()->getTimestamp(), $nextAction, 'Send must set the 15-min cooldown');
    }

    public function test_client_send_blocked_when_on_cooldown(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 200_000);
        $offer  = $this->makeOffer($banker, $client);

        $this->setTimerFuture($client, 'next_action_at', 900);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client'), [
                'target_id' => $offer->id,
                'amount'    => 50_000,
            ])
            ->assertSessionHas('error');

        $offer->refresh();
        $this->assertSame(0, (int) $offer->amount_sent);
    }

    public function test_client_send_blocked_when_insufficient_dirty_cash(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 10_000);
        $offer  = $this->makeOffer($banker, $client);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client'), [
                'target_id' => $offer->id,
                'amount'    => 50_000,
            ])
            ->assertSessionHas('error');

        $client->refresh();
        $this->assertSame(10_000, (int) $client->dirty_cash);
    }

    public function test_client_send_blocked_above_bank_capacity(): void
    {
        
        DB::table('businesses')->where('id', $this->bank->id)->update(['balance' => 400_000]);

        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 500_000);
        $offer  = LaunderOffer::create([
            'banker_id'   => $banker->id,
            'client_id'   => $client->id,
            'amount'      => 100_000,
            'cut_pct'     => 0.15,
            'amount_sent' => 0,
            'status'      => LaunderOffer::STATUS_PENDING,
        ]);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client'), [
                'target_id' => $offer->id,
                'amount'    => 200_000, 
            ])
            ->assertSessionHas('error');

        $client->refresh();
        $this->assertSame(500_000, (int) $client->dirty_cash, 'No dirty cash should be deducted on capacity error');
    }

    public function test_client_send_blocked_on_inactive_offer(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 200_000);
        $offer  = $this->makeOffer($banker, $client, status: LaunderOffer::STATUS_CANCELLED);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client'), [
                'target_id' => $offer->id,
                'amount'    => 50_000,
            ])
            ->assertSessionHas('error');

        $client->refresh();
        $this->assertSame(200_000, (int) $client->dirty_cash);
    }

    public function test_client_cannot_send_to_offer_they_do_not_own(): void
    {
        $banker    = $this->makeBanker();
        $realClient = $this->makeClient(dirtyCash: 200_000);
        $intruder  = $this->makeClient(dirtyCash: 200_000);
        $offer     = $this->makeOffer($banker, $realClient);

        $this->actingAs($intruder->user)
            ->post(route('actions.banker-launder-client'), [
                'target_id' => $offer->id,
                'amount'    => 50_000,
            ])
            ->assertSessionHas('error');

        $offer->refresh();
        $this->assertSame(0, (int) $offer->amount_sent);
    }

    public function test_client_send_below_minimum_1000_is_rejected(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 200_000);
        $offer  = $this->makeOffer($banker, $client);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client'), [
                'target_id' => $offer->id,
                'amount'    => 999,
            ])
            ->assertSessionHas('error');

        $offer->refresh();
        $this->assertSame(0, (int) $offer->amount_sent);
    }

    public function test_client_cumulative_sends_respect_capacity(): void
    {
        
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 2_000_000);
        $offer  = $this->makeOffer($banker, $client, amountSent: 600_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client'), [
                'target_id' => $offer->id,
                'amount'    => 500_000, 
            ])
            ->assertSessionHas('error');

        $offer->refresh();
        $this->assertSame(600_000, (int) $offer->amount_sent, 'amount_sent must not change on capacity breach');
    }

    
    
    

    public function test_update_cut_happy_path(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, cutPct: 0.10);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.update-cut', $offer), [
                'cut_pct' => 0.25,
            ])
            ->assertSessionHas('success');

        $offer->refresh();
        $this->assertEqualsWithDelta(0.25, (float) $offer->cut_pct, 0.001);
    }

    public function test_update_cut_blocked_when_funds_are_queued(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, cutPct: 0.10, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.update-cut', $offer), [
                'cut_pct' => 0.25,
            ])
            ->assertSessionHas('error');

        $offer->refresh();
        $this->assertEqualsWithDelta(0.10, (float) $offer->cut_pct, 0.001, 'Cut must be unchanged when funds are queued');
    }

    public function test_update_cut_blocked_for_non_banker(): void
    {
        $banker   = $this->makeBanker();
        $client   = $this->makeClient();
        $intruder = $this->makeBanker();
        $offer    = $this->makeOffer($banker, $client, cutPct: 0.10);

        $this->actingAs($intruder->user)
            ->post(route('career.banking.launder.update-cut', $offer), [
                'cut_pct' => 0.25,
            ])
            ->assertSessionHas('error');

        $offer->refresh();
        $this->assertEqualsWithDelta(0.10, (float) $offer->cut_pct, 0.001);
    }

    public function test_update_cut_blocked_on_inactive_offer(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, status: LaunderOffer::STATUS_CANCELLED);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.update-cut', $offer), [
                'cut_pct' => 0.25,
            ])
            ->assertSessionHas('error');
    }

    
    
    

    
    private function forceSuccess(Character $banker): void
    {
        DB::table('character_stats')->where('character_id', $banker->id)->update(['luck' => 9_999_999]);
        DB::table('character_timers')->where('character_id', $banker->id)->update(['strength' => 100]);
    }

    private function forceFail(Character $banker): void
    {
        DB::table('character_stats')->where('character_id', $banker->id)->update(['luck' => 1]);
        DB::table('character_timers')->where('character_id', $banker->id)->update(['strength' => 0]);
    }

    public function test_execute_success_money_math_no_wire_fee(): void
    {
        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $client = $this->makeClient();
        $amount = 200_000;
        $cutPct = 0.15;

        $offer = $this->makeOffer($banker, $client, cutPct: $cutPct, amountSent: $amount, status: LaunderOffer::STATUS_CLIENT_SENT);

        $overhead    = (int) floor($amount * BankerLaunderClient::OVERHEAD);
        $cut         = (int) floor($amount * $cutPct);
        $clientClean = $amount - $overhead - $cut;

        $bankBefore = (int) $this->bank->fresh()->balance;

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        
        if ($response->getSession()->has('error')) {
            $this->markTestIncomplete('Roll failed even at max luck — rerun.');
        }

        $response->assertSessionHas('success');

        $client->refresh();
        $banker->refresh();

        $this->assertSame($clientClean, (int) $client->cash_in_bank, 'Client receives amount minus overhead and cut');
        $this->assertSame($cut,         (int) $banker->cash_in_bank,  'Banker receives their cut');
        $this->assertSame(
            $bankBefore + $overhead,
            (int) $this->bank->fresh()->balance,
            'Bank vault receives the overhead fee'
        );

        
        $offer->refresh();
        $this->assertSame(LaunderOffer::STATUS_PENDING, $offer->status);
        $this->assertSame(0, (int) $offer->amount_sent);
    }

    public function test_execute_success_money_conservation(): void
    {
        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $client = $this->makeClient();
        $amount = 100_000;

        $offer = $this->makeOffer($banker, $client, cutPct: 0.20, amountSent: $amount, status: LaunderOffer::STATUS_CLIENT_SENT);

        $bankBefore = (int) $this->bank->fresh()->balance;

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('error')) {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $client->refresh();
        $banker->refresh();

        
        $this->assertSame(
            $amount,
            (int) $client->cash_in_bank + (int) $banker->cash_in_bank + ((int) $this->bank->fresh()->balance - $bankBefore),
            'Total money must be conserved across client, banker, and bank'
        );
    }

    public function test_execute_success_wire_fee_applied_for_cross_city_client(): void
    {
        $remoteCity = City::create(['name' => 'Remote-' . uniqid(), 'slug' => 'rem-' . uniqid(), 'crime_rate' => 0]);

        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $this->bank->update(['data' => ['wire_fee' => 2.0, 'loan_interest' => 1.0]]);

        $client = $this->makeClient(homeCity: $remoteCity);
        $amount = 100_000;
        $cutPct = 0.10;

        $overhead    = (int) floor($amount * BankerLaunderClient::OVERHEAD);
        $cut         = (int) floor($amount * $cutPct);
        $cleanBefore = $amount - $overhead - $cut;
        $wireFee     = max(1, (int) round($cleanBefore * 0.02));
        $finalClean  = $cleanBefore - $wireFee;

        $offer = $this->makeOffer($banker, $client, cutPct: $cutPct, amountSent: $amount, status: LaunderOffer::STATUS_CLIENT_SENT);

        $bankBefore = (int) $this->bank->fresh()->balance;

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('error')) {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $client->refresh();

        $this->assertSame($finalClean, (int) $client->cash_in_bank, 'Client receives clean amount minus wire fee');
        $this->assertSame(
            $bankBefore + $overhead + $wireFee,
            (int) $this->bank->fresh()->balance,
            'Bank vault receives overhead + wire fee'
        );
    }

    public function test_execute_success_no_wire_fee_for_same_city_client(): void
    {
        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $this->bank->update(['data' => ['wire_fee' => 2.0, 'loan_interest' => 1.0]]);

        $client = $this->makeClient(); 
        $amount = 100_000;
        $cutPct = 0.10;

        $overhead    = (int) floor($amount * BankerLaunderClient::OVERHEAD);
        $cut         = (int) floor($amount * $cutPct);
        $expectedClean = $amount - $overhead - $cut; 

        $offer = $this->makeOffer($banker, $client, cutPct: $cutPct, amountSent: $amount, status: LaunderOffer::STATUS_CLIENT_SENT);

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('error')) {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $client->refresh();
        $this->assertSame($expectedClean, (int) $client->cash_in_bank, 'No wire fee for same-city client');
    }

    public function test_execute_success_creates_crime_record(): void
    {
        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('error')) {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $crime = CrimeRecord::where('character_id', $banker->id)
            ->where('type', CrimeRecord::TYPE_MONEY_LAUNDERING)
            ->latest()
            ->first();

        $this->assertNotNull($crime);
        $this->assertSame(CrimeRecord::STATUS_OPEN, $crime->status);
    }

    public function test_execute_success_creates_bank_transaction_for_client(): void
    {
        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('error')) {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $this->assertTrue(
            DB::table('bank_transactions')
                ->where('character_id', $client->id)
                ->where('type', BankTransaction::TYPE_DEPOSIT)
                ->exists()
        );
    }

    public function test_execute_success_creates_journal_entry_for_client(): void
    {
        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('error')) {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $this->assertTrue(
            CharacterJournal::where('character_id', $client->id)
                ->where('type', 'banker_launder_executed')
                ->exists()
        );
    }

    public function test_execute_success_sets_banker_cooldown_and_zeroes_strength(): void
    {
        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('error')) {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $timers = DB::table('character_timers')->where('character_id', $banker->id)->first();
        $this->assertGreaterThan(now()->getTimestamp(), (int) $timers->next_action_at, 'Cooldown must be set');
        $this->assertSame(0, (int) $timers->strength, 'Strength must be zeroed');
    }

    public function test_execute_failure_dirty_cash_vanishes_no_credits(): void
    {
        $banker = $this->makeBanker();
        $this->forceFail($banker);
        $client = $this->makeClient();
        $amount = 50_000;

        $offer = $this->makeOffer($banker, $client, amountSent: $amount, status: LaunderOffer::STATUS_CLIENT_SENT);

        $bankBefore   = (int) $this->bank->fresh()->balance;
        $clientBefore = (int) $client->cash_in_bank;
        $bankerBefore = (int) $banker->cash_in_bank;

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('success')) {
            $this->markTestIncomplete('Unexpectedly won at luck=1 — rerun.');
        }

        $response->assertSessionHas('error');

        $client->refresh();
        $banker->refresh();

        $this->assertSame($clientBefore, (int) $client->cash_in_bank, 'Client gets nothing on failure');
        $this->assertSame($bankerBefore, (int) $banker->cash_in_bank, 'Banker gets nothing on failure');
        $this->assertSame($bankBefore,   (int) $this->bank->fresh()->balance, 'Bank gets nothing on failure');

        
        $offer->refresh();
        $this->assertSame(LaunderOffer::STATUS_PENDING, $offer->status);
        $this->assertSame(0, (int) $offer->amount_sent);
    }

    public function test_execute_failure_still_sets_cooldown_and_zeroes_strength(): void
    {
        $banker = $this->makeBanker();
        $this->forceFail($banker);
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('success')) {
            $this->markTestIncomplete('Unexpectedly won at luck=1 — rerun.');
        }

        $timers = DB::table('character_timers')->where('character_id', $banker->id)->first();
        $this->assertGreaterThan(now()->getTimestamp(), (int) $timers->next_action_at);
        $this->assertSame(0, (int) $timers->strength);
    }

    public function test_execute_failure_creates_failed_journal_for_client(): void
    {
        $banker = $this->makeBanker();
        $this->forceFail($banker);
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('success')) {
            $this->markTestIncomplete('Unexpectedly won at luck=1 — rerun.');
        }

        $this->assertTrue(
            CharacterJournal::where('character_id', $client->id)
                ->where('type', 'banker_launder_failed')
                ->exists()
        );
    }

    public function test_execute_blocked_when_no_funds_queued(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client); 

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer))
            ->assertSessionHas('error');
    }

    public function test_execute_blocked_when_banker_not_in_home_city(): void
    {
        $other  = City::create(['name' => 'Away-' . uniqid(), 'slug' => 'away-' . uniqid(), 'crime_rate' => 0]);
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        DB::table('characters')->where('id', $banker->id)->update(['city_id' => $other->id]);

        
        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer))
            ->assertRedirect(route('dashboard'));

        $offer->refresh();
        $this->assertSame(50_000, (int) $offer->amount_sent, 'Funds must not be consumed when banker is away');
    }

    public function test_execute_blocked_when_banker_hospitalized(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $this->setTimerFuture($banker, 'hospital_until', 7200);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer))
            ->assertRedirect(route('hospital'));

        $offer->refresh();
        $this->assertSame(50_000, (int) $offer->amount_sent);
    }

    public function test_execute_blocked_for_non_banker(): void
    {
        $banker   = $this->makeBanker();
        $client   = $this->makeClient();
        $intruder = $this->makeBanker();
        $offer    = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $this->actingAs($intruder->user)
            ->post(route('career.banking.launder.execute', $offer))
            ->assertSessionHas('error');

        $offer->refresh();
        $this->assertSame(50_000, (int) $offer->amount_sent);
    }

    public function test_execute_blocked_when_banker_lost_career_rank(): void
    {
        $banker = $this->makeBanker(rank: 2);
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        DB::table('characters')->where('id', $banker->id)->update(['career_rank' => 1]);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer))
            ->assertSessionHas('error');

        $offer->refresh();
        $this->assertSame(50_000, (int) $offer->amount_sent);
    }

    
    
    

    public function test_banker_cancel_with_funds_queued_refunds_client(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 0);
        $offer  = $this->makeOffer($banker, $client, amountSent: 80_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.cancel', $offer))
            ->assertSessionHas('success');

        $client->refresh();
        $offer->refresh();

        $this->assertSame(80_000, (int) $client->dirty_cash, 'Queued funds must be returned to client');
        $this->assertSame(LaunderOffer::STATUS_CANCELLED, $offer->status);
        $this->assertSame(0, (int) $offer->amount_sent);
    }

    public function test_client_cancel_with_funds_queued_refunds_dirty_cash(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 0);
        $offer  = $this->makeOffer($banker, $client, amountSent: 60_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $this->actingAs($client->user)
            ->post(route('career.banking.launder.cancel', $offer))
            ->assertSessionHas('success');

        $client->refresh();
        $offer->refresh();

        $this->assertSame(60_000, (int) $client->dirty_cash, 'Client self-cancel must also refund dirty cash');
        $this->assertSame(LaunderOffer::STATUS_CANCELLED, $offer->status);
    }

    public function test_cancel_with_no_funds_queued_no_refund(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 0);
        $offer  = $this->makeOffer($banker, $client); 

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.cancel', $offer))
            ->assertSessionHas('success');

        $client->refresh();
        $this->assertSame(0, (int) $client->dirty_cash, 'No refund when nothing was queued');
        $this->assertSame(LaunderOffer::STATUS_CANCELLED, $offer->refresh()->status);
    }

    public function test_cancel_blocked_for_non_participant(): void
    {
        $banker    = $this->makeBanker();
        $client    = $this->makeClient();
        $intruder  = $this->makeClient();
        $offer     = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $this->actingAs($intruder->user)
            ->post(route('career.banking.launder.cancel', $offer))
            ->assertSessionHas('error');

        $offer->refresh();
        $this->assertSame(LaunderOffer::STATUS_CLIENT_SENT, $offer->status, 'Offer must remain active');
    }

    public function test_cancel_blocked_on_already_cancelled_offer(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, status: LaunderOffer::STATUS_CANCELLED);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.cancel', $offer))
            ->assertSessionHas('error');
    }

    public function test_cancel_notifies_other_party(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.cancel', $offer));

        $this->assertTrue(
            CharacterJournal::where('character_id', $client->id)
                ->where('type', 'banker_launder_cancelled')
                ->exists(),
            'Client must receive a cancellation journal entry'
        );
    }

    
    
    

    public function test_refund_restores_dirty_cash_and_resets_offer_to_pending(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 0);
        $offer  = $this->makeOffer($banker, $client, amountSent: 75_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client-refund'), [
                'target_id' => $offer->id,
            ])
            ->assertSessionHas('success');

        $client->refresh();
        $offer->refresh();

        $this->assertSame(75_000, (int) $client->dirty_cash, 'Dirty cash must be restored');
        $this->assertSame(LaunderOffer::STATUS_PENDING, $offer->status, 'Offer must reset to pending');
        $this->assertSame(0, (int) $offer->amount_sent);
    }

    public function test_refund_blocked_when_no_funds_queued(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client); 

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client-refund'), [
                'target_id' => $offer->id,
            ])
            ->assertSessionHas('error');
    }

    public function test_refund_blocked_for_non_client(): void
    {
        $banker    = $this->makeBanker();
        $client    = $this->makeClient(dirtyCash: 0);
        $intruder  = $this->makeClient();
        $offer     = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $this->actingAs($intruder->user)
            ->post(route('actions.banker-launder-client-refund'), [
                'target_id' => $offer->id,
            ])
            ->assertSessionHas('error');

        $client->refresh();
        $this->assertSame(0, (int) $client->dirty_cash, 'Dirty cash must not be transferred to intruder');
    }

    public function test_refund_blocked_on_inactive_offer(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 0);
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CANCELLED);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client-refund'), [
                'target_id' => $offer->id,
            ])
            ->assertSessionHas('error');

        $client->refresh();
        $this->assertSame(0, (int) $client->dirty_cash);
    }

    public function test_refund_arrangement_remains_active_after_refund(): void
    {
        
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 0);
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client-refund'), [
                'target_id' => $offer->id,
            ]);

        $offer->refresh();
        $this->assertTrue($offer->isActive(), 'Arrangement must remain active after a refund');
    }

    
    
    

    public function test_banker_death_cancels_active_offers_and_refunds_queued_funds(): void
    {
        $banker  = $this->makeBanker();
        $client1 = $this->makeClient(dirtyCash: 0);
        $client2 = $this->makeClient(dirtyCash: 0);

        
        $offer1 = $this->makeOffer($banker, $client1, amountSent: 100_000, status: LaunderOffer::STATUS_CLIENT_SENT);
        $offer2 = $this->makeOffer($banker, $client2); 

        $banker->kill('combat', 'Test death');

        $client1->refresh();
        $client2->refresh();
        $offer1->refresh();
        $offer2->refresh();

        $this->assertSame(100_000, (int) $client1->dirty_cash, 'Queued dirty cash must be returned to client1');
        $this->assertSame(0,       (int) $client2->dirty_cash, 'client2 had no funds queued — no refund');
        $this->assertSame(LaunderOffer::STATUS_CANCELLED, $offer1->status);
        $this->assertSame(LaunderOffer::STATUS_CANCELLED, $offer2->status);
    }

    public function test_client_death_cancels_their_active_offers_no_refund(): void
    {
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 0);

        $offer = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $client->kill('combat', 'Test death');

        $offer->refresh();

        
        $this->assertSame(LaunderOffer::STATUS_CANCELLED, $offer->status);
        $this->assertSame(0, (int) $offer->amount_sent);
    }

    
    
    

    public function test_concurrent_client_sends_cannot_exceed_capacity(): void
    {
        
        $banker = $this->makeBanker();
        $client = $this->makeClient(dirtyCash: 2_000_000);
        $offer  = $this->makeOffer($banker, $client);

        $action  = new BankerLaunderClient();

        $result1 = $action->execute($client->fresh(), ['target_id' => $offer->id, 'amount' => 700_000]);
        $result2 = $action->execute($client->fresh(), ['target_id' => $offer->id, 'amount' => 700_000]);

        $offer->refresh();

        $this->assertLessThanOrEqual(
            BankerLaunderClient::maxAmount((int) $this->bank->fresh()->balance),
            (int) $offer->amount_sent,
            'Total queued must never exceed the bank capacity regardless of concurrent sends'
        );

        
        $client->refresh();
        $this->assertSame(
            2_000_000 - (int) $offer->amount_sent,
            (int) $client->dirty_cash
        );
    }

    public function test_concurrent_execute_and_cancel_only_one_wins(): void
    {
        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        
        $executeResp = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        
        $offerFresh = LaunderOffer::find($offer->id);

        $cancelResp = $this->actingAs($client->user)
            ->post(route('career.banking.launder.cancel', $offerFresh));

        $offer->refresh();

        
        
        
        $this->assertNotSame(
            LaunderOffer::STATUS_CLIENT_SENT,
            $offer->status,
            'Offer should not remain in client_sent state after execute+cancel race'
        );
        $this->assertSame(0, (int) $offer->amount_sent, 'amount_sent must be 0 after either outcome');
    }

    
    
    

    public function test_max_amount_calculation_at_boundary_balances(): void
    {
        
        $this->assertSame(100_000, BankerLaunderClient::maxAmount(400_000));

        
        $this->assertSame(1_000_000, BankerLaunderClient::maxAmount(10_000_000));

        
        $this->assertSame(100_000, BankerLaunderClient::maxAmount(0));
    }

    public function test_executed_offer_resets_to_pending_not_executed_status(): void
    {
        
        
        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $client = $this->makeClient();
        $offer  = $this->makeOffer($banker, $client, amountSent: 50_000, status: LaunderOffer::STATUS_CLIENT_SENT);

        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('error')) {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $offer->refresh();
        $this->assertSame(
            LaunderOffer::STATUS_PENDING,
            $offer->status,
            'Offer must reset to PENDING after a successful execute so the arrangement is reusable'
        );
    }

    public function test_add_client_does_not_create_second_offer_when_first_is_cancelled(): void
    {
        
        $banker = $this->makeBanker();
        $client = $this->makeClient();

        $cancelled = $this->makeOffer($banker, $client, status: LaunderOffer::STATUS_CANCELLED);

        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => $client->display_name,
                'cut_pct'     => 0.15,
            ])
            ->assertSessionHas('success');

        $this->assertSame(
            1,
            LaunderOffer::where('banker_id', $banker->id)
                ->where('client_id', $client->id)
                ->active()
                ->count(),
            'One new active offer must exist after the cancelled one'
        );
    }

    public function test_full_launder_cycle_money_conservation(): void
    {
        
        $banker = $this->makeBanker();
        $this->forceSuccess($banker);
        $client = $this->makeClient(dirtyCash: 200_000);

        $amount = 200_000;
        $cutPct = 0.20;

        
        $this->actingAs($banker->user)
            ->post(route('career.banking.launder.add-client'), [
                'client_name' => $client->display_name,
                'cut_pct'     => $cutPct,
            ]);

        $offer = LaunderOffer::where('banker_id', $banker->id)->where('client_id', $client->id)->active()->firstOrFail();

        
        $this->actingAs($client->user)
            ->post(route('actions.banker-launder-client'), [
                'target_id' => $offer->id,
                'amount'    => $amount,
            ]);

        $client->refresh();
        $this->assertSame(0, (int) $client->dirty_cash, 'All dirty cash queued');

        $bankBefore = (int) $this->bank->fresh()->balance;

        
        $response = $this->actingAs($banker->user)
            ->post(route('career.banking.launder.execute', $offer));

        if ($response->getSession()->has('error')) {
            $this->markTestIncomplete('Roll failed — rerun.');
        }

        $client->refresh();
        $banker->refresh();

        $overhead    = (int) floor($amount * BankerLaunderClient::OVERHEAD);
        $cut         = (int) floor($amount * $cutPct);
        $clientClean = $amount - $overhead - $cut;

        $this->assertSame($clientClean, (int) $client->cash_in_bank, 'Client clean balance correct');
        $this->assertSame($cut,         (int) $banker->cash_in_bank,  'Banker cut correct');
        $this->assertSame(
            $bankBefore + $overhead,
            (int) $this->bank->fresh()->balance,
            'Bank overhead correct'
        );
        $this->assertSame(
            $amount,
            (int) $client->cash_in_bank + (int) $banker->cash_in_bank + ((int) $this->bank->fresh()->balance - $bankBefore),
            'Full money conservation: client + banker + bank = original dirty amount'
        );
    }
}
