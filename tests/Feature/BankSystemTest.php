<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class BankSystemTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private int $unemployedCareerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::create([
            'name' => 'BankCity-'.uniqid(),
            'slug' => 'bank-city-'.uniqid(),
            'crime_rate' => 0,
        ]);

        $unemployed = DB::table('careers')->where('code', 'unemployed')->first();
        $this->assertNotNull($unemployed, 'Unemployed career must exist in seeded DB');
        $this->unemployedCareerId = $unemployed->id;
    }

    

    
    private function makeCharacter(
        City $city,
        int $cashOnHand = 50_000,
        int $cashInBank = 100_000,
        ?int $homeCityId = null,
    ): Character {
        $user = User::factory()->create();

        $char = Character::create([
            'user_id' => $user->id,
            'display_name' => 'BankChar-'.uniqid(),
            'gender' => 'male',
            'city_id' => $city->id,
            'home_city_id' => $homeCityId ?? $city->id,
            'career_id' => $this->unemployedCareerId,
            'career_rank' => 1,
            'health' => 100,
            'max_health' => 100,
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => $cashInBank,
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => 1_000,
            'luck' => 1_000,
            'offense' => 1_000,
            'defense' => 1_000,
            'influence' => 0,
        ]);

        CharacterTimers::create(['character_id' => $char->id]);

        return $char;
    }

    
    private function makeBank(
        City $city,
        float $wireFee = 0.0,
        float $loanInterest = 2.0,
        ?int $ownerId = null,
    ): Business {
        return Business::create([
            'city_id' => $city->id,
            'code' => 'bank',
            'name' => 'Bank of '.$city->name,
            'slug' => 'bank-'.$city->id.'-'.uniqid(),
            'is_purchasable' => $ownerId === null,
            'is_active' => true,
            'base_price' => 5_000_000,
            'balance' => 0,
            'sort_order' => 1,
            'owner_id' => $ownerId,
            'data' => ['wire_fee' => $wireFee, 'loan_interest' => $loanInterest],
        ]);
    }

    
    private function makeRemoteCity(float $wireFee = 0.0): array
    {
        $city = City::create([
            'name' => 'Remote-'.uniqid(),
            'slug' => 'remote-'.uniqid(),
            'crime_rate' => 0,
        ]);

        $bank = $this->makeBank($city, wireFee: $wireFee);

        return [$city, $bank];
    }

    
    private function expectedFee(int $amount, float $rate): int
    {
        return $rate > 0 ? max(1, (int) round($amount * ($rate / 100))) : 0;
    }

    

    public function test_index_renders_for_character_in_city(): void
    {
        $this->makeBank($this->city);
        $char = $this->makeCharacter($this->city);

        $this->actingAs($char->user)
            ->get(route('city.bank.index', $this->city->slug))
            ->assertSuccessful();
    }

    public function test_index_redirects_when_no_bank_in_city(): void
    {
        $char = $this->makeCharacter($this->city);

        $this->actingAs($char->user)
            ->get(route('city.bank.index', $this->city->slug))
            ->assertRedirect(route('city.show', $this->city->slug));
    }

    public function test_index_my_wire_fee_comes_from_home_city_bank_not_visited_bank(): void
    {
        
        
        [$remoteCity] = $this->makeRemoteCity(wireFee: 1.0);
        $this->makeBank($this->city, wireFee: 3.0); 

        
        $char = $this->makeCharacter($remoteCity, homeCityId: $this->city->id);

        $response = $this->actingAs($char->user)
            ->get(route('city.bank.index', $remoteCity->slug));

        $response->assertSuccessful();
        $props = $response->original->getData()['page']['props']['bankData'];

        $this->assertEqualsWithDelta(
            3.0,
            (float) $props['my_wire_fee'],
            0.001,
            'my_wire_fee must reflect the sender\'s HOME city bank rate'
        );
        $this->assertEqualsWithDelta(
            1.0,
            (float) $props['wire_fee'],
            0.001,
            'wire_fee must reflect the VISITED city bank rate (for the owner panel)'
        );
    }

    

    public function test_deposit_moves_cash_from_hand_to_bank(): void
    {
        $this->makeBank($this->city);
        $char = $this->makeCharacter($this->city, cashOnHand: 20_000, cashInBank: 0);

        $this->actingAs($char->user)
            ->post(route('city.bank.deposit', $this->city->slug), ['amount' => 10_000])
            ->assertSessionHas('success');

        $char->refresh();
        $this->assertSame(10_000, (int) $char->cash_on_hand);
        $this->assertSame(10_000, (int) $char->cash_in_bank);
    }

    public function test_deposit_preserves_total_money(): void
    {
        $this->makeBank($this->city);
        $char = $this->makeCharacter($this->city, cashOnHand: 30_000, cashInBank: 10_000);
        $before = $char->cash_on_hand + $char->cash_in_bank;

        $this->actingAs($char->user)
            ->post(route('city.bank.deposit', $this->city->slug), ['amount' => 10_000]);

        $char->refresh();
        $this->assertSame($before, $char->cash_on_hand + $char->cash_in_bank,
            'Total money must be conserved on deposit');
    }

    public function test_deposit_rejects_amount_exceeding_cash_on_hand(): void
    {
        $this->makeBank($this->city);
        $char = $this->makeCharacter($this->city, cashOnHand: 500, cashInBank: 0);

        $this->actingAs($char->user)
            ->post(route('city.bank.deposit', $this->city->slug), ['amount' => 1_000])
            ->assertSessionHas('error');

        $char->refresh();
        $this->assertSame(500, (int) $char->cash_on_hand, 'Cash on hand unchanged on failed deposit');
        $this->assertSame(0, (int) $char->cash_in_bank, 'Bank balance unchanged on failed deposit');
    }

    public function test_deposit_validation_rejects_zero(): void
    {
        $this->makeBank($this->city);
        $char = $this->makeCharacter($this->city);

        $this->actingAs($char->user)
            ->post(route('city.bank.deposit', $this->city->slug), ['amount' => 0])
            ->assertSessionHasErrors('amount');
    }

    public function test_deposit_validation_rejects_negative(): void
    {
        $this->makeBank($this->city);
        $char = $this->makeCharacter($this->city);

        $this->actingAs($char->user)
            ->post(route('city.bank.deposit', $this->city->slug), ['amount' => -1])
            ->assertSessionHasErrors('amount');
    }

    

    public function test_withdraw_moves_cash_from_bank_to_hand(): void
    {
        $this->makeBank($this->city);
        $char = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 30_000);

        $this->actingAs($char->user)
            ->post(route('city.bank.withdraw', $this->city->slug), ['amount' => 15_000])
            ->assertSessionHas('success');

        $char->refresh();
        $this->assertSame(15_000, (int) $char->cash_on_hand);
        $this->assertSame(15_000, (int) $char->cash_in_bank);
    }

    public function test_withdraw_preserves_total_money(): void
    {
        $this->makeBank($this->city);
        $char = $this->makeCharacter($this->city, cashOnHand: 5_000, cashInBank: 20_000);
        $before = $char->cash_on_hand + $char->cash_in_bank;

        $this->actingAs($char->user)
            ->post(route('city.bank.withdraw', $this->city->slug), ['amount' => 10_000]);

        $char->refresh();
        $this->assertSame($before, $char->cash_on_hand + $char->cash_in_bank,
            'Total money must be conserved on withdrawal');
    }

    public function test_withdraw_rejects_amount_exceeding_bank_balance(): void
    {
        $this->makeBank($this->city);
        $char = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 500);

        $this->actingAs($char->user)
            ->post(route('city.bank.withdraw', $this->city->slug), ['amount' => 1_000])
            ->assertSessionHas('error');

        $char->refresh();
        $this->assertSame(0, (int) $char->cash_on_hand, 'Cash on hand unchanged on failed withdrawal');
        $this->assertSame(500, (int) $char->cash_in_bank, 'Bank balance unchanged on failed withdrawal');
    }

    public function test_withdraw_validation_rejects_zero(): void
    {
        $this->makeBank($this->city);
        $char = $this->makeCharacter($this->city);

        $this->actingAs($char->user)
            ->post(route('city.bank.withdraw', $this->city->slug), ['amount' => 0])
            ->assertSessionHasErrors('amount');
    }

    

    public function test_same_city_transfer_debits_sender_and_credits_recipient_exactly(): void
    {
        
        $this->makeBank($this->city, wireFee: 2.0);
        $sender = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 50_000);
        $recipient = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 10_000,
                'recipient' => $recipient->display_name,
            ])
            ->assertSessionHas('success');

        $sender->refresh();
        $recipient->refresh();

        $this->assertSame(40_000, (int) $sender->cash_in_bank, 'Sender debited amount only — no fee on same-city');
        $this->assertSame(10_000, (int) $recipient->cash_in_bank, 'Recipient credited full amount');
    }

    public function test_same_city_transfer_does_not_increase_bank_balance(): void
    {
        $bank = $this->makeBank($this->city, wireFee: 2.0);
        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($this->city, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 10_000,
                'recipient' => $recipient->display_name,
            ]);

        $bank->refresh();
        $this->assertSame(0, (int) $bank->balance, 'Bank vault must not collect a fee on same-city transfer');
    }

    public function test_same_city_transfer_emits_no_warning_flash(): void
    {
        $this->makeBank($this->city, wireFee: 2.0);
        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($this->city, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 10_000,
                'recipient' => $recipient->display_name,
            ])
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');
    }

    public function test_same_city_transfer_conserves_total_money(): void
    {
        $this->makeBank($this->city, wireFee: 2.0);
        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($this->city, cashInBank: 0);
        $before = $sender->cash_in_bank + $recipient->cash_in_bank;

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 10_000,
                'recipient' => $recipient->display_name,
            ]);

        $sender->refresh();
        $recipient->refresh();
        $this->assertSame(
            $before,
            $sender->cash_in_bank + $recipient->cash_in_bank,
            'No money created or destroyed in a same-city transfer'
        );
    }

    

    public function test_cross_city_sender_debited_amount_plus_fee(): void
    {
        [$remoteCity] = $this->makeRemoteCity();
        $this->makeBank($this->city, wireFee: 2.0);

        $sender = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 50_000);
        $recipient = $this->makeCharacter($remoteCity, cashOnHand: 0, cashInBank: 0);

        $amount = 10_000;
        $expectedFee = $this->expectedFee($amount, 2.0); 

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => $amount,
                'recipient' => $recipient->display_name,
            ])
            ->assertSessionHas('success');

        $sender->refresh();
        $this->assertSame(
            50_000 - $amount - $expectedFee,
            (int) $sender->cash_in_bank,
            'Sender debited transfer amount + wire fee'
        );
    }

    public function test_cross_city_recipient_receives_exact_amount_not_reduced_by_fee(): void
    {
        [$remoteCity] = $this->makeRemoteCity();
        $this->makeBank($this->city, wireFee: 2.0);

        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($remoteCity, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 10_000,
                'recipient' => $recipient->display_name,
            ]);

        $recipient->refresh();
        $this->assertSame(10_000, (int) $recipient->cash_in_bank,
            'Recipient receives the exact amount — fee is not deducted from their credit');
    }

    public function test_cross_city_fee_deposited_into_sender_home_bank_balance(): void
    {
        [$remoteCity] = $this->makeRemoteCity();
        $senderHomeBank = $this->makeBank($this->city, wireFee: 2.0);

        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($remoteCity, cashInBank: 0);

        $amount = 10_000;
        $expectedFee = $this->expectedFee($amount, 2.0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => $amount,
                'recipient' => $recipient->display_name,
            ]);

        $senderHomeBank->refresh();
        $this->assertSame(
            $expectedFee,
            (int) $senderHomeBank->balance,
            'Wire fee must land atomically in the sender\'s home-city bank vault'
        );
    }

    public function test_cross_city_fee_goes_to_sender_home_bank_not_recipient_bank(): void
    {
        [$remoteCity, $recipientBank] = $this->makeRemoteCity(wireFee: 3.0);
        $senderHomeBank = $this->makeBank($this->city, wireFee: 2.0);

        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($remoteCity, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 10_000,
                'recipient' => $recipient->display_name,
            ]);

        $recipientBank->refresh();
        $this->assertSame(0, (int) $recipientBank->balance,
            'Recipient\'s bank must receive nothing — fee is the sender\'s home bank\'s revenue');
    }

    public function test_cross_city_with_zero_fee_rate_no_charge_no_warning(): void
    {
        [$remoteCity] = $this->makeRemoteCity();
        $senderHomeBank = $this->makeBank($this->city, wireFee: 0.0);

        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($remoteCity, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 10_000,
                'recipient' => $recipient->display_name,
            ])
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');

        $sender->refresh();
        $this->assertSame(40_000, (int) $sender->cash_in_bank, 'No fee when wire_fee rate is 0%');

        $senderHomeBank->refresh();
        $this->assertSame(0, (int) $senderHomeBank->balance);
    }

    public function test_cross_city_fee_minimum_is_one_dollar(): void
    {
        
        [$remoteCity] = $this->makeRemoteCity();
        $senderHomeBank = $this->makeBank($this->city, wireFee: 1.0);

        $sender = $this->makeCharacter($this->city, cashInBank: 10_000);
        $recipient = $this->makeCharacter($remoteCity, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 20, 
                'recipient' => $recipient->display_name,
            ]);

        $senderHomeBank->refresh();
        $this->assertGreaterThanOrEqual(1, (int) $senderHomeBank->balance,
            'Wire fee minimum is $1 regardless of percentage calculation result');
    }

    public function test_cross_city_transfer_emits_warning_flash_when_fee_charged(): void
    {
        [$remoteCity] = $this->makeRemoteCity();
        $this->makeBank($this->city, wireFee: 2.0);

        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($remoteCity, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 10_000,
                'recipient' => $recipient->display_name,
            ])
            ->assertSessionHas('success')
            ->assertSessionHas('warning');
    }

    public function test_cross_city_fee_charged_from_sender_home_bank_regardless_of_visited_branch(): void
    {
        
        
        
        
        
        
        
        
        

        [$remoteCity,  $remoteCityBank] = $this->makeRemoteCity(wireFee: 4.0); 
        $senderHomeBank = $this->makeBank($this->city, wireFee: 2.0);

        $thirdCity = City::create([
            'name' => 'ThirdCity-'.uniqid(),
            'slug' => 'third-'.uniqid(),
            'crime_rate' => 0,
        ]);
        $this->makeBank($thirdCity, wireFee: 0.0);

        
        $sender = $this->makeCharacter($remoteCity, cashInBank: 50_000, homeCityId: $this->city->id);
        
        $recipient = $this->makeCharacter($thirdCity, cashInBank: 0);

        $amount = 10_000;
        $homeFee = $this->expectedFee($amount, 2.0); 

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $remoteCity->slug), [
                'amount' => $amount,
                'recipient' => $recipient->display_name,
            ]);

        $senderHomeBank->refresh();
        $remoteCityBank->refresh();

        $this->assertSame($homeFee, (int) $senderHomeBank->balance,
            'Fee must be collected by the sender\'s HOME city bank (2%), not the visited branch');
        $this->assertSame(0, (int) $remoteCityBank->balance,
            'Visited branch (4%) must collect nothing');
    }

    

    public function test_cross_city_blocked_when_balance_covers_amount_but_not_fee(): void
    {
        
        [$remoteCity] = $this->makeRemoteCity();
        $this->makeBank($this->city, wireFee: 2.0);

        $sender = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 10_000);
        $recipient = $this->makeCharacter($remoteCity, cashOnHand: 0, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 10_000,
                'recipient' => $recipient->display_name,
            ])
            ->assertSessionHas('error');

        $sender->refresh();
        $recipient->refresh();
        $this->assertSame(10_000, (int) $sender->cash_in_bank, 'Sender unchanged — transfer rejected');
        $this->assertSame(0, (int) $recipient->cash_in_bank, 'Recipient receives nothing on failed transfer');
    }

    public function test_transfer_blocked_when_bank_balance_is_zero(): void
    {
        $this->makeBank($this->city);
        $sender = $this->makeCharacter($this->city, cashOnHand: 50_000, cashInBank: 0);
        $recipient = $this->makeCharacter($this->city, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 1_000,
                'recipient' => $recipient->display_name,
            ])
            ->assertSessionHas('error');
    }

    

    public function test_transfer_to_self_rejected(): void
    {
        $this->makeBank($this->city);
        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 1_000,
                'recipient' => $sender->display_name,
            ])
            ->assertSessionHas('error');

        $sender->refresh();
        $this->assertSame(50_000, (int) $sender->cash_in_bank, 'Balance unchanged on self-transfer attempt');
    }

    public function test_transfer_to_nonexistent_player_rejected(): void
    {
        $this->makeBank($this->city);
        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 1_000,
                'recipient' => 'NoSuchPlayer_'.uniqid(),
            ])
            ->assertSessionHas('error');
    }

    public function test_transfer_to_dead_character_rejected(): void
    {
        $this->makeBank($this->city);
        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $dead = $this->makeCharacter($this->city, cashInBank: 0);

        
        $dead->delete();

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 1_000,
                'recipient' => $dead->display_name,
            ])
            ->assertSessionHas('error');

        $sender->refresh();
        $this->assertSame(50_000, (int) $sender->cash_in_bank, 'Balance unchanged when recipient is dead');
    }

    public function test_transfer_to_zero_health_character_rejected(): void
    {
        $this->makeBank($this->city);
        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $zeroHp = $this->makeCharacter($this->city, cashInBank: 0);

        
        $zeroHp->update(['health' => 0]);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 1_000,
                'recipient' => $zeroHp->display_name,
            ])
            ->assertSessionHas('error');
    }

    public function test_transfer_validation_rejects_missing_recipient(): void
    {
        $this->makeBank($this->city);
        $sender = $this->makeCharacter($this->city);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), ['amount' => 1_000])
            ->assertSessionHasErrors('recipient');
    }

    public function test_transfer_validation_rejects_zero_amount(): void
    {
        $this->makeBank($this->city);
        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($this->city, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 0,
                'recipient' => $recipient->display_name,
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_transfer_note_field_is_optional(): void
    {
        $this->makeBank($this->city);
        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($this->city, cashInBank: 0);

        
        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 5_000,
                'recipient' => $recipient->display_name,
            ])
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();
    }

    public function test_transfer_note_field_validation_rejects_overlong_string(): void
    {
        $this->makeBank($this->city);
        $sender = $this->makeCharacter($this->city, cashInBank: 50_000);
        $recipient = $this->makeCharacter($this->city, cashInBank: 0);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => 5_000,
                'recipient' => $recipient->display_name,
                'note' => str_repeat('x', 21), 
            ])
            ->assertSessionHasErrors('note');
    }

    

    public function test_cross_city_transfer_total_money_equals_sender_debit_split_to_recipient_and_bank(): void
    {
        
        
        [$remoteCity] = $this->makeRemoteCity();
        $senderHomeBank = $this->makeBank($this->city, wireFee: 2.5);

        $sender = $this->makeCharacter($this->city, cashInBank: 100_000);
        $recipient = $this->makeCharacter($remoteCity, cashInBank: 0);

        $amount = 40_000;
        $fee = $this->expectedFee($amount, 2.5);

        $this->actingAs($sender->user)
            ->post(route('city.bank.transfer', $this->city->slug), [
                'amount' => $amount,
                'recipient' => $recipient->display_name,
            ]);

        $sender->refresh();
        $recipient->refresh();
        $senderHomeBank->refresh();

        $this->assertSame(
            100_000,
            (int) $sender->cash_in_bank + (int) $recipient->cash_in_bank + (int) $senderHomeBank->balance,
            'All money is accounted for: sender + recipient + bank vault = original sender balance'
        );
    }

    

    public function test_owner_can_update_wire_fee_and_loan_interest(): void
    {
        $owner = $this->makeCharacter($this->city);
        $bank = $this->makeBank($this->city, wireFee: 0.5, loanInterest: 1.0, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.bank.settings', $this->city->slug), [
                'wire_fee' => 2.5,
                'loan_interest' => 3.0,
            ])
            ->assertSessionHas('success');

        $bank->refresh();
        $this->assertEqualsWithDelta(2.5, (float) $bank->getSetting('wire_fee'), 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $bank->getSetting('loan_interest'), 0.001);
    }

    public function test_non_owner_cannot_update_bank_settings(): void
    {
        $owner = $this->makeCharacter($this->city);
        $intruder = $this->makeCharacter($this->city);
        $bank = $this->makeBank($this->city, wireFee: 1.0, ownerId: $owner->id);

        $this->actingAs($intruder->user)
            ->post(route('city.bank.settings', $this->city->slug), [
                'wire_fee' => 3.0,
                'loan_interest' => 5.0,
            ])
            ->assertSessionHas('error');

        $bank->refresh();
        $this->assertEqualsWithDelta(1.0, (float) $bank->getSetting('wire_fee'), 0.001,
            'Wire fee must be unchanged after non-owner update attempt');
    }

    

    public function test_settings_wire_fee_rejects_above_maximum_of_5_percent(): void
    {
        $owner = $this->makeCharacter($this->city);
        $this->makeBank($this->city, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.bank.settings', $this->city->slug), [
                'wire_fee' => 5.1, 
                'loan_interest' => 2.0,
            ])
            ->assertSessionHasErrors('wire_fee');
    }

    public function test_settings_wire_fee_accepts_zero_for_free_transfers(): void
    {
        $owner = $this->makeCharacter($this->city);
        $bank = $this->makeBank($this->city, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.bank.settings', $this->city->slug), [
                'wire_fee' => 0.0, 
                'loan_interest' => 2.0,
            ])
            ->assertSessionHasNoErrors();

        $bank->refresh();
        $this->assertEqualsWithDelta(0.0, (float) $bank->getSetting('wire_fee'), 0.001);
    }

    public function test_settings_wire_fee_accepts_boundary_values(): void
    {
        $owner = $this->makeCharacter($this->city);
        $bank = $this->makeBank($this->city, ownerId: $owner->id);

        foreach ([0.0, 0.5, 5.0] as $boundary) {
            $this->actingAs($owner->user)
                ->post(route('city.bank.settings', $this->city->slug), [
                    'wire_fee' => $boundary,
                    'loan_interest' => 2.0,
                ])
                ->assertSessionHasNoErrors();
        }
    }

    

    public function test_settings_loan_interest_rejects_above_maximum_of_5_percent(): void
    {
        $owner = $this->makeCharacter($this->city);
        $this->makeBank($this->city, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.bank.settings', $this->city->slug), [
                'wire_fee' => 1.0,
                'loan_interest' => 5.1, 
            ])
            ->assertSessionHasErrors('loan_interest');
    }

    public function test_settings_loan_interest_rejects_below_minimum_of_0_5_percent(): void
    {
        $owner = $this->makeCharacter($this->city);
        $this->makeBank($this->city, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.bank.settings', $this->city->slug), [
                'wire_fee' => 1.0,
                'loan_interest' => 0.4, 
            ])
            ->assertSessionHasErrors('loan_interest');
    }

    public function test_settings_loan_interest_accepts_boundary_values(): void
    {
        $owner = $this->makeCharacter($this->city);
        $bank = $this->makeBank($this->city, ownerId: $owner->id);

        foreach ([0.5, 5.0] as $boundary) {
            $this->actingAs($owner->user)
                ->post(route('city.bank.settings', $this->city->slug), [
                    'wire_fee' => 1.0,
                    'loan_interest' => $boundary,
                ])
                ->assertSessionHasNoErrors();
        }
    }

    public function test_settings_update_for_city_with_no_bank_returns_error(): void
    {
        
        $char = $this->makeCharacter($this->city);

        $this->actingAs($char->user)
            ->post(route('city.bank.settings', $this->city->slug), [
                'wire_fee' => 1.0,
                'loan_interest' => 2.0,
            ])
            ->assertSessionHas('error');
    }

    
    
    

    

    public function test_cd_purchase_happy_path(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 500_000);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 1_000_000]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 50_000);

        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 10_000,
            ])
            ->assertSessionHas('success');

        $buyer->refresh();
        $bank->refresh();

        
        $this->assertEquals(40_000, $buyer->cash_in_bank);

        
        $this->assertEquals(1_010_000, $bank->balance);

        
        $cd = \App\Models\BankCertificate::where('character_id', $buyer->id)
            ->where('business_id', $bank->id)
            ->first();

        $this->assertNotNull($cd);
        $this->assertEquals(10_000, $cd->principal);
        $this->assertEquals(200, $cd->rate);  
        $this->assertEquals(200, $cd->interest_owed); 
        $this->assertNull($cd->settled_at);
        $this->assertNull($cd->outcome);
    }

    public function test_cd_purchase_interest_calculation_with_decimal_rate(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 3.5, ownerId: $owner->id);
        $bank->update(['balance' => 5_000_000]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 100_000);

        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 50_000,
            ])
            ->assertSessionHas('success');

        $cd = \App\Models\BankCertificate::where('character_id', $buyer->id)->first();

        
        $this->assertEquals(350, $cd->rate);
        $this->assertEquals(1_750, $cd->interest_owed);
    }

    public function test_cd_purchase_rejected_if_not_home_city(): void
    {
        [$remoteCity, $remoteBank] = $this->makeRemoteCity();
        $remoteBank->update(['balance' => 5_000_000]);

        
        $char = $this->makeCharacter($remoteCity, cashOnHand: 0, cashInBank: 100_000, homeCityId: $this->city->id);
        $this->makeBank($this->city); 

        $this->actingAs($char->user)
            ->post(route('city.bank.certificates.purchase', $remoteCity->slug), [
                'amount' => 5_000,
            ])
            ->assertSessionHas('error');

        
        $this->assertEquals(0, \App\Models\BankCertificate::where('character_id', $char->id)->count());
    }

    public function test_cd_purchase_rejected_if_already_active_cd(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 5_000_000]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 100_000);

        
        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 5_000,
            ])
            ->assertSessionHas('success');

        
        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 5_000,
            ])
            ->assertSessionHas('error');

        
        $this->assertEquals(1, \App\Models\BankCertificate::where('character_id', $buyer->id)->count());
    }

    public function test_cd_purchase_rejected_if_insufficient_balance(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 5_000_000]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 50_000, cashInBank: 500);

        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 1_000,
            ])
            ->assertSessionHas('error');

        $buyer->refresh();
        $this->assertEquals(500, $buyer->cash_in_bank); 
    }

    public function test_cd_purchase_rejected_below_minimum(): void
    {
        $owner = $this->makeCharacter($this->city);
        $bank = $this->makeBank($this->city, ownerId: $owner->id);
        $bank->update(['balance' => 5_000_000]);

        $buyer = $this->makeCharacter($this->city, cashInBank: 100_000);

        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 999, 
            ])
            ->assertSessionHasErrors('amount');
    }

    public function test_cd_purchase_rejected_by_capital_adequacy(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 100_000]);

        
        \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $owner->id,
            'principal' => 80_000,
            'rate' => 200,
            'interest_owed' => 1_600,
            'matures_at' => now()->addHours(24),
        ]);

        
        
        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 100_000);

        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 5_000, 
            ])
            ->assertSessionHas('error', 'The bank cannot accept a deposit of this size due to capital adequacy requirements.');
    }

    public function test_cd_purchase_within_capital_adequacy_succeeds(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 100_000]);

        
        \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $owner->id,
            'principal' => 80_000,
            'rate' => 200,
            'interest_owed' => 1_600,
            'matures_at' => now()->addHours(24),
        ]);

        
        
        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 100_000);

        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 4_000, 
            ])
            ->assertSessionHas('success');
    }

    public function test_cd_purchase_creates_bank_transaction(): void
    {
        $owner = $this->makeCharacter($this->city);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 5_000_000]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 50_000);

        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 10_000,
            ])
            ->assertSessionHas('success');

        $tx = \App\Models\BankTransaction::where('character_id', $buyer->id)
            ->where('note', 'CD purchase')
            ->first();

        $this->assertNotNull($tx);
        $this->assertEquals(10_000, $tx->amount);
        $this->assertEquals(40_000, $tx->balance_after);
    }

    public function test_cd_purchase_no_bank_returns_error(): void
    {
        
        $char = $this->makeCharacter($this->city, cashInBank: 100_000);

        $this->actingAs($char->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 5_000,
            ])
            ->assertSessionHas('error');
    }

    public function test_cd_purchase_rejected_when_bank_balance_zero(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 0]); 

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 100_000);

        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 1_000,
            ])
            ->assertSessionHas('error', 'This bank is not accepting new certificates at this time.');

        
        $this->assertEquals(0, \App\Models\BankCertificate::where('character_id', $buyer->id)->count());
        $buyer->refresh();
        $this->assertEquals(100_000, $buyer->cash_in_bank);
    }

    public function test_cd_purchase_rejected_when_liabilities_consume_all_balance(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 50_000]);

        
        \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $owner->id,
            'principal' => 50_000,
            'rate' => 200,
            'interest_owed' => 1_000,
            'matures_at' => now()->addHours(24),
        ]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 100_000);

        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 1_000,
            ])
            ->assertSessionHas('error', 'This bank is not accepting new certificates at this time.');
    }

    

    public function test_auto_settle_pays_full_amount_on_maturity(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 500_000]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 10_000);

        
        $cd = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $buyer->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHour(),
        ]);

        
        $this->actingAs($buyer->user)
            ->get(route('city.bank.index', $this->city->slug));

        $buyer->refresh();
        $bank->refresh();
        $cd->refresh();

        
        $this->assertEquals(10_000 + 10_200, $buyer->cash_in_bank); 
        
        $this->assertEquals(500_000 - 10_200, $bank->balance);
        
        $this->assertNotNull($cd->settled_at);
        $this->assertEquals(\App\Models\BankCertificate::OUTCOME_MATURED, $cd->outcome);
    }

    public function test_auto_settle_does_not_settle_immature_cd(): void
    {
        $owner = $this->makeCharacter($this->city);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 500_000]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 10_000);

        
        $cd = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $buyer->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->addHours(12), 
        ]);

        $this->actingAs($buyer->user)
            ->get(route('city.bank.index', $this->city->slug));

        $cd->refresh();
        $buyer->refresh();

        $this->assertNull($cd->settled_at);
        $this->assertEquals(10_000, $buyer->cash_in_bank); 
    }

    public function test_auto_settle_settles_all_matured_cds_at_bank(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 2_000_000]);

        $buyerA = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $buyerB = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);

        $cdA = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $buyerA->id,
            'principal' => 50_000,
            'rate' => 200,
            'interest_owed' => 1_000,
            'matures_at' => now()->subHour(),
        ]);

        $cdB = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $buyerB->id,
            'principal' => 30_000,
            'rate' => 200,
            'interest_owed' => 600,
            'matures_at' => now()->subHour(),
        ]);

        
        $visitor = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $this->actingAs($visitor->user)
            ->get(route('city.bank.index', $this->city->slug));

        $cdA->refresh();
        $cdB->refresh();
        $buyerA->refresh();
        $buyerB->refresh();
        $bank->refresh();

        
        $this->assertNotNull($cdA->settled_at);
        $this->assertNotNull($cdB->settled_at);
        $this->assertEquals(\App\Models\BankCertificate::OUTCOME_MATURED, $cdA->outcome);
        $this->assertEquals(\App\Models\BankCertificate::OUTCOME_MATURED, $cdB->outcome);

        
        $this->assertEquals(51_000, $buyerA->cash_in_bank);
        $this->assertEquals(30_600, $buyerB->cash_in_bank);

        
        $this->assertEquals(2_000_000 - 51_000 - 30_600, $bank->balance);
    }

    public function test_auto_settle_creates_bank_transaction(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 500_000]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 5_000);

        $cd = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $buyer->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHour(),
        ]);

        $this->actingAs($buyer->user)
            ->get(route('city.bank.index', $this->city->slug));

        $tx = \App\Models\BankTransaction::where('character_id', $buyer->id)
            ->where('note', 'CD matured')
            ->first();

        $this->assertNotNull($tx);
        $this->assertEquals(10_200, $tx->amount);
        $this->assertEquals(5_000 + 10_200, $tx->balance_after);
    }

    public function test_auto_settle_earliest_maturing_cd_gets_priority(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        
        $bank->update(['balance' => 15_000]);

        $earlyBuyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $lateBuyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);

        
        $cdEarly = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $earlyBuyer->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHours(2),
        ]);

        
        $cdLate = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $lateBuyer->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHour(),
        ]);

        $visitor = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $this->actingAs($visitor->user)
            ->get(route('city.bank.index', $this->city->slug));

        $cdEarly->refresh();
        $cdLate->refresh();
        $earlyBuyer->refresh();
        $lateBuyer->refresh();
        $bank->refresh();

        
        $this->assertEquals(10_200, $earlyBuyer->cash_in_bank);
        $this->assertEquals(\App\Models\BankCertificate::OUTCOME_MATURED, $cdEarly->outcome);

        
        $this->assertEquals(4_800, $lateBuyer->cash_in_bank);
        $this->assertEquals(\App\Models\BankCertificate::OUTCOME_DEFAULTED, $cdLate->outcome);

        $this->assertEquals(0, $bank->balance);
    }

    

    public function test_cascade_takes_from_owner_when_bank_insufficient(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 20_000, cashInBank: 30_000);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 5_000]); 

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);

        $cd = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $buyer->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHour(),
        ]);

        $this->actingAs($buyer->user)
            ->get(route('city.bank.index', $this->city->slug));

        $buyer->refresh();
        $owner->refresh();
        $bank->refresh();
        $cd->refresh();

        
        $this->assertEquals(10_200, $buyer->cash_in_bank);
        $this->assertEquals(0, $bank->balance);
        
        $ownerTotal = $owner->cash_on_hand + $owner->cash_in_bank;
        $this->assertEquals((20_000 + 30_000) - 5_200, $ownerTotal);
        $this->assertEquals(\App\Models\BankCertificate::OUTCOME_MATURED, $cd->outcome);
    }

    public function test_cascade_takes_from_manager_when_bank_and_owner_insufficient(): void
    {
        
        $bankingCareer = DB::table('careers')->where('code', 'banking')->first();
        if (! $bankingCareer) {
            $this->markTestSkipped('Banking career not seeded');
        }

        $owner = $this->makeCharacter($this->city, cashOnHand: 1_000, cashInBank: 1_000);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 3_000]); 

        
        $managerUser = User::factory()->create();
        $manager = Character::create([
            'user_id' => $managerUser->id,
            'display_name' => 'BankManager-'.uniqid(),
            'gender' => 'male',
            'city_id' => $this->city->id,
            'home_city_id' => $this->city->id,
            'career_id' => $bankingCareer->id,
            'career_rank' => 4,
            'health' => 100,
            'max_health' => 100,
            'cash_on_hand' => 10_000,
            'cash_in_bank' => 10_000,
        ]);
        CharacterStats::create([
            'character_id' => $manager->id,
            'intelligence' => 1_000,
            'luck' => 1_000,
            'offense' => 1_000,
            'defense' => 1_000,
            'influence' => 0,
        ]);
        \App\Models\CharacterTimers::create(['character_id' => $manager->id]);

        
        cache()->forget("city_{$this->city->id}_leader_banking");

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);

        
        
        $cd = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $buyer->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHour(),
        ]);

        $this->actingAs($buyer->user)
            ->get(route('city.bank.index', $this->city->slug));

        $buyer->refresh();
        $owner->refresh();
        $manager->refresh();
        $bank->refresh();
        $cd->refresh();

        $this->assertEquals(10_200, $buyer->cash_in_bank);
        $this->assertEquals(0, $bank->balance);
        $this->assertEquals(0, $owner->cash_on_hand + $owner->cash_in_bank);
        $mgrRemaining = $manager->cash_on_hand + $manager->cash_in_bank;
        $this->assertEquals(20_000 - 5_200, $mgrRemaining);
        $this->assertEquals(\App\Models\BankCertificate::OUTCOME_MATURED, $cd->outcome);
    }

    public function test_cascade_default_when_all_sources_exhausted(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 500, cashInBank: 500);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 1_000]); 

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);

        
        $cd = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $buyer->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHour(),
        ]);

        $this->actingAs($buyer->user)
            ->get(route('city.bank.index', $this->city->slug));

        $buyer->refresh();
        $owner->refresh();
        $bank->refresh();
        $cd->refresh();

        
        $this->assertEquals(2_000, $buyer->cash_in_bank);
        $this->assertEquals(0, $bank->balance);
        $this->assertEquals(0, $owner->cash_on_hand + $owner->cash_in_bank);
        $this->assertEquals(\App\Models\BankCertificate::OUTCOME_DEFAULTED, $cd->outcome);
    }

    public function test_cascade_default_transaction_note_says_default(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 500]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);

        $cd = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $buyer->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHour(),
        ]);

        $this->actingAs($buyer->user)
            ->get(route('city.bank.index', $this->city->slug));

        $tx = \App\Models\BankTransaction::where('character_id', $buyer->id)
            ->where('note', 'CD default')
            ->first();

        $this->assertNotNull($tx);
        $this->assertEquals(500, $tx->amount); 
    }

    

    public function test_cascade_skips_owner_when_owner_is_cd_holder(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 100_000, cashInBank: 100_000);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 0]); 

        
        $cd = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $owner->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHour(),
        ]);

        $ownerTotalBefore = $owner->cash_on_hand + $owner->cash_in_bank;

        $this->actingAs($owner->user)
            ->get(route('city.bank.index', $this->city->slug));

        $owner->refresh();
        $cd->refresh();

        
        
        $this->assertEquals(\App\Models\BankCertificate::OUTCOME_DEFAULTED, $cd->outcome);
        
        
        $this->assertEquals($ownerTotalBefore, $owner->cash_on_hand + $owner->cash_in_bank);
    }

    public function test_cascade_skips_manager_when_manager_is_cd_holder(): void
    {
        $bankingCareer = DB::table('careers')->where('code', 'banking')->first();
        if (! $bankingCareer) {
            $this->markTestSkipped('Banking career not seeded');
        }

        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 0]);

        
        $managerUser = User::factory()->create();
        $manager = Character::create([
            'user_id' => $managerUser->id,
            'display_name' => 'BankMgr-'.uniqid(),
            'gender' => 'male',
            'city_id' => $this->city->id,
            'home_city_id' => $this->city->id,
            'career_id' => $bankingCareer->id,
            'career_rank' => 4,
            'health' => 100,
            'max_health' => 100,
            'cash_on_hand' => 50_000,
            'cash_in_bank' => 50_000,
        ]);
        CharacterStats::create([
            'character_id' => $manager->id,
            'intelligence' => 1_000,
            'luck' => 1_000,
            'offense' => 1_000,
            'defense' => 1_000,
            'influence' => 0,
        ]);
        \App\Models\CharacterTimers::create(['character_id' => $manager->id]);
        cache()->forget("city_{$this->city->id}_leader_banking");

        
        $cd = \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $manager->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHour(),
        ]);

        $mgrTotalBefore = $manager->cash_on_hand + $manager->cash_in_bank;

        $this->actingAs($manager->user)
            ->get(route('city.bank.index', $this->city->slug));

        $manager->refresh();
        $cd->refresh();

        
        $this->assertEquals(\App\Models\BankCertificate::OUTCOME_DEFAULTED, $cd->outcome);
        
        $this->assertEquals($mgrTotalBefore, $manager->cash_on_hand + $manager->cash_in_bank);
    }

    

    public function test_cd_purchase_and_settle_conserves_total_money(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 1_000_000]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 50_000);

        $systemTotalBefore = $bank->balance + $buyer->cash_in_bank + $owner->cash_on_hand + $owner->cash_in_bank;

        
        $this->actingAs($buyer->user)
            ->post(route('city.bank.certificates.purchase', $this->city->slug), [
                'amount' => 20_000,
            ])
            ->assertSessionHas('success');

        $buyer->refresh();
        $bank->refresh();

        $systemAfterPurchase = $bank->balance + $buyer->cash_in_bank + $owner->cash_on_hand + $owner->cash_in_bank;
        $this->assertEquals($systemTotalBefore, $systemAfterPurchase);

        
        $cd = \App\Models\BankCertificate::where('character_id', $buyer->id)->first();
        $cd->update(['matures_at' => now()->subHour()]);

        
        $this->actingAs($buyer->user)
            ->get(route('city.bank.index', $this->city->slug));

        $buyer->refresh();
        $bank->refresh();
        $owner->refresh();

        
        
        $systemAfterSettle = $bank->balance + $buyer->cash_in_bank + $owner->cash_on_hand + $owner->cash_in_bank;
        
        $this->assertEquals($systemTotalBefore, $systemAfterSettle);
    }

    

    public function test_bank_cannot_be_sold_while_cds_active(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 100_000, cashInBank: 100_000);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 500_000]);

        $buyer = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);

        
        \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $buyer->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->addHours(12),
        ]);

        $this->actingAs($owner->user)
            ->post(route('city.business.venture.sell', $this->city->slug), [
                'business_id' => $bank->id,
                'price' => 5_000_000,
            ])
            ->assertSessionHas('error');
    }

    public function test_bank_can_be_sold_when_no_active_cds(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 100_000, cashInBank: 100_000);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 500_000]);

        
        \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $owner->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->subHour(),
            'settled_at' => now(),
            'outcome' => \App\Models\BankCertificate::OUTCOME_MATURED,
        ]);

        $this->actingAs($owner->user)
            ->post(route('city.business.venture.sell', $this->city->slug), [
                'business_id' => $bank->id,
                'price' => 5_000_000,
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_bank_cannot_be_purchased_while_cds_active(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 100_000, cashInBank: 100_000);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 500_000, 'is_purchasable' => true, 'sale_price' => 5_000_000]);

        $depositor = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);

        
        \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $depositor->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->addHours(12),
        ]);

        $rich = $this->makeCharacter($this->city, cashOnHand: 10_000_000, cashInBank: 0);

        $this->actingAs($rich->user)
            ->post(route('city.business.venture.purchase', $this->city->slug), [
                'business_id' => $bank->id,
            ])
            ->assertSessionHas('error');
    }

    

    public function test_bank_owner_withdraw_capped_by_liabilities(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 100_000]);

        
        \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $this->makeCharacter($this->city)->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->addHours(12),
        ]);

        
        $this->actingAs($owner->user)
            ->post(route('city.business.withdraw', $this->city->slug), [
                'business_id' => $bank->id,
                'amount' => 95_000,
            ])
            ->assertSessionHas('error');
    }

    public function test_bank_owner_can_withdraw_within_liability_cap(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 100_000]);

        
        \App\Models\BankCertificate::create([
            'business_id' => $bank->id,
            'character_id' => $this->makeCharacter($this->city)->id,
            'principal' => 10_000,
            'rate' => 200,
            'interest_owed' => 200,
            'matures_at' => now()->addHours(12),
        ]);

        
        $this->actingAs($owner->user)
            ->post(route('city.business.withdraw', $this->city->slug), [
                'business_id' => $bank->id,
                'amount' => 89_800,
            ])
            ->assertSessionHasNoErrors();

        $bank->refresh();
        $this->assertEquals(10_200, $bank->balance);
    }

    public function test_bank_owner_withdraw_no_cds_full_balance_available(): void
    {
        $owner = $this->makeCharacter($this->city, cashOnHand: 0, cashInBank: 0);
        $bank = $this->makeBank($this->city, loanInterest: 2.0, ownerId: $owner->id);
        $bank->update(['balance' => 100_000]);

        $this->actingAs($owner->user)
            ->post(route('city.business.withdraw', $this->city->slug), [
                'business_id' => $bank->id,
                'amount' => 100_000,
            ])
            ->assertSessionHasNoErrors();

        $bank->refresh();
        $this->assertEquals(0, $bank->balance);
    }
}
