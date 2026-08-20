<?php

namespace Tests\Feature;

use App\Models\Career;
use App\Models\CareerPromotion;
use App\Models\CareerRank;
use App\Models\BankTransaction;
use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\Corporation;
use App\Models\CorporationBoardPromotion;
use App\Models\CorporationMergerRequest;
use App\Models\CorporationProperty;
use App\Models\CorporationSubsidiaryInvite;
use App\Models\CorporationTrustVote;
use App\Models\GameItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CorporationSystemTest extends TestCase
{
    use DatabaseTransactions;

    private int $corpCareerId;
    private int $unemployedCareerId;
    private bool $trustHoldingRowsCleared = false;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->trustHoldingRowsCleared = false;

        $corporation = Career::findByCode('corporation');
        $unemployed = Career::findByCode('unemployed');

        $this->assertNotNull($corporation, 'corporation career must exist');
        $this->assertNotNull($unemployed, 'unemployed career must exist');

        $this->corpCareerId = $corporation->id;
        $this->unemployedCareerId = $unemployed->id;
    }

    private function makeCity(string $label = 'corp'): City
    {
        return City::create([
            'name' => 'CorpCity-' . $label . '-' . uniqid(),
            'slug' => 'corp-city-' . $label . '-' . uniqid(),
            'crime_rate' => 35,
        ]);
    }

    private function careerId(string $code): int
    {
        $career = Career::findByCode($code);
        $this->assertNotNull($career, "{$code} career must exist");

        return $career->id;
    }

    private function xpForRank(int $rank): int
    {
        return (int) (CareerRank::where('career_id', $this->corpCareerId)
            ->where('rank_level', $rank)
            ->value('xp_required') ?? match ($rank) {
            1 => 0,
            2 => 1_000,
            3 => 5_000,
            4 => 10_000,
            default => 100_000,
        });
    }

    private function makeCharacter(
        City $city,
        string $careerCode = 'corporation',
        int $rank = 1,
        ?int $careerXp = null,
        int $cashOnHand = 50_000,
        int $cashInBank = 50_000,
        int $dirtyCash = 0,
        ?int $totalExp = null,
        ?string $name = null,
    ): Character {
        $user = User::factory()->create();
        $careerId = $this->careerId($careerCode);
        $careerXp ??= $this->xpForRank($rank);

        $character = Character::create([
            'user_id' => $user->id,
            'display_name' => $name ?? 'CorpChar-' . uniqid(),
            'gender' => 'male',
            'city_id' => $city->id,
            'home_city_id' => $city->id,
            'career_id' => $careerId,
            'career_rank' => $rank,
            'career_xp' => $careerXp,
            'total_character_exp' => $totalExp ?? $careerXp,
            'health' => 100,
            'max_health' => 100,
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => $cashInBank,
            'dirty_cash' => $dirtyCash,
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ]);

        CharacterStats::create([
            'character_id' => $character->id,
            'influence' => 50,
            'intelligence' => 1_000,
            'offense' => 1_000,
            'defense' => 1_000,
            'luck' => 1_000,
        ]);

        CharacterTimers::create([
            'character_id' => $character->id,
            'next_action_at' => 0,
            'strength' => 100,
        ]);

        return $character;
    }

    private function makeReadyRank3(City $city, int $cashOnHand = 50_000): Character
    {
        return $this->makeCharacter(
            city: $city,
            rank: 3,
            careerXp: $this->xpForRank(4),
            cashOnHand: $cashOnHand,
            totalExp: $this->xpForRank(4),
        );
    }

    private function makeCeo(City $city, ?string $name = null): Character
    {
        return $this->makeCharacter(
            city: $city,
            rank: 4,
            careerXp: $this->xpForRank(4),
            cashOnHand: 3_000_000,
            totalExp: $this->xpForRank(4),
            name: $name,
        );
    }

    private function makeReadyCeo(City $city, ?string $name = null, int $cashOnHand = 6_000_000): Character
    {
        return $this->makeCharacter(
            city: $city,
            rank: 4,
            careerXp: max($this->xpForRank(5), $this->xpForRank(4)),
            cashOnHand: $cashOnHand,
            totalExp: max($this->xpForRank(5), $this->xpForRank(4)),
            name: $name,
        );
    }

    private function makeCorp(Character $ceo, string $name = 'Test Corp', int $slushFund = 0): Corporation
    {
        $corp = Corporation::create([
            'name' => $name . '-' . uniqid(),
            'home_city_id' => $ceo->home_city_id,
            'founder_id' => $ceo->id,
            'ceo_id' => $ceo->id,
            'slush_fund' => $slushFund,
        ]);

        CorporationProperty::createStartingHeadquarters($corp);
        $corp->attachMember($ceo);
        $ceo->refresh();

        return $corp->fresh();
    }

    private function setCorpHqTier(Corporation $corp, int $tier): Corporation
    {
        $template = CorporationProperty::template(CorporationProperty::TYPE_HQ, $tier);

        CorporationProperty::updateOrCreate(
            [
                'corporation_id' => $corp->id,
                'type' => CorporationProperty::TYPE_HQ,
            ],
            [
                'tier' => $tier,
                'name' => $template?->name ?? "{$corp->name} Headquarters",
                'image_url' => $template?->image_url,
                'price' => $template?->price ?? 0,
                'condition' => CorporationProperty::CONDITION_CONSTRUCTED,
                'last_upkeep_at' => now(),
            ]
        );

        $corp->update(['hq_tier' => $tier]);

        return $corp->fresh();
    }

    private function addMedicalProperty(
        Corporation $corp,
        string $condition = CorporationProperty::CONDITION_CONSTRUCTED,
        array $data = [],
    ): CorporationProperty {
        $template = CorporationProperty::template(CorporationProperty::TYPE_MEDICAL, 1);

        return CorporationProperty::create([
            'corporation_id' => $corp->id,
            'type' => CorporationProperty::TYPE_MEDICAL,
            'tier' => 1,
            'name' => $template?->name ?? 'Test Medical Lab',
            'image_url' => $template?->image_url,
            'price' => $template?->price ?? 500_000,
            'condition' => $condition,
            'last_upkeep_at' => now(),
            'data' => $data,
        ]);
    }

    private function addLaunderingProperty(
        Corporation $corp,
        string $condition = CorporationProperty::CONDITION_CONSTRUCTED,
        array $data = [],
    ): CorporationProperty {
        $template = CorporationProperty::template(CorporationProperty::TYPE_LAUNDERING, 1);

        return CorporationProperty::create([
            'corporation_id' => $corp->id,
            'type' => CorporationProperty::TYPE_LAUNDERING,
            'tier' => 1,
            'name' => $template?->name ?? 'PanamaCo Offshore Trust',
            'image_url' => $template?->image_url,
            'price' => $template?->price ?? 500_000,
            'condition' => $condition,
            'last_upkeep_at' => now(),
            'data' => $data,
        ]);
    }

    private function ensureMedicineTemplates(): void
    {
        foreach (CorporationProperty::MEDICAL_PRODUCTS as $slug => $label) {
            GameItem::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $label,
                    'type' => 'item',
                    'slot' => 'item',
                    'description' => 'Corporate medicine pack.',
                    'image_url' => "https://images.thedirector.app/items/{$slug}.png",
                    'price' => 0,
                    'is_active' => true,
                    'stock' => null,
                    'max_stock' => null,
                    'durability' => null,
                    'data' => [
                        'consumable' => true,
                        'corporate_only' => true,
                        'pack_units' => CorporationProperty::MEDICAL_PACK_UNITS,
                        'effect' => $slug === CorporationProperty::MEDICINE_CX717
                            ? 'reduceStudyTimer'
                            : 'reduceTravelTimer',
                        'amount_seconds' => 60,
                    ],
                ],
            );
        }
    }

    private function giveMedicineDegree(Character $character): Character
    {
        $character->update([
            'degrees' => [
                'medicine' => [
                    'city_id' => $character->home_city_id,
                    'cycles' => (int) (config('timers.degree_cycles.medicine') ?? 30),
                    'completed_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        return $character->fresh();
    }

    private function attachMember(
        Corporation $corp,
        Character $member,
        ?string $position = null,
        ?Character $reportsTo = null,
    ): Character {
        if ((int) $member->career_id !== $this->corpCareerId) {
            $member->update([
                'career_id' => $this->corpCareerId,
                'career_rank' => 1,
                'career_xp' => $this->xpForRank(1),
            ]);
            $member->refresh();
        }

        $corp->attachMember($member, $position, $reportsTo);

        return $member->fresh();
    }

    private function makeMergeReadyCompany(City $city, string $name, int $cashOnHand = 6_000_000): array
    {
        $ceo = $this->makeReadyCeo($city, "{$name} CEO", $cashOnHand);
        $corp = $this->makeCorp($ceo, $name);
        $successor = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);
        $member = $this->attachMember(
            $corp,
            $this->makeCharacter($city, rank: 2, careerXp: $this->xpForRank(2)),
            Corporation::POSITION_MEMBER,
            $successor,
        );

        return [
            'ceo' => $ceo->fresh(),
            'corp' => $corp->fresh(),
            'successor' => $successor->fresh(),
            'member' => $member->fresh(),
        ];
    }

    private function makeHoldingCompany(City $city, string $name = 'Holding Test'): Corporation
    {
        return Corporation::create([
            'name' => $name . '-' . uniqid(),
            'home_city_id' => $city->id,
            'founder_id' => null,
            'ceo_id' => null,
            'is_holding_company' => true,
        ]);
    }

    private function makeBoardMember(Corporation $holding, City $city, int $rank = 5, ?string $name = null): Character
    {
        $member = $this->makeCharacter(
            city: $city,
            rank: $rank,
            careerXp: $this->xpForRank($rank),
            totalExp: $this->xpForRank($rank),
            name: $name,
        );

        $member->update([
            'corporation_id' => $holding->id,
            'corporation_position' => $rank >= 6
                ? Corporation::POSITION_CHAIRMAN
                : Corporation::POSITION_GROUP_PRESIDENT,
            'corporation_reports_to_id' => null,
        ]);

        return $member->fresh();
    }

    private function makeReadyChairman(Corporation $holding, City $city, ?string $name = null): Character
    {
        $member = $this->makeBoardMember($holding, $city, 6, $name);
        $member->update([
            'career_xp' => $this->xpForRank(7),
            'total_character_exp' => $this->xpForRank(7),
            'corporation_position' => Corporation::POSITION_CHAIRMAN,
        ]);

        return $member->fresh();
    }

    private function makeTrustReadyHolding(City $city, string $name = 'Trust Ready Holding'): array
    {
        if (!$this->trustHoldingRowsCleared) {
            CorporationTrustVote::query()->delete();
            Character::where('corporation_position', Corporation::POSITION_DIRECTOR_OF_BOARD)
                ->update([
                    'corporation_id' => null,
                    'corporation_position' => null,
                    'corporation_reports_to_id' => null,
                ]);
            Corporation::withTrashed()
                ->where('is_holding_company', true)
                ->forceDelete();
            $this->trustHoldingRowsCleared = true;
        }

        $holding = $this->makeHoldingCompany($city, $name);
        $holding->forceFill(['created_at' => now()->subDays(2)])->save();
        $holding->refresh();
        $holding = $this->setCorpHqTier($holding, 3);
        $token = substr(uniqid(), -6);

        $board = [
            $this->makeReadyChairman($holding, $city, "Chair One {$token}"),
            $this->makeReadyChairman($holding, $city, "Chair Two {$token}"),
            $this->makeReadyChairman($holding, $city, "Chair Three {$token}"),
        ];

        $subsidiaries = [
            $this->makeSubsidiary($holding, $city, "Trust Sub A {$token}"),
            $this->makeSubsidiary($holding, $city, "Trust Sub B {$token}"),
        ];

        return [
            'holding' => $holding->fresh(),
            'board' => $board,
            'subsidiaries' => $subsidiaries,
        ];
    }

    private function completeDirectorVote(Corporation $holding, array $board, Character $candidate): CorporationTrustVote
    {
        $this->actingAs($board[0]->user)
            ->post(route('career.corporation.trust-votes.start'))
            ->assertSessionHas('success', 'Director vote opened.');

        $this->actingAs($board[0]->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $candidate->id])
            ->assertSessionHas('success', 'Vote recorded.');

        $this->actingAs($board[2]->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $candidate->id])
            ->assertSessionHas('success', 'Director vote completed.');

        return CorporationTrustVote::where('holding_company_id', $holding->id)->firstOrFail();
    }

    private function makeSubsidiary(Corporation $holding, City $city, string $name, bool $withSuccessor = true): array
    {
        $ceo = $this->makeReadyCeo($city, "{$name} CEO");
        $corp = $this->makeCorp($ceo, $name);
        $corp->update(['parent_trust_id' => $holding->id]);

        $successor = null;
        if ($withSuccessor) {
            $successor = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);
        }

        $member = $this->attachMember(
            $corp,
            $this->makeCharacter($city, rank: 2, careerXp: $this->xpForRank(2)),
            Corporation::POSITION_MEMBER,
            $successor,
        );

        return [
            'ceo' => $ceo->fresh(),
            'corp' => $corp->fresh(),
            'successor' => $successor?->fresh(),
            'member' => $member->fresh(),
        ];
    }

    private function completeMerger(City $city): array
    {
        $left = $this->makeMergeReadyCompany($city, 'Left Merge');
        $right = $this->makeMergeReadyCompany($city, 'Right Merge');
        $holdingName = 'Holding-' . substr(uniqid(), -8);

        $this->actingAs($left['ceo']->user)
            ->post(route('career.corporation.merger.propose'), [
                'target_corporation_id' => $right['corp']->id,
                'requester_successor_id' => $left['successor']->id,
                'holding_name' => $holdingName,
                'holding_image_url' => 'https://example.com/holding.jpg',
            ])
            ->assertSessionHas('success');

        $mergerRequest = CorporationMergerRequest::where('holding_name', $holdingName)->first();
        $this->assertNotNull($mergerRequest);

        $this->actingAs($right['ceo']->user)
            ->post(route('career.corporation.merger.accept', $mergerRequest), [
                'target_successor_id' => $right['successor']->id,
            ])
            ->assertRedirect(route('career.corporate'));

        return [
            'left' => [
                'ceo' => $left['ceo']->fresh(),
                'corp' => $left['corp']->fresh(),
                'successor' => $left['successor']->fresh(),
                'member' => $left['member']->fresh(),
            ],
            'right' => [
                'ceo' => $right['ceo']->fresh(),
                'corp' => $right['corp']->fresh(),
                'successor' => $right['successor']->fresh(),
                'member' => $right['member']->fresh(),
            ],
            'holding' => Corporation::where('name', $holdingName)->firstOrFail()->fresh(),
            'mergerRequest' => $mergerRequest->fresh(),
        ];
    }

    private function promoteThroughScreen(Character $character, int $targetRank): void
    {
        $option = CareerPromotion::findForPromotion($this->corpCareerId, $targetRank) ? 1 : 0;

        $this->actingAs($character->user)
            ->post(route('career.promote'), ['option' => $option])
            ->assertRedirect();
    }

    public function test_founding_uses_cash_on_hand_not_bank_balance(): void
    {
        $city = $this->makeCity('cash');
        $founder = $this->makeReadyRank3($city, cashOnHand: 0);
        $founder->update(['cash_in_bank' => 3_000_000]);

        $this->actingAs($founder->user)
            ->post(route('corporation.found'), ['name' => 'Cash Locked Corp'])
            ->assertSessionHas('error', 'You need $1,000,000 to incorporate.');

        $this->assertNull($founder->fresh()->corporation_id);
        $this->assertFalse(Corporation::where('name', 'Cash Locked Corp')->exists());
    }

    public function test_rank_three_ready_character_founds_then_promotes_to_ceo(): void
    {
        $city = $this->makeCity('found');
        $founder = $this->makeReadyRank3($city, cashOnHand: 2_500_000);

        $this->actingAs($founder->user)
            ->post(route('corporation.found'), [
                'name' => 'Boardroom Test Corp',
                'image_url' => 'https://example.com/banner.jpg',
            ])
            ->assertRedirect(route('career.promote'));

        $founder->refresh();
        $corp = Corporation::where('name', 'Boardroom Test Corp')->first();

        $this->assertNotNull($corp);
        $this->assertSame(1_500_000, $founder->cash_on_hand);
        $this->assertSame($corp->id, $founder->corporation_id);
        $this->assertSame($founder->id, $corp->ceo_id);
        $this->assertSame(3, $founder->career_rank);
        $this->assertSame('https://example.com/banner.jpg', $corp->image_url);
        $this->assertSame(1, $corp->fresh()->hq_tier);
        $startingHq = CorporationProperty::template(CorporationProperty::TYPE_HQ, 1);
        $this->assertDatabaseHas('corporation_properties', [
            'corporation_id' => $corp->id,
            'type' => CorporationProperty::TYPE_HQ,
            'tier' => 1,
            'price' => $startingHq?->price,
            'condition' => CorporationProperty::CONDITION_CONSTRUCTED,
        ]);

        $option = CareerPromotion::findForPromotion($this->corpCareerId, 4) ? 1 : 0;

        $this->actingAs($founder->user)
            ->post(route('career.promote'), ['option' => $option])
            ->assertRedirect();

        $this->assertSame(4, $founder->fresh()->career_rank);
    }

    public function test_rank_four_promotion_requires_being_corporation_ceo(): void
    {
        $city = $this->makeCity('promotion');
        $character = $this->makeReadyRank3($city, cashOnHand: 3_000_000);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('boardroom');

        $character->promote();
    }

    public function test_corporation_career_caps_at_rank_four_for_phase_one(): void
    {
        $city = $this->makeCity('cap');
        $ceo = $this->makeReadyCeo($city);
        $this->makeCorp($ceo, 'Cap Corp');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('zenith');

        $ceo->promote();
    }

    public function test_rank_four_non_ceo_cannot_join_another_corporation(): void
    {
        $city = $this->makeCity('rank4join');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Join Guard Corp');
        $rankFour = $this->makeCeo($city, 'RankFour-' . uniqid());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Managing Directors cannot join another corporation.');

        $corp->attachMember($rankFour);
    }

    public function test_same_city_can_have_multiple_independent_ceos(): void
    {
        $city = $this->makeCity('city-ceos');
        $first = $this->makeCeo($city);
        $second = $this->makeCeo($city);

        $corpA = $this->makeCorp($first, 'First Local Corp');
        $corpB = $this->makeCorp($second, 'Second Local Corp');

        $this->assertNotSame($corpA->id, $corpB->id);
        $this->assertSame($first->id, $corpA->ceo_id);
        $this->assertSame($second->id, $corpB->ceo_id);
        $this->assertSame($city->id, $corpA->home_city_id);
        $this->assertSame($city->id, $corpB->home_city_id);
    }

    public function test_phase_one_capacity_is_seven_members_total(): void
    {
        $city = $this->makeCity('capacity');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Capacity Corp');
        $corp = $this->setCorpHqTier($corp, 3);

        for ($i = 0; $i < 6; $i++) {
            $this->attachMember($corp, $this->makeCharacter($city, rank: 1));
        }

        $this->assertSame(7, $corp->fresh()->member_count);
        $this->assertSame(7, $corp->fresh()->max_member_slots);
        $this->assertTrue($corp->fresh()->isFull());

        $target = $this->makeCharacter($city, careerCode: 'unemployed');

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.invite'), ['target' => $target->display_name])
            ->assertSessionHas('error', 'Your corporation is at maximum capacity.');
    }

    public function test_invite_accept_starts_corporation_career_and_defaults_to_available_vp(): void
    {
        $city = $this->makeCity('invite');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Invite Corp');
        $corp = $this->setCorpHqTier($corp, 2);
        $cfo = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);
        $vp = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_VP, $cfo);
        $target = $this->makeCharacter($city, careerCode: 'unemployed');

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.invite'), ['target' => $target->display_name])
            ->assertSessionHas('success');

        $journal = CharacterJournal::where('character_id', $target->id)
            ->where('type', 'corporation_invite_request')
            ->first();

        $this->assertNotNull($journal);

        $this->actingAs($target->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('success');

        $target->refresh();
        $this->assertSame($this->corpCareerId, $target->career_id);
        $this->assertSame($corp->id, $target->corporation_id);
        $this->assertNull($target->corporation_position);
        $this->assertSame($vp->id, $target->corporation_reports_to_id);
    }

    public function test_assignment_enforces_phase_one_reporting_rules(): void
    {
        $city = $this->makeCity('assign');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Assign Corp');

        $cfo = $this->attachMember($corp, $this->makeReadyRank3($city));
        $vp = $this->attachMember($corp, $this->makeReadyRank3($city));
        $member = $this->attachMember($corp, $this->makeCharacter($city, rank: 1));
        $duplicateCfo = $this->attachMember($corp, $this->makeReadyRank3($city));

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.assign-position'), [
                'member_id' => $cfo->id,
                'position' => Corporation::POSITION_CFO,
                'reports_to_id' => $vp->id,
            ])
            ->assertSessionHas('success');

        $this->assertSame(Corporation::POSITION_CFO, $cfo->fresh()->corporation_position);
        $this->assertNull($cfo->fresh()->corporation_reports_to_id);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.assign-position'), [
                'member_id' => $vp->id,
                'position' => Corporation::POSITION_VP,
                'reports_to_id' => $cfo->id,
            ])
            ->assertSessionHas('success');

        $this->assertSame(Corporation::POSITION_VP, $vp->fresh()->corporation_position);
        $this->assertSame($cfo->id, $vp->fresh()->corporation_reports_to_id);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.assign-position'), [
                'member_id' => $member->id,
                'position' => Corporation::POSITION_MEMBER,
                'reports_to_id' => $vp->id,
            ])
            ->assertSessionHas('success');

        $this->assertNull($member->fresh()->corporation_position);
        $this->assertSame($vp->id, $member->fresh()->corporation_reports_to_id);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.assign-position'), [
                'member_id' => $duplicateCfo->id,
                'position' => Corporation::POSITION_CFO,
            ])
            ->assertSessionHas('error', 'CFO position is already filled.');
    }

    public function test_line_managers_can_only_manage_direct_reports(): void
    {
        $city = $this->makeCity('line');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Line Corp');
        $cfo = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);
        $vp = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_VP, $cfo);
        $memberUnderVp = $this->attachMember($corp, $this->makeCharacter($city, rank: 1), Corporation::POSITION_MEMBER, $vp);

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.kick'), ['member_id' => $memberUnderVp->id])
            ->assertSessionHas('error', 'You can only remove members assigned to you.');

        $this->actingAs($vp->user)
            ->post(route('career.corporation.kick'), ['member_id' => $memberUnderVp->id])
            ->assertSessionHas('success');

        $this->assertNull($memberUnderVp->fresh()->corporation_id);
    }

    public function test_rank_demotion_costs_influence_logs_journals_and_reassigns_reports(): void
    {
        $city = $this->makeCity('rank-demote');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Rank Demote Corp');
        $cfo = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);
        $vp = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_VP, $cfo);
        $member = $this->attachMember($corp, $this->makeCharacter($city, rank: 2), Corporation::POSITION_MEMBER, $vp);

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.demote-rank'), ['member_id' => $vp->id])
            ->assertSessionHas('success');

        $cfo->refresh();
        $vp->refresh();
        $member->refresh();

        $this->assertSame(2, $vp->career_rank);
        $this->assertSame($this->xpForRank(2), $vp->career_xp);
        $this->assertNull($vp->corporation_position);
        $this->assertSame($cfo->id, $vp->corporation_reports_to_id);
        $this->assertSame($cfo->id, $member->corporation_reports_to_id);
        $this->assertEquals(40, $cfo->stats->fresh()->influence);

        $targetJournal = CharacterJournal::where('character_id', $vp->id)
            ->where('type', 'demoted')
            ->first();
        $ceoJournal = CharacterJournal::where('character_id', $ceo->id)
            ->where('type', 'demoted')
            ->first();

        $this->assertNotNull($targetJournal);
        $this->assertNotNull($ceoJournal);
        $this->assertStringContainsString('CFO', $targetJournal->description);
        $this->assertStringContainsString('has demoted you to', $targetJournal->description);
        $this->assertStringContainsString('has demoted your employee', $ceoJournal->description);
    }

    public function test_rank_demotion_prevents_lateral_demotions_and_rank_one_demotions(): void
    {
        $city = $this->makeCity('rank-demote-guard');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Rank Demote Guard Corp');
        $cfo = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);
        $cto = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CTO);
        $rankOne = $this->attachMember($corp, $this->makeCharacter($city, rank: 1), Corporation::POSITION_MEMBER, $cfo);

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.demote-rank'), ['member_id' => $cto->id])
            ->assertSessionHas('error', 'You can only demote members assigned to you.');

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.demote-rank'), ['member_id' => $rankOne->id])
            ->assertSessionHas('error', 'That member cannot be demoted any further.');

        $this->assertSame(3, $cto->fresh()->career_rank);
        $this->assertSame(1, $rankOne->fresh()->career_rank);
        $this->assertEquals(50, $cfo->stats->fresh()->influence);
    }

    public function test_killing_csuite_clears_direct_reports_without_removing_remaining_members(): void
    {
        $city = $this->makeCity('cfo-death');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Death Corp');
        $cfo = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);
        $vp = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_VP, $cfo);
        $member = $this->attachMember($corp, $this->makeCharacter($city, rank: 1), Corporation::POSITION_MEMBER, $cfo);

        $cfo->kill('test', 'Killed during corporation test');

        $this->assertSoftDeleted('characters', ['id' => $cfo->id]);
        $this->assertSame($corp->id, $vp->fresh()->corporation_id);
        $this->assertSame($corp->id, $member->fresh()->corporation_id);
        $this->assertNull($vp->fresh()->corporation_reports_to_id);
        $this->assertNull($member->fresh()->corporation_reports_to_id);
    }

    public function test_killing_vp_clears_member_reports_without_removing_members(): void
    {
        $city = $this->makeCity('vp-death');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'VP Death Corp');
        $cfo = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);
        $vp = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_VP, $cfo);
        $member = $this->attachMember($corp, $this->makeCharacter($city, rank: 1), Corporation::POSITION_MEMBER, $vp);

        $vp->kill('test', 'Killed during corporation test');

        $this->assertSoftDeleted('characters', ['id' => $vp->id]);
        $this->assertSame($corp->id, $member->fresh()->corporation_id);
        $this->assertNull($member->fresh()->corporation_reports_to_id);
    }

    public function test_ceo_death_promotes_ready_successor_and_clears_their_reports(): void
    {
        $city = $this->makeCity('ceo-death');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Succession Corp');
        $successor = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);
        $dependent = $this->attachMember($corp, $this->makeCharacter($city, rank: 1), Corporation::POSITION_MEMBER, $successor);

        $ceo->kill('test', 'CEO died during corporation test');

        $corp->refresh();
        $successor->refresh();
        $dependent->refresh();

        $this->assertSame($successor->id, $corp->ceo_id);
        $this->assertSame(4, $successor->career_rank);
        $this->assertNull($successor->corporation_position);
        $this->assertNull($successor->corporation_reports_to_id);
        $this->assertSame($corp->id, $dependent->corporation_id);
        $this->assertNull($dependent->corporation_reports_to_id);
    }

    public function test_ceo_death_dissolves_when_no_ready_successor_and_detaches_members(): void
    {
        $city = $this->makeCity('no-successor');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'No Successor Corp');
        $member = $this->attachMember($corp, $this->makeCharacter($city, rank: 2, careerXp: $this->xpForRank(2)));

        $ceo->kill('test', 'CEO died during corporation test');

        $trashedCorp = Corporation::withTrashed()->find($corp->id);

        $this->assertNotNull($trashedCorp);
        $this->assertTrue($trashedCorp->trashed());
        $this->assertNull($member->fresh()->corporation_id);
    }

    public function test_transfer_ceo_promotes_successor_and_demotes_old_ceo_to_rank_three_floor(): void
    {
        $city = $this->makeCity('transfer');
        $oldCeo = $this->makeCeo($city);
        $oldCeo->update(['career_xp' => $this->xpForRank(4) + 50_000]);
        $corp = $this->makeCorp($oldCeo, 'Transfer Corp');
        $newCeo = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);

        $this->actingAs($oldCeo->user)
            ->post(route('career.corporation.transfer-ceo'), ['member_id' => $newCeo->id])
            ->assertSessionHas('success');

        $corp->refresh();
        $oldCeo->refresh();
        $newCeo->refresh();

        $this->assertSame($newCeo->id, $corp->ceo_id);
        $this->assertSame(4, $newCeo->career_rank);
        $this->assertNull($newCeo->corporation_position);
        $this->assertNull($newCeo->corporation_reports_to_id);
        $this->assertSame(3, $oldCeo->career_rank);
        $this->assertSame($this->xpForRank(3), $oldCeo->career_xp);
        $this->assertNull($oldCeo->corporation_position);
    }

    public function test_non_ceo_quit_detaches_without_resetting_career_xp(): void
    {
        $city = $this->makeCity('quit');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Quit Corp');
        $member = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_VP);
        $xpBefore = $member->career_xp;
        $totalBefore = $member->total_character_exp;

        $this->actingAs($member->user)
            ->post(route('career.corporation.quit'))
            ->assertSessionHas('success');

        $member->refresh();
        $this->assertNull($member->corporation_id);
        $this->assertNull($member->corporation_position);
        $this->assertNull($member->corporation_reports_to_id);
        $this->assertSame($this->corpCareerId, $member->career_id);
        $this->assertSame(3, $member->career_rank);
        $this->assertSame($xpBefore, $member->career_xp);
        $this->assertSame($totalBefore, $member->total_character_exp);
    }

    public function test_solo_ceo_dissolve_quits_corporation_career(): void
    {
        $city = $this->makeCity('dissolve');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Dissolve Corp');

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.dissolve'))
            ->assertSessionHas('success');

        $ceo->refresh();
        $trashedCorp = Corporation::withTrashed()->find($corp->id);

        $this->assertTrue($trashedCorp->trashed());
        $this->assertNull($ceo->corporation_id);
        $this->assertSame($this->unemployedCareerId, $ceo->career_id);
        $this->assertSame(1, $ceo->career_rank);
    }

    public function test_finance_controls_require_authority_and_pay_only_leadership(): void
    {
        $city = $this->makeCity('finance');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Finance Corp', slushFund: 1_000);
        $cfo = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);
        $vp = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_VP, $cfo);
        $member = $this->attachMember($corp, $this->makeCharacter($city, rank: 1), Corporation::POSITION_MEMBER, $vp);

        $this->actingAs($member->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $cfo->id,
                'amount' => 100,
            ])
            ->assertSessionHas('error', 'Only the CEO or CFO can distribute funds.');

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $member->id,
                'amount' => 100,
            ])
            ->assertSessionHas('error', 'Funds can only be distributed to C-suite and Vice Presidents.');

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $vp->id,
                'amount' => 250,
            ])
            ->assertSessionHas('success');

        $this->assertSame(750, $corp->fresh()->slush_fund);
        $this->assertSame(250, $vp->fresh()->dirty_cash);
    }

    public function test_cash_reserves_use_clean_money_and_same_distribution_rules(): void
    {
        $city = $this->makeCity('reserves');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Reserve Corp');
        $cfo = $this->attachMember(
            $corp,
            $this->makeReadyRank3($city, cashOnHand: 1_000),
            Corporation::POSITION_CFO,
        );
        $vp = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_VP, $cfo);
        $member = $this->attachMember($corp, $this->makeCharacter($city, rank: 1), Corporation::POSITION_MEMBER, $vp);

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.reserves.deposit'), ['amount' => 300])
            ->assertSessionHas('success');

        $this->assertSame(700, $cfo->fresh()->cash_on_hand);
        $this->assertSame(0, $cfo->fresh()->dirty_cash);
        $this->assertSame(300, $corp->fresh()->cash_reserves);

        $corp->update(['cash_reserves' => 500]);

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.reserves.distribute'), [
                'member_id' => $member->id,
                'amount' => 100,
            ])
            ->assertSessionHas('error', 'Funds can only be distributed to C-suite and Vice Presidents.');

        $cashBefore = $vp->fresh()->cash_on_hand;
        $dirtyBefore = $vp->fresh()->dirty_cash;

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.reserves.distribute'), [
                'member_id' => $vp->id,
                'amount' => 200,
            ])
            ->assertSessionHas('success');

        $this->assertSame(300, $corp->fresh()->cash_reserves);
        $this->assertSame($cashBefore + 200, $vp->fresh()->cash_on_hand);
        $this->assertSame($dirtyBefore, $vp->fresh()->dirty_cash);
    }

    public function test_corporation_property_purchases_drain_reserves_and_leave_pending_construction(): void
    {
        $city = $this->makeCity('properties');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Property Corp');
        $hqTwo = CorporationProperty::template(CorporationProperty::TYPE_HQ, 2);
        $medical = CorporationProperty::template(CorporationProperty::TYPE_MEDICAL, 1);

        $this->assertNotNull($hqTwo);
        $this->assertNotNull($medical);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.properties.purchase'), ['property_id' => $hqTwo->id])
            ->assertSessionHas('error', 'Your corporation does not have the required cash reserves to purchase this property.');

        $corp->update(['cash_reserves' => $hqTwo->price + $medical->price]);

        // Pre-purchase: corp owns its founding tier-1 HQ as the operational row.
        $this->assertDatabaseHas('corporation_properties', [
            'corporation_id' => $corp->id,
            'type' => CorporationProperty::TYPE_HQ,
            'tier' => 1,
            'condition' => CorporationProperty::CONDITION_CONSTRUCTED,
        ]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.properties.purchase'), ['property_id' => $hqTwo->id])
            ->assertSessionHas('success');

        $corp->refresh();
        // Purchase leaves the new HQ in PENDING alongside the still-operational
        // tier-1 row. Capacity does NOT bump until an Engineer completes
        // the build via the Workshop.
        $this->assertSame(1, $corp->hq_tier);
        $this->assertSame($medical->price, $corp->cash_reserves);
        $this->assertDatabaseHas('corporation_properties', [
            'corporation_id' => $corp->id,
            'type' => CorporationProperty::TYPE_HQ,
            'tier' => 1,
            'condition' => CorporationProperty::CONDITION_CONSTRUCTED,
        ]);
        $this->assertDatabaseHas('corporation_properties', [
            'corporation_id' => $corp->id,
            'type' => CorporationProperty::TYPE_HQ,
            'tier' => 2,
            'name' => $hqTwo->name,
            'condition' => CorporationProperty::CONDITION_PENDING,
        ]);
        $this->assertSame(2, CorporationProperty::where('corporation_id', $corp->id)
            ->where('type', CorporationProperty::TYPE_HQ)
            ->count());

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.properties.purchase'), ['property_id' => $medical->id])
            ->assertSessionHas('success');

        $this->assertSame(0, $corp->fresh()->cash_reserves);
        $this->assertDatabaseHas('corporation_properties', [
            'corporation_id' => $corp->id,
            'type' => CorporationProperty::TYPE_MEDICAL,
            'tier' => 1,
            'name' => $medical->name,
            'condition' => CorporationProperty::CONDITION_PENDING,
        ]);
    }

    public function test_defaulted_headquarters_applies_operating_company_stat_penalty(): void
    {
        $homeCity = $this->makeCity('defaulted-property');
        $awayCity = $this->makeCity('defaulted-property-away');
        $ceo = $this->makeCeo($homeCity);
        $corp = $this->makeCorp($ceo, 'Defaulted Property Corp');
        $member = $this->makeCharacter($homeCity, name: 'Defaulted Worker');

        $corp->attachMember($member);
        $member->update(['city_id' => $awayCity->id]);

        $cleanEffective = $member->stats->fresh()
            ->load('character.corporation.properties')
            ->effectiveStats();

        $this->assertSame(50.0, $cleanEffective['influence']);
        $this->assertSame(1000, $cleanEffective['offense']);

        $this->addMedicalProperty($corp, CorporationProperty::CONDITION_DEFAULTED);

        $medicalDefaultedEffective = $member->stats->fresh()
            ->load('character.corporation.properties')
            ->effectiveStats();

        $this->assertSame(50.0, $medicalDefaultedEffective['influence']);
        $this->assertSame(1000, $medicalDefaultedEffective['offense']);

        CorporationProperty::where('corporation_id', $corp->id)
            ->where('type', CorporationProperty::TYPE_HQ)
            ->update(['condition' => CorporationProperty::CONDITION_DEFAULTED]);

        $defaultedEffective = $member->stats->fresh()
            ->load('character.corporation.properties')
            ->effectiveStats();

        $this->assertSame(42.5, $defaultedEffective['influence']);
        $this->assertSame(850, $defaultedEffective['intelligence']);
        $this->assertSame(850, $defaultedEffective['offense']);
        $this->assertSame(850, $defaultedEffective['defense']);
        $this->assertSame(850, $defaultedEffective['luck']);
    }

    public function test_corporation_cannot_chain_buy_hq_upgrades_while_construction_pending(): void
    {
        $city = $this->makeCity('hq-chain');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Chain Corp');
        $hqTwo = CorporationProperty::template(CorporationProperty::TYPE_HQ, 2);
        $hqThree = CorporationProperty::template(CorporationProperty::TYPE_HQ, 3);

        $this->assertNotNull($hqTwo);
        $this->assertNotNull($hqThree);

        $corp->update(['cash_reserves' => $hqTwo->price + $hqThree->price]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.properties.purchase'), ['property_id' => $hqTwo->id])
            ->assertSessionHas('success');

        // tier-3 isn't even returned by purchasableTemplatesFor while tier-2
        // is pending — the controller's "you cannot downgrade" guard kicks in
        // first. (Skipping the tier-2 build to leapfrog tiers is what we're
        // protecting against here.)
        $this->actingAs($ceo->user)
            ->post(route('career.corporation.properties.purchase'), ['property_id' => $hqThree->id])
            ->assertSessionHas('error', "You cannot downgrade your corporation's properties!");
    }

    public function test_engineer_constructs_pending_corp_property_for_fee_and_bumps_hq_tier(): void
    {
        $city = $this->makeCity('engineer-build');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Build Corp');
        $hqTwo = CorporationProperty::template(CorporationProperty::TYPE_HQ, 2);

        $this->assertNotNull($hqTwo);

        $fee = max(1, (int) ceil($hqTwo->price * 0.001));
        $corp->update(['cash_reserves' => $hqTwo->price]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.properties.purchase'), ['property_id' => $hqTwo->id])
            ->assertSessionHas('success');

        $pending = CorporationProperty::where('corporation_id', $corp->id)
            ->where('type', CorporationProperty::TYPE_HQ)
            ->where('tier', 2)
            ->where('condition', CorporationProperty::CONDITION_PENDING)
            ->firstOrFail();

        // Invariant: pre-construction, the corp keeps its prior operational
        // tier-1 row in place. New stat bonus and member slots are NOT
        // granted until the engineer finishes the new building.
        $corp->refresh();
        $this->assertSame(2, CorporationProperty::where('corporation_id', $corp->id)
            ->where('type', CorporationProperty::TYPE_HQ)
            ->count(), 'Pending upgrade must add a SECOND HQ row, not overwrite tier-1.');
        $this->assertSame(1, $corp->hq_tier, 'hq_tier must stay at the prior operational tier until engineer builds.');
        $this->assertSame(3, $corp->max_member_slots, 'Member slots must reflect the prior tier during pending construction.');
        $this->assertSame(5, CorporationProperty::HeadquartersBonus($corp->fresh()->load('properties')), 'HQ stat bonus must reflect prior tier (1 * 5), not pending tier.');
        $this->assertTrue($corp->hasWorkingHQ(), 'Pending upgrade must not break invitations — corp still has a working HQ.');

        // Junior technician (rank 1) cannot construct.
        $junior = $this->makeCharacter(city: $city, careerCode: 'technician', rank: 1);

        $this->actingAs($junior->user)
            ->post(route('career.technician.construct-corp-property'), ['property_id' => $pending->id])
            ->assertSessionHas('error', 'Only Engineers can construct corporation properties.');

        // Engineer (rank 2) in a different city is rejected.
        $otherCity = $this->makeCity('engineer-build-other');
        $foreignEngineer = $this->makeCharacter(city: $otherCity, careerCode: 'technician', rank: 2);
        $this->actingAs($foreignEngineer->user)
            ->post(route('career.technician.construct-corp-property'), ['property_id' => $pending->id])
            ->assertSessionHas('error', 'You can only construct properties in your current city.');

        // Engineer (rank 2) in the corp's home city builds and gets paid.
        $engineer = $this->makeCharacter(city: $city, careerCode: 'technician', rank: 2, cashOnHand: 0);

        $reservesBefore = (int) $corp->fresh()->cash_reserves;

        $this->actingAs($engineer->user)
            ->post(route('career.technician.construct-corp-property'), ['property_id' => $pending->id])
            ->assertSessionHas('success');

        $corp->refresh();
        $pending->refresh();

        $this->assertSame(2, $corp->hq_tier);
        $this->assertSame(5, $corp->max_member_slots);
        $this->assertSame($reservesBefore, (int) $corp->cash_reserves);
        $this->assertSame($fee, (int) $engineer->fresh()->cash_on_hand);
        $this->assertSame(CorporationProperty::CONDITION_CONSTRUCTED, $pending->condition);
        // The prior tier-1 row is decommissioned — the new building has
        // physically replaced the old one, so only one HQ row remains.
        $this->assertSame(1, CorporationProperty::where('corporation_id', $corp->id)
            ->where('type', CorporationProperty::TYPE_HQ)
            ->count(), 'Engineer build must decommission the previous operational HQ.');
        $this->assertSame(2, (int) CorporationProperty::where('corporation_id', $corp->id)
            ->where('type', CorporationProperty::TYPE_HQ)
            ->value('tier'));
    }

    public function test_medicine_production_picker_lists_local_medical_corporations_without_leaking_construction_state(): void
    {
        $city = $this->makeCity('medicine-picker');
        $otherCity = $this->makeCity('medicine-picker-other');
        $worker = $this->giveMedicineDegree($this->makeCharacter($city, careerCode: 'unemployed'));

        $localCeo = $this->makeCeo($city, 'Local CEO');
        $localCorp = $this->makeCorp($localCeo, 'Local Medical');
        $this->addMedicalProperty($localCorp, CorporationProperty::CONDITION_PENDING, [
            'medical_payout_per_pack' => 1_500,
        ]);

        $otherCeo = $this->makeCeo($otherCity, 'Other CEO');
        $otherCorp = $this->makeCorp($otherCeo, 'Other Medical');
        $this->addMedicalProperty($otherCorp);

        $holding = Corporation::create([
            'name' => 'Holding Medical-' . uniqid(),
            'home_city_id' => $city->id,
            'founder_id' => $localCeo->id,
            'slush_fund' => 100_000,
            'is_holding_company' => true,
        ]);
        $this->addMedicalProperty($holding);

        $shape = (new \App\Actions\MedicineProduction())->getShape($worker);
        $targets = collect($shape['targets']);
        $targetIds = $targets->pluck('id')->all();
        $localTarget = $targets->firstWhere('id', $localCorp->id);

        $this->assertContains($localCorp->id, $targetIds);
        $this->assertNotContains($otherCorp->id, $targetIds);
        $this->assertNotContains($holding->id, $targetIds);
        $this->assertSame('PPP $1,500', $localTarget['badge']);
    }

    public function test_medicine_production_requires_completed_degree_and_operational_medical_property(): void
    {
        $city = $this->makeCity('medicine-gates');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical Gates', slushFund: 100_000);
        $property = $this->addMedicalProperty($corp);
        $worker = $this->makeCharacter($city, careerCode: 'unemployed', dirtyCash: 0);

        $this->actingAs($worker->user)
            ->post(route('actions.corporation.medical.produce'), ['target_id' => $corp->id])
            ->assertSessionHas('error', 'You need a completed medicine degree to work this property.');

        $this->assertSame(100_000, (int) $corp->fresh()->slush_fund);
        $this->assertSame(0, (int) $worker->fresh()->dirty_cash);

        $worker = $this->giveMedicineDegree($worker);
        $property->update(['condition' => CorporationProperty::CONDITION_PENDING]);

        $this->actingAs($worker->user)
            ->post(route('actions.corporation.medical.produce'), ['target_id' => $corp->id])
            ->assertSessionHas('error', "That corporation's building is currently under maintenance and cannot be worked at!");

        $this->assertSame(100_000, (int) $corp->fresh()->slush_fund);
        $this->assertSame(0, (int) $worker->fresh()->dirty_cash);
    }

    public function test_medicine_production_debits_slush_pays_dirty_cash_records_stock_and_sets_cooldown(): void
    {
        $city = $this->makeCity('medicine-profit');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical Profit', slushFund: 100_000);
        $property = $this->addMedicalProperty($corp, data: [
            'medical_payout_per_pack' => 1_000,
        ]);
        $worker = $this->giveMedicineDegree($this->makeCharacter($city, careerCode: 'unemployed', dirtyCash: 0));

        $this->actingAs($worker->user)
            ->post(route('actions.corporation.medical.produce'), ['target_id' => $corp->id])
            ->assertSessionHas('success');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);
        $stockedPacks = collect($data['stock'])->sum(fn(array $product) => (int) $product[CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $stockedCost = collect($data['stock'])->sum(fn(array $product) => (int) $product[CorporationProperty::MEDICAL_STOCK_STOCKED]['cost']);
        $expectedCost = $stockedPacks * 1_000;

        $this->assertGreaterThanOrEqual(1, $stockedPacks);
        $this->assertLessThanOrEqual(3, $stockedPacks);
        $this->assertSame($expectedCost, $stockedCost);
        $this->assertSame(100_000 - $expectedCost, (int) $corp->fresh()->slush_fund);
        $this->assertSame($expectedCost, (int) $worker->fresh()->dirty_cash);
        $this->assertTrue($worker->fresh()->timers->next_action_at->isFuture());

        $corpSlushAfterFirstRun = (int) $corp->fresh()->slush_fund;
        $workerDirtyAfterFirstRun = (int) $worker->fresh()->dirty_cash;

        $this->actingAs($worker->user)
            ->post(route('actions.corporation.medical.produce'), ['target_id' => $corp->id])
            ->assertSessionHas('error', 'You need to wait before performing another action.');

        $this->assertSame($corpSlushAfterFirstRun, (int) $corp->fresh()->slush_fund);
        $this->assertSame($workerDirtyAfterFirstRun, (int) $worker->fresh()->dirty_cash);
    }

    public function test_medicine_production_cannot_mint_worker_cash_without_corporate_slush(): void
    {
        $city = $this->makeCity('medicine-no-free-worker');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'No Free Worker', slushFund: 1_000);
        $property = $this->addMedicalProperty($corp, data: [
            'medical_payout_per_pack' => CorporationProperty::MAX_MEDICAL_PAYOUT_PER_PACK,
        ]);
        $worker = $this->giveMedicineDegree($this->makeCharacter($city, careerCode: 'unemployed', dirtyCash: 0));

        $this->actingAs($worker->user)
            ->post(route('actions.corporation.medical.produce'), ['target_id' => $corp->id])
            ->assertSessionHas('error', 'The company cannot fund a production run.');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);

        $this->assertSame(0, collect($data['stock'])->sum(fn(array $product) => (int) $product[CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']));
        $this->assertSame(1_000, (int) $corp->fresh()->slush_fund);
        $this->assertSame(0, (int) $worker->fresh()->dirty_cash);
    }

    public function test_ceo_can_set_medical_payout_per_pack_with_server_side_bounds(): void
    {
        $city = $this->makeCity('medicine-price');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical Price');
        $property = $this->addMedicalProperty($corp);
        $cfo = $this->attachMember($corp, $this->makeCharacter($city, rank: 3), Corporation::POSITION_CFO);

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.profits.medical.payout-rate'), ['rate' => 1_000])
            ->assertSessionHas('error', 'Only the CEO can set the medical payout rate.');

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.profits.medical.payout-rate'), ['rate' => 999])
            ->assertSessionHasErrors('rate');

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.profits.medical.payout-rate'), ['rate' => 3_000])
            ->assertSessionHas('success');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);

        $this->assertSame(3_000, $data['medical_payout_per_pack']);
    }

    public function test_mirror_laundering_uses_only_prefunded_offshore_balance_and_preserves_existing_trust_money(): void
    {
        $city = $this->makeCity('mirror-accounting');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Mirror Accounting', slushFund: 3_000_000);
        $cfo = $this->attachMember($corp, $this->makeCharacter($city, rank: 3), Corporation::POSITION_CFO);
        $banker = $this->makeCharacter($city, careerCode: 'banking', rank: 2, cashInBank: 0, name: 'MirrorBanker-' . uniqid());
        $property = $this->addLaunderingProperty($corp, data: [
            'offshore_balance' => 600,
            'banker_percentage' => 10,
        ]);

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.profits.laundering.mirror'), [
                'banker_name' => $banker->display_name,
                'amount' => 3_000_000,
            ])
            ->assertSessionHas('error', 'PanamaCo does not hold enough available funds.');

        $this->assertSame(3_000_000, (int) $corp->fresh()->slush_fund);
        $this->assertSame(600, $property->fresh()->offshoreBalance());

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.profits.laundering.offshore-transfer'), [
                'amount' => 3_000_000,
            ])
            ->assertSessionHas('success');

        $this->assertSame(0, (int) $corp->fresh()->slush_fund);
        $this->assertSame(3_000_600, $property->fresh()->offshoreBalance());

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.profits.laundering.mirror'), [
                'banker_name' => $banker->display_name,
                'amount' => 3_000_000,
            ])
            ->assertSessionHas('success');

        $property->refresh();
        $this->assertSame(3_000_600, $property->offshoreBalance());
        $this->assertSame(3_000_000, $property->reservedMirrorBalance());
        $this->assertSame(600, $property->availableOffshoreBalance());

        $journal = CharacterJournal::where('character_id', $banker->id)
            ->where('type', 'corporate_mirror_transaction_request')
            ->first();
        $this->assertNotNull($journal);
        $requestKey = (string) $journal->data['request_key'];

        $this->actingAs($banker->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('success');

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.profits.laundering.mirror.execute'), [
                'request_key' => $requestKey,
            ])
            ->assertSessionHas('success');

        $data = CorporationProperty::normalizeLaunderingData($property->fresh()->data);
        $this->assertSame([], $data['mirror_requests']);
        $this->assertGreaterThanOrEqual(300_600, $data['offshore_balance']);
        $this->assertLessThanOrEqual(600_600, $data['offshore_balance']);
        $this->assertSame(2_850_000, (int) $corp->fresh()->cash_reserves);
        $this->assertSame(0, (int) $corp->fresh()->slush_fund);
        $this->assertSame(150_000, (int) $banker->fresh()->cash_in_bank);

        $completionJournal = CharacterJournal::where('character_id', $banker->id)
            ->where('type', 'corporate_mirror_transaction_completed')
            ->first();
        $this->assertNotNull($completionJournal);
        $this->assertSame(150_000, (int) $completionJournal->data['banker_fee']);
        $this->assertStringContainsString('fee was deposited', $completionJournal->description);
        $this->assertNull(
            CharacterJournal::where('character_id', $cfo->id)
                ->where('type', 'corporate_mirror_transaction_completed')
                ->first()
        );

        $transaction = BankTransaction::where('character_id', $banker->id)
            ->where('type', BankTransaction::TYPE_DEPOSIT)
            ->first();
        $this->assertNotNull($transaction);
        $this->assertSame(150_000, (int) $transaction->amount);
        $this->assertSame('PanamaCo Trust', $transaction->counterparty);
        $this->assertSame('Private Placement Fee', $transaction->note);
    }

    public function test_offshore_trust_transfer_is_cfo_only_operational_and_capped(): void
    {
        $city = $this->makeCity('mirror-transfer-guard');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Mirror Transfer Guard', slushFund: 6_000_000);
        $cfo = $this->attachMember($corp, $this->makeCharacter($city, rank: 3), Corporation::POSITION_CFO);
        $member = $this->attachMember($corp, $this->makeCharacter($city, rank: 1), Corporation::POSITION_MEMBER);
        $property = $this->addLaunderingProperty($corp);

        $this->actingAs($member->user)
            ->post(route('career.corporation.profits.laundering.offshore-transfer'), [
                'amount' => 100_000,
            ])
            ->assertSessionHas('error', 'Only the CFO can move funds into the offshore trust.');

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.profits.laundering.offshore-transfer'), [
                'amount' => 5_000_000,
            ])
            ->assertSessionHas('success');

        $this->assertSame(1_000_000, (int) $corp->fresh()->slush_fund);
        $this->assertSame(5_000_000, $property->fresh()->offshoreBalance());

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.profits.laundering.offshore-transfer'), [
                'amount' => 1,
            ])
            ->assertSessionHas('error', 'PanamaCo can hold at most $5,000,000.');

        $property->update(['condition' => CorporationProperty::CONDITION_DEFAULTED]);

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.profits.laundering.offshore-transfer'), [
                'amount' => 100_000,
            ])
            ->assertSessionHas('error', "Your corporation's laundering property is currently under maintenance.");

        $this->assertSame(1_000_000, (int) $corp->fresh()->slush_fund);
        $this->assertSame(5_000_000, $property->fresh()->offshoreBalance());
    }

    public function test_medical_npc_sale_removes_stock_credits_slush_profit_and_sets_cooldown(): void
    {
        $city = $this->makeCity('medicine-npc');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical NPC', slushFund: 1_000);
        $corp->update(['total_profits' => 200]);
        $property = $this->addMedicalProperty($corp, data: [
            'stock' => [
                CorporationProperty::MEDICINE_CX717 => [
                    CorporationProperty::MEDICAL_STOCK_STOCKED => ['packs' => 3, 'cost' => 4_500],
                ],
                CorporationProperty::MEDICINE_TAK925 => [
                    CorporationProperty::MEDICAL_STOCK_STOCKED => ['packs' => 1, 'cost' => 1_500],
                ],
            ],
        ]);
        $crimeBefore = (float) $city->fresh()->crime_rate;
        $market = CorporationProperty::medicalProductMarket(CorporationProperty::MEDICINE_CX717);
        $minPayout = 3 * (int) $market['npc_price_min'];
        $maxPayout = 3 * (int) $market['npc_price_max'];

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.profits.medical.sell-npc'), [
                'product' => CorporationProperty::MEDICINE_CX717,
            ])
            ->assertSessionHas('success');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);

        $this->assertSame(0, $data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $this->assertSame(1, $data['stock'][CorporationProperty::MEDICINE_TAK925][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $freshCorp = $corp->fresh();
        $payout = (int) $freshCorp->slush_fund - 1_000;
        $this->assertGreaterThanOrEqual($minPayout, $payout);
        $this->assertLessThanOrEqual($maxPayout, $payout);
        $this->assertSame(200 + $payout, (int) $freshCorp->total_profits);
        $this->assertGreaterThan($crimeBefore, (float) $city->fresh()->crime_rate);
        $this->assertTrue($ceo->fresh()->timers->next_action_at->isFuture());

        $slushAfterSale = (int) $corp->fresh()->slush_fund;

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.profits.medical.sell-npc'), [
                'product' => CorporationProperty::MEDICINE_TAK925,
            ])
            ->assertSessionHas('error', 'You need to wait before performing another action.');

        $this->assertSame($slushAfterSale, (int) $corp->fresh()->slush_fund);
    }

    public function test_medicine_sale_reserves_stock_and_blocks_duplicate_reserved_pack(): void
    {
        $this->ensureMedicineTemplates();

        $city = $this->makeCity('medicine-reserve');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical Reserve');
        $property = $this->addMedicalProperty($corp, data: [
            'stock' => [
                CorporationProperty::MEDICINE_CX717 => [
                    CorporationProperty::MEDICAL_STOCK_STOCKED => ['packs' => 1, 'cost' => 900],
                ],
            ],
        ]);
        $buyer = $this->makeCharacter($city, careerCode: 'unemployed', cashOnHand: 50_000);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.actions.medical-sale'), [
                'target_id' => $buyer->id,
                'product' => CorporationProperty::MEDICINE_CX717,
                'pack_count' => 1,
                'amount' => 5_000,
            ])
            ->assertSessionHas('success');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);
        $reservedRows = $data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_RESERVED];

        $this->assertSame(0, $data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $this->assertCount(1, $reservedRows);
        $this->assertSame(1, collect($reservedRows)->sum('packs'));
        $this->assertNotNull(CharacterJournal::where('character_id', $buyer->id)->where('type', 'corporate_medicine_sale_request')->first());

        $ceo->timers()->update(['next_action_at' => 0]);
        $secondBuyer = $this->makeCharacter($city, careerCode: 'unemployed', cashOnHand: 50_000);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.actions.medical-sale'), [
                'target_id' => $secondBuyer->id,
                'product' => CorporationProperty::MEDICINE_CX717,
                'pack_count' => 1,
                'amount' => 5_000,
            ])
            ->assertSessionHas('error', 'You already have a medicine offer waiting on a buyer.');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);
        $this->assertSame(0, $data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $this->assertSame(1, collect($data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_RESERVED])->sum('packs'));
    }

    public function test_medicine_sale_cancel_releases_reserved_stock_and_clears_pending_request(): void
    {
        $this->ensureMedicineTemplates();

        $city = $this->makeCity('medicine-cancel');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical Cancel');
        $property = $this->addMedicalProperty($corp, data: [
            'stock' => [
                CorporationProperty::MEDICINE_TAK925 => [
                    CorporationProperty::MEDICAL_STOCK_STOCKED => ['packs' => 2, 'cost' => 1_800],
                ],
            ],
        ]);
        $buyer = $this->makeCharacter($city, careerCode: 'unemployed', cashOnHand: 50_000);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.actions.medical-sale'), [
                'target_id' => $buyer->id,
                'product' => CorporationProperty::MEDICINE_TAK925,
                'pack_count' => 2,
                'amount' => 8_000,
            ])
            ->assertSessionHas('success');

        $journal = CharacterJournal::where('character_id', $buyer->id)
            ->where('type', 'corporate_medicine_sale_request')
            ->first();

        $this->assertNotNull($journal);
        $requestKey = (string) ($journal->data['request_key'] ?? '');
        $this->assertNotSame('', $requestKey);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.actions.medical-sale.cancel'), [
                'request_key' => $requestKey,
            ])
            ->assertSessionHas('success');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);

        $this->assertSame(2, $data['stock'][CorporationProperty::MEDICINE_TAK925][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $this->assertSame(0, collect($data['stock'][CorporationProperty::MEDICINE_TAK925][CorporationProperty::MEDICAL_STOCK_RESERVED])->sum('packs'));
        $this->assertNull(CharacterJournal::find($journal->id));
    }

    public function test_medicine_sale_offer_does_not_gate_on_buyer_capacity_until_acceptance(): void
    {
        $this->ensureMedicineTemplates();

        $city = $this->makeCity('medicine-buyer-capacity');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical Capacity');
        $property = $this->addMedicalProperty($corp, data: [
            'stock' => [
                CorporationProperty::MEDICINE_CX717 => [
                    CorporationProperty::MEDICAL_STOCK_STOCKED => ['packs' => 2, 'cost' => 1_800],
                ],
            ],
        ]);
        $buyer = $this->makeCharacter($city, careerCode: 'unemployed', cashOnHand: 50_000);
        $filler = GameItem::create([
            'name' => 'Pocket Filler-' . uniqid(),
            'slug' => 'pocket-filler-' . uniqid(),
            'type' => 'item',
            'slot' => 'item',
            'description' => 'Test item.',
            'price' => 0,
            'is_active' => true,
        ]);

        for ($i = 0; $i < Character::MAX_ON_HAND_ITEMS; $i++) {
            $buyer->items()->create([
                'game_item_id' => $filler->id,
                'durability_remaining' => null,
                'location' => 'on_hand',
                'is_equipped' => false,
                'equipped_slot' => null,
            ]);
        }

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.actions.medical-sale'), [
                'target_id' => $buyer->id,
                'product' => CorporationProperty::MEDICINE_CX717,
                'pack_count' => 2,
                'amount' => 10_000,
            ])
            ->assertSessionHas('success');

        $journal = CharacterJournal::where('character_id', $buyer->id)
            ->where('type', 'corporate_medicine_sale_request')
            ->first();
        $this->assertNotNull($journal);

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);
        $this->assertSame(0, $data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $this->assertSame(2, collect($data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_RESERVED])->sum('packs'));

        $this->actingAs($buyer->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('error', 'You cannot carry that many packs.');
    }

    public function test_medicine_sale_cannot_target_same_corporation_member(): void
    {
        $this->ensureMedicineTemplates();

        $city = $this->makeCity('medicine-same-corp-target');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical Internal Sale');
        $property = $this->addMedicalProperty($corp, data: [
            'stock' => [
                CorporationProperty::MEDICINE_CX717 => [
                    CorporationProperty::MEDICAL_STOCK_STOCKED => ['packs' => 1, 'cost' => 900],
                ],
            ],
        ]);
        $member = $this->makeCharacter($city, careerCode: 'unemployed', cashOnHand: 50_000);
        $corp->attachMember($member);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.actions.medical-sale'), [
                'target_id' => $member->id,
                'product' => CorporationProperty::MEDICINE_CX717,
                'pack_count' => 1,
                'amount' => 5_000,
            ])
            ->assertSessionHas('error', 'You cannot sell company stock to someone in your corporation.');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);

        $this->assertSame(1, $data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $this->assertSame(0, collect($data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_RESERVED])->sum('packs'));
        $this->assertNull(CharacterJournal::where('character_id', $member->id)->where('type', 'corporate_medicine_sale_request')->first());
    }

    public function test_medicine_sale_releases_reserved_stock_if_buyer_joins_corporation_before_acceptance(): void
    {
        $this->ensureMedicineTemplates();

        $city = $this->makeCity('medicine-same-corp-accept');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical Accept Guard', slushFund: 1_000);
        $property = $this->addMedicalProperty($corp, data: [
            'stock' => [
                CorporationProperty::MEDICINE_TAK925 => [
                    CorporationProperty::MEDICAL_STOCK_STOCKED => ['packs' => 1, 'cost' => 900],
                ],
            ],
        ]);
        $buyer = $this->makeCharacter($city, careerCode: 'unemployed', cashOnHand: 50_000);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.actions.medical-sale'), [
                'target_id' => $buyer->id,
                'product' => CorporationProperty::MEDICINE_TAK925,
                'pack_count' => 1,
                'amount' => 7_000,
            ])
            ->assertSessionHas('success');

        $journal = CharacterJournal::where('character_id', $buyer->id)
            ->where('type', 'corporate_medicine_sale_request')
            ->first();
        $this->assertNotNull($journal);
        $requestKey = (string) ($journal->data['request_key'] ?? '');
        $this->assertNotSame('', $requestKey);

        $corp->attachMember($buyer);

        $this->actingAs($buyer->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('error', 'That sale can no longer be completed.');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);

        $this->assertSame(1, $data['stock'][CorporationProperty::MEDICINE_TAK925][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $this->assertSame(0, collect($data['stock'][CorporationProperty::MEDICINE_TAK925][CorporationProperty::MEDICAL_STOCK_RESERVED])->sum('packs'));
        $this->assertNull(CharacterJournal::find($journal->id));
        $this->assertFalse(Cache::has($requestKey));
        $this->assertSame(0, $buyer->items()->count());
        $this->assertSame(1_000, (int) $corp->fresh()->slush_fund);
    }

    public function test_medicine_sale_request_url_leak_cannot_be_used_by_another_character(): void
    {
        $this->ensureMedicineTemplates();

        $city = $this->makeCity('medicine-url-leak');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical Leak Guard', slushFund: 1_000);
        $property = $this->addMedicalProperty($corp, data: [
            'stock' => [
                CorporationProperty::MEDICINE_TAK925 => [
                    CorporationProperty::MEDICAL_STOCK_STOCKED => ['packs' => 1, 'cost' => 900],
                ],
            ],
        ]);
        $buyer = $this->makeCharacter($city, careerCode: 'unemployed', cashOnHand: 50_000);
        $intruder = $this->makeCharacter($city, careerCode: 'unemployed', cashOnHand: 50_000);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.actions.medical-sale'), [
                'target_id' => $buyer->id,
                'product' => CorporationProperty::MEDICINE_TAK925,
                'pack_count' => 1,
                'amount' => 7_000,
            ])
            ->assertSessionHas('success');

        $journal = CharacterJournal::where('character_id', $buyer->id)
            ->where('type', 'corporate_medicine_sale_request')
            ->first();

        $this->assertNotNull($journal);

        $this->actingAs($intruder->user)
            ->post(route('journal.decline', $journal->id))
            ->assertSessionHas('error', 'Request not found or invalid.');

        $this->actingAs($intruder->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('error', 'Request not found or invalid.');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);
        $this->assertSame(0, $data['stock'][CorporationProperty::MEDICINE_TAK925][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $this->assertSame(1, collect($data['stock'][CorporationProperty::MEDICINE_TAK925][CorporationProperty::MEDICAL_STOCK_RESERVED])->sum('packs'));
        $this->assertNotNull(CharacterJournal::find($journal->id));

        $this->actingAs($buyer->user)
            ->post(route('journal.accept', $journal->id))
            ->assertSessionHas('success');

        $this->assertNull(CharacterJournal::find($journal->id));
        $this->assertSame(1, $buyer->items()->count());
        $this->assertSame(8_000, $corp->fresh()->slush_fund);
        $this->assertSame(43_000, $buyer->fresh()->cash_on_hand);
    }

    public function test_corporate_medicine_consumables_ready_unix_timers_and_track_three_uses(): void
    {
        $this->ensureMedicineTemplates();

        $city = $this->makeCity('medicine-timers');
        $character = $this->makeCharacter($city, careerCode: 'unemployed');
        $templates = GameItem::whereIn('slug', [
            CorporationProperty::MEDICINE_CX717,
            CorporationProperty::MEDICINE_TAK925,
        ])->get()->keyBy('slug');

        $travelItem = $character->items()->create([
            'game_item_id' => $templates[CorporationProperty::MEDICINE_TAK925]->id,
            'durability_remaining' => null,
            'location' => 'on_hand',
            'is_equipped' => false,
            'equipped_slot' => null,
            'data' => [
                'units' => CorporationProperty::MEDICAL_PACK_UNITS,
                'pack_units' => CorporationProperty::MEDICAL_PACK_UNITS,
            ],
        ]);
        $studyItem = $character->items()->create([
            'game_item_id' => $templates[CorporationProperty::MEDICINE_CX717]->id,
            'durability_remaining' => null,
            'location' => 'on_hand',
            'is_equipped' => false,
            'equipped_slot' => null,
            'data' => [
                'units' => CorporationProperty::MEDICAL_PACK_UNITS,
                'pack_units' => CorporationProperty::MEDICAL_PACK_UNITS,
            ],
        ]);

        $travelBefore = now()->addMinutes(10)->getTimestamp();
        $studyBefore = now()->addMinutes(12)->getTimestamp();
        $talentCooldown = now()->addHour()->getTimestamp();
        $character->timers()->update([
            'next_travel_at' => $travelBefore,
            'next_study_at' => $studyBefore,
            'next_talents_at' => $talentCooldown,
        ]);

        $this->actingAs($character->user)
            ->post(route('settings.consume'), ['id' => $travelItem->id])
            ->assertSessionHas('success');

        $this->actingAs($character->user)
            ->post(route('settings.consume'), ['id' => $studyItem->id])
            ->assertSessionHas('success');

        $readyCutoff = now()->getTimestamp();
        $timerRow = DB::table('character_timers')
            ->where('character_id', $character->id)
            ->first(['next_travel_at', 'next_study_at', 'next_talents_at']);

        $this->assertLessThanOrEqual($readyCutoff, (int) $timerRow->next_travel_at);
        $this->assertLessThanOrEqual($readyCutoff, (int) $timerRow->next_study_at);
        $this->assertLessThan($travelBefore, (int) $timerRow->next_travel_at);
        $this->assertLessThan($studyBefore, (int) $timerRow->next_study_at);
        $this->assertSame($talentCooldown, (int) $timerRow->next_talents_at);

        $travelItem->refresh()->load('template');
        $studyItem->refresh()->load('template');

        $this->assertSame(2, (int) $travelItem->data['units']);
        $this->assertSame(2, (int) $studyItem->data['units']);
        $this->assertSame(67, $travelItem->conditionPercent());
        $this->assertSame(67, $studyItem->conditionPercent());
    }

    public function test_medical_npc_sale_clears_reserved_stock_without_duplicate_player_sale(): void
    {
        $this->ensureMedicineTemplates();

        $city = $this->makeCity('medicine-reserved-npc');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Medical Reserved NPC', slushFund: 1_000);
        $property = $this->addMedicalProperty($corp, data: [
            'stock' => [
                CorporationProperty::MEDICINE_CX717 => [
                    CorporationProperty::MEDICAL_STOCK_STOCKED => ['packs' => 2, 'cost' => 1_800],
                ],
            ],
        ]);
        $buyer = $this->makeCharacter($city, careerCode: 'unemployed', cashOnHand: 50_000);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.actions.medical-sale'), [
                'target_id' => $buyer->id,
                'product' => CorporationProperty::MEDICINE_CX717,
                'pack_count' => 2,
                'amount' => 10_000,
            ])
            ->assertSessionHas('success');

        $journal = CharacterJournal::where('character_id', $buyer->id)
            ->where('type', 'corporate_medicine_sale_request')
            ->first();

        $this->assertNotNull($journal);
        $ceo->timers()->update(['next_action_at' => 0]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.profits.medical.sell-npc'), [
                'product' => CorporationProperty::MEDICINE_CX717,
            ])
            ->assertSessionHas('success');

        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);
        $this->assertSame(0, $data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
        $this->assertSame(0, collect($data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_RESERVED])->sum('packs'));

        $this->assertNull(CharacterJournal::find($journal->id));
        $this->assertSame(0, $buyer->items()->count());
    }

    public function test_medical_npc_sale_rejects_empty_or_non_operational_stock_without_free_company_money(): void
    {
        $city = $this->makeCity('medicine-no-free-company');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'No Free Company', slushFund: 1_000);
        $property = $this->addMedicalProperty($corp);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.profits.medical.sell-npc'), [
                'product' => CorporationProperty::MEDICINE_CX717,
            ])
            ->assertSessionHas('error', 'There is no stock to sell.');

        $this->assertSame(1_000, (int) $corp->fresh()->slush_fund);

        $property->update([
            'condition' => CorporationProperty::CONDITION_PENDING,
            'data' => [
                'stock' => [
                    CorporationProperty::MEDICINE_CX717 => [
                        CorporationProperty::MEDICAL_STOCK_STOCKED => ['packs' => 2, 'cost' => 3_000],
                    ],
                ],
            ],
        ]);

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.profits.medical.sell-npc'), [
                'product' => CorporationProperty::MEDICINE_CX717,
            ])
            ->assertSessionHas('error', "Your corporation's building is currently under maintenance and cannot be worked at!");

        $this->assertSame(1_000, (int) $corp->fresh()->slush_fund);
        $data = CorporationProperty::normalizeMedicalData($property->fresh()->data);
        $this->assertSame(2, $data['stock'][CorporationProperty::MEDICINE_CX717][CorporationProperty::MEDICAL_STOCK_STOCKED]['packs']);
    }

    public function test_phase_two_merger_creates_holding_company_and_pending_board_handoffs(): void
    {
        $city = $this->makeCity('phase-two-merge');
        $left = $this->makeMergeReadyCompany($city, 'Phase Two Left');
        $right = $this->makeMergeReadyCompany($city, 'Phase Two Right');
        $holdingName = 'Holding-' . substr(uniqid(), -8);

        $this->actingAs($left['ceo']->user)
            ->post(route('career.corporation.merger.propose'), [
                'target_corporation_id' => $right['corp']->id,
                'requester_successor_id' => $left['successor']->id,
                'holding_name' => $holdingName,
                'holding_image_url' => 'https://example.com/phase-two.jpg',
            ])
            ->assertSessionHas('success');

        $mergerRequest = CorporationMergerRequest::where('holding_name', $holdingName)->first();
        $this->assertNotNull($mergerRequest);

        $this->actingAs($right['ceo']->user)
            ->post(route('career.corporation.merger.accept', $mergerRequest), [
                'target_successor_id' => $right['successor']->id,
            ])
            ->assertRedirect(route('career.corporate'));

        $holding = Corporation::where('name', $holdingName)->first();
        $this->assertNotNull($holding);
        $this->assertTrue((bool) $holding->is_holding_company);
        $this->assertNull($holding->ceo_id);
        $this->assertSame($city->id, $holding->home_city_id);
        $this->assertSame('https://example.com/phase-two.jpg', $holding->image_url);

        $this->assertSame($holding->id, $left['corp']->fresh()->parent_trust_id);
        $this->assertSame($holding->id, $right['corp']->fresh()->parent_trust_id);
        $this->assertSame($left['ceo']->id, $left['corp']->fresh()->ceo_id);
        $this->assertSame($right['ceo']->id, $right['corp']->fresh()->ceo_id);
        $this->assertSame(1_000_000, $left['ceo']->fresh()->cash_on_hand);

        $mergerRequest->refresh();
        $this->assertSame(CorporationMergerRequest::STATUS_COMPLETED, $mergerRequest->status);
        $this->assertSame($left['successor']->id, $mergerRequest->requester_successor_id);
        $this->assertSame($right['successor']->id, $mergerRequest->target_successor_id);
        $this->assertNull($mergerRequest->requester_handoff_completed_at);
        $this->assertNull($mergerRequest->target_handoff_completed_at);
        $this->assertSame(3, $holding->fresh()->boardCapacity());
        $this->assertSame(2, $holding->fresh()->pendingBoardIntakeCount());
    }

    public function test_merger_blocks_invalid_phase_two_entry_states(): void
    {
        $firstCity = $this->makeCity('merge-block-a');
        $secondCity = $this->makeCity('merge-block-b');
        $left = $this->makeMergeReadyCompany($firstCity, 'Block Left');
        $differentCityTarget = $this->makeMergeReadyCompany($secondCity, 'Block Other City');

        $this->actingAs($left['ceo']->user)
            ->post(route('career.corporation.merger.propose'), [
                'target_corporation_id' => $differentCityTarget['corp']->id,
                'requester_successor_id' => $left['successor']->id,
                'holding_name' => 'Holding-' . substr(uniqid(), -8),
            ])
            ->assertSessionHas('error', 'Companies must share the same headquarters city.');

        $cityWithHolding = $this->makeCity('merge-block-holding');
        $this->makeHoldingCompany($cityWithHolding, 'Existing Holding');
        $holdingLeft = $this->makeMergeReadyCompany($cityWithHolding, 'Holding Block Left');
        $holdingRight = $this->makeMergeReadyCompany($cityWithHolding, 'Holding Block Right');

        $this->actingAs($holdingLeft['ceo']->user)
            ->post(route('career.corporation.merger.propose'), [
                'target_corporation_id' => $holdingRight['corp']->id,
                'requester_successor_id' => $holdingLeft['successor']->id,
                'holding_name' => 'Holding-' . substr(uniqid(), -8),
            ])
            ->assertSessionHas('error', 'That headquarters city already has a holding company.');

        $cashCity = $this->makeCity('merge-block-cash');
        $poorLeft = $this->makeMergeReadyCompany($cashCity, 'Poor Left', cashOnHand: 4_999_999);
        $cashRight = $this->makeMergeReadyCompany($cashCity, 'Cash Right');

        $this->actingAs($poorLeft['ceo']->user)
            ->post(route('career.corporation.merger.propose'), [
                'target_corporation_id' => $cashRight['corp']->id,
                'requester_successor_id' => $poorLeft['successor']->id,
                'holding_name' => 'Holding-' . substr(uniqid(), -8),
            ])
            ->assertSessionHas('error', 'You do not have the merger setup funds on hand.');

        $successorCity = $this->makeCity('merge-block-successor');
        $successorLeft = $this->makeMergeReadyCompany($successorCity, 'Successor Left');
        $successorRight = $this->makeMergeReadyCompany($successorCity, 'Successor Right');
        $invalidSuccessor = $this->makeReadyRank3($successorCity);

        $this->actingAs($successorLeft['ceo']->user)
            ->post(route('career.corporation.merger.propose'), [
                'target_corporation_id' => $successorRight['corp']->id,
                'requester_successor_id' => $invalidSuccessor->id,
                'holding_name' => 'Holding-' . substr(uniqid(), -8),
            ])
            ->assertSessionHas('error', 'Your company needs a valid successor.');
    }

    public function test_rank_five_handoff_moves_ceo_to_holding_board_and_keeps_successor_promotion_ready(): void
    {
        $city = $this->makeCity('rank-five-handoff');
        $state = $this->completeMerger($city);
        $oldCeo = $state['left']['ceo'];
        $successor = $state['left']['successor'];
        $subsidiary = $state['left']['corp'];
        $holding = $state['holding'];

        $this->promoteThroughScreen($oldCeo, 5);

        $oldCeo->refresh();
        $successor->refresh();
        $subsidiary->refresh();

        $this->assertSame(5, $oldCeo->career_rank);
        $this->assertSame($holding->id, $oldCeo->corporation_id);
        $this->assertSame(Corporation::POSITION_GROUP_PRESIDENT, $oldCeo->corporation_position);
        $this->assertNull($oldCeo->corporation_reports_to_id);
        $this->assertSame($successor->id, $subsidiary->ceo_id);
        $this->assertSame(3, $successor->career_rank);
        $this->assertNull($successor->corporation_position);
        $this->assertNull($successor->corporation_reports_to_id);
        $this->assertNotNull($state['mergerRequest']->fresh()->requester_handoff_completed_at);

        $this->promoteThroughScreen($successor, 4);
        $this->assertSame(4, $successor->fresh()->career_rank);
    }

    public function test_rank_six_board_promotion_updates_position_and_rank_seven_stays_blocked(): void
    {
        $city = $this->makeCity('rank-six');
        $state = $this->completeMerger($city);
        $boardMember = $state['left']['ceo'];

        $this->promoteThroughScreen($boardMember, 5);
        $boardMember->refresh();
        $boardMember->update(['career_xp' => max($this->xpForRank(6), 1_000_000)]);

        $this->promoteThroughScreen($boardMember->fresh(), 6);

        $boardMember->refresh();
        $this->assertSame(6, $boardMember->career_rank);
        $this->assertSame(Corporation::POSITION_CHAIRMAN, $boardMember->corporation_position);

        $boardMember->update(['career_xp' => 2_000_000]);
        $this->assertNotNull($boardMember->fresh()->getPromotionChecker());
        $this->assertSame(6, $boardMember->fresh()->career_rank);
    }

    public function test_board_promotion_requires_capacity_and_hands_off_subsidiary_ceo(): void
    {
        $city = $this->makeCity('board-promotion');
        $holding = $this->makeHoldingCompany($city, 'Board Promotion Holding');
        $firstSub = $this->makeSubsidiary($holding, $city, 'Promotion Sub A');
        $secondSub = $this->makeSubsidiary($holding, $city, 'Promotion Sub B');
        $boardA = $this->makeBoardMember($holding, $city, 5, 'Board A');
        $this->makeBoardMember($holding, $city, 6, 'Board B');

        $this->assertSame(3, $holding->fresh()->boardCapacity());
        $this->assertSame(2, $holding->fresh()->boardMemberCount());
        $this->assertSame(1, $holding->fresh()->availableBoardSlots());

        $this->actingAs($boardA->user)
            ->post(route('career.corporation.board-promotions.create'), [
                'subsidiary_id' => $firstSub['corp']->id,
                'successor_id' => $firstSub['successor']->id,
            ])
            ->assertSessionHas('success');

        $promotion = CorporationBoardPromotion::where('subsidiary_id', $firstSub['corp']->id)->first();
        $this->assertNotNull($promotion);
        $this->assertSame(0, $holding->fresh()->availableBoardSlots());

        $this->promoteThroughScreen($firstSub['ceo'], 5);

        $promotedCeo = $firstSub['ceo']->fresh();
        $successor = $firstSub['successor']->fresh();
        $subsidiary = $firstSub['corp']->fresh();

        $this->assertSame($holding->id, $promotedCeo->corporation_id);
        $this->assertSame(Corporation::POSITION_GROUP_PRESIDENT, $promotedCeo->corporation_position);
        $this->assertSame($successor->id, $subsidiary->ceo_id);
        $this->assertSame(3, $successor->career_rank);
        $this->assertSame(CorporationBoardPromotion::STATUS_COMPLETED, $promotion->fresh()->status);

        $this->actingAs($boardA->user)
            ->post(route('career.corporation.board-promotions.create'), [
                'subsidiary_id' => $secondSub['corp']->id,
                'successor_id' => $secondSub['successor']->id,
            ])
            ->assertSessionHas('error', 'The holding board has no open seat.');
    }

    public function test_subsidiary_invite_acceptance_creates_board_handoff_without_immediate_promotion(): void
    {
        $city = $this->makeCity('subsidiary-invite');
        $holding = $this->makeHoldingCompany($city, 'Invite Holding');
        $this->makeSubsidiary($holding, $city, 'Invite Existing A');
        $this->makeSubsidiary($holding, $city, 'Invite Existing B');
        $boardMember = $this->makeBoardMember($holding, $city, 5, 'Invite Board');
        $target = $this->makeMergeReadyCompany($city, 'Invite Target');

        $this->actingAs($boardMember->user)
            ->post(route('career.corporation.subsidiary-invites.create'), [
                'target_corporation_id' => $target['corp']->id,
            ])
            ->assertSessionHas('success');

        $invite = CorporationSubsidiaryInvite::where('target_corporation_id', $target['corp']->id)->first();
        $this->assertNotNull($invite);
        $this->assertSame(CorporationSubsidiaryInvite::STATUS_PENDING, $invite->status);

        $this->actingAs($target['ceo']->user)
            ->post(route('career.corporation.subsidiary-invites.accept', $invite), [
                'successor_id' => $target['successor']->id,
            ])
            ->assertRedirect(route('career.corporate'));

        $promotion = CorporationBoardPromotion::where('subsidiary_id', $target['corp']->id)
            ->where('promoted_ceo_id', $target['ceo']->id)
            ->first();

        $this->assertNotNull($promotion);
        $this->assertSame(CorporationBoardPromotion::STATUS_PENDING, $promotion->status);
        $this->assertSame(CorporationSubsidiaryInvite::STATUS_COMPLETED, $invite->fresh()->status);
        $this->assertSame($holding->id, $target['corp']->fresh()->parent_trust_id);
        $this->assertSame($target['ceo']->id, $target['corp']->fresh()->ceo_id);
        $this->assertSame(4, $target['ceo']->fresh()->career_rank);
        $this->assertNull($target['ceo']->fresh()->corporation_reports_to_id);

        $this->promoteThroughScreen($target['ceo'], 5);

        $this->assertSame($holding->id, $target['ceo']->fresh()->corporation_id);
        $this->assertSame(Corporation::POSITION_GROUP_PRESIDENT, $target['ceo']->fresh()->corporation_position);
        $this->assertSame($target['successor']->id, $target['corp']->fresh()->ceo_id);
        $this->assertSame(CorporationBoardPromotion::STATUS_COMPLETED, $promotion->fresh()->status);
    }

    public function test_subsidiary_invite_acceptance_rechecks_capacity_after_another_invite_fills_last_slot(): void
    {
        $city = $this->makeCity('subsidiary-invite-slot');
        $holding = $this->makeHoldingCompany($city, 'Slot Holding');
        $this->makeSubsidiary($holding, $city, 'Slot Existing A');
        $this->makeSubsidiary($holding, $city, 'Slot Existing B');
        $this->makeSubsidiary($holding, $city, 'Slot Existing C');
        $boardMember = $this->makeBoardMember($holding, $city, 5, 'Slot Board A');
        $this->makeBoardMember($holding, $city, 5, 'Slot Board B');
        $this->makeBoardMember($holding, $city, 6, 'Slot Board C');
        $this->makeBoardMember($holding, $city, 6, 'Slot Board D');
        $firstTarget = $this->makeMergeReadyCompany($city, 'Slot Target A');
        $secondTarget = $this->makeMergeReadyCompany($city, 'Slot Target B');

        $this->actingAs($boardMember->user)
            ->post(route('career.corporation.subsidiary-invites.create'), [
                'target_corporation_id' => $firstTarget['corp']->id,
            ])
            ->assertSessionHas('success');

        $this->actingAs($boardMember->user)
            ->post(route('career.corporation.subsidiary-invites.create'), [
                'target_corporation_id' => $secondTarget['corp']->id,
            ])
            ->assertSessionHas('success');

        $firstInvite = CorporationSubsidiaryInvite::where('target_corporation_id', $firstTarget['corp']->id)->firstOrFail();
        $secondInvite = CorporationSubsidiaryInvite::where('target_corporation_id', $secondTarget['corp']->id)->firstOrFail();

        $this->actingAs($firstTarget['ceo']->user)
            ->post(route('career.corporation.subsidiary-invites.accept', $firstInvite), [
                'successor_id' => $firstTarget['successor']->id,
            ])
            ->assertRedirect(route('career.corporate'));

        $this->assertSame(4, $holding->fresh()->activeSubsidiaries()->count());
        $this->assertFalse($holding->fresh()->hasSubsidiaryCapacity());

        $this->actingAs($secondTarget['ceo']->user)
            ->post(route('career.corporation.subsidiary-invites.accept', $secondInvite), [
                'successor_id' => $secondTarget['successor']->id,
            ])
            ->assertSessionHas('error', 'The holding company has no open subsidiary slot.');

        $this->assertNull($secondTarget['corp']->fresh()->parent_trust_id);
        $this->assertSame(CorporationSubsidiaryInvite::STATUS_PENDING, $secondInvite->fresh()->status);
        $this->assertFalse(
            CorporationBoardPromotion::where('subsidiary_id', $secondTarget['corp']->id)->exists()
        );
    }

    public function test_subsidiary_invite_acceptance_rechecks_board_capacity_after_subsidiary_loss(): void
    {
        $city = $this->makeCity('subsidiary-invite-loss');
        $holding = $this->makeHoldingCompany($city, 'Loss Holding');
        $this->makeSubsidiary($holding, $city, 'Loss Existing A');
        $removedSub = $this->makeSubsidiary($holding, $city, 'Loss Existing B');
        $boardMember = $this->makeBoardMember($holding, $city, 5, 'Loss Board A');
        $this->makeBoardMember($holding, $city, 5, 'Loss Board B');
        $this->makeBoardMember($holding, $city, 6, 'Loss Board C');
        $target = $this->makeMergeReadyCompany($city, 'Loss Target');

        $this->actingAs($boardMember->user)
            ->post(route('career.corporation.subsidiary-invites.create'), [
                'target_corporation_id' => $target['corp']->id,
            ])
            ->assertSessionHas('success');

        $invite = CorporationSubsidiaryInvite::where('target_corporation_id', $target['corp']->id)->firstOrFail();

        $this->actingAs($boardMember->user)
            ->post(route('career.corporation.subsidiaries.kick'), [
                'subsidiary_id' => $removedSub['corp']->id,
            ])
            ->assertRedirect(route('career.corporate'));

        $this->assertSame(1, $holding->fresh()->activeSubsidiaries()->count());
        $this->assertSame(2, $holding->fresh()->boardCapacity());
        $this->assertSame(3, $holding->fresh()->boardMemberCount());

        $this->actingAs($target['ceo']->user)
            ->post(route('career.corporation.subsidiary-invites.accept', $invite), [
                'successor_id' => $target['successor']->id,
            ])
            ->assertSessionHas('error', 'The holding board has no open seat.');

        $this->assertNull($target['corp']->fresh()->parent_trust_id);
        $this->assertSame(CorporationSubsidiaryInvite::STATUS_PENDING, $invite->fresh()->status);
    }

    public function test_subsidiary_ceo_death_succeeds_or_deletes_and_reconciles_holding(): void
    {
        $city = $this->makeCity('subsidiary-death');
        $holding = $this->makeHoldingCompany($city, 'Death Holding');
        $this->makeBoardMember($holding, $city, 5, 'Governor A');
        $withSuccessor = $this->makeSubsidiary($holding, $city, 'Succession Sub', true);
        $withoutSuccessor = $this->makeSubsidiary($holding, $city, 'No Succession Sub', false);

        $withSuccessor['ceo']->kill('test', 'Subsidiary CEO died during corporation test');

        $this->assertSame($withSuccessor['successor']->id, $withSuccessor['corp']->fresh()->ceo_id);
        $this->assertSame(4, $withSuccessor['successor']->fresh()->career_rank);
        $this->assertSame($holding->id, $withSuccessor['corp']->fresh()->parent_trust_id);

        $withoutSuccessor['ceo']->kill('test', 'Subsidiary CEO died during corporation test');

        $this->assertTrue(Corporation::withTrashed()->find($withoutSuccessor['corp']->id)->trashed());
        $this->assertNull($withoutSuccessor['member']->fresh()->corporation_id);
        $this->assertFalse($holding->fresh()->trashed());
        $this->assertSame($holding->id, $withSuccessor['corp']->fresh()->parent_trust_id);
    }

    public function test_holding_collapses_when_all_subsidiaries_are_lost(): void
    {
        $city = $this->makeCity('holding-collapse');
        $holding = $this->makeHoldingCompany($city, 'Collapse Holding');
        $boardA = $this->makeBoardMember($holding, $city, 5, 'Collapse Board A');
        $boardB = $this->makeBoardMember($holding, $city, 6, 'Collapse Board B');
        $firstSub = $this->makeSubsidiary($holding, $city, 'Collapse Sub A', false);
        $secondSub = $this->makeSubsidiary($holding, $city, 'Collapse Sub B', false);

        $firstSub['ceo']->kill('test', 'Subsidiary CEO died during corporation test');

        $this->assertFalse($holding->fresh()->trashed());
        $this->assertTrue(Corporation::withTrashed()->find($firstSub['corp']->id)->trashed());

        $secondSub['ceo']->kill('test', 'Subsidiary CEO died during corporation test');

        $trashedHolding = Corporation::withTrashed()->find($holding->id);
        $this->assertTrue($trashedHolding->trashed());
        $this->assertNull($boardA->fresh()->corporation_id);
        $this->assertNull($boardB->fresh()->corporation_id);
        $this->assertSame(3, $boardA->fresh()->career_rank);
        $this->assertSame($this->xpForRank(3), $boardB->fresh()->career_xp);
        $this->assertDatabaseHas('character_journals', [
            'character_id' => $boardA->id,
            'type' => 'corporation_holding_collapsed',
        ]);
        $this->assertDatabaseHas('character_journals', [
            'character_id' => $boardB->id,
            'type' => 'corporation_holding_collapsed',
        ]);
    }

    public function test_all_board_members_dead_frees_subsidiaries_and_collapses_holding(): void
    {
        $city = $this->makeCity('board-death');
        $holding = $this->makeHoldingCompany($city, 'Board Death Holding');
        $firstSub = $this->makeSubsidiary($holding, $city, 'Board Death Sub A');
        $secondSub = $this->makeSubsidiary($holding, $city, 'Board Death Sub B');
        $boardA = $this->makeBoardMember($holding, $city, 5, 'Doomed Board A');
        $boardB = $this->makeBoardMember($holding, $city, 6, 'Doomed Board B');

        $boardA->kill('test', 'Board member died during corporation test');

        $this->assertSoftDeleted('characters', ['id' => $boardA->id]);
        $this->assertFalse($holding->fresh()->trashed());
        $this->assertSame($holding->id, $firstSub['corp']->fresh()->parent_trust_id);

        $boardB->kill('test', 'Board member died during corporation test');

        $this->assertTrue(Corporation::withTrashed()->find($holding->id)->trashed());
        $this->assertNull($firstSub['corp']->fresh()->parent_trust_id);
        $this->assertNull($secondSub['corp']->fresh()->parent_trust_id);
    }

    public function test_board_overcapacity_blocks_new_intake_without_evicting_existing_board(): void
    {
        $city = $this->makeCity('overcapacity');
        $holding = $this->makeHoldingCompany($city, 'Overcapacity Holding');
        $firstSub = $this->makeSubsidiary($holding, $city, 'Overcapacity Sub A');
        $secondSub = $this->makeSubsidiary($holding, $city, 'Overcapacity Sub B');
        $boardA = $this->makeBoardMember($holding, $city, 5, 'Over Board A');
        $boardB = $this->makeBoardMember($holding, $city, 5, 'Over Board B');
        $boardC = $this->makeBoardMember($holding, $city, 6, 'Over Board C');

        $this->assertSame(3, $holding->fresh()->boardCapacity());
        $this->assertSame(3, $holding->fresh()->boardMemberCount());

        $this->actingAs($boardA->user)
            ->post(route('career.corporation.subsidiaries.kick'), [
                'subsidiary_id' => $secondSub['corp']->id,
            ])
            ->assertRedirect(route('career.corporate'));

        $this->assertNull($secondSub['corp']->fresh()->parent_trust_id);
        $this->assertSame(2, $holding->fresh()->boardCapacity());
        $this->assertSame(3, $holding->fresh()->boardMemberCount());
        $this->assertSame($holding->id, $boardB->fresh()->corporation_id);
        $this->assertSame($holding->id, $boardC->fresh()->corporation_id);

        $this->actingAs($boardA->user)
            ->post(route('career.corporation.board-promotions.create'), [
                'subsidiary_id' => $firstSub['corp']->id,
                'successor_id' => $firstSub['successor']->id,
            ])
            ->assertSessionHas('error', 'The holding board has no open seat.');
    }

    public function test_holding_board_cannot_kick_last_subsidiary_or_leave_from_company_actions(): void
    {
        $city = $this->makeCity('last-subsidiary');
        $holding = $this->makeHoldingCompany($city, 'Last Sub Holding');
        $subsidiary = $this->makeSubsidiary($holding, $city, 'Only Sub');
        $boardMember = $this->makeBoardMember($holding, $city, 5, 'Locked Board');

        $this->actingAs($boardMember->user)
            ->post(route('career.corporation.subsidiaries.kick'), [
                'subsidiary_id' => $subsidiary['corp']->id,
            ])
            ->assertSessionHas('error', 'You cannot kick out your last subsidiary, what would you be holding?!');

        $this->assertSame($holding->id, $subsidiary['corp']->fresh()->parent_trust_id);

        $this->actingAs($boardMember->user)
            ->post(route('career.corporation.quit'))
            ->assertSessionHas('error', 'Board members cannot resign — exit only happens through holding collapse or death.');

        $this->assertSame($holding->id, $boardMember->fresh()->corporation_id);
    }

    public function test_only_ceo_can_update_banner_and_board_notes(): void
    {
        $city = $this->makeCity('banner');
        $ceo = $this->makeCeo($city);
        $corp = $this->makeCorp($ceo, 'Banner Corp');
        $cfo = $this->attachMember($corp, $this->makeReadyRank3($city), Corporation::POSITION_CFO);

        $this->actingAs($cfo->user)
            ->post(route('career.corporation.banner'), ['image_url' => 'https://example.com/cfo.jpg'])
            ->assertSessionHas('error', 'Only the CEO can update the banner.');

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.banner'), ['image_url' => 'https://example.com/ceo.jpg'])
            ->assertSessionHas('success');

        $this->actingAs($ceo->user)
            ->post(route('career.corporation.board-notes'), ['board_notes' => 'Keep it short.'])
            ->assertSessionHas('success');

        $corp->refresh();
        $this->assertSame('https://example.com/ceo.jpg', $corp->image_url);
        $this->assertSame('Keep it short.', $corp->board_notes);
    }

    // ─── Holding-company distribute / consolidated funds ────────────────────

    public function test_holding_distribute_drains_consolidated_pot_in_id_order(): void
    {
        $city = $this->makeCity('holding-drain');
        $holding = $this->makeHoldingCompany($city, 'Drain Holding');
        $subA = $this->makeSubsidiary($holding, $city, 'Sub A');
        $subB = $this->makeSubsidiary($holding, $city, 'Sub B');

        // Order subs by id and seed pots so the drain has to reach across.
        $orderedSubs = collect([$subA['corp'], $subB['corp']])->sortBy('id')->values();
        $firstSub = $orderedSubs->first();
        $secondSub = $orderedSubs->last();

        $holding->update(['slush_fund' => 100_000]);
        $firstSub->update(['slush_fund' => 200_000]);
        $secondSub->update(['slush_fund' => 300_000]);

        $sender = $this->makeBoardMember($holding, $city, 5, 'Sender Director');
        $receiver = $this->makeBoardMember($holding, $city, 5, 'Receiver Director');

        // Need: holding 100k + firstSub 200k + 50k of secondSub.
        $this->actingAs($sender->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $receiver->id,
                'amount' => 350_000,
            ])
            ->assertSessionHas('success');

        $this->assertSame(0, (int) $holding->fresh()->slush_fund);
        $this->assertSame(0, (int) $firstSub->fresh()->slush_fund);
        $this->assertSame(250_000, (int) $secondSub->fresh()->slush_fund);
        $this->assertSame(350_000, (int) $receiver->fresh()->dirty_cash);
    }

    public function test_holding_distribute_rejects_amount_above_consolidated(): void
    {
        $city = $this->makeCity('holding-overdraft');
        $holding = $this->makeHoldingCompany($city, 'Overdraft Holding');
        $subA = $this->makeSubsidiary($holding, $city, 'Sub A');
        $subB = $this->makeSubsidiary($holding, $city, 'Sub B');

        $holding->update(['slush_fund' => 100_000]);
        $subA['corp']->update(['slush_fund' => 200_000]);
        $subB['corp']->update(['slush_fund' => 300_000]);

        $sender = $this->makeBoardMember($holding, $city, 5);
        $receiver = $this->makeBoardMember($holding, $city, 5);

        // Consolidated total = 600k. Asking for 700k should bounce.
        $this->actingAs($sender->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $receiver->id,
                'amount' => 700_000,
            ])
            ->assertSessionHas('error', 'Insufficient consolidated funds.');

        // Pots untouched.
        $this->assertSame(100_000, (int) $holding->fresh()->slush_fund);
        $this->assertSame(200_000, (int) $subA['corp']->fresh()->slush_fund);
        $this->assertSame(300_000, (int) $subB['corp']->fresh()->slush_fund);
        $this->assertSame(0, (int) $receiver->fresh()->dirty_cash);
    }

    public function test_subsidiary_ceo_distribute_pulls_only_from_their_own_pot(): void
    {
        $city = $this->makeCity('sub-isolation');
        $holding = $this->makeHoldingCompany($city, 'Isolation Holding');
        $subA = $this->makeSubsidiary($holding, $city, 'Sub A');
        $subB = $this->makeSubsidiary($holding, $city, 'Sub B');

        // Sub A CEO has a tiny pot; the holding and Sub B have huge pots.
        // The operating-branch validation must reject anything beyond Sub A's
        // own slush — the consolidated number is irrelevant here.
        $subA['corp']->update(['slush_fund' => 50_000]);
        $subB['corp']->update(['slush_fund' => 1_000_000]);
        $holding->update(['slush_fund' => 1_000_000]);

        // Try to distribute more than Sub A holds → operating branch error.
        $this->actingAs($subA['ceo']->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $subA['successor']->id,
                'amount' => 60_000,
            ])
            ->assertSessionHas('error', 'Insufficient slush funds.');

        $this->assertSame(50_000, (int) $subA['corp']->fresh()->slush_fund);

        // Distribute within Sub A's own pot succeeds and leaves the holding
        // and sibling subsidiary completely untouched.
        $this->actingAs($subA['ceo']->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $subA['successor']->id,
                'amount' => 30_000,
            ])
            ->assertSessionHas('success');

        $this->assertSame(20_000, (int) $subA['corp']->fresh()->slush_fund);
        $this->assertSame(1_000_000, (int) $subB['corp']->fresh()->slush_fund);
        $this->assertSame(1_000_000, (int) $holding->fresh()->slush_fund);
        $this->assertSame(30_000, (int) $subA['successor']->fresh()->dirty_cash);
    }

    public function test_holding_distribute_rejects_self_and_non_board_targets(): void
    {
        $city = $this->makeCity('holding-targets');
        $holding = $this->makeHoldingCompany($city, 'Target Holding');
        $sub = $this->makeSubsidiary($holding, $city, 'Sub T');
        $holding->update(['slush_fund' => 500_000]);

        $sender = $this->makeBoardMember($holding, $city, 5, 'Sender');
        $otherBoard = $this->makeBoardMember($holding, $city, 5, 'Other Board');

        // Self target.
        $this->actingAs($sender->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $sender->id,
                'amount' => 100,
            ])
            ->assertSessionHas('error', 'You cannot distribute funds to yourself.');

        // Subsidiary CEO is not on the holding board — reject.
        $this->actingAs($sender->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $sub['ceo']->id,
                'amount' => 100,
            ])
            ->assertSessionHas('error', 'Target must be another board member of this holding company.');

        // Subsidiary's rank-2 member — also not on the board.
        $this->actingAs($sender->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $sub['member']->id,
                'amount' => 100,
            ])
            ->assertSessionHas('error', 'Target must be another board member of this holding company.');

        // Another board member is the only valid target.
        $this->actingAs($sender->user)
            ->post(route('career.corporation.distribute'), [
                'member_id' => $otherBoard->id,
                'amount' => 100,
            ])
            ->assertSessionHas('success');

        $this->assertSame(100, (int) $otherBoard->fresh()->dirty_cash);
    }

    public function test_corporate_audit_target_query_skips_holdings(): void
    {
        $city = $this->makeCity('audit-skip');
        $holding = $this->makeHoldingCompany($city, 'Skipped Holding');
        $holding->update(['slush_fund' => 1_000_000]);

        // The audit's target picker is the read side of CorporateAudit::execute.
        // Even with a fat slush_fund, a holding must not be eligible — the
        // CrimeRecord chain assumes a CEO to charge.
        $picked = Corporation::where('home_city_id', $city->id)
            ->where('is_holding_company', false)
            ->where('slush_fund', '>', 0)
            ->orderByDesc('slush_fund')
            ->first();

        $this->assertNull($picked);

        // But an operating company in the same city with a smaller pot
        // is still picked, proving the filter is the only thing excluding
        // the holding.
        $opCeo = $this->makeReadyCeo($city, 'Op CEO');
        $opCorp = $this->makeCorp($opCeo, 'Operating Co', slushFund: 50);

        $picked = Corporation::where('home_city_id', $city->id)
            ->where('is_holding_company', false)
            ->where('slush_fund', '>', 0)
            ->orderByDesc('slush_fund')
            ->first();

        $this->assertNotNull($picked);
        $this->assertSame($opCorp->id, $picked->id);
    }

    public function test_investment_fraud_rejects_holding_board_members(): void
    {
        $city = $this->makeCity('fraud-reject');
        $holding = $this->makeHoldingCompany($city, 'Fraud Holding');
        $this->makeSubsidiary($holding, $city, 'Sub Fraud');
        $boardMember = $this->makeBoardMember($holding, $city, 5);
        $boardMember->loadMissing('corporation');

        $action = new \App\Actions\InvestmentFraud();
        $check = $action->canExecute($boardMember);

        $this->assertFalse($check['valid']);
        $this->assertSame(
            'Your holding company is too sophisticated for such a crude scheme.',
            $check['error'],
        );
    }

    public function test_merger_creates_tier_three_headquarters_for_holding(): void
    {
        $city = $this->makeCity('merger-hq');
        $merger = $this->completeMerger($city);
        $holding = $merger['holding']->fresh();

        $this->assertSame(3, (int) $holding->hq_tier);

        $hq = CorporationProperty::where('corporation_id', $holding->id)
            ->where('type', CorporationProperty::TYPE_HQ)
            ->first();

        $this->assertNotNull($hq, 'Holding company should receive a starting HQ on merger.');
        $this->assertSame(3, (int) $hq->tier);
        $this->assertSame(CorporationProperty::CONDITION_CONSTRUCTED, $hq->condition);
    }

    public function test_director_vote_requires_final_ready_holding(): void
    {
        $city = $this->makeCity('director-gates');
        $first = $this->makeTrustReadyHolding($city, 'First Director Holding');
        $second = $this->makeTrustReadyHolding($city, 'Second Director Holding');

        $this->actingAs($first['board'][0]->user)
            ->post(route('career.corporation.trust-votes.start'))
            ->assertSessionHas('error', 'Only the final holding company can appoint a Director of the Board.');

        $second['holding']->delete();

        $first['holding']->properties()
            ->where('type', CorporationProperty::TYPE_HQ)
            ->update(['condition' => CorporationProperty::CONDITION_DEFAULTED]);

        $this->actingAs($first['board'][0]->user)
            ->post(route('career.corporation.trust-votes.start'))
            ->assertSessionHas('error', 'The holding company needs a completed headquarters.');

        $this->assertFalse(CorporationTrustVote::where('holding_company_id', $first['holding']->id)->exists());
    }

    public function test_director_vote_unlocks_rank_seven_promotion_and_creates_trust(): void
    {
        $city = $this->makeCity('director-flow');
        $setup = $this->makeTrustReadyHolding($city, 'Final Director Holding');
        $holding = $setup['holding'];
        [$voterOne, $candidate, $voterThree] = $setup['board'];

        $this->actingAs($candidate->user)
            ->post(route('career.promote'), ['option' => 1])
            ->assertSessionHas('error');

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.start'))
            ->assertSessionHas('success', 'Director vote opened.');

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $candidate->id])
            ->assertSessionHas('success', 'Vote recorded.');

        $this->actingAs($voterThree->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $candidate->id])
            ->assertSessionHas('success', 'Director vote completed.');

        $vote = CorporationTrustVote::where('holding_company_id', $holding->id)->first();
        $this->assertSame(CorporationTrustVote::STATUS_COMPLETED, $vote->status);
        $this->assertSame($candidate->id, (int) $vote->winner_id);

        $this->actingAs($candidate->user)
            ->get(route('career.corporate'))
            ->assertRedirect(route('career.promote'));

        $this->promoteThroughScreen($candidate->fresh(), 7);

        $candidate->refresh();
        $holding->refresh();
        $vote->refresh();

        $this->assertSame(7, (int) $candidate->career_rank);
        $this->assertSame(Corporation::POSITION_DIRECTOR_OF_BOARD, $candidate->corporation_position);
        $this->assertSame($holding->id, (int) $candidate->corporation_id);
        $this->assertNotNull($vote->promotion_completed_at);
        $this->assertNull($holding->parent_trust_id);
        $this->assertSame($candidate->id, (int) $holding->directorOfBoard()?->id);
    }

    public function test_director_vote_blocks_when_global_director_already_exists(): void
    {
        $city = $this->makeCity('director-global');
        $setup = $this->makeTrustReadyHolding($city, 'Global Director Holding');

        $rogueCity = $this->makeCity('director-global-rogue');
        $rogueCeo = $this->makeReadyCeo($rogueCity, 'Rogue Director CEO');
        $rogueCorp = $this->makeCorp($rogueCeo, 'Rogue Director Corp');
        $rogueDirector = $this->makeCharacter(
            city: $rogueCity,
            rank: 7,
            careerXp: $this->xpForRank(7),
            totalExp: $this->xpForRank(7),
            name: 'Existing DOTB ' . uniqid(),
        );
        $rogueDirector->update([
            'corporation_id' => $rogueCorp->id,
            'corporation_position' => Corporation::POSITION_DIRECTOR_OF_BOARD,
            'corporation_reports_to_id' => null,
        ]);

        $this->actingAs($setup['board'][0]->user)
            ->post(route('career.corporation.trust-votes.start'))
            ->assertSessionHas('error', 'The Director of the Board seat is already occupied.');
    }

    public function test_director_vote_blocks_self_votes_and_duplicate_ballots(): void
    {
        $city = $this->makeCity('director-ballots');
        $setup = $this->makeTrustReadyHolding($city, 'Ballot Holding');
        [$voterOne, $candidate] = $setup['board'];

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.start'))
            ->assertSessionHas('success');

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $voterOne->id])
            ->assertSessionHas('error', 'You cannot vote for yourself.');

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $candidate->id])
            ->assertSessionHas('success', 'Vote recorded.');

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $candidate->id])
            ->assertSessionHas('error', 'You already voted in this attempt.');

        $this->assertSame(
            1,
            DB::table('corporation_trust_vote_ballots')
                ->where('voter_id', $voterOne->id)
                ->count(),
        );
    }

    // ─── Concurrent / replacement / freed-subsidiary edge cases ─────────────

    public function test_director_promotion_is_cancelled_if_another_holding_emerges_after_tally(): void
    {
        $city = $this->makeCity('director-race');
        $setup = $this->makeTrustReadyHolding($city, 'Race Winner Holding');
        $holding = $setup['holding'];
        [$voterOne, $candidate, $voterThree] = $setup['board'];

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.start'))
            ->assertSessionHas('success');

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $candidate->id])
            ->assertSessionHas('success');

        $this->actingAs($voterThree->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $candidate->id])
            ->assertSessionHas('success', 'Director vote completed.');

        $vote = CorporationTrustVote::where('holding_company_id', $holding->id)->first();
        $this->assertSame(CorporationTrustVote::STATUS_COMPLETED, $vote->status);

        $this->makeTrustReadyHolding($this->makeCity('director-rival'), 'Late Rival Holding');

        $this->actingAs($candidate->user)
            ->post(route('career.promote'), ['option' => 1])
            ->assertSessionHas('error', 'Only the final holding company can appoint a Director of the Board.');

        $this->assertSame(CorporationTrustVote::STATUS_CANCELLED, $vote->fresh()->status);
        $this->assertSame(6, (int) $candidate->fresh()->career_rank);
        $this->assertNull($holding->fresh()->parent_trust_id);
    }

    public function test_director_promotion_is_cancelled_if_hq_defaults_after_tally(): void
    {
        $city = $this->makeCity('director-defaulted-hq');
        $setup = $this->makeTrustReadyHolding($city, 'Defaulted HQ Holding');
        $holding = $setup['holding'];
        $candidate = $setup['board'][1];
        $vote = $this->completeDirectorVote($holding, $setup['board'], $candidate);

        $holding->properties()
            ->where('type', CorporationProperty::TYPE_HQ)
            ->update(['condition' => CorporationProperty::CONDITION_DEFAULTED]);

        $this->actingAs($candidate->user)
            ->post(route('career.promote'), ['option' => 1])
            ->assertSessionHas('error', 'The holding company needs a completed headquarters.');

        $this->assertSame(CorporationTrustVote::STATUS_CANCELLED, $vote->fresh()->status);
        $this->assertSame(6, (int) $candidate->fresh()->career_rank);
        $this->assertNull($holding->fresh()->directorOfBoard());
    }

    public function test_director_promotion_is_cancelled_if_subsidiary_count_drops_after_tally(): void
    {
        $city = $this->makeCity('director-subsidiary-loss');
        $setup = $this->makeTrustReadyHolding($city, 'Subsidiary Loss Holding');
        $holding = $setup['holding'];
        $candidate = $setup['board'][1];
        $vote = $this->completeDirectorVote($holding, $setup['board'], $candidate);

        $setup['subsidiaries'][0]['corp']->delete();

        $this->actingAs($candidate->user)
            ->post(route('career.promote'), ['option' => 1])
            ->assertSessionHas('error', 'The holding company requires at least 2 active subsidiaries.');

        $this->assertSame(CorporationTrustVote::STATUS_CANCELLED, $vote->fresh()->status);
        $this->assertSame(6, (int) $candidate->fresh()->career_rank);
        $this->assertNull($holding->fresh()->directorOfBoard());
    }

    public function test_director_promotion_is_cancelled_if_board_drops_below_three_after_tally(): void
    {
        $city = $this->makeCity('director-board-loss');
        $setup = $this->makeTrustReadyHolding($city, 'Board Loss Holding');
        $holding = $setup['holding'];
        [$voterOne, $candidate] = $setup['board'];
        $vote = $this->completeDirectorVote($holding, $setup['board'], $candidate);

        $voterOne->fresh()->kill('test', 'Board quorum loss test');

        $this->actingAs($candidate->user)
            ->post(route('career.promote'), ['option' => 1])
            ->assertSessionHas('error', 'The board must complete a Director vote before you can advance.');

        $this->assertSame(CorporationTrustVote::STATUS_CANCELLED, $vote->fresh()->status);
        $this->assertSame(6, (int) $candidate->fresh()->career_rank);
        $this->assertNull($holding->fresh()->directorOfBoard());
    }

    public function test_director_promotion_is_cancelled_if_winner_dies_after_tally(): void
    {
        $city = $this->makeCity('director-winner-loss');
        $setup = $this->makeTrustReadyHolding($city, 'Winner Loss Holding');
        $holding = $setup['holding'];
        $candidate = $setup['board'][1];
        $vote = $this->completeDirectorVote($holding, $setup['board'], $candidate);

        $candidate->fresh()->kill('test', 'Director winner loss test');

        $this->assertSame(CorporationTrustVote::STATUS_CANCELLED, $vote->fresh()->status);
        $this->assertNull($holding->fresh()->directorOfBoard());
        $this->assertNull(
            CorporationTrustVote::promotionPending()
                ->where('holding_company_id', $holding->id)
                ->first()
        );
    }

    public function test_director_vote_fails_when_all_ballots_land_without_majority(): void
    {
        $city = $this->makeCity('director-no-majority');
        $setup = $this->makeTrustReadyHolding($city, 'No Majority Holding');
        $holding = $setup['holding'];
        [$voterOne, $voterTwo, $voterThree] = $setup['board'];

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.start'))
            ->assertSessionHas('success', 'Director vote opened.');

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $voterTwo->id])
            ->assertSessionHas('success', 'Vote recorded.');

        $this->actingAs($voterTwo->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $voterThree->id])
            ->assertSessionHas('success', 'Vote recorded.');

        $this->actingAs($voterThree->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $voterOne->id])
            ->assertSessionHas('error', 'Director vote closed without a majority.');

        $vote = CorporationTrustVote::where('holding_company_id', $holding->id)->first();
        $this->assertSame(CorporationTrustVote::STATUS_FAILED, $vote->status);
        $this->assertNull($vote->winner_id);
        $this->assertNull($holding->fresh()->directorOfBoard());
    }

    public function test_director_death_allows_a_new_vote(): void
    {
        $city = $this->makeCity('director-death');
        $setup = $this->makeTrustReadyHolding($city, 'Continuity Holding');
        $holding = $setup['holding'];
        [$voterOne, $candidate, $voterThree] = $setup['board'];

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.start'))
            ->assertSessionHas('success');

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $candidate->id])
            ->assertSessionHas('success');

        $this->actingAs($voterThree->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $candidate->id])
            ->assertSessionHas('success', 'Director vote completed.');

        $this->promoteThroughScreen($candidate->fresh(), 7);
        $holding->refresh();
        $this->assertNull($holding->parent_trust_id);
        $this->assertSame($candidate->id, (int) $holding->directorOfBoard()?->id);

        $candidate->fresh()->kill('test', 'Director continuity test');

        $holding->refresh();
        $this->assertNull($holding->parent_trust_id);
        $this->assertNull($holding->directorOfBoard());

        $replacement = $this->makeReadyChairman($holding, $city, 'Replacement Director');

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.start'))
            ->assertSessionHas('success', 'Director vote opened.');

        $this->actingAs($voterOne->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $replacement->id])
            ->assertSessionHas('success', 'Vote recorded.');

        $this->actingAs($voterThree->user)
            ->post(route('career.corporation.trust-votes.vote'), ['candidate_id' => $replacement->id])
            ->assertSessionHas('success', 'Director vote completed.');

        $this->promoteThroughScreen($replacement->fresh(), 7);

        $holding->refresh();
        $this->assertNull($holding->parent_trust_id);
        $this->assertSame(Corporation::POSITION_DIRECTOR_OF_BOARD, $replacement->fresh()->corporation_position);
        $this->assertSame($replacement->id, (int) $holding->directorOfBoard()?->id);
    }

    public function test_concurrent_mergers_in_same_city_only_let_the_first_holding_form(): void
    {
        // Two independent merger pairs both want to crown a holding in the
        // same city. The first to reach acceptance gets the city slot; the
        // second must hit the "already has a holding" gate at acceptance,
        // even though both proposals were valid at creation time.
        $city = $this->makeCity('concurrent-merger');

        $left = $this->makeMergeReadyCompany($city, 'Concurrent Left');
        $right = $this->makeMergeReadyCompany($city, 'Concurrent Right');
        $third = $this->makeMergeReadyCompany($city, 'Concurrent Third');
        $fourth = $this->makeMergeReadyCompany($city, 'Concurrent Fourth');

        $proposeFirst = $this->actingAs($left['ceo']->user)
            ->post(route('career.corporation.merger.propose'), [
                'target_corporation_id' => $right['corp']->id,
                'requester_successor_id' => $left['successor']->id,
                'holding_name' => 'First Holding-' . substr(uniqid(), -6),
                'holding_image_url' => 'https://example.com/first.jpg',
            ])
            ->assertSessionHas('success');

        $proposeSecond = $this->actingAs($third['ceo']->user)
            ->post(route('career.corporation.merger.propose'), [
                'target_corporation_id' => $fourth['corp']->id,
                'requester_successor_id' => $third['successor']->id,
                'holding_name' => 'Second Holding-' . substr(uniqid(), -6),
                'holding_image_url' => 'https://example.com/second.jpg',
            ])
            ->assertSessionHas('success');

        $firstRequest = CorporationMergerRequest::where('requester_corporation_id', $left['corp']->id)->first();
        $secondRequest = CorporationMergerRequest::where('requester_corporation_id', $third['corp']->id)->first();

        $this->assertNotNull($firstRequest);
        $this->assertNotNull($secondRequest);

        // First merger reaches acceptance — claims the city slot.
        $this->actingAs($right['ceo']->user)
            ->post(route('career.corporation.merger.accept', $firstRequest), [
                'target_successor_id' => $right['successor']->id,
            ])
            ->assertRedirect(route('career.corporate'));

        $holdingsInCity = Corporation::where('home_city_id', $city->id)
            ->where('is_holding_company', true)
            ->whereNull('deleted_at')
            ->get();
        $this->assertCount(1, $holdingsInCity);

        // Second merger now races to accept and must lose to the gate.
        $this->actingAs($fourth['ceo']->user)
            ->post(route('career.corporation.merger.accept', $secondRequest), [
                'target_successor_id' => $fourth['successor']->id,
            ])
            ->assertSessionHas('error', 'That headquarters city already has a holding company.');

        // Still one holding, the original.
        $this->assertSame(
            1,
            Corporation::where('home_city_id', $city->id)
                ->where('is_holding_company', true)
                ->whereNull('deleted_at')
                ->count(),
            'A second concurrent merger must not bypass the one-holding-per-city rule.',
        );

        // Losing pair stays as Phase 1 operating companies — no parent_trust_id.
        $this->assertNull($third['corp']->fresh()->parent_trust_id);
        $this->assertNull($fourth['corp']->fresh()->parent_trust_id);
        $this->assertSame(
            CorporationMergerRequest::STATUS_PENDING,
            $secondRequest->fresh()->status,
            'The losing merger request should remain pending so the pair can retry against another holding-less moment.',
        );
    }

    public function test_freed_subsidiary_can_be_re_invited_by_the_holding_it_just_left(): void
    {
        // Subsidiary invites are city-scoped (controller line 2286), so the
        // realistic "freed → re-attached" path is: a holding kicks one of
        // its subsidiaries, the company drops to Phase 1 with no parent,
        // and is later re-invited back into the same holding under the
        // remaining-board-capacity gate. Tests that being-freed isn't a
        // permanent identity loss — the corp can rejoin the holding network.
        $city = $this->makeCity('freed-subsidiary');

        $holding = $this->makeHoldingCompany($city, 'Reabsorber Holding');
        $board = $this->makeBoardMember($holding, $city, 5, 'Reabsorber Board');
        // 3 subsidiaries so the kick passes the last-subsidiary guard.
        $stay = $this->makeSubsidiary($holding, $city, 'Stay Sub');
        $alsoStay = $this->makeSubsidiary($holding, $city, 'AlsoStay Sub');
        $cast = $this->makeSubsidiary($holding, $city, 'Cast Out Sub');

        // Kick the cast-out subsidiary.
        $this->actingAs($board->user)
            ->post(route('career.corporation.subsidiaries.kick'), [
                'subsidiary_id' => $cast['corp']->id,
            ])
            ->assertSessionHas('success');

        $this->assertNull(
            $cast['corp']->fresh()->parent_trust_id,
            'Kicked subsidiary detached from old holding.',
        );

        // Same holding re-invites the freed subsidiary.
        $this->actingAs($board->user)
            ->post(route('career.corporation.subsidiary-invites.create'), [
                'target_corporation_id' => $cast['corp']->id,
            ])
            ->assertSessionHas('success');

        $invite = CorporationSubsidiaryInvite::where('target_corporation_id', $cast['corp']->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($invite);

        // Cast-out CEO accepts the invite.
        $this->actingAs($cast['ceo']->user)
            ->post(route('career.corporation.subsidiary-invites.accept', $invite), [
                'successor_id' => $cast['successor']->id,
            ])
            ->assertRedirect(route('career.corporate'));

        $this->assertSame(
            $holding->id,
            $cast['corp']->fresh()->parent_trust_id,
            'Freed subsidiary re-attached to the holding it had left.',
        );
        $this->assertSame(
            CorporationSubsidiaryInvite::STATUS_COMPLETED,
            $invite->fresh()->status,
        );
    }

    public function test_holding_collapse_frees_the_city_slot_for_a_fresh_merger(): void
    {
        // A holding's subsidiaries are all destroyed → the holding collapses
        // via reconcileHoldingLifecycle. The "one holding per city" gate must
        // then permit a brand-new merger between two unrelated operating
        // companies in the same city, demonstrating the slot is reclaimable.
        $city = $this->makeCity('replacement-holding');

        $original = $this->makeHoldingCompany($city, 'Original Holding');
        $subA = $this->makeSubsidiary($original, $city, 'Original Sub A');
        $subB = $this->makeSubsidiary($original, $city, 'Original Sub B');
        $this->makeBoardMember($original, $city, 5, 'Original Board');

        // Kill every subsidiary, then trigger reconciliation.
        $subA['corp']->fresh()->delete();
        $subB['corp']->fresh()->delete();
        $original->fresh()->reconcileHoldingLifecycle();

        $this->assertTrue(
            Corporation::withTrashed()->find($original->id)?->trashed() === true,
            'Original holding should have collapsed.',
        );
        $this->assertSame(
            0,
            Corporation::where('home_city_id', $city->id)
                ->where('is_holding_company', true)
                ->whereNull('deleted_at')
                ->count(),
            'No live holding remains in the city.',
        );

        // Two unrelated operating companies merge in the now-empty city.
        $merger = $this->completeMerger($city);

        $newHolding = Corporation::where('id', $merger['holding']->id)->first();
        $this->assertNotNull($newHolding);
        $this->assertTrue((bool) $newHolding->is_holding_company);
        $this->assertSame($city->id, (int) $newHolding->home_city_id);

        // And there's still exactly one live holding in the city (the new one).
        $this->assertSame(
            1,
            Corporation::where('home_city_id', $city->id)
                ->where('is_holding_company', true)
                ->whereNull('deleted_at')
                ->count(),
        );

        // Tier-3 HQ assertion still holds for the replacement.
        $this->assertSame(3, (int) $newHolding->hq_tier);
        $this->assertNotNull(
            CorporationProperty::where('corporation_id', $newHolding->id)
                ->where('type', CorporationProperty::TYPE_HQ)
                ->where('tier', 3)
                ->first(),
        );
    }

}
