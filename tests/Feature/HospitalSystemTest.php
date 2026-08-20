<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Career;
use App\Models\Character;
use App\Models\CharacterJournal;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class HospitalSystemTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;
    private int  $healthcareCareerId;
    private int  $unemployedCareerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->city = City::create([
            'name'       => 'MedCity-' . uniqid(),
            'slug'       => 'med-city-' . uniqid(),
            'crime_rate' => 0,
        ]);

        $healthcare = DB::table('careers')->where('code', 'healthcare')->first();
        $this->assertNotNull($healthcare, 'Healthcare career must be seeded');
        $this->healthcareCareerId = $healthcare->id;

        $unemployed = DB::table('careers')->where('code', 'unemployed')->first();
        $this->assertNotNull($unemployed, 'Unemployed career must be seeded');
        $this->unemployedCareerId = $unemployed->id;
    }

    

    private function makeCharacter(
        City   $city,
        int    $careerId      = 0,
        int    $rank          = 1,
        int    $cashOnHand    = 100_000,
        int    $cashInBank    = 0,
        int    $health        = 80,
        int    $maxHealth     = 100,
        string $gender        = 'male',
        ?int   $homeCityId    = null,
        int    $careerXp      = 5_000,
    ): Character {
        $user = User::factory()->create();

        $char = Character::create([
            'user_id'      => $user->id,
            'display_name' => 'TestChar-' . uniqid(),
            'gender'       => $gender,
            'city_id'      => $city->id,
            'home_city_id' => $homeCityId ?? $city->id,
            'career_id'    => $careerId ?: $this->unemployedCareerId,
            'career_rank'  => $rank,
            'career_xp'    => $careerXp,
            'health'       => $health,
            'max_health'   => $maxHealth,
            'cash_on_hand' => $cashOnHand,
            'cash_in_bank' => $cashInBank,
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'intelligence' => 1_000,
            'luck'         => 1_000,
            'offense'      => 1_000,
            'defense'      => 1_000,
            'influence'    => 0,
        ]);

        CharacterTimers::create(['character_id' => $char->id]);

        return $char;
    }

    private function makeHealthcareChar(
        City $city,
        int  $rank         = 3,
        int  $cashOnHand   = 100_000,
        int  $careerXp     = 10_000,
        ?int $homeCityId   = null,
    ): Character {
        return $this->makeCharacter(
            $city,
            careerId:   $this->healthcareCareerId,
            rank:       $rank,
            cashOnHand: $cashOnHand,
            careerXp:   $careerXp,
            homeCityId: $homeCityId,
        );
    }

    private function makeHospital(
        City  $city,
        int   $surgeryFee = 10_000,
        int   $genderFee  = 50_000,
        ?int  $ownerId    = null,
    ): Business {
        return Business::create([
            'city_id'        => $city->id,
            'code'           => 'hospital',
            'name'           => 'St. ' . $city->name . ' Medical Centre',
            'slug'           => 'hospital-' . $city->id . '-' . uniqid(),
            'is_purchasable' => $ownerId === null,
            'is_active'      => true,
            'base_price'     => 500_000,
            'balance'        => 200_000,
            'sort_order'     => 1,
            'owner_id'       => $ownerId,
            'data'           => [
                'surgery_fee'              => $surgeryFee,
                'gender_reassignment_fee'  => $genderFee,
            ],
        ]);
    }

    
    private function enqueueCharacter(Business $hospital, Character $character, string $queue): void
    {
        $current   = (array) $hospital->getSetting($queue, []);
        $current[] = ['id' => $character->id, 'applied_at' => now()->getTimestamp()];
        $hospital->setSetting($queue, $current);
    }

    
    
    

    public function test_index_renders_for_authenticated_character(): void
    {
        $this->makeHospital($this->city);
        $char = $this->makeCharacter($this->city);

        $this->actingAs($char->user)
            ->get(route('city.hospital.index', $this->city->slug))
            ->assertSuccessful();
    }

    public function test_index_renders_without_hospital_in_city(): void
    {
        $char = $this->makeCharacter($this->city);

        
        $this->actingAs($char->user)
            ->get(route('city.hospital.index', $this->city->slug))
            ->assertSuccessful();
    }

    public function test_index_is_owner_flag_true_for_hospital_owner(): void
    {
        $owner    = $this->makeCharacter($this->city);
        $hospital = $this->makeHospital($this->city, ownerId: $owner->id);

        $response = $this->actingAs($owner->user)
            ->get(route('city.hospital.index', $this->city->slug));

        $response->assertSuccessful();
        $this->assertTrue($response->original->getData()['page']['props']['is_owner']);
    }

    public function test_index_is_owner_flag_false_for_non_owner(): void
    {
        $owner   = $this->makeCharacter($this->city);
        $visitor = $this->makeCharacter($this->city);
        $this->makeHospital($this->city, ownerId: $owner->id);

        $response = $this->actingAs($visitor->user)
            ->get(route('city.hospital.index', $this->city->slug));

        $this->assertFalse($response->original->getData()['page']['props']['is_owner']);
    }

    public function test_index_hospitalised_characters_appear_in_patients_list(): void
    {
        $this->makeHospital($this->city);
        $patient = $this->makeCharacter($this->city);
        $visitor = $this->makeCharacter($this->city);

        
        DB::table('character_timers')
            ->where('character_id', $patient->id)
            ->update(['hospital_until' => now()->addHours(2)->getTimestamp()]);

        $response = $this->actingAs($visitor->user)
            ->get(route('city.hospital.index', $this->city->slug));

        $patients = $response->original->getData()['page']['props']['patients'];
        $this->assertCount(1, $patients);
    }

    public function test_index_discharged_characters_do_not_appear_in_patients(): void
    {
        $this->makeHospital($this->city);
        $char    = $this->makeCharacter($this->city);
        $visitor = $this->makeCharacter($this->city);

        
        DB::table('character_timers')
            ->where('character_id', $char->id)
            ->update(['hospital_until' => now()->subHour()->getTimestamp()]);

        $response = $this->actingAs($visitor->user)
            ->get(route('city.hospital.index', $this->city->slug));

        $patients = $response->original->getData()['page']['props']['patients'];
        $this->assertCount(0, $patients);
    }

    public function test_index_in_surgery_queue_flag_reflects_own_queue_entry(): void
    {
        $hospital = $this->makeHospital($this->city);
        $patient  = $this->makeCharacter($this->city, health: 60);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $response = $this->actingAs($patient->user)
            ->get(route('city.hospital.index', $this->city->slug));

        $props = $response->original->getData()['page']['props'];
        $this->assertTrue($props['in_surgery_queue']);
        $this->assertFalse($props['in_gender_queue']);
    }

    
    
    

    public function test_career_portal_renders_for_healthcare_staff(): void
    {
        $surgeon = $this->makeHealthcareChar($this->city);

        $this->actingAs($surgeon->user)
            ->get(route('career.healthcare'))
            ->assertSuccessful();
    }

    public function test_career_portal_blocked_for_non_healthcare_character(): void
    {
        
        $unemployed = $this->makeCharacter($this->city);

        $this->actingAs($unemployed->user)
            ->get(route('career.healthcare'))
            ->assertRedirect();
    }

    public function test_career_portal_surgeon_cuts_are_ten_percent_of_fees(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 20_000, genderFee: 100_000);
        $surgeon  = $this->makeHealthcareChar($this->city);

        $response = $this->actingAs($surgeon->user)
            ->get(route('career.healthcare'));

        $props = $response->original->getData()['page']['props'];

        $this->assertSame(2_000,  (int) $props['surgeon_surgery_cut']);
        $this->assertSame(10_000, (int) $props['surgeon_gender_cut']);
    }

    public function test_career_portal_surgery_fee_uses_default_when_no_hospital(): void
    {
        
        $surgeon = $this->makeHealthcareChar($this->city);

        $response = $this->actingAs($surgeon->user)
            ->get(route('career.healthcare'));

        $props = $response->original->getData()['page']['props'];

        $this->assertSame(10_000, (int) $props['surgery_fee']);
        $this->assertSame(50_000, (int) $props['gender_fee']);
        $this->assertSame(1_000,  (int) $props['surgeon_surgery_cut']); 
        $this->assertSame(5_000,  (int) $props['surgeon_gender_cut']);  
    }

    public function test_career_portal_purges_soft_deleted_patient_from_surgery_queue(): void
    {
        $hospital = $this->makeHospital($this->city);
        $patient  = $this->makeCharacter($this->city, health: 50);
        $surgeon  = $this->makeHealthcareChar($this->city);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        
        $patient->delete();

        $this->actingAs($surgeon->user)
            ->get(route('career.healthcare'));

        $hospital->refresh();
        $queue = $hospital->getSetting('surgery_queue', []);
        $this->assertCount(0, $queue, 'Soft-deleted patient must be purged from surgery queue on portal load');
    }

    public function test_career_portal_purges_soft_deleted_patient_from_gender_queue(): void
    {
        $hospital = $this->makeHospital($this->city);
        $patient  = $this->makeCharacter($this->city, health: 100, gender: 'male');
        $surgeon  = $this->makeHealthcareChar($this->city);

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');
        $patient->delete();

        $this->actingAs($surgeon->user)
            ->get(route('career.healthcare'));

        $hospital->refresh();
        $this->assertCount(0, $hospital->getSetting('gender_queue', []));
    }

    public function test_career_portal_purges_hard_deleted_patient_from_queue(): void
    {
        $hospital = $this->makeHospital($this->city);
        $patient  = $this->makeCharacter($this->city, health: 50);
        $surgeon  = $this->makeHealthcareChar($this->city);

        $deadId = $patient->id;
        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        
        $patient->forceDelete();

        $this->actingAs($surgeon->user)
            ->get(route('career.healthcare'));

        $hospital->refresh();
        $this->assertCount(0, $hospital->getSetting('surgery_queue', []),
            'Hard-deleted patient must be purged on portal load');
    }

    public function test_city_index_purges_soft_deleted_patient_from_queue(): void
    {
        $hospital = $this->makeHospital($this->city);
        $patient  = $this->makeCharacter($this->city, health: 50);
        $visitor  = $this->makeCharacter($this->city);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');
        $patient->delete();

        $this->actingAs($visitor->user)
            ->get(route('city.hospital.index', $this->city->slug));

        $hospital->refresh();
        $this->assertCount(0, $hospital->getSetting('surgery_queue', []),
            'Soft-deleted patient must be purged from queue on city hospital load');
    }

    public function test_purge_retains_alive_patients_in_queue(): void
    {
        $hospital = $this->makeHospital($this->city);
        $patient  = $this->makeCharacter($this->city, health: 50);
        $surgeon  = $this->makeHealthcareChar($this->city);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->get(route('career.healthcare'));

        $hospital->refresh();
        $this->assertCount(1, $hospital->getSetting('surgery_queue', []),
            'Alive patient must NOT be purged from the queue');
    }

    
    
    

    public function test_owner_can_update_surgery_and_gender_fees(): void
    {
        $owner    = $this->makeCharacter($this->city);
        $hospital = $this->makeHospital($this->city, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.hospital.settings', $this->city->slug), [
                'surgery_fee'             => 50_000,
                'gender_reassignment_fee' => 200_000,
            ])
            ->assertSessionHas('success');

        $hospital->refresh();
        $this->assertSame(50_000,  (int) $hospital->getSetting('surgery_fee'));
        $this->assertSame(200_000, (int) $hospital->getSetting('gender_reassignment_fee'));
    }

    public function test_non_owner_cannot_update_hospital_settings(): void
    {
        $owner     = $this->makeCharacter($this->city);
        $intruder  = $this->makeCharacter($this->city);
        $hospital  = $this->makeHospital($this->city, surgeryFee: 10_000, ownerId: $owner->id);

        $this->actingAs($intruder->user)
            ->post(route('city.hospital.settings', $this->city->slug), [
                'surgery_fee' => 99_000,
            ])
            ->assertSessionHas('error');

        $hospital->refresh();
        $this->assertSame(10_000, (int) $hospital->getSetting('surgery_fee'),
            'Surgery fee must remain unchanged after non-owner update attempt');
    }

    public function test_settings_surgery_fee_rejects_below_minimum(): void
    {
        $owner = $this->makeCharacter($this->city);
        $this->makeHospital($this->city, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.hospital.settings', $this->city->slug), [
                'surgery_fee' => 9_999, 
            ])
            ->assertSessionHasErrors('surgery_fee');
    }

    public function test_settings_surgery_fee_rejects_above_maximum(): void
    {
        $owner = $this->makeCharacter($this->city);
        $this->makeHospital($this->city, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.hospital.settings', $this->city->slug), [
                'surgery_fee' => 100_001, 
            ])
            ->assertSessionHasErrors('surgery_fee');
    }

    public function test_settings_gender_fee_rejects_below_minimum(): void
    {
        $owner = $this->makeCharacter($this->city);
        $this->makeHospital($this->city, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.hospital.settings', $this->city->slug), [
                'gender_reassignment_fee' => 49_999, 
            ])
            ->assertSessionHasErrors('gender_reassignment_fee');
    }

    public function test_settings_gender_fee_rejects_above_maximum(): void
    {
        $owner = $this->makeCharacter($this->city);
        $this->makeHospital($this->city, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.hospital.settings', $this->city->slug), [
                'gender_reassignment_fee' => 500_001, 
            ])
            ->assertSessionHasErrors('gender_reassignment_fee');
    }

    public function test_settings_accepts_boundary_values(): void
    {
        $owner = $this->makeCharacter($this->city);
        $this->makeHospital($this->city, ownerId: $owner->id);

        $this->actingAs($owner->user)
            ->post(route('city.hospital.settings', $this->city->slug), [
                'surgery_fee'             => 10_000,
                'gender_reassignment_fee' => 50_000,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner->user)
            ->post(route('city.hospital.settings', $this->city->slug), [
                'surgery_fee'             => 100_000,
                'gender_reassignment_fee' => 500_000,
            ])
            ->assertSessionHasNoErrors();
    }

    
    
    

    public function test_apply_surgery_happy_path(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $patient  = $this->makeCharacter($this->city, health: 60, cashOnHand: 20_000);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-surgery', $this->city->slug))
            ->assertSessionHas('success');

        $hospital->refresh();
        $queue = $hospital->getSetting('surgery_queue', []);
        $this->assertCount(1, $queue);
        $this->assertSame($patient->id, $queue[0]['id']);
    }

    public function test_apply_surgery_blocked_when_already_at_full_health(): void
    {
        $this->makeHospital($this->city, surgeryFee: 10_000);
        $patient = $this->makeCharacter($this->city, health: 100, maxHealth: 100, cashOnHand: 50_000);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-surgery', $this->city->slug))
            ->assertSessionHas('error');
    }

    public function test_apply_surgery_blocked_when_insufficient_cash(): void
    {
        $this->makeHospital($this->city, surgeryFee: 10_000);
        $patient = $this->makeCharacter($this->city, health: 50, cashOnHand: 5_000);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-surgery', $this->city->slug))
            ->assertSessionHas('error');
    }

    public function test_apply_surgery_blocked_when_jailed(): void
    {
        $this->makeHospital($this->city, surgeryFee: 10_000);
        $patient = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        DB::table('character_timers')
            ->where('character_id', $patient->id)
            ->update(['jail_until' => now()->addHours(2)->getTimestamp()]);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-surgery', $this->city->slug))
            ->assertSessionHas('error');
    }

    public function test_apply_surgery_blocked_when_already_hospitalised(): void
    {
        $this->makeHospital($this->city, surgeryFee: 10_000);
        $patient = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        DB::table('character_timers')
            ->where('character_id', $patient->id)
            ->update(['hospital_until' => now()->addHours(2)->getTimestamp()]);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-surgery', $this->city->slug))
            ->assertSessionHas('error');
    }

    public function test_apply_surgery_blocked_when_next_talents_at_in_future(): void
    {
        $this->makeHospital($this->city, surgeryFee: 10_000);
        $patient = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        
        DB::table('character_timers')
            ->where('character_id', $patient->id)
            ->update(['next_talents_at' => now()->addHours(1)->getTimestamp()]);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-surgery', $this->city->slug))
            ->assertSessionHas('error');
    }

    public function test_apply_surgery_prevents_duplicate_queue_entry(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        
        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-surgery', $this->city->slug))
            ->assertSessionHas('success');

        
        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-surgery', $this->city->slug))
            ->assertSessionHas('error');

        $hospital->refresh();
        $this->assertCount(1, $hospital->getSetting('surgery_queue', []),
            'Duplicate entry must not be added to surgery queue');
    }

    public function test_apply_surgery_records_applied_at_timestamp(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        $before = now()->getTimestamp();

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-surgery', $this->city->slug));

        $hospital->refresh();
        $entry = $hospital->getSetting('surgery_queue', [])[0] ?? [];

        $this->assertArrayHasKey('applied_at', $entry);
        $this->assertGreaterThanOrEqual($before, $entry['applied_at']);
    }

    
    
    

    public function test_apply_gender_happy_path(): void
    {
        $hospital = $this->makeHospital($this->city, genderFee: 50_000);
        $patient  = $this->makeCharacter($this->city, cashOnHand: 60_000);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-gender', $this->city->slug))
            ->assertSessionHas('success');

        $hospital->refresh();
        $queue = $hospital->getSetting('gender_queue', []);
        $this->assertCount(1, $queue);
        $this->assertSame($patient->id, $queue[0]['id']);
    }

    public function test_apply_gender_blocked_when_insufficient_cash(): void
    {
        $this->makeHospital($this->city, genderFee: 50_000);
        $patient = $this->makeCharacter($this->city, cashOnHand: 10_000);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-gender', $this->city->slug))
            ->assertSessionHas('error');
    }

    public function test_apply_gender_blocked_when_jailed(): void
    {
        $this->makeHospital($this->city, genderFee: 50_000);
        $patient = $this->makeCharacter($this->city, cashOnHand: 100_000);

        DB::table('character_timers')
            ->where('character_id', $patient->id)
            ->update(['jail_until' => now()->addHours(2)->getTimestamp()]);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-gender', $this->city->slug))
            ->assertSessionHas('error');
    }

    public function test_apply_gender_prevents_duplicate_queue_entry(): void
    {
        $hospital = $this->makeHospital($this->city, genderFee: 50_000);
        $patient  = $this->makeCharacter($this->city, cashOnHand: 150_000);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-gender', $this->city->slug))
            ->assertSessionHas('success');

        $this->actingAs($patient->user)
            ->post(route('city.hospital.apply-gender', $this->city->slug))
            ->assertSessionHas('error');

        $hospital->refresh();
        $this->assertCount(1, $hospital->getSetting('gender_queue', []));
    }

    
    
    

    public function test_cancel_surgery_removes_character_from_queue(): void
    {
        $hospital = $this->makeHospital($this->city);
        $patient  = $this->makeCharacter($this->city, health: 50);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($patient->user)
            ->post(route('city.hospital.cancel-surgery', $this->city->slug))
            ->assertSessionHas('success');

        $hospital->refresh();
        $this->assertCount(0, $hospital->getSetting('surgery_queue', []));
    }

    public function test_cancel_surgery_blocked_when_not_in_queue(): void
    {
        $this->makeHospital($this->city);
        $patient = $this->makeCharacter($this->city, health: 50);

        $this->actingAs($patient->user)
            ->post(route('city.hospital.cancel-surgery', $this->city->slug))
            ->assertSessionHas('error');
    }

    public function test_cancel_gender_removes_character_from_queue(): void
    {
        $hospital = $this->makeHospital($this->city);
        $patient  = $this->makeCharacter($this->city);

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');

        $this->actingAs($patient->user)
            ->post(route('city.hospital.cancel-gender', $this->city->slug))
            ->assertSessionHas('success');

        $hospital->refresh();
        $this->assertCount(0, $hospital->getSetting('gender_queue', []));
    }

    public function test_cancel_surgery_does_not_remove_other_patients(): void
    {
        $hospital  = $this->makeHospital($this->city);
        $patientA  = $this->makeCharacter($this->city, health: 50);
        $patientB  = $this->makeCharacter($this->city, health: 50);

        $this->enqueueCharacter($hospital, $patientA, 'surgery_queue');
        $this->enqueueCharacter($hospital, $patientB, 'surgery_queue');

        $this->actingAs($patientA->user)
            ->post(route('city.hospital.cancel-surgery', $this->city->slug));

        $hospital->refresh();
        $queue = $hospital->getSetting('surgery_queue', []);
        $this->assertCount(1, $queue);
        $this->assertSame($patientB->id, $queue[0]['id']);
    }

    
    
    

    public function test_surgery_happy_path_heals_patient_and_pays_surgeon(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, cashOnHand: 0);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('success');

        $surgeon->refresh();
        $patient->refresh();
        $hospital->refresh();

        
        $this->assertLessThan(50_000, $patient->cash_on_hand);

        
        $this->assertSame(1_000, (int) $surgeon->cash_on_hand);

        
        $this->assertGreaterThan(200_000, $hospital->balance, 'Hospital balance must increase by 90% of fee');

        
        $this->assertGreaterThan(50, $patient->health);
    }

    public function test_surgery_fee_split_is_ten_percent_surgeon_ninety_hospital(): void
    {
        $fee      = 20_000;
        $hospital = $this->makeHospital($this->city, surgeryFee: $fee);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, cashOnHand: 0);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 100_000);

        $hospitalBalanceBefore = $hospital->balance;

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ]);

        $surgeon->refresh();
        $patient->refresh();
        $hospital->refresh();

        $surgeonCut  = (int) floor($fee * 0.10); 
        $hospitalCut = $fee - $surgeonCut;        

        $this->assertSame($surgeonCut, (int) $surgeon->cash_on_hand);
        $this->assertSame($hospitalBalanceBefore + $hospitalCut, (int) $hospital->balance);
        $this->assertSame(100_000 - $fee, (int) $patient->cash_on_hand);
    }

    public function test_surgery_total_money_is_conserved(): void
    {
        
        
        $fee      = 10_000;
        $hospital = $this->makeHospital($this->city, surgeryFee: $fee);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, cashOnHand: 0);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        $totalBefore = $surgeon->cash_on_hand + $patient->cash_on_hand + $hospital->balance;

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ]);

        $surgeon->refresh();
        $patient->refresh();
        $hospital->refresh();

        $totalAfter = $surgeon->cash_on_hand + $patient->cash_on_hand + $hospital->balance;
        $this->assertSame($totalBefore, $totalAfter, 'Surgery must conserve total money in the system');
    }

    public function test_surgery_removes_patient_from_surgery_queue(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ]);

        $hospital->refresh();
        $this->assertCount(0, $hospital->getSetting('surgery_queue', []),
            'Patient must be removed from queue after successful surgery');
    }

    public function test_surgery_writes_journal_entry_for_patient(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ]);

        $journal = CharacterJournal::where('character_id', $patient->id)
            ->where('type', 'hospital_surgery')
            ->first();

        $this->assertNotNull($journal, 'hospital_surgery journal entry must be created for patient');
    }

    public function test_surgery_awards_xp_to_surgeon(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, careerXp: 5_000);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        $xpBefore = $surgeon->career_xp;

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ]);

        $surgeon->refresh();
        $this->assertGreaterThan($xpBefore, $surgeon->career_xp, 'Surgeon must gain XP after successful surgery');
    }

    public function test_surgery_sets_next_talents_at_cooldown_on_patient(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ]);

        $patient->refresh();
        $this->assertTrue(
            $patient->timers?->next_talents_at?->isFuture(),
            'next_talents_at must be set to a future timestamp after surgery'
        );
    }

    public function test_surgery_blocked_for_rank_below_3(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 2);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('error');

        
        $patient->refresh();
        $this->assertSame(50_000, (int) $patient->cash_on_hand);
    }

    public function test_surgery_blocked_when_patient_has_insufficient_cash(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, cashOnHand: 0);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 500);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('error');

        
        $surgeon->refresh();
        $this->assertSame(0, (int) $surgeon->cash_on_hand);
    }

    public function test_surgery_blocked_when_patient_is_at_full_health_and_refunds(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, cashOnHand: 0);
        
        $patient  = $this->makeCharacter($this->city, health: 100, maxHealth: 100, cashOnHand: 50_000);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $hospitalBalBefore = $hospital->balance;

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('error');

        $surgeon->refresh();
        $patient->refresh();
        $hospital->refresh();

        
        $this->assertSame(50_000, (int) $patient->cash_on_hand, 'Patient must be fully refunded on zero-heal');
        $this->assertSame(0, (int) $surgeon->cash_on_hand, 'Surgeon must not retain any cut on refund');
        $this->assertSame($hospitalBalBefore, (int) $hospital->balance, 'Hospital balance must be unchanged after refund');
    }

    public function test_surgery_blocked_when_patient_is_jailed(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        DB::table('character_timers')
            ->where('character_id', $patient->id)
            ->update(['jail_until' => now()->addHours(2)->getTimestamp()]);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('error');
    }

    public function test_surgery_blocked_when_surgeon_on_action_cooldown(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        DB::table('character_timers')
            ->where('character_id', $surgeon->id)
            ->update(['next_action_at' => now()->addMinutes(5)->getTimestamp()]);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('error');
    }

    public function test_surgery_blocked_when_surgeon_attempts_to_operate_on_self(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, cashOnHand: 50_000);
        $surgeon->update(['health' => 50]);

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($surgeon->id),
            ])
            ->assertSessionHas('error');
    }

    public function test_surgery_blocked_for_dead_patient(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        
        $patient->delete();

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('error');
    }

    public function test_surgery_blocked_with_invalid_encrypted_id(): void
    {
        $this->makeHospital($this->city);
        $surgeon = $this->makeHealthcareChar($this->city, rank: 3);

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => 'not-a-valid-encrypted-id',
            ])
            ->assertSessionHas('error');
    }

    public function test_surgery_sets_action_cooldown_on_surgeon(): void
    {
        $hospital = $this->makeHospital($this->city, surgeryFee: 10_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, health: 50, cashOnHand: 50_000);

        $this->enqueueCharacter($hospital, $patient, 'surgery_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.surgery'), [
                'character_id' => encrypt($patient->id),
            ]);

        $surgeon->refresh();
        $this->assertTrue(
            $surgeon->timers?->next_action_at?->isFuture(),
            'Action cooldown must be set on surgeon after surgery'
        );
    }

    
    
    

    public function test_gender_reassignment_flips_gender_and_pays_surgeon(): void
    {
        $fee      = 50_000;
        $hospital = $this->makeHospital($this->city, genderFee: $fee);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, cashOnHand: 0);
        $patient  = $this->makeCharacter($this->city, gender: 'male', cashOnHand: 100_000);

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.gender'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('success');

        $patient->refresh();
        $surgeon->refresh();
        $hospital->refresh();

        $this->assertSame('female', $patient->gender, 'Patient gender must flip male → female');
        $this->assertSame((int) floor($fee * 0.10), (int) $surgeon->cash_on_hand);
        $this->assertGreaterThan(200_000, $hospital->balance);
    }

    public function test_gender_reassignment_female_to_male(): void
    {
        $hospital = $this->makeHospital($this->city, genderFee: 50_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, gender: 'female', cashOnHand: 100_000);

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.gender'), [
                'character_id' => encrypt($patient->id),
            ]);

        $patient->refresh();
        $this->assertSame('male', $patient->gender);
    }

    public function test_gender_reassignment_fee_split_is_correct(): void
    {
        $fee      = 80_000;
        $hospital = $this->makeHospital($this->city, genderFee: $fee);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, cashOnHand: 0);
        $patient  = $this->makeCharacter($this->city, gender: 'male', cashOnHand: 200_000);

        $hospitalBalanceBefore = $hospital->balance;

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.gender'), [
                'character_id' => encrypt($patient->id),
            ]);

        $surgeon->refresh();
        $patient->refresh();
        $hospital->refresh();

        $surgeonCut  = (int) floor($fee * 0.10); 
        $hospitalCut = $fee - $surgeonCut;        

        $this->assertSame($surgeonCut, (int) $surgeon->cash_on_hand);
        $this->assertSame($hospitalBalanceBefore + $hospitalCut, (int) $hospital->balance);
        $this->assertSame(200_000 - $fee, (int) $patient->cash_on_hand);
    }

    public function test_gender_reassignment_writes_journal_entry_for_patient(): void
    {
        $hospital = $this->makeHospital($this->city, genderFee: 50_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, gender: 'male', cashOnHand: 100_000);

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.gender'), [
                'character_id' => encrypt($patient->id),
            ]);

        $journal = CharacterJournal::where('character_id', $patient->id)
            ->where('type', 'hospital_gender_reassignment')
            ->first();

        $this->assertNotNull($journal, 'hospital_gender_reassignment journal must be created for patient');
    }

    public function test_gender_reassignment_removes_patient_from_gender_queue(): void
    {
        $hospital = $this->makeHospital($this->city, genderFee: 50_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, gender: 'male', cashOnHand: 100_000);

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.gender'), [
                'character_id' => encrypt($patient->id),
            ]);

        $hospital->refresh();
        $this->assertCount(0, $hospital->getSetting('gender_queue', []),
            'Patient must be removed from gender queue after procedure');
    }

    public function test_gender_reassignment_blocked_for_rank_below_3(): void
    {
        $hospital = $this->makeHospital($this->city, genderFee: 50_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 2);
        $patient  = $this->makeCharacter($this->city, cashOnHand: 100_000);

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.gender'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('error');
    }

    public function test_gender_reassignment_blocked_when_patient_insufficient_cash(): void
    {
        $hospital = $this->makeHospital($this->city, genderFee: 50_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, cashOnHand: 0);
        $patient  = $this->makeCharacter($this->city, gender: 'male', cashOnHand: 1_000);

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.gender'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('error');

        $surgeon->refresh();
        $this->assertSame(0, (int) $surgeon->cash_on_hand, 'Surgeon must not be paid when patient cannot afford procedure');
    }

    public function test_gender_reassignment_blocked_when_patient_is_jailed(): void
    {
        $hospital = $this->makeHospital($this->city, genderFee: 50_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, cashOnHand: 100_000);

        DB::table('character_timers')
            ->where('character_id', $patient->id)
            ->update(['jail_until' => now()->addHours(2)->getTimestamp()]);

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.gender'), [
                'character_id' => encrypt($patient->id),
            ])
            ->assertSessionHas('error');

        $patient->refresh();
        $this->assertSame('male', $patient->gender, 'Gender must not change if patient is jailed');
    }

    public function test_gender_reassignment_blocked_for_self(): void
    {
        $hospital = $this->makeHospital($this->city, genderFee: 50_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3, cashOnHand: 100_000);

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.gender'), [
                'character_id' => encrypt($surgeon->id),
            ])
            ->assertSessionHas('error');
    }

    public function test_gender_reassignment_sets_action_cooldown_on_surgeon(): void
    {
        $hospital = $this->makeHospital($this->city, genderFee: 50_000);
        $surgeon  = $this->makeHealthcareChar($this->city, rank: 3);
        $patient  = $this->makeCharacter($this->city, cashOnHand: 100_000);

        $this->enqueueCharacter($hospital, $patient, 'gender_queue');

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.gender'), [
                'character_id' => encrypt($patient->id),
            ]);

        $surgeon->refresh();
        $this->assertTrue(
            $surgeon->timers?->next_action_at?->isFuture(),
            'Action cooldown must be set on surgeon after gender procedure'
        );
    }

    
    
    

    public function test_chief_can_dismiss_lower_rank_staff(): void
    {
        $chief  = $this->makeHealthcareChar($this->city, rank: 4);
        $junior = $this->makeHealthcareChar($this->city, rank: 2);

        $this->actingAs($chief->user)
            ->post(route('career.healthcare.dismiss'), [
                'character_id' => $junior->id,
            ])
            ->assertSessionHas('success');

        $junior->refresh();
        $this->assertNotEquals(
            $this->healthcareCareerId,
            $junior->career_id,
            'Dismissed character must no longer be in healthcare career'
        );
    }

    public function test_dismiss_writes_journal_entry_for_target(): void
    {
        $chief  = $this->makeHealthcareChar($this->city, rank: 4);
        $junior = $this->makeHealthcareChar($this->city, rank: 2);

        $this->actingAs($chief->user)
            ->post(route('career.healthcare.dismiss'), [
                'character_id' => $junior->id,
            ]);

        $journal = CharacterJournal::where('character_id', $junior->id)
            ->where('type', 'dismissed_by')
            ->first();

        $this->assertNotNull($journal, 'dismissed_by journal must be created for dismissed character');
    }

    public function test_dismiss_blocked_for_non_chief(): void
    {
        $surgeon = $this->makeHealthcareChar($this->city, rank: 3);
        $junior  = $this->makeHealthcareChar($this->city, rank: 1);

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.dismiss'), [
                'character_id' => $junior->id,
            ])
            ->assertSessionHas('error');

        $junior->refresh();
        $this->assertSame($this->healthcareCareerId, (int) $junior->career_id,
            'Non-chief must not be able to dismiss staff');
    }

    public function test_dismiss_blocked_for_self(): void
    {
        $chief = $this->makeHealthcareChar($this->city, rank: 4);

        $this->actingAs($chief->user)
            ->post(route('career.healthcare.dismiss'), [
                'character_id' => $chief->id,
            ])
            ->assertSessionHas('error');
    }

    public function test_dismiss_blocked_for_another_rank_4(): void
    {
        $chief1 = $this->makeHealthcareChar($this->city, rank: 4);
        $chief2 = $this->makeHealthcareChar($this->city, rank: 4);

        $this->actingAs($chief1->user)
            ->post(route('career.healthcare.dismiss'), [
                'character_id' => $chief2->id,
            ])
            ->assertSessionHas('error');

        $chief2->refresh();
        $this->assertSame($this->healthcareCareerId, (int) $chief2->career_id,
            'Cannot dismiss another rank-4 Surgeon General');
    }

    public function test_dismiss_blocked_for_staff_in_different_city(): void
    {
        $otherCity = City::create([
            'name'       => 'Other-' . uniqid(),
            'slug'       => 'other-' . uniqid(),
            'crime_rate' => 0,
        ]);

        $chief = $this->makeHealthcareChar($this->city, rank: 4);

        
        $junior = $this->makeHealthcareChar(
            $otherCity,
            rank:       2,
            homeCityId: $otherCity->id,
        );

        $this->actingAs($chief->user)
            ->post(route('career.healthcare.dismiss'), [
                'character_id' => $junior->id,
            ])
            ->assertSessionHas('error');

        $junior->refresh();
        $this->assertSame($this->healthcareCareerId, (int) $junior->career_id,
            'Chief must not dismiss staff from other city');
    }

    public function test_dismiss_preserves_target_career_xp(): void
    {
        $chief  = $this->makeHealthcareChar($this->city, rank: 4);
        $junior = $this->makeHealthcareChar($this->city, rank: 2, careerXp: 8_000);

        $this->actingAs($chief->user)
            ->post(route('career.healthcare.dismiss'), [
                'character_id' => $junior->id,
            ]);

        $junior->refresh();
        
        $this->assertGreaterThan(0, $junior->total_character_exp,
            'Dismissed character should retain accumulated XP (preserveExp: true)');
    }

    
    
    

    public function test_resign_removes_character_from_healthcare_career(): void
    {
        $surgeon = $this->makeHealthcareChar($this->city, rank: 3);

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.resign'))
            ->assertRedirect(route('dashboard'));

        $surgeon->refresh();
        $this->assertNotEquals($this->healthcareCareerId, $surgeon->career_id,
            'Resigned character must no longer be in healthcare career');
    }

    public function test_resign_redirects_to_dashboard_with_success(): void
    {
        $surgeon = $this->makeHealthcareChar($this->city, rank: 3);

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.resign'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success');
    }

    public function test_resign_writes_career_step_down_journal(): void
    {
        $surgeon = $this->makeHealthcareChar($this->city, rank: 3);

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.resign'));

        $journal = CharacterJournal::where('character_id', $surgeon->id)
            ->where('type', 'career_step_down')
            ->first();

        $this->assertNotNull($journal, 'career_step_down journal must be created on resign');
    }

    public function test_resign_blocked_when_not_in_healthcare_career(): void
    {
        $char = $this->makeCharacter($this->city); 

        
        
        $this->actingAs($char->user)
            ->post(route('career.healthcare.resign'))
            ->assertRedirect();
    }

    public function test_resign_preserves_character_career_xp(): void
    {
        $surgeon = $this->makeHealthcareChar($this->city, rank: 3, careerXp: 12_000);
        $expBefore = $surgeon->total_character_exp;

        $this->actingAs($surgeon->user)
            ->post(route('career.healthcare.resign'));

        $surgeon->refresh();
        $this->assertSame($expBefore, $surgeon->total_character_exp,
            'Resign (preserveExp: true) must not reduce total_character_exp');
    }
}
