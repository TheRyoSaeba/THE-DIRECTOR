<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\CrimeRecord;
use App\Models\User;
use App\Services\OrganizedHitService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class OrganizedHitTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;
    private int  $unemployedCareerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->city = City::create([
            'name'       => 'HitCity-' . uniqid(),
            'slug'       => 'hitcity-' . uniqid(),
            'crime_rate' => 0.0,
        ]);

        $unemployed = DB::table('careers')->whereRaw("LOWER(code) = 'unemployed'")->first();
        $this->assertNotNull($unemployed, 'Unemployed career must exist in DB');
        $this->unemployedCareerId = $unemployed->id;
    }

    

    private function makeCharacter(
        bool  $online     = true,
        ?City $inCity     = null,
        int   $health     = 100,
        int   $offense    = 5000,
        int   $defense    = 500,
        int   $intelligence = 3000,
        int   $luck       = 3000,
    ): Character {
        $user = User::factory()->create();

        if ($online) {
            DB::table('sessions')->insert([
                'id'            => uniqid('sess_', true),
                'user_id'       => $user->id,
                'ip_address'    => '127.0.0.1',
                'user_agent'    => 'phpunit',
                'payload'       => '',
                'last_activity' => time(),
            ]);
        }

        $char = Character::create([
            'user_id'               => $user->id,
            'display_name'          => 'Hit-' . uniqid(),
            'gender'                => 'male',
            'city_id'               => ($inCity ?? $this->city)->id,
            'home_city_id'          => ($inCity ?? $this->city)->id,
            'career_id'             => $this->unemployedCareerId,
            'career_rank'           => 1,
            'health'                => $health,
            'max_health'            => 100,
            'cash_on_hand'          => 100_000,
            'dirty_cash'            => 10_000,
            'total_character_exp'   => 50_000,
        ]);

        
        DB::table('characters')->where('id', $char->id)->update([
            'created_at' => now()->subDays(10),
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => $intelligence,
            'luck'         => $luck,
            'offense'      => $offense,
            'defense'      => $defense,
            'influence'    => 10,
        ]);

        CharacterTimers::create([
            'character_id' => $char->id,
            'next_action_at' => 0,
        ]);

        return $char;
    }

    
    private function initiate(
        Character $initiator,
        Character $target,
        Character $acc0,
        Character $acc1,
    ): void {
        $error = OrganizedHitService::initiate($initiator, $target, [$acc0->id, $acc1->id]);
        $this->assertNull($error, "initiate() should succeed but returned: {$error}");
    }

    
    private function forceState(int $initiatorId, array $overrides = []): void
    {
        $base = [
            'initiator_id'       => $initiatorId,
            'target_id'          => 999,
            'target_name'        => 'Target',
            'member_ids'         => [$initiatorId, $initiatorId + 1, $initiatorId + 2],
            'member_names'       => [$initiatorId => 'A', $initiatorId + 1 => 'B', $initiatorId + 2 => 'C'],
            'accepted_by'        => [$initiatorId],
            'is_ready'           => false,
            'expires_at'         => now()->addMinutes(15)->timestamp,
            'execute_expires_at' => null,
        ];
        Cache::put(OrganizedHitService::cacheKey($initiatorId), array_merge($base, $overrides), 900);

        // Mirror what initiate() does: register in the active set so findOpForMember
        // can locate this op. Without this, forced states are invisible to the new
        // single-source-of-truth scan.
        $active = Cache::get('org_hits_active', []);
        Cache::put('org_hits_active', array_values(array_unique([...$active, $initiatorId])), 86400);
    }

    private function getState(Character $character): ?array
    {
        return OrganizedHitService::getState($character->id);
    }

    

    public function test_initiate_blocked_when_initiator_already_has_active_op(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        
        $this->initiate($initiator, $target, $acc0, $acc1);

        
        $acc2 = $this->makeCharacter();
        $acc3 = $this->makeCharacter();
        $error = OrganizedHitService::initiate($initiator, $target, [$acc2->id, $acc3->id]);
        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('active', $error);
    }

    public function test_initiate_blocked_when_fewer_than_two_accomplices(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();

        $error = OrganizedHitService::initiate($initiator, $target, [$acc0->id]);
        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('2', $error);
    }

    public function test_initiate_blocked_when_target_is_an_accomplice(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();

        $error = OrganizedHitService::initiate($initiator, $target, [$target->id, $acc0->id]);
        $this->assertNotNull($error);
    }

    public function test_initiate_blocked_when_initiator_is_in_accomplice_list(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();

        $error = OrganizedHitService::initiate($initiator, $target, [$initiator->id, $acc0->id]);
        $this->assertNotNull($error);
    }

    public function test_initiate_blocked_when_accomplice_in_wrong_city(): void
    {
        $otherCity = City::create([
            'name' => 'OtherCity-' . uniqid(), 'slug' => 'other-' . uniqid(), 'crime_rate' => 0,
        ]);
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter(inCity: $otherCity); 
        $acc1      = $this->makeCharacter();

        $error = OrganizedHitService::initiate($initiator, $target, [$acc0->id, $acc1->id]);
        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('city', $error);
    }

    public function test_initiate_blocked_when_accomplice_is_dead(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $acc0->kill('test', 'test');

        $error = OrganizedHitService::initiate($initiator, $target, [$acc0->id, $acc1->id]);
        $this->assertNotNull($error);
    }

    

    public function test_initiate_creates_redis_state_with_correct_shape(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $state = $this->getState($initiator);

        $this->assertNotNull($state);
        $this->assertSame($initiator->id, $state['initiator_id']);
        $this->assertSame($target->id,    $state['target_id']);
        $this->assertSame($target->display_name, $state['target_name']);
        $this->assertCount(3, $state['member_ids']);
        $this->assertContains($initiator->id, $state['member_ids']);
        $this->assertContains($acc0->id,      $state['member_ids']);
        $this->assertContains($acc1->id,      $state['member_ids']);
        $this->assertSame([$initiator->id], $state['accepted_by']); 
        $this->assertFalse($state['is_ready']);
        $this->assertNull($state['execute_expires_at']);
        $this->assertGreaterThan(now()->timestamp, $state['expires_at']);

        $this->assertContains(
            $initiator->id,
            Cache::get('org_hits_active', []),
            'Initiator must be registered in the active set'
        );
    }

    public function test_initiate_sends_invite_journals_only_to_accomplices(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        
        $this->assertTrue(
            CharacterJournal::where('character_id', $acc0->id)->where('type', 'organized_hit_invite')->exists()
        );
        $this->assertTrue(
            CharacterJournal::where('character_id', $acc1->id)->where('type', 'organized_hit_invite')->exists()
        );

        
        $this->assertFalse(
            CharacterJournal::where('character_id', $initiator->id)->where('type', 'organized_hit_invite')->exists()
        );
        $this->assertFalse(
            CharacterJournal::where('character_id', $target->id)->where('type', 'organized_hit_invite')->exists()
        );
    }

    public function test_initiate_makes_op_discoverable_for_every_member(): void
    {
        // Replaces the old reverse-pointer assertion. With the per-character
        // org_hit_invite_* keys removed, the active set + findOpForMember scan
        // is the single source of truth that lets controller code locate the
        // op for the initiator and either accomplice.
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $this->assertNotNull(OrganizedHitService::findOpForMember($initiator->id));
        $this->assertNotNull(OrganizedHitService::findOpForMember($acc0->id));
        $this->assertNotNull(OrganizedHitService::findOpForMember($acc1->id));
    }

    public function test_initiate_invite_journal_contains_member_names(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $journal = CharacterJournal::where('character_id', $acc0->id)
            ->where('type', 'organized_hit_invite')
            ->first();

        $this->assertNotNull($journal);
        $this->assertArrayHasKey('member_names', $journal->data);
        $this->assertArrayHasKey('initiator_id', $journal->data);
        $this->assertSame($initiator->id, $journal->data['initiator_id']);
    }

    

    public function test_accept_fails_when_op_does_not_exist(): void
    {
        $accomplice = $this->makeCharacter();
        $error = OrganizedHitService::accept($accomplice, 99999);
        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('no longer exists', $error);
    }

    public function test_accept_fails_when_character_not_in_member_list(): void
    {
        $initiator  = $this->makeCharacter();
        $target     = $this->makeCharacter();
        $acc0       = $this->makeCharacter();
        $acc1       = $this->makeCharacter();
        $outsider   = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $error = OrganizedHitService::accept($outsider, $initiator->id);
        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('not invited', $error);
    }

    public function test_accept_fails_when_already_accepted(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);

        $error = OrganizedHitService::accept($acc0, $initiator->id);
        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('already', $error);
    }

    public function test_accept_fails_when_accomplice_committed_to_another_op(): void
    {
        // In the new contract, initiate() blocks an accomplice from being added
        // to a second op at the API level — so the only way an accomplice ends
        // up committed elsewhere at the moment of accept() is via state corruption
        // or a race. We exercise that defence directly by force-injecting a
        // second op where acc0 is already accepted.
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $stranger = $this->makeCharacter();
        $other    = $this->makeCharacter();

        $this->forceState($stranger->id, [
            'member_ids'   => [$stranger->id, $acc0->id, $other->id],
            'member_names' => [$stranger->id => 'S', $acc0->id => 'A0', $other->id => 'O'],
            'accepted_by'  => [$stranger->id, $acc0->id], // acc0 committed elsewhere
        ]);

        $error = OrganizedHitService::accept($acc0, $initiator->id);
        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('committed', $error);
    }

    public function test_accept_fails_when_op_has_expired(): void
    {
        $initiator = $this->makeCharacter();

        $this->forceState($initiator->id, [
            'expires_at' => now()->subMinutes(1)->timestamp, 
        ]);

        $accomplice = $this->makeCharacter();
        $error = OrganizedHitService::accept($accomplice, $initiator->id);
        $this->assertNotNull($error);
        
        $this->assertNull($this->getState($initiator));
    }

    

    public function test_accept_updates_accepted_by_list(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        $error = OrganizedHitService::accept($acc0, $initiator->id);

        $this->assertNull($error);
        $state = $this->getState($initiator);
        $this->assertContains($acc0->id, $state['accepted_by']);
        $this->assertFalse($state['is_ready']); 
    }

    public function test_accept_deletes_invite_journal_for_accepting_member(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);

        $this->assertFalse(
            CharacterJournal::where('character_id', $acc0->id)
                ->where('type', 'organized_hit_invite')
                ->exists()
        );

        
        $this->assertTrue(
            CharacterJournal::where('character_id', $acc1->id)
                ->where('type', 'organized_hit_invite')
                ->exists()
        );
    }

    public function test_accept_notifies_initiator_via_journal(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);

        $this->assertTrue(
            CharacterJournal::where('character_id', $initiator->id)
                ->where('type', 'organized_hit_accepted')
                ->exists()
        );
    }

    

    public function test_all_members_accepting_sets_is_ready_and_execute_window(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $state = $this->getState($initiator);
        $this->assertTrue($state['is_ready']);
        $this->assertNotNull($state['execute_expires_at']);
        $this->assertGreaterThan(now()->timestamp, $state['execute_expires_at']);
    }

    public function test_accepted_accomplice_journal_marks_all_ready_when_last_member(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $lastAcceptedJournal = CharacterJournal::where('character_id', $initiator->id)
            ->where('type', 'organized_hit_accepted')
            ->orderByDesc('id')
            ->first();

        $this->assertTrue((bool) ($lastAcceptedJournal->data['all_ready'] ?? false));
    }

    

    public function test_decline_collapses_the_operation(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::decline($acc0, $initiator->id);

        $this->assertNull($this->getState($initiator));
        $this->assertNotContains(
            $initiator->id,
            Cache::get('org_hits_active', []),
            'Active set must be cleaned up on decline-collapse'
        );
    }

    public function test_decline_notifies_initiator(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::decline($acc0, $initiator->id);

        $this->assertTrue(
            CharacterJournal::where('character_id', $initiator->id)
                ->where('type', 'organized_hit_declined')
                ->exists()
        );
    }

    public function test_decline_deletes_both_accomplice_invite_journals(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::decline($acc0, $initiator->id);

        
        $this->assertFalse(
            CharacterJournal::where('character_id', $acc0->id)->where('type', 'organized_hit_invite')->exists()
        );
        
        $this->assertFalse(
            CharacterJournal::where('character_id', $acc1->id)->where('type', 'organized_hit_invite')->exists()
        );
    }

    public function test_decline_sends_collapsed_journal_to_all_members(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::decline($acc0, $initiator->id);

        foreach ([$initiator, $acc0, $acc1] as $member) {
            $this->assertTrue(
                CharacterJournal::where('character_id', $member->id)
                    ->where('type', 'organized_hit_collapsed')
                    ->exists(),
                "Member {$member->display_name} should get a collapsed journal"
            );
        }
    }

    public function test_decline_does_not_apply_cooldown_to_non_accepted_members(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        
        OrganizedHitService::decline($acc0, $initiator->id);

        $acc1->refresh();
        $acc1->unsetRelation('timers');

        // acc1 never accepted, so they must not be penalised.
        $this->assertTrue(
            $acc1->timers->next_conflict_at === null
                || $acc1->timers->next_conflict_at->lessThanOrEqualTo(now()),
            'Non-accepted member must not be on combat cooldown'
        );
    }

    public function test_decline_after_accept_applies_cooldown_to_accepted_members(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);

        
        OrganizedHitService::decline($acc1, $initiator->id);

        $acc0->refresh();
        $acc0->unsetRelation('timers');
        $this->assertNotNull($acc0->timers->next_conflict_at, 'next_conflict_at must be set');
        $this->assertTrue(
            $acc0->timers->next_conflict_at->greaterThan(now()),
            'acc0 accepted and should receive a combat cooldown on collapse'
        );
    }

    public function test_decline_on_nonexistent_op_is_silent(): void
    {
        $accomplice = $this->makeCharacter();
        
        OrganizedHitService::decline($accomplice, 99999);
        $this->assertTrue(true);
    }

    

    public function test_cancel_removes_redis_state(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::cancel($initiator);

        $this->assertNull($this->getState($initiator));
        $this->assertNotContains(
            $initiator->id,
            Cache::get('org_hits_active', []),
            'Active set must be cleaned up on cancel'
        );
    }

    public function test_cancel_does_not_apply_cooldowns(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::cancel($initiator);

        
        $acc0->refresh();
        $acc0->unsetRelation('timers');
        $this->assertTrue(
            $acc0->timers->next_conflict_at === null
                || $acc0->timers->next_conflict_at->lessThanOrEqualTo(now()),
            'Cancel should NOT apply combat cooldown to any member'
        );
    }

    public function test_cancel_sends_collapsed_journal_to_all_members(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::cancel($initiator);

        foreach ([$initiator, $acc0, $acc1] as $member) {
            $this->assertTrue(
                CharacterJournal::where('character_id', $member->id)
                    ->where('type', 'organized_hit_collapsed')
                    ->exists()
            );
        }
    }

    public function test_cancel_on_nonexistent_op_is_silent(): void
    {
        $initiator = $this->makeCharacter();
        OrganizedHitService::cancel($initiator);
        $this->assertTrue(true);
    }

    

    public function test_expired_op_collapses_on_next_getstate_call(): void
    {
        $initiator = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->forceState($initiator->id, [
            'member_ids'  => [$initiator->id, $acc0->id, $acc1->id],
            'member_names'=> [$initiator->id => 'Init', $acc0->id => 'A', $acc1->id => 'B'],
            'accepted_by' => [$initiator->id],
            'expires_at'  => now()->subSeconds(1)->timestamp,
        ]);

        $state = OrganizedHitService::getState($initiator->id);

        $this->assertNull($state, 'getState should return null for expired ops');
        $this->assertNull(Cache::get(OrganizedHitService::cacheKey($initiator->id)));
    }

    public function test_expired_op_fires_collapsed_journals(): void
    {
        $initiator = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->forceState($initiator->id, [
            'member_ids'  => [$initiator->id, $acc0->id, $acc1->id],
            'member_names'=> [$initiator->id => 'Init', $acc0->id => 'A', $acc1->id => 'B'],
            'accepted_by' => [$initiator->id],
            'expires_at'  => now()->subSeconds(1)->timestamp,
        ]);

        OrganizedHitService::getState($initiator->id);

        foreach ([$initiator, $acc0, $acc1] as $member) {
            $this->assertTrue(
                CharacterJournal::where('character_id', $member->id)
                    ->where('type', 'organized_hit_collapsed')
                    ->exists()
            );
        }
    }

    public function test_execute_window_expiry_collapses_op_on_execute(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        
        $state = $this->getState($initiator);
        $state['execute_expires_at'] = now()->subSeconds(1)->timestamp;
        Cache::put(OrganizedHitService::cacheKey($initiator->id), $state, 3600);

        $response = $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'));

        $response->assertSessionHas('error');
        $this->assertStringContainsStringIgnoringCase('expired', $response->getSession()->get('error'));
        $this->assertNull($this->getState($initiator));
    }

    

    public function test_execute_blocked_when_no_active_op(): void
    {
        $initiator = $this->makeCharacter();

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'))
            ->assertSessionHas('error');
    }

    public function test_execute_blocked_when_op_not_ready(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        
        OrganizedHitService::accept($acc0, $initiator->id);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'))
            ->assertSessionHas('error');
    }

    public function test_execute_blocked_when_member_is_hospitalized(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        
        DB::table('character_timers')
            ->where('character_id', $acc0->id)
            ->update(['hospital_until' => now()->addHours(2)->getTimestamp()]);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'))
            ->assertSessionHas('error');

        
        $this->assertNull($this->getState($initiator));
    }

    public function test_execute_blocked_when_member_moved_to_different_city(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $otherCity = City::create(['name' => 'Gone-' . uniqid(), 'slug' => 'gone-' . uniqid(), 'crime_rate' => 0]);
        $acc0->update(['city_id' => $otherCity->id]);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'))
            ->assertSessionHas('error');

        $this->assertNull($this->getState($initiator));
    }

    

    public function test_execute_damage_reduces_target_health(): void
    {
        $initiator = $this->makeCharacter(offense: 9000, intelligence: 8000);
        $target    = $this->makeCharacter(health: 100);
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        
        $target->update(['health' => 100, 'max_health' => 100]);

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        
        $target->stats()->update(['defense' => 99999, 'luck' => 99999]);
        $target->update(['health' => 100, 'max_health' => 100]);

        $response = $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'));

        
        $response->assertRedirect(route('conflict.results'));

        $target->refresh();
        $this->assertLessThan(100, $target->health);
    }

    public function test_execute_damage_applies_doubled_protection_to_target(): void
    {
        $initiator = $this->makeCharacter(offense: 9000);
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $target->stats()->update(['defense' => 99999]);
        $target->update(['health' => 100, 'max_health' => 100]);

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'));

        $target->refresh();
        $target->unsetRelation('timers');

        $normalMax = config('timers.protection_max');
        $this->assertNotNull($target->timers->protection_until);
        $this->assertGreaterThanOrEqual(
            $normalMax,
            $target->timers->protection_until->getTimestamp() - now()->timestamp,
            'Target protection should be at least the normal max (doubled)'
        );
    }

    public function test_execute_applies_doubled_conflict_cooldown_to_all_members(): void
    {
        $initiator = $this->makeCharacter(offense: 9000);
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $target->stats()->update(['defense' => 99999]);
        $target->update(['health' => 100, 'max_health' => 100]);

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'));

        $normalCooldown = config('timers.conflict');
        foreach ([$initiator, $acc0, $acc1] as $member) {
            $member->refresh();
            $member->unsetRelation('timers');
            $this->assertNotNull(
                $member->timers->next_conflict_at,
                "{$member->display_name} must have next_conflict_at set"
            );
            $remaining = $member->timers->next_conflict_at->getTimestamp() - now()->timestamp;
            $this->assertGreaterThanOrEqual(
                $normalCooldown,
                $remaining,
                "{$member->display_name} should have doubled cooldown"
            );
        }
    }

    public function test_execute_clears_redis_state_on_success(): void
    {
        $initiator = $this->makeCharacter(offense: 9000);
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $target->stats()->update(['defense' => 99999]);
        $target->update(['health' => 100, 'max_health' => 100]);

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'));

        $this->assertNull($this->getState($initiator));

        // Regression: previous implementation forgot to remove the initiator from
        // the active set on a successful execute, leaking dead entries until the
        // 24h TTL expired.
        $this->assertNotContains(
            $initiator->id,
            Cache::get('org_hits_active', []),
            'Active set must be cleaned up on successful execute'
        );
    }

    public function test_execute_damage_does_not_write_attack_received_to_victim(): void
    {
        $initiator = $this->makeCharacter(offense: 9000);
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $target->stats()->update(['defense' => 99999]);
        $target->update(['health' => 100, 'max_health' => 100]);

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'));

        $this->assertFalse(
            CharacterJournal::where('character_id', $target->id)
                ->where('type', 'attack_received')
                ->exists(),
            'Victim must NOT get an attack_received journal — only organized_attack_received'
        );

        $this->assertTrue(
            CharacterJournal::where('character_id', $target->id)
                ->where('type', 'organized_attack_received')
                ->exists()
        );
    }

    public function test_execute_damage_sends_result_journal_to_accomplices_not_initiator(): void
    {
        $initiator = $this->makeCharacter(offense: 9000);
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $target->stats()->update(['defense' => 99999]);
        $target->update(['health' => 100, 'max_health' => 100]);

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'));

        
        $this->assertTrue(
            CharacterJournal::where('character_id', $acc0->id)->where('type', 'organized_hit_result')->exists()
        );
        $this->assertTrue(
            CharacterJournal::where('character_id', $acc1->id)->where('type', 'organized_hit_result')->exists()
        );

        
        $this->assertFalse(
            CharacterJournal::where('character_id', $initiator->id)->where('type', 'organized_hit_result')->exists()
        );
    }

    

    public function test_execute_kill_creates_organized_hit_crime_record_with_participants(): void
    {
        $initiator = $this->makeCharacter(offense: 99999, intelligence: 99999);
        $target    = $this->makeCharacter(health: 1, defense: 1);
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $target->update(['health' => 1, 'max_health' => 100]);
        $target->stats()->update(['defense' => 1, 'luck' => 1]);

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'))
            ->assertRedirect(route('conflict.results'));

        $target->refresh();
        $this->assertNotNull($target->deleted_at, 'Target must be killed (soft deleted)');

        // Group crime: organized_hit with all accomplices listed as participants.
        // Initiator is the lead (character_id), not in participants.
        $crime = CrimeRecord::where('character_id', $initiator->id)
            ->where('type', CrimeRecord::TYPE_ORGANIZED_HIT)
            ->first();

        $this->assertNotNull($crime, 'An organized_hit crime record must be created');
        $this->assertSame(CrimeRecord::SEV_CAPITAL, $crime->severity);
        $this->assertSame($target->id, (int) $crime->data['victim_id']);
        $this->assertEqualsCanonicalizing(
            [$acc0->id, $acc1->id],
            array_map('intval', $crime->data['participants'] ?? []),
            'Both accomplices must appear in data.participants'
        );
        $this->assertNotContains(
            $initiator->id,
            array_map('intval', $crime->data['participants'] ?? []),
            'The lead is recorded via character_id, never duplicated in participants'
        );

        // No legacy 'murder' crime should be created for this kill.
        $this->assertFalse(
            CrimeRecord::where('character_id', $initiator->id)
                ->where('type', CrimeRecord::TYPE_MURDER)
                ->exists(),
            'Organized hits must not produce a plain murder crime record'
        );
    }

    public function test_execute_kill_makes_accomplices_isConflicted(): void
    {
        // Accomplices show up in data.participants, so CrimeRecord::isConflicted
        // recognises them — prevents them from later prosecuting/judging the
        // case they took part in.
        $initiator = $this->makeCharacter(offense: 99999, intelligence: 99999);
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $target->update(['health' => 1]);
        $target->stats()->update(['defense' => 1, 'luck' => 1]);

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.execute'));

        $crime = CrimeRecord::where('character_id', $initiator->id)
            ->where('type', CrimeRecord::TYPE_ORGANIZED_HIT)
            ->firstOrFail();

        $this->assertTrue($crime->isConflicted($initiator), 'Initiator is conflicted');
        $this->assertTrue($crime->isConflicted($acc0),      'acc0 is conflicted');
        $this->assertTrue($crime->isConflicted($acc1),      'acc1 is conflicted');
    }

    

    public function test_accepted_accomplice_appears_in_organizedhit_props_via_controller(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);

        
        $response = $this->actingAs($acc0->user)
            ->get(route('conflict'));

        $response->assertOk();
        $props = $response->viewData('page')['props'] ?? [];
        $organizedHit = $props['organizedHit'] ?? null;

        $this->assertNotNull($organizedHit, 'Accepted accomplice should see the op on the conflict page');
        $this->assertFalse($organizedHit['is_initiator'] ?? true, 'is_initiator should be false for accomplice');
        $this->assertContains($acc0->id, $organizedHit['accepted_by']);
    }

    

    public function test_pending_invite_still_shown_after_journal_is_deleted(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        
        CharacterJournal::where('character_id', $acc0->id)
            ->where('type', 'organized_hit_invite')
            ->delete();

        
        $response = $this->actingAs($acc0->user)
            ->get(route('conflict'));

        $response->assertOk();
        $props       = $response->viewData('page')['props'] ?? [];
        $pendingInvite = $props['pendingInvite'] ?? null;

        $this->assertNotNull($pendingInvite, 'Invite should still show after journal deletion (controller resolves via active set, not the journal row)');
        $this->assertSame($initiator->id, $pendingInvite['initiator_id']);
    }

    

    public function test_initiate_blocked_when_accomplice_already_in_another_op(): void
    {
        $initiator1 = $this->makeCharacter();
        $initiator2 = $this->makeCharacter();
        $target1    = $this->makeCharacter();
        $target2    = $this->makeCharacter();
        $acc0       = $this->makeCharacter();
        $acc1       = $this->makeCharacter();
        $acc2       = $this->makeCharacter();

        $this->initiate($initiator1, $target1, $acc0, $acc1);

        // initiator2 tries to draft acc0 into a second op — must be rejected.
        $error = OrganizedHitService::initiate($initiator2, $target2, [$acc0->id, $acc2->id]);

        $this->assertNotNull($error);
        $this->assertStringContainsStringIgnoringCase('another operation', $error);
    }

    

    public function test_all_invite_journals_cleaned_up_on_collapse(): void
    {
        // Renamed from "all invite types" — the previous version asserted on a
        // legacy 'organized_hit_request' type that was never produced by the
        // service nor cleaned up. The actual contract: collapse() removes any
        // stale 'organized_hit_invite' rows for every member.
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        OrganizedHitService::cancel($initiator);

        // Both accomplices' invite rows must be gone.
        $this->assertFalse(
            CharacterJournal::where('type', 'organized_hit_invite')
                ->whereIn('character_id', [$acc0->id, $acc1->id])
                ->whereJsonContains('data->initiator_id', $initiator->id)
                ->exists()
        );
    }

    

    public function test_no_active_state_shown_after_successful_execute(): void
    {
        $initiator = $this->makeCharacter(offense: 9000);
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $target->stats()->update(['defense' => 99999]);
        $target->update(['health' => 100, 'max_health' => 100]);

        $this->initiate($initiator, $target, $acc0, $acc1);
        OrganizedHitService::accept($acc0, $initiator->id);
        OrganizedHitService::accept($acc1, $initiator->id);

        $this->actingAs($initiator->user)->post(route('conflict.organized.execute'));

        $response = $this->actingAs($initiator->user)->get(route('conflict'));
        $response->assertOk();

        $props = $response->viewData('page')['props'] ?? [];
        $this->assertNull($props['organizedHit'] ?? null, 'After execution, organizedHit prop must be null');
    }

    

    public function test_initiate_route_validates_request(): void
    {
        $char = $this->makeCharacter();

        
        $this->actingAs($char->user)
            ->post(route('conflict.organized.initiate'), ['target' => 'SomeTarget'])
            ->assertSessionHasErrors('accomplice_ids');
    }

    public function test_initiate_route_requires_exactly_two_accomplices(): void
    {
        $char = $this->makeCharacter();
        $acc  = $this->makeCharacter();

        $this->actingAs($char->user)
            ->post(route('conflict.organized.initiate'), [
                'target'          => 'SomeTarget',
                'accomplice_ids'  => [$acc->id], 
            ])
            ->assertSessionHasErrors('accomplice_ids');
    }

    public function test_accept_route_returns_success_on_valid_accept(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $this->actingAs($acc0->user)
            ->post(route('conflict.organized.accept', ['initiatorId' => $initiator->id]))
            ->assertSessionHas('success');
    }

    public function test_decline_route_returns_info_on_decline(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $this->actingAs($acc0->user)
            ->post(route('conflict.organized.decline', ['initiatorId' => $initiator->id]))
            ->assertSessionHas('info');
    }

    public function test_cancel_route_returns_info(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $this->actingAs($initiator->user)
            ->post(route('conflict.organized.cancel'))
            ->assertSessionHas('info');

        $this->assertNull($this->getState($initiator));
    }

    
    
    

    public function test_findOpForMember_locates_op_via_initiator_id(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $found = OrganizedHitService::findOpForMember($initiator->id);
        $this->assertNotNull($found);
        $this->assertSame($initiator->id, $found['initiator_id']);
    }

    public function test_findOpForMember_locates_op_via_accomplice_id(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $found = OrganizedHitService::findOpForMember($acc0->id);
        $this->assertNotNull($found);
        $this->assertSame($initiator->id, $found['initiator_id']);
    }

    public function test_findOpForMember_returns_null_for_unrelated_character(): void
    {
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();
        $bystander = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $this->assertNull(OrganizedHitService::findOpForMember($bystander->id));
    }

    public function test_findOpForMember_honours_excludeInitiatorId(): void
    {
        // Used by accept() to ask "is this character in any OTHER op besides
        // the one being accepted?". Without exclusion, the accomplice is found
        // in their own op and accept() would reject itself.
        $initiator = $this->makeCharacter();
        $target    = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->initiate($initiator, $target, $acc0, $acc1);

        $this->assertNotNull(OrganizedHitService::findOpForMember($acc0->id));
        $this->assertNull(
            OrganizedHitService::findOpForMember($acc0->id, excludeInitiatorId: $initiator->id),
            'Excluding the only op the member is in should yield null'
        );
    }

    public function test_findOpForMember_auto_collapses_expired_op_during_scan(): void
    {
        $initiator = $this->makeCharacter();
        $acc0      = $this->makeCharacter();
        $acc1      = $this->makeCharacter();

        $this->forceState($initiator->id, [
            'member_ids'   => [$initiator->id, $acc0->id, $acc1->id],
            'member_names' => [$initiator->id => 'I', $acc0->id => 'A0', $acc1->id => 'A1'],
            'accepted_by'  => [$initiator->id],
            'expires_at'   => now()->subSeconds(1)->timestamp,
        ]);

        $this->assertNull(OrganizedHitService::findOpForMember($acc0->id));
        $this->assertNull(Cache::get(OrganizedHitService::cacheKey($initiator->id)));
        $this->assertNotContains($initiator->id, Cache::get('org_hits_active', []));
    }
}
