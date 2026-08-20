<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\CrimeRecord;
use App\Models\User;
use App\Models\Business;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArrestSystemTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;
    private int  $policeCareerId;

    private int  $unemployedCareerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $this->city = City::create([
            'name'       => 'TestCity-' . uniqid(),
            'slug'       => 'testcity-' . uniqid(),
            'crime_rate' => 50,
        ]);

        $policeCareer = DB::table('careers')->whereRaw('LOWER(code) = ?', ['police'])->first();
        $this->assertNotNull($policeCareer, 'Police career must exist in DB');
        $this->policeCareerId = $policeCareer->id;

        $unemployedCareer = DB::table('careers')->whereRaw('LOWER(code) = ?', ['unemployed'])->first();
        $this->assertNotNull($unemployedCareer, 'Unemployed career must exist in DB');
        $this->unemployedCareerId = $unemployedCareer->id;
    }

    private function makeCharacter(
        ?int $careerId = null,
        int $rank         = 0,
        int $intelligence = 1_000,
        int $luck         = 1_000,
        int $offense      = 1_000,
        int $defense      = 1_000,
        array $stats      = []
    ): Character {
        $user = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => uniqid('sess_', true),
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'TestChar-' . uniqid(),
            'gender'       => 'male',
            'city_id'      => $this->city->id,
            'home_city_id' => $this->city->id,
            'career_id'    => $careerId ?? $this->unemployedCareerId,
            'career_rank'  => $rank,
            'health'       => 100,
            'max_health'   => 100,
            'cash_on_hand' => 50_000,
            'cash_in_bank' => 100_000,
        ]);

        CharacterStats::create(array_merge([
            'character_id' => $char->id,
            'intelligence' => $intelligence,
            'luck'         => $luck,
            'offense'      => $offense,
            'defense'      => $defense,
            'influence'    => 0,
        ], $stats));

        CharacterTimers::create([
            'character_id'   => $char->id,
            'next_action_at' => 0,
        ]);

        return $char;
    }

    private function makeCop(): Character
    {
        return $this->makeCharacter($this->policeCareerId, 1, intelligence: 99_999, luck: 99_999);
    }

    private function makeCriminal(): Character
    {
        return $this->makeCharacter(null, 0, defense: 1, luck: 1);
    }

    private function resetActionTimer(Character $character): void
    {
        CharacterTimers::where('character_id', $character->id)
            ->update(['next_action_at' => 0]);
        $character->unsetRelation('timers');
        if ($character->relationLoaded('user') && $character->user) {
            $character->user->unsetRelation('character');
        }
    }

    public function test_cop_can_successfully_arrest_suspect_with_warrant(): void
    {
        $cop     = $this->makeCop();
        $suspect = $this->makeCriminal();

        $warrant = CrimeRecord::create([
            'character_id' => $suspect->id,
            'city_id'      => $this->city->id,
            'type'         => 'robbery',
            'severity'     => CrimeRecord::SEV_FELONY,
            'status'       => CrimeRecord::STATUS_SENTENCED,
            'sentence'     => ['fine' => 5_000, 'jail_seconds' => 7_200],
            'sentenced_at' => now()->subHours(2), 
            'committed_at' => now(),
            'data'         => [],
        ]);

        do {
            $this->resetActionTimer($cop);
            $response = $this->actingAs($cop->user)
                ->post(route('career.police.actions.arrest'), [
                    'target_id' => "{$warrant->id}:{$suspect->id}",
                ]);
        } while (!$response->getSession()->has('success'));
        $response->assertSessionHas('success');

        $suspect->refresh();
        $warrant->refresh();

        
        $this->assertSame(45_000, (int) $suspect->cash_on_hand);
        $this->assertTrue($suspect->timers->jail_until->isFuture());

        
        $this->assertSame(CrimeRecord::STATUS_CLOSED, $warrant->status);

        
        $this->assertTrue(
            CharacterJournal::where('character_id', $suspect->id)
                ->where('type', 'arrested')
                ->whereRaw("data->>'status' = 'success'")
                ->exists()
        );
    }

    public function test_arrest_fails_if_cop_fails_stat_roll(): void
    {
        while (true) {
            
            $cop     = $this->makeCharacter($this->policeCareerId, 1, 1, 1);
            $suspect = $this->makeCharacter(null, 0, 10, 99_999, 10, 99_999);

            $warrant = CrimeRecord::create([
                'character_id' => $suspect->id,
                'city_id'      => $this->city->id,
                'type'         => 'robbery',
                'severity'     => CrimeRecord::SEV_FELONY,
                'status'       => CrimeRecord::STATUS_SENTENCED,
                'sentence'     => ['fine' => 5_000, 'jail_seconds' => 7_200],
                'sentenced_at' => now()->subHours(2),
                'committed_at' => now(),
                'data'         => [],
            ]);

            $response = $this->actingAs($cop->user)
                ->post(route('career.police.actions.arrest'), [
                    'target_id' => "{$warrant->id}:{$suspect->id}",
                ]);
            
            if ($response->getSession()->has('error') && 
                str_contains($response->getSession()->get('error'), 'slipped through your grasp')) {
                break;
            }
        }

        $suspect->refresh();
        $warrant->refresh();

        
        $this->assertSame(50_000, (int) $suspect->cash_on_hand);
        $this->assertNull($suspect->timers->jail_until);

        
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $warrant->status);

        
        $this->assertTrue(
            CharacterJournal::where('character_id', $suspect->id)
                ->where('type', 'arrested')
                ->whereRaw("data->>'status' = 'failure'")
                ->exists()
        );
    }

    public function test_cannot_arrest_without_warrant(): void
    {
        $cop     = $this->makeCop();
        $suspect = $this->makeCriminal();

        
        $this->actingAs($cop->user)
            ->post(route('career.police.actions.arrest'), [
                'target_id' => "0:{$suspect->id}",
            ])
            ->assertSessionHas('error', 'This warrant is invalid, has been appealed, or is not yet actionable.');
    }

    public function test_cannot_arrest_target_in_different_city(): void
    {
        $cop     = $this->makeCop();
        $suspect = $this->makeCriminal();
        
        
        $otherCity = City::create(['name' => 'OtherCity', 'slug' => 'othercity', 'crime_rate' => 50]);
        $suspect->update(['city_id' => $otherCity->id]);

        $warrant = CrimeRecord::create([
            'character_id' => $suspect->id,
            'city_id'      => $this->city->id, 
            'type'         => 'robbery',
            'severity'     => CrimeRecord::SEV_FELONY,
            'status'       => CrimeRecord::STATUS_SENTENCED,
            'sentence'     => ['fine' => 5_000, 'jail_seconds' => 7_200],
            'sentenced_at' => now()->subHours(2),
            'committed_at' => now(),
            'data'         => [],
        ]);

        $this->actingAs($cop->user)
            ->post(route('career.police.actions.arrest'), [
                'target_id' => "{$warrant->id}:{$suspect->id}",
            ])
            ->assertSessionHas('error', 'The suspect is not present in your city.');
    }

    public function test_multi_participant_case_remains_open_until_all_arrested(): void
    {
        $cop = $this->makeCop();
        $leadSuspect = $this->makeCriminal();
        $accomplice = $this->makeCriminal();

        $warrant = CrimeRecord::create([
            'character_id' => $leadSuspect->id,
            'city_id'      => $this->city->id,
            'type'         => 'heist',
            'severity'     => CrimeRecord::SEV_FELONY,
            'status'       => CrimeRecord::STATUS_SENTENCED,
            'sentence'     => ['fine' => 10_000, 'jail_seconds' => 14_400],
            'sentenced_at' => now()->subHours(2),
            'committed_at' => now(),
            'data'         => [
                'participants' => [$accomplice->id],
            ],
        ]);

        
        do {
            $this->resetActionTimer($cop);
            $response = $this->actingAs($cop->user)
                ->post(route('career.police.actions.arrest'), [
                    'target_id' => "{$warrant->id}:{$accomplice->id}",
                ]);
        } while (!$response->getSession()->has('success'));
        $response->assertSessionHas('success');

        $accomplice->refresh();
        $warrant->refresh();

        
        $this->assertSame(40_000, (int) $accomplice->cash_on_hand);
        $this->assertTrue($accomplice->timers->jail_until->isFuture());

        
        $this->assertSame(CrimeRecord::STATUS_SENTENCED, $warrant->status);
        $this->assertContains($accomplice->id, $warrant->data['arrested_parties']);

        
        $this->resetActionTimer($cop);
        $this->actingAs($cop->user)
            ->post(route('career.police.actions.arrest'), [
                'target_id' => "{$warrant->id}:{$accomplice->id}",
            ])
            ->assertSessionHas('error', 'This suspect has already been arrested on this warrant.');

        
        do {
            $this->resetActionTimer($cop);
            $response = $this->actingAs($cop->user)
                ->post(route('career.police.actions.arrest'), [
                    'target_id' => "{$warrant->id}:{$leadSuspect->id}",
                ]);
        } while (!$response->getSession()->has('success'));
        $response->assertSessionHas('success');

        $leadSuspect->refresh();
        $warrant->refresh();

        
        $this->assertSame(40_000, (int) $leadSuspect->cash_on_hand);
        $this->assertTrue($leadSuspect->timers->jail_until->isFuture());

        
        $this->assertSame(CrimeRecord::STATUS_CLOSED, $warrant->status);
        $this->assertContains($leadSuspect->id, $warrant->data['arrested_parties']);
        $this->assertContains($accomplice->id, $warrant->data['arrested_parties']);
    }

    public function test_arrest_targets_use_charged_parties_not_hidden_actual_parties(): void
    {
        $cop = $this->makeCop();
        $actualLead = $this->makeCriminal();
        $actualParticipant = $this->makeCriminal();
        $chargedLead = $this->makeCriminal();
        $chargedParticipant = $this->makeCriminal();

        $warrant = CrimeRecord::create([
            'character_id' => $chargedLead->id,
            'city_id' => $this->city->id,
            'type' => 'heist',
            'severity' => CrimeRecord::SEV_FELONY,
            'status' => CrimeRecord::STATUS_SENTENCED,
            'sentence' => ['fine' => 10_000, 'jail_seconds' => 14_400],
            'sentenced_at' => now()->subHours(2),
            'committed_at' => now(),
            'data' => [
                'participants' => [$chargedParticipant->id],
                'charged_suspect_ids' => [$chargedLead->id, $chargedParticipant->id],
                'actual_character_id' => $actualLead->id,
                'actual_participants' => [$actualParticipant->id],
            ],
        ]);

        $targetIds = collect((new \App\Actions\Arrest())->getShape($cop)['targets'])
            ->pluck('id')
            ->all();

        $this->assertContains("{$warrant->id}:{$chargedLead->id}", $targetIds);
        $this->assertContains("{$warrant->id}:{$chargedParticipant->id}", $targetIds);
        $this->assertNotContains("{$warrant->id}:{$actualLead->id}", $targetIds);
        $this->assertNotContains("{$warrant->id}:{$actualParticipant->id}", $targetIds);
    }

    
    
    

    public function test_can_enroll_in_police_academy(): void
    {
        $character = $this->makeCriminal();
        
        
        $character->update(['degrees' => ['arts' => ['completed_at' => now()->toIso8601String()]]]);

        $this->actingAs($character->user)
            ->post(route('city.police.enroll', $this->city->slug))
            ->assertSessionHas('success');

        $character->refresh();
        $this->assertArrayHasKey('police_academy', $character->degrees);
    }

    public function test_can_graduate_police_academy(): void
    {
        $character = $this->makeCriminal();
        
        $character->update([
            'degrees' => [
                'arts' => ['completed_at' => now()->toIso8601String()],
                'police_academy' => [
                    'city_id' => $this->city->id,
                    'cycles' => 30,
                    'completed_at' => now()->toIso8601String()
                ]
            ]
        ]);

        $this->actingAs($character->user)
            ->post(route('city.police.graduate', $this->city->slug))
            ->assertSessionHas('success');

        $character->refresh();
        $this->assertSame($this->policeCareerId, $character->career_id);
    }

    
    
    

    public function test_police_commissioner_can_step_down(): void
    {
        
        $successor = $this->makeCharacter($this->policeCareerId, 3);
        $successor->update(['career_xp' => 999_999_999]); 

        $commissioner = $this->makeCharacter($this->policeCareerId, 4);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('success');

        $commissioner->refresh();
        $this->assertSame($this->unemployedCareerId, $commissioner->career_id);

        $this->assertTrue(
            CharacterJournal::where('character_id', $commissioner->id)
                ->where('type', 'career_step_down')
                ->exists()
        );
    }

    public function test_police_commissioner_cannot_step_down_without_successor(): void
    {
        $commissioner = $this->makeCharacter($this->policeCareerId, 4);
        
        

        $this->actingAs($commissioner->user)
            ->post(route('career.police.step-down'))
            ->assertSessionHas('error'); 

        $commissioner->refresh();
        $this->assertSame($this->policeCareerId, $commissioner->career_id);
    }

    public function test_law_chief_justice_can_step_down(): void
    {
        $lawCareer = DB::table('careers')->whereRaw('LOWER(code) = ?', ['law'])->first();
        $this->assertNotNull($lawCareer);
        
        $successor = $this->makeCharacter($lawCareer->id, 3);
        $successor->update(['career_xp' => 999_999_999]); 

        $chiefJustice = $this->makeCharacter($lawCareer->id, 4);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.step-down'))
            ->assertSessionHas('success');

        $chiefJustice->refresh();
        $this->assertSame($this->unemployedCareerId, $chiefJustice->career_id);

        $this->assertTrue(
            CharacterJournal::where('character_id', $chiefJustice->id)
                ->where('type', 'career_step_down')
                ->exists()
        );
    }

    
    
    

    public function test_police_commissioner_can_dismiss_officer(): void
    {
        $commissioner = $this->makeCharacter($this->policeCareerId, 4);
        $officer = $this->makeCharacter($this->policeCareerId, 1);

        $this->actingAs($commissioner->user)
            ->post(route('career.police.dismiss'), [
                'character_id' => $officer->id
            ])
            ->assertSessionHas('success');

        $officer->refresh();
        $this->assertSame($this->unemployedCareerId, $officer->career_id);
        
        $this->assertTrue(
            CharacterJournal::where('character_id', $officer->id)
                ->where('type', 'dismissed_by')
                ->exists()
        );
    }

    public function test_police_commissioner_cannot_dismiss_another_commissioner(): void
    {
        $commissioner1 = $this->makeCharacter($this->policeCareerId, 4);
        $commissioner2 = $this->makeCharacter($this->policeCareerId, 4);

        $this->actingAs($commissioner1->user)
            ->post(route('career.police.dismiss'), [
                'character_id' => $commissioner2->id
            ])
            ->assertSessionHas('error', 'You cannot dismiss another Commissioner.');

        $commissioner2->refresh();
        $this->assertSame($this->policeCareerId, $commissioner2->career_id);
    }

    public function test_law_chief_justice_can_dismiss_member(): void
    {
        $lawCareer = DB::table('careers')->whereRaw('LOWER(code) = ?', ['law'])->first();
        $this->assertNotNull($lawCareer);

        $chiefJustice = $this->makeCharacter($lawCareer->id, 4);
        $lawyer = $this->makeCharacter($lawCareer->id, 1);

        $this->actingAs($chiefJustice->user)
            ->post(route('career.law.dismiss'), [
                'character_id' => $lawyer->id
            ])
            ->assertSessionHas('success');

        $lawyer->refresh();
        $this->assertSame($this->unemployedCareerId, $lawyer->career_id);
    }

    
    
    

    public function test_police_commissioner_can_quit_career_via_settings(): void
    {
        $business = Business::create([
            'city_id'      => $this->city->id,
            'owner_id'     => null,
            'code'         => 'police',
            'name'         => 'Police Dept',
            'slug'         => 'police-dept-slug-' . uniqid(),
            'type'         => 'service',
            'price'        => 1000,
            'is_purchasable' => true,
        ]);

        $commissioner = $this->makeCharacter($this->policeCareerId, 4);
        
        $business->update([
            'owner_id' => $commissioner->id,
            'is_purchasable' => false,
        ]);

        $this->actingAs($commissioner->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => $commissioner->display_name
            ])
            ->assertSessionHas('success');

        $commissioner->refresh();
        $this->assertSame($this->unemployedCareerId, $commissioner->career_id);
        
        $business->refresh();
        $this->assertNull($business->owner_id);
        $this->assertTrue($business->is_purchasable);
    }

    public function test_law_member_can_quit_career_via_settings(): void
    {
        $lawCareer = DB::table('careers')->whereRaw('LOWER(code) = ?', ['law'])->first();
        $this->assertNotNull($lawCareer);

        $lawyer = $this->makeCharacter($lawCareer->id, 1);

        $this->actingAs($lawyer->user)
            ->post(route('settings.quit-career'), [
                'confirmation_name' => $lawyer->display_name
            ])
            ->assertSessionHas('success');

        $lawyer->refresh();
        $this->assertSame($this->unemployedCareerId, $lawyer->career_id);
    }

    public function test_police_cannot_start_corporation(): void
    {
        $officer = $this->makeCharacter($this->policeCareerId, 1);
        $officer->update(['cash_on_hand' => 5_000_000, 'degrees' => ['finance' => ['completed_at' => now()->toIso8601String()]]]);
        $officer->stats()->update(['influence' => 100]);

        $this->actingAs($officer->user)
            ->post(route('corporation.found'), [
                'name' => 'CopCorp'
            ])
            ->assertSessionHas('error', 'Police officers and Law career members cannot start a corporation.');

        $officer->refresh();
        $this->assertNull($officer->corporation_id);
    }

    public function test_police_cannot_switch_careers_via_university(): void
    {
        $officer = $this->makeCharacter($this->policeCareerId, 1);
        
        $officer->update([
            'degrees' => [
                'finance' => [
                    'city_id' => $this->city->id,
                    'cycles' => 100,
                    'completed_at' => now()->toIso8601String()
                ]
            ]
        ]);

        $this->actingAs($officer->user)
            ->post(route('city.university.start-career', $this->city->slug), [
                'degree_code' => 'finance'
            ])
            ->assertSessionHas('error', 'You cannot start a new career while serving on the force. Step down or force quit from settings instead.');

        $officer->refresh();
        $this->assertSame($this->policeCareerId, $officer->career_id);
    }
}
