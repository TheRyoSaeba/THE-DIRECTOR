<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\Character;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CorporationMergerRequest;
use App\Models\CorporationProperty;
use App\Models\CorporationSubsidiaryInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CorporationMoveTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeCity(string $label = 'move'): City
    {
        return City::create([
            'name' => 'MoveCity-' . $label . '-' . uniqid(),
            'slug' => 'move-city-' . $label . '-' . uniqid(),
            'crime_rate' => 35,
        ]);
    }

    private function makeCharacter(
        City $city,
        string $careerCode = 'corporation',
        int $rank = 1,
        int $cashOnHand = 100_000,
        ?City $physicallyIn = null,
    ): Character {
        $user = User::factory()->create();
        $career = Career::findByCode($careerCode);

        $character = Character::create([
            'user_id' => $user->id,
            'display_name' => 'MoveChar-' . uniqid(),
            'gender' => 'male',
            'city_id' => ($physicallyIn ?? $city)->id,
            'home_city_id' => $city->id,
            'career_id' => $career->id,
            'career_rank' => $rank,
            'career_xp' => 0,
            'total_character_exp' => 0,
            'health' => 100,
            'max_health' => 100,
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => 0,
            'dirty_cash' => 0,
        ]);

        CharacterStats::create([
            'character_id' => $character->id,
            'influence' => 50,
            'intelligence' => 1000,
            'offense' => 1000,
            'defense' => 1000,
            'luck' => 1000,
        ]);

        CharacterTimers::create([
            'character_id' => $character->id,
            'next_action_at' => 0,
            'strength' => 100,
        ]);

        return $character->fresh();
    }

    private function makeCeo(City $city): Character
    {
        $ceo = $this->makeCharacter($city);
        $ceo->update(['career_rank' => 4]);
        return $ceo->fresh();
    }

    private function makeCorp(
        Character $ceo,
        string $name = 'MoveCorp',
        int $cashReserves = 1_000_000,
        bool $isHolding = false,
        ?int $parentTrustId = null,
    ): Corporation {
        $corp = Corporation::create([
            'name' => $name . '-' . uniqid(),
            'home_city_id' => $ceo->home_city_id,
            'founder_id' => $ceo->id,
            'ceo_id' => $isHolding ? null : $ceo->id,
            'cash_reserves' => $cashReserves,
            'is_holding_company' => $isHolding,
            'parent_trust_id' => $parentTrustId,
        ]);

        CorporationProperty::createStartingHeadquarters($corp, $isHolding ? 3 : 1);
        if (! $isHolding) {
            $corp->attachMember($ceo);
        }

        return $corp->fresh();
    }

    // ===================== CEO request flow =====================

    public function test_ceo_in_destination_city_files_request_and_escrows_fee(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $reservesBefore = (int) $corp->cash_reserves;

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('success');

        $this->assertSame($reservesBefore - Corporation::MOVE_HQ_FEE,
            (int) $corp->fresh()->cash_reserves,
            'Filing a move request must escrow the fee from cash_reserves');

        $payload = Cache::get(Corporation::moveRequestKey($corp->id));
        $this->assertNotNull($payload);
        $this->assertSame($corp->id, $payload['corporation_id']);
        $this->assertSame($dest->id, $payload['to_city_id']);
        $this->assertSame($home->id, $payload['from_city_id']);
        $this->assertSame(Corporation::MOVE_HQ_FEE, $payload['fee_escrowed']);

        $cityIndex = Cache::get(Corporation::moveRequestsCityKey($dest->id));
        $this->assertIsArray($cityIndex);
        $this->assertArrayHasKey($corp->id, $cityIndex);
    }

    public function test_ceo_in_home_city_cannot_file_request(): void
    {
        $home = $this->makeCity('home');
        $ceo = $this->makeCeo($home);
        $corp = $this->makeCorp($ceo);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'Travel to the destination city before filing a relocation request.');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves);
        $this->assertNull(Cache::get(Corporation::moveRequestKey($corp->id)));
    }

    public function test_holding_company_cannot_file_request(): void
    {
        // Simulates state drift: a phase-1 corp gets flipped to a holding
        // (the only realistic way a CEO ends up here is upgrade-during-flow,
        // since holdings have no CEO at rest).
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());
        $corp->update(['is_holding_company' => true]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'Only standalone phase-1 operating companies can relocate.');
    }

    public function test_subsidiary_cannot_file_request(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');

        // Holding shell to set parent_trust_id against
        $holdingCeo = $this->makeCeo($home);
        $holding = $this->makeCorp($holdingCeo, 'TheHolding', isHolding: true);

        $subCeo = $this->makeCeo($home);
        $subCeo->update(['city_id' => $dest->id]);
        $sub = $this->makeCorp($subCeo->fresh(), 'TheSub', parentTrustId: $holding->id);

        $this->actingAs($subCeo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'Only standalone phase-1 operating companies can relocate.');
    }

    public function test_pending_hq_blocks_request(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        // Drop a PENDING tier-2 HQ row alongside the existing tier-1 CONSTRUCTED.
        CorporationProperty::create([
            'corporation_id' => $corp->id,
            'type' => CorporationProperty::TYPE_HQ,
            'tier' => 2,
            'name' => 'Pending HQ',
            'price' => 2_500_000,
            'condition' => CorporationProperty::CONDITION_PENDING,
            'last_upkeep_at' => now(),
        ]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'Finish your pending construction before relocating.');
    }

    public function test_pending_merger_blocks_request(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');

        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $otherCeo = $this->makeCeo($home);
        $otherCorp = $this->makeCorp($otherCeo, 'OtherCorp');

        CorporationMergerRequest::create([
            'requester_id' => $ceo->id,
            'target_id' => $otherCeo->id,
            'requester_corporation_id' => $corp->id,
            'target_corporation_id' => $otherCorp->id,
            'holding_name' => 'Conglomerate',
            'status' => CorporationMergerRequest::STATUS_PENDING,
        ]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'Resolve your pending merger before relocating.');
    }

    public function test_pending_subsidiary_invitation_blocks_request(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');

        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $holdingCeo = $this->makeCeo($home);
        $holding = $this->makeCorp($holdingCeo, 'TheHolding', isHolding: true);

        CorporationSubsidiaryInvite::create([
            'holding_company_id' => $holding->id,
            'target_corporation_id' => $corp->id,
            'requester_id' => $holdingCeo->id,
            'target_ceo_id' => $ceo->id,
            'status' => CorporationSubsidiaryInvite::STATUS_PENDING,
        ]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'Resolve your pending subsidiary invitation before relocating.');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves);
    }

    public function test_active_investment_fraud_blocks_request(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        // Plant a live fraud cache entry that hasn't expired yet.
        Cache::put(\App\Actions\InvestmentFraud::cacheKey($corp), [
            'expires_at' => now()->addMinutes(10)->timestamp,
            'required_members' => [],
            'accepted_by' => [],
        ], 600);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'Cancel your pending investment fraud action before relocating.');
    }

    public function test_insufficient_reserves_blocks_request(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh(), cashReserves: 50_000);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error');

        $this->assertSame(50_000, (int) $corp->fresh()->cash_reserves);
    }

    public function test_duplicate_request_is_rejected(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('success');

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'You already have a relocation request awaiting customs approval.');

        // Reserves only debited once.
        $this->assertSame(1_000_000 - Corporation::MOVE_HQ_FEE,
            (int) $corp->fresh()->cash_reserves);
    }

    // ===================== CEO cancel flow =====================

    public function test_ceo_can_cancel_pending_request_full_refund(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $this->actingAs($ceo->user)->post(route('career.corporation.move.request'))->assertSessionHas('success');

        $this->actingAs($ceo->user)->post(route('career.corporation.move.cancel'))->assertSessionHas('success');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves,
            'Cancel must refund the full escrowed fee');
        $this->assertNull(Cache::get(Corporation::moveRequestKey($corp->id)));
        $this->assertEmpty(Cache::get(Corporation::moveRequestsCityKey($dest->id), []));
    }

    public function test_cancel_with_no_pending_request_fails_cleanly(): void
    {
        $home = $this->makeCity('home');
        $ceo = $this->makeCeo($home);
        $corp = $this->makeCorp($ceo);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.cancel'))
            ->assertSessionHas('error', 'No active relocation request to cancel.');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves);
    }

    // ===================== Customs approval flow =====================

    private function fileMoveRequest(City $homeCity, City $destCity): array
    {
        $ceo = $this->makeCeo($homeCity);
        $ceo->update(['city_id' => $destCity->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('success');

        return ['ceo' => $ceo->fresh(), 'corp' => $corp->fresh()];
    }

    public function test_customs_rank_2_in_destination_approves_and_flips_home_city(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['corp' => $corp] = $this->fileMoveRequest($home, $dest);

        $agent = $this->makeCharacter($dest, careerCode: 'customs', rank: 2, cashOnHand: 0);

        $this->actingAs($agent->user)
            ->post(route('career.customs.move.approve'), ['corporation_id' => $corp->id])
            ->assertSessionHas('success');

        $this->assertSame($dest->id, (int) $corp->fresh()->home_city_id,
            'Approval must flip corp.home_city_id to the destination city');

        $this->assertSame(Corporation::MOVE_HQ_AGENT_PAYOUT,
            (int) $agent->fresh()->cash_on_hand,
            'Agent must receive MOVE_HQ_AGENT_PAYOUT');

        // The remaining $90k disappears — corp paid the full fee, agent took
        // their cut, the rest is bureaucratic vapor.
        $expectedReserves = 1_000_000 - Corporation::MOVE_HQ_FEE;
        $this->assertSame($expectedReserves, (int) $corp->fresh()->cash_reserves,
            'Approval must NOT refund any portion of the fee back to the corp');

        $this->assertNull(Cache::get(Corporation::moveRequestKey($corp->id)));
        $this->assertEmpty(Cache::get(Corporation::moveRequestsCityKey($dest->id), []));
    }

    public function test_state_drift_pending_subsidiary_invite_at_approval_refunds(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['ceo' => $ceo, 'corp' => $corp] = $this->fileMoveRequest($home, $dest);

        $holdingCeo = $this->makeCeo($home);
        $holding = $this->makeCorp($holdingCeo, 'DriftHolding', isHolding: true);

        CorporationSubsidiaryInvite::create([
            'holding_company_id' => $holding->id,
            'target_corporation_id' => $corp->id,
            'requester_id' => $holdingCeo->id,
            'target_ceo_id' => $ceo->id,
            'status' => CorporationSubsidiaryInvite::STATUS_PENDING,
        ]);

        $agent = $this->makeCharacter($dest, careerCode: 'customs', rank: 2);

        $this->actingAs($agent->user)
            ->post(route('career.customs.move.approve'), ['corporation_id' => $corp->id])
            ->assertSessionHas('error', 'Corporation now has a pending subsidiary invitation. Fee refunded.');

        $this->assertSame($home->id, (int) $corp->fresh()->home_city_id);
        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves);
    }

    public function test_state_drift_active_fraud_at_approval_refunds(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['corp' => $corp] = $this->fileMoveRequest($home, $dest);

        Cache::put(\App\Actions\InvestmentFraud::cacheKey($corp), [
            'expires_at' => now()->addMinutes(10)->timestamp,
            'required_members' => [],
            'accepted_by' => [],
        ], 600);

        $agent = $this->makeCharacter($dest, careerCode: 'customs', rank: 2);

        $this->actingAs($agent->user)
            ->post(route('career.customs.move.approve'), ['corporation_id' => $corp->id])
            ->assertSessionHas('error', 'Corporation has a pending investment fraud action. Fee refunded.');

        $this->assertSame($home->id, (int) $corp->fresh()->home_city_id);
        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves);
    }

    public function test_customs_rank_1_cannot_approve(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['corp' => $corp] = $this->fileMoveRequest($home, $dest);

        $agent = $this->makeCharacter($dest, careerCode: 'customs', rank: 1, cashOnHand: 0);

        $this->actingAs($agent->user)
            ->post(route('career.customs.move.approve'), ['corporation_id' => $corp->id])
            ->assertSessionHas('error', 'You need at least rank 2 to process corporate relocation requests.');

        $this->assertSame($home->id, (int) $corp->fresh()->home_city_id);
        $this->assertNotNull(Cache::get(Corporation::moveRequestKey($corp->id)));
        $this->assertSame(0, (int) $agent->fresh()->cash_on_hand);
    }

    public function test_customs_in_wrong_city_cannot_approve(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $someOther = $this->makeCity('other');
        ['corp' => $corp] = $this->fileMoveRequest($home, $dest);

        $agent = $this->makeCharacter($someOther, careerCode: 'customs', rank: 2);

        $this->actingAs($agent->user)
            ->post(route('career.customs.move.approve'), ['corporation_id' => $corp->id])
            ->assertSessionHas('error', 'You can only review relocations to your home city.');

        $this->assertSame($home->id, (int) $corp->fresh()->home_city_id);
    }

    public function test_customs_deny_refunds_full_fee_still_pays_agent(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['corp' => $corp] = $this->fileMoveRequest($home, $dest);

        $agent = $this->makeCharacter($dest, careerCode: 'customs', rank: 2, cashOnHand: 0);

        $this->actingAs($agent->user)
            ->post(route('career.customs.move.deny'), ['corporation_id' => $corp->id])
            ->assertSessionHas('success');

        $this->assertSame($home->id, (int) $corp->fresh()->home_city_id,
            'Denial must NOT change home_city_id');
        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves,
            'Denial must refund the full escrowed fee');
        $this->assertSame(Corporation::MOVE_HQ_AGENT_PAYOUT,
            (int) $agent->fresh()->cash_on_hand,
            'Agent still gets paid for processing time on a denial');
        $this->assertNull(Cache::get(Corporation::moveRequestKey($corp->id)));
    }

    public function test_state_drift_pending_merger_at_approval_refunds(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['ceo' => $ceo, 'corp' => $corp] = $this->fileMoveRequest($home, $dest);

        // Drift: another corp files a merger against this corp after the move
        // request was already filed.
        $otherCeo = $this->makeCeo($home);
        $otherCorp = $this->makeCorp($otherCeo, 'OtherCorp');

        CorporationMergerRequest::create([
            'requester_id' => $otherCeo->id,
            'target_id' => $ceo->id,
            'requester_corporation_id' => $otherCorp->id,
            'target_corporation_id' => $corp->id,
            'holding_name' => 'DriftHold',
            'status' => CorporationMergerRequest::STATUS_PENDING,
        ]);

        $agent = $this->makeCharacter($dest, careerCode: 'customs', rank: 2);

        $this->actingAs($agent->user)
            ->post(route('career.customs.move.approve'), ['corporation_id' => $corp->id])
            ->assertSessionHas('error', 'Corporation now has a pending merger. Fee refunded.');

        $this->assertSame($home->id, (int) $corp->fresh()->home_city_id);
        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves,
            'State-drift rejection must refund the full fee');
    }

    public function test_state_drift_pending_hq_at_approval_refunds(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['corp' => $corp] = $this->fileMoveRequest($home, $dest);

        // Drift: a PENDING HQ shows up after the request was filed.
        CorporationProperty::create([
            'corporation_id' => $corp->id,
            'type' => CorporationProperty::TYPE_HQ,
            'tier' => 2,
            'name' => 'Pending HQ',
            'price' => 2_500_000,
            'condition' => CorporationProperty::CONDITION_PENDING,
            'last_upkeep_at' => now(),
        ]);

        $agent = $this->makeCharacter($dest, careerCode: 'customs', rank: 2);

        $this->actingAs($agent->user)
            ->post(route('career.customs.move.approve'), ['corporation_id' => $corp->id])
            ->assertSessionHas('error', 'Corporation has unfinished construction. Fee refunded.');

        $this->assertSame($home->id, (int) $corp->fresh()->home_city_id);
        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves);
    }

    public function test_evicted_request_returns_clean_error_to_customs(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['corp' => $corp] = $this->fileMoveRequest($home, $dest);

        // Simulate a Redis eviction between request and approval.
        Cache::forget(Corporation::moveRequestKey($corp->id));

        $agent = $this->makeCharacter($dest, careerCode: 'customs', rank: 2);

        $this->actingAs($agent->user)
            ->post(route('career.customs.move.approve'), ['corporation_id' => $corp->id])
            ->assertSessionHas('error', 'That relocation request has expired or no longer exists.');
    }

    // ===================== Cheese / edge cases =====================

    public function test_successful_request_sets_two_hour_action_cooldown(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('success');

        $ceo->refresh()->loadMissing('timers');
        $nextAction = $ceo->timers?->next_action_at;

        $this->assertNotNull($nextAction);
        $this->assertGreaterThanOrEqual(now()->addHours(2)->subMinutes(1), $nextAction,
            'Action timer must land ~2 hours in the future');
        $this->assertLessThanOrEqual(now()->addHours(2)->addMinutes(1), $nextAction);
    }

    public function test_ceo_on_existing_action_cooldown_cannot_file_request(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $ceo->fresh()->timers()->update([
            'next_action_at' => now()->addMinutes(30)->getTimestamp(),
        ]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'You need to wait before performing another action.');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves,
            'Cooldown rejection must not debit reserves');
        $this->assertNull(Cache::get(Corporation::moveRequestKey($corp->id)));
    }

    public function test_cancel_does_not_reset_cooldown_so_immediate_refile_blocked(): void
    {
        // The classic cheese: file → cancel → file again to bypass the 2hr
        // throttle. The cooldown must outlive a cancel.
        $home = $this->makeCity('home');
        $dest1 = $this->makeCity('dest1');
        $dest2 = $this->makeCity('dest2');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest1->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('success');

        // Cancel — gets fee back, but timer should remain.
        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.cancel'))
            ->assertSessionHas('success');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves,
            'Cancel must refund the fee');

        // Travel to a different destination and immediately re-file.
        $ceo->fresh()->update(['city_id' => $dest2->id]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'You need to wait before performing another action.');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves,
            'Re-file during cooldown must not double-debit');
    }

    public function test_dead_ceo_cannot_file_request(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id, 'health' => 0, 'deleted_at' => now()->subMinutes(2)]);
        $corp = $this->makeCorp($ceo->fresh());

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves);
    }

    public function test_hospitalized_ceo_cannot_file_request(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $ceo->fresh()->timers()->update([
            'hospital_until' => now()->addMinutes(30)->getTimestamp(),
        ]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves);
    }

    public function test_jailed_ceo_cannot_file_request(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $ceo->fresh()->timers()->update([
            'jail_until' => now()->addMinutes(30)->getTimestamp(),
        ]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error');

        $this->assertSame(1_000_000, (int) $corp->fresh()->cash_reserves);
    }

    public function test_non_ceo_member_cannot_cancel(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['ceo' => $ceo, 'corp' => $corp] = $this->fileMoveRequest($home, $dest);

        // Attach a regular member.
        $member = $this->makeCharacter($home);
        $corp->attachMember($member);

        $this->actingAs($member->fresh()->user)
            ->post(route('career.corporation.move.cancel'))
            ->assertSessionHas('error', 'Only the CEO can cancel a relocation request.');

        // Request must still be live.
        $this->assertNotNull(Cache::get(Corporation::moveRequestKey($corp->id)));
        $this->assertSame(1_000_000 - Corporation::MOVE_HQ_FEE,
            (int) $corp->fresh()->cash_reserves,
            'Reserves must remain in escrow when a non-CEO tries to cancel');
    }

    public function test_dissolve_cleans_pending_move_cache(): void
    {
        // Cheese: CEO files move, then dissolves the corp before customs
        // can act. Cache must not linger and pollute the customs queue.
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        $ceo = $this->makeCeo($home);
        $ceo->update(['city_id' => $dest->id]);
        $corp = $this->makeCorp($ceo->fresh());

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('success');

        $this->assertNotNull(Cache::get(Corporation::moveRequestKey($corp->id)));
        $cityIndex = Cache::get(Corporation::moveRequestsCityKey($dest->id), []);
        $this->assertArrayHasKey($corp->id, $cityIndex);

        // Travel back home to satisfy dissolve's CEO-presence implicit
        // requirement. Bump the timer past now() so dissolve can run after
        // the 2hr move cooldown was set.
        $ceo->fresh()->update(['city_id' => $home->id]);
        $ceo->fresh()->timers()->update(['next_action_at' => 0]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.dissolve'))
            ->assertSessionHas('success');

        $this->assertNull(Cache::get(Corporation::moveRequestKey($corp->id)),
            'Dissolve must clear the per-corp move cache key');
        $this->assertEmpty(Cache::get(Corporation::moveRequestsCityKey($dest->id), []),
            'Dissolve must remove the corp from the destination city index');
    }

    public function test_request_already_in_destination_city_is_rejected_after_approve(): void
    {
        // Cheese: approve a move, immediately file another with the same
        // CEO still standing in the (now-current) home city → must be
        // blocked by the same-city gate, not by anything else.
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['ceo' => $ceo, 'corp' => $corp] = $this->fileMoveRequest($home, $dest);

        $agent = $this->makeCharacter($dest, careerCode: 'customs', rank: 2);
        $this->actingAs($agent->user)
            ->post(route('career.customs.move.approve'), ['corporation_id' => $corp->id])
            ->assertSessionHas('success');

        $this->assertSame($dest->id, (int) $corp->fresh()->home_city_id);

        // Wait out the cooldown so we're not testing the wrong gate.
        $ceo->fresh()->timers()->update(['next_action_at' => 0]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.move.request'))
            ->assertSessionHas('error', 'Travel to the destination city before filing a relocation request.');
    }

    public function test_customs_with_pending_action_cooldown_cannot_decide(): void
    {
        $home = $this->makeCity('home');
        $dest = $this->makeCity('dest');
        ['corp' => $corp] = $this->fileMoveRequest($home, $dest);

        $agent = $this->makeCharacter($dest, careerCode: 'customs', rank: 2);
        $agent->fresh()->timers()->update([
            'next_action_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        $this->actingAs($agent->user)
            ->post(route('career.customs.move.approve'), ['corporation_id' => $corp->id])
            ->assertSessionHas('error', 'You need to wait before performing another action.');

        $this->assertSame($home->id, (int) $corp->fresh()->home_city_id);
        $this->assertNotNull(Cache::get(Corporation::moveRequestKey($corp->id)));
    }
}
