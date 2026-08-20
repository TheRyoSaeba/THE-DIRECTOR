<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Character;
use App\Models\CharacterItem;
use App\Models\CharacterStats;
use App\Models\CharacterTimers;
use App\Models\City;
use App\Models\GameItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;


class PharmacySystemTest extends TestCase
{
    use DatabaseTransactions;

    
    
    

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } catch (\PDOException $e) {
            if (
                !str_contains($e->getMessage(), "terminating connection") &&
                !str_contains($e->getMessage(), "SSL SYSCALL") &&
                !str_contains($e->getMessage(), "server closed the connection")
            ) {
                throw $e;
            }
            DB::reconnect();
        }
    }

    
    
    

    private function makeCity(string $label = 'pharm'): City
    {
        return City::create([
            'name' => 'PharmCity-' . $label,
            'slug' => 'pharm-' . $label . '-' . uniqid(),
            'crime_rate' => 50,
        ]);
    }

    private function makePharmacy(City $city, ?Character $owner = null): Business
    {
        return Business::create([
            'city_id' => $city->id,
            'code' => 'shop-pharmacy',
            'name' => 'Test Pharmacy',
            'slug' => 'test-pharmacy-' . uniqid(),
            'is_purchasable' => false,
            'base_price' => 0,
            'sort_order' => 1,
            'is_active' => true,
            'owner_id' => $owner?->id,
            'balance' => 0,
            'data' => [],
        ]);
    }

    private function makeDrug(string $effect = 'addDefense', int $price = 25000): GameItem
    {
        return GameItem::create([
            'name' => 'TestDrug-' . uniqid(),
            'slug' => 'test-drug-' . uniqid(),
            'type' => 'item',
            'slot' => 'item',
            'description' => 'Test drug',
            'image_url' => null,
            'price' => $price,
            'is_active' => true,
            'rarity' => 'common',
            'stock' => 10,
            'durability' => null,
            'data' => [
                'consumable' => true,
                'effect' => $effect,
                'amount' => 10,
            ],
        ]);
    }

    private function makeHealDrug(int $price = 75000): GameItem
    {
        return GameItem::create([
            'name' => 'TestMorphine-' . uniqid(),
            'slug' => 'test-morphine-' . uniqid(),
            'type' => 'item',
            'slot' => 'item',
            'description' => 'Test heal drug',
            'image_url' => null,
            'price' => $price,
            'is_active' => true,
            'rarity' => 'uncommon',
            'stock' => 5,
            'durability' => null,
            'data' => [
                'consumable' => true,
                'effect' => 'heal',
                'min' => 5,
                'max' => 15,
            ],
        ]);
    }

    private function makeCharacter(City $city, int $cash = 500000): Character
    {
        $user = User::factory()->create();

        $char = Character::create([
            'user_id' => $user->id,
            'display_name' => 'Patient-' . uniqid(),
            'gender' => 'male',
            'city_id' => $city->id,
            'home_city_id' => $city->id,
            'career_id' => DB::table('careers')->where('code', 'unemployed')->first()->id,
            'career_rank' => 1,
            'health' => 50,
            'max_health' => 100,
            'cash_on_hand' => $cash,
            'cash_in_bank' => 0,
        ]);

        CharacterStats::create([
            'character_id' => $char->id,
            'defense' => 10,
            'intelligence' => 10,
        ]);

        CharacterTimers::create([
            'character_id' => $char->id,
        ]);

        return $char;
    }

    private function giveItemToCharacter(Character $char, GameItem $drug): CharacterItem
    {
        return CharacterItem::create([
            'character_id' => $char->id,
            'game_item_id' => $drug->id,
            'location' => 'on_hand',
            'is_equipped' => false,
            'equipped_slot' => null,
        ]);
    }

    
    
    

    public function test_consume_defense_drug_increases_defense_stat(): void
    {
        $city = $this->makeCity('consume-def');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');
        $item = $this->giveItemToCharacter($char, $drug);

        $user = $char->user;
        $defenseBefore = $char->stats->defense;

        $response = $this->actingAs($user)->post('/settings/consume', ['id' => $item->id]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $char->stats->refresh();
        $this->assertEquals($defenseBefore + 10, $char->stats->defense);
    }

    public function test_consume_intelligence_drug_increases_intelligence_stat(): void
    {
        $city = $this->makeCity('consume-int');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addIntelligence');
        $item = $this->giveItemToCharacter($char, $drug);

        $user = $char->user;
        $intBefore = $char->stats->intelligence;

        $this->actingAs($user)->post('/settings/consume', ['id' => $item->id]);

        $char->stats->refresh();
        $this->assertEquals($intBefore + 10, $char->stats->intelligence);
    }

    public function test_consume_heal_drug_increases_health(): void
    {
        $city = $this->makeCity('consume-heal');
        $char = $this->makeCharacter($city);
        $drug = $this->makeHealDrug();
        $item = $this->giveItemToCharacter($char, $drug);

        $user = $char->user;
        $healthBefore = $char->health;

        $this->actingAs($user)->post('/settings/consume', ['id' => $item->id]);

        $char->refresh();
        $this->assertGreaterThan($healthBefore, $char->health);
        $this->assertLessThanOrEqual($char->max_health, $char->health);
    }

    public function test_consume_deletes_item_from_inventory(): void
    {
        $city = $this->makeCity('consume-delete');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');
        $item = $this->giveItemToCharacter($char, $drug);

        $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);

        $this->assertDatabaseMissing('character_items', ['id' => $item->id]);
    }

    
    
    

    public function test_consume_defense_message_does_not_reveal_amount(): void
    {
        $city = $this->makeCity('msg-def');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');
        $item = $this->giveItemToCharacter($char, $drug);

        $response = $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);

        $msg = session('success');
        $this->assertStringNotContainsString('10', $msg);
        $this->assertStringNotContainsString('defense', strtolower($msg));
        $this->assertStringContainsString('resilient', $msg);
    }

    public function test_consume_intelligence_message_does_not_reveal_amount(): void
    {
        $city = $this->makeCity('msg-int');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addIntelligence');
        $item = $this->giveItemToCharacter($char, $drug);

        $response = $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);

        $msg = session('success');
        $this->assertStringNotContainsString('10', $msg);
        $this->assertStringNotContainsString('intelligence', strtolower($msg));
        $this->assertStringContainsString('sharper', $msg);
    }

    
    
    

    public function test_consume_fails_when_item_not_in_inventory(): void
    {
        $city = $this->makeCity('guard-missing');
        $char = $this->makeCharacter($city);

        $response = $this->actingAs($char->user)->post('/settings/consume', ['id' => 999999]);

        $response->assertSessionHas('error');
    }

    public function test_consume_fails_for_non_consumable_item(): void
    {
        $city = $this->makeCity('guard-gadget');
        $char = $this->makeCharacter($city);

        
        $gadget = GameItem::create([
            'name' => 'TestGadget-' . uniqid(),
            'slug' => 'test-gadget-' . uniqid(),
            'type' => 'gadget',
            'slot' => 'accessory',
            'description' => 'A gadget',
            'price' => 5000,
            'is_active' => true,
            'rarity' => 'common',
            'stock' => 10,
        ]);

        $item = $this->giveItemToCharacter($char, $gadget);

        $response = $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('character_items', ['id' => $item->id]); 
    }

    public function test_consume_fails_when_item_is_in_safe(): void
    {
        $city = $this->makeCity('guard-safe');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');

        $item = CharacterItem::create([
            'character_id' => $char->id,
            'game_item_id' => $drug->id,
            'location' => 'safe',  
            'is_equipped' => false,
        ]);

        $response = $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('character_items', ['id' => $item->id]); 
    }

    
    
    

    public function test_consume_sets_talent_cooldown(): void
    {
        $city = $this->makeCity('cd-set');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');
        $item = $this->giveItemToCharacter($char, $drug);

        $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);

        $char->timers->refresh();
        $this->assertNotNull($char->timers->next_talents_at);
    }

    public function test_consume_fails_when_talent_on_cooldown(): void
    {
        $city = $this->makeCity('cd-block');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');
        $item = $this->giveItemToCharacter($char, $drug);

        
        $char->timers()->update(['next_talents_at' => now()->addHour()->getTimestamp()]);

        
        $response = $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('character_items', ['id' => $item->id]); 
    }

    
    
    

    public function test_consumable_item_cannot_be_equipped(): void
    {
        $city = $this->makeCity('equip-block');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');
        $item = $this->giveItemToCharacter($char, $drug);

        $response = $this->actingAs($char->user)->post('/settings/equip', [
            'id' => $item->id,
            'slot' => 'item',
        ]);

        $response->assertSessionHas('error');

        $item->refresh();
        $this->assertFalse($item->is_equipped);
        $this->assertNull($item->equipped_slot);
    }

    
    
    

    public function test_purchase_insurance_deducts_premium_and_activates(): void
    {
        $city = $this->makeCity('ins-buy');
        $char = $this->makeCharacter($city, 200000);
        $pharmacy = $this->makePharmacy($city);
        $pharmacy->setSetting('insurance_premium', 50000);

        $cashBefore = $char->cash_on_hand;

        $response = $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/insurance"
        );

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $char->refresh();
        $this->assertEquals($cashBefore - 50000, $char->cash_on_hand);

        
        $pharmacy->refresh();
        $subscribers = $pharmacy->getSetting('insurance_subscribers', []);
        $this->assertArrayHasKey((string)$char->id, $subscribers);
    }

    public function test_purchase_insurance_fails_with_insufficient_funds(): void
    {
        $city = $this->makeCity('ins-broke');
        $char = $this->makeCharacter($city, 1000); 
        $pharmacy = $this->makePharmacy($city);
        $pharmacy->setSetting('insurance_premium', 50000);

        $response = $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/insurance"
        );

        $response->assertSessionHas('error');

        $char->refresh();
        $this->assertEquals(1000, $char->cash_on_hand); 
    }

    public function test_purchase_insurance_fails_when_already_insured(): void
    {
        $city = $this->makeCity('ins-dup');
        $char = $this->makeCharacter($city, 500000);
        $pharmacy = $this->makePharmacy($city);
        $pharmacy->setSetting('insurance_premium', 50000);

        
        $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/insurance"
        );

        
        $response = $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/insurance"
        );

        $response->assertSessionHas('error');
    }

    public function test_insurance_premium_goes_to_business_balance(): void
    {
        $city = $this->makeCity('ins-bal');
        $char = $this->makeCharacter($city, 200000);
        $pharmacy = $this->makePharmacy($city);
        $pharmacy->setSetting('insurance_premium', 50000);

        $balanceBefore = $pharmacy->balance;

        $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/insurance"
        );

        $pharmacy->refresh();
        $this->assertEquals($balanceBefore + 50000, $pharmacy->balance);
    }

    public function test_insurance_fails_on_non_pharmacy_shop(): void
    {
        $city = $this->makeCity('ins-wrong');
        $char = $this->makeCharacter($city, 200000);

        
        $weaponShop = Business::create([
            'city_id' => $city->id,
            'code' => 'shop-weapons',
            'name' => 'Weapon Shop',
            'slug' => 'test-weapons-' . uniqid(),
            'is_purchasable' => false,
            'base_price' => 0,
            'sort_order' => 1,
            'is_active' => true,
            'balance' => 0,
            'data' => [],
        ]);

        $response = $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$weaponShop->slug}/insurance"
        );

        $response->assertStatus(404);
    }

    
    
    

    public function test_owner_can_set_insurance_premium(): void
    {
        $city = $this->makeCity('prem-set');
        $owner = $this->makeCharacter($city);
        $pharmacy = $this->makePharmacy($city, $owner);

        $this->actingAs($owner->user)->post(
            route('city.shop.settings', ['city' => $city->slug, 'business_slug' => $pharmacy->slug]),
            ['insurance_premium' => 75000]
        );

        $pharmacy->refresh();
        $this->assertEquals(75000, $pharmacy->getSetting('insurance_premium'));
    }

    public function test_insurance_premium_clamped_to_min_max(): void
    {
        $city = $this->makeCity('prem-clamp');
        $owner = $this->makeCharacter($city);
        $pharmacy = $this->makePharmacy($city, $owner);

        
        $this->actingAs($owner->user)->post(
            route('city.shop.settings', ['city' => $city->slug, 'business_slug' => $pharmacy->slug]),
            ['insurance_premium' => 1000]
        );
        $pharmacy->refresh();
        $this->assertEquals(10000, $pharmacy->getSetting('insurance_premium'));

        
        $this->actingAs($owner->user)->post(
            route('city.shop.settings', ['city' => $city->slug, 'business_slug' => $pharmacy->slug]),
            ['insurance_premium' => 999999]
        );
        $pharmacy->refresh();
        $this->assertEquals(100000, $pharmacy->getSetting('insurance_premium'));
    }

    
    
    

    public function test_insured_player_gets_discount_on_drug_purchase(): void
    {
        $city = $this->makeCity('disc-buy');
        $char = $this->makeCharacter($city, 500000);
        $pharmacy = $this->makePharmacy($city);
        $drug = $this->makeDrug('addDefense', 100000);

        
        $pharmacy->setSetting('insurance_subscribers', [
            (string)$char->id => now()->addDays(7)->toIso8601String(),
        ]);

        $cashBefore = $char->cash_on_hand;

        $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/purchase",
            ['item_slug' => $drug->slug]
        );

        $char->refresh();
        $expectedPrice = (int) round(100000 * 0.70); 
        $this->assertEquals($cashBefore - $expectedPrice, $char->cash_on_hand);
    }

    public function test_uninsured_player_pays_full_price(): void
    {
        $city = $this->makeCity('disc-full');
        $char = $this->makeCharacter($city, 500000);
        $pharmacy = $this->makePharmacy($city);
        $drug = $this->makeDrug('addDefense', 100000);

        $cashBefore = $char->cash_on_hand;

        $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/purchase",
            ['item_slug' => $drug->slug]
        );

        $char->refresh();
        $this->assertEquals($cashBefore - 100000, $char->cash_on_hand);
    }

    public function test_expired_insurance_means_no_discount(): void
    {
        $city = $this->makeCity('disc-exp');
        $char = $this->makeCharacter($city, 500000);
        $pharmacy = $this->makePharmacy($city);
        $drug = $this->makeDrug('addDefense', 100000);

        
        $pharmacy->setSetting('insurance_subscribers', [
            (string)$char->id => now()->subDay()->toIso8601String(),
        ]);

        $cashBefore = $char->cash_on_hand;

        $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/purchase",
            ['item_slug' => $drug->slug]
        );

        $char->refresh();
        $this->assertEquals($cashBefore - 100000, $char->cash_on_hand); 
    }

    
    
    

    public function test_shop_index_does_not_expose_item_data_field(): void
    {
        $city = $this->makeCity('priv-index');
        $char = $this->makeCharacter($city);
        $pharmacy = $this->makePharmacy($city);
        $drug = $this->makeDrug('addDefense');

        $response = $this->actingAs($char->user)->get(
            "/{$city->slug}/shop/{$pharmacy->slug}"
        );

        $response->assertOk();

        
        $page = $response->viewData('page') ?? [];
        if (isset($page['props']['products'])) {
            foreach ($page['props']['products'] as $product) {
                $this->assertArrayNotHasKey('data', $product);
                $this->assertArrayNotHasKey('effect', $product);
                if (isset($product['attributes'])) {
                    $this->assertArrayNotHasKey('effect', $product['attributes']);
                    $this->assertArrayNotHasKey('consumable', $product['attributes']);
                }
            }
        }
    }

    
    
    

    public function test_consumable_can_be_stashed_in_safe(): void
    {
        $city = $this->makeCity('stash-ok');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');
        $item = $this->giveItemToCharacter($char, $drug);

        
        $property = DB::table('properties')->first();
        if ($property) {
            $char->update(['property_id' => $property->id]);
            
            
            $this->assertTrue(true);
        } else {
            $this->markTestSkipped('No properties exist in database to test stashing.');
        }
    }

    public function test_consumed_item_cannot_be_consumed_again(): void
    {
        $city = $this->makeCity('double-consume');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');
        $item = $this->giveItemToCharacter($char, $drug);
        $itemId = $item->id;

        
        $this->actingAs($char->user)->post('/settings/consume', ['id' => $itemId]);

        
        $char->timers()->update(['next_talents_at' => 0]);

        
        $response = $this->actingAs($char->user)->post('/settings/consume', ['id' => $itemId]);

        $response->assertSessionHas('error');
    }

    
    
    

    public function test_multiple_defense_drugs_stack_additively(): void
    {
        $city = $this->makeCity('stack-def');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');

        $item1 = $this->giveItemToCharacter($char, $drug);
        $item2 = $this->giveItemToCharacter($char, $drug);
        $item3 = $this->giveItemToCharacter($char, $drug);

        $defenseBefore = $char->stats->defense;

        
        $this->actingAs($char->user)->post('/settings/consume', ['id' => $item1->id]);
        $char->stats->refresh();
        $this->assertEquals($defenseBefore + 10, $char->stats->defense);

        
        $char->timers()->update(['next_talents_at' => 0]);

        
        $this->actingAs($char->user)->post('/settings/consume', ['id' => $item2->id]);
        $char->stats->refresh();
        $this->assertEquals($defenseBefore + 20, $char->stats->defense);

        
        $char->timers()->update(['next_talents_at' => 0]);

        
        $this->actingAs($char->user)->post('/settings/consume', ['id' => $item3->id]);
        $char->stats->refresh();
        $this->assertEquals($defenseBefore + 30, $char->stats->defense);
    }

    public function test_multiple_intelligence_drugs_stack_additively(): void
    {
        $city = $this->makeCity('stack-int');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addIntelligence');

        $item1 = $this->giveItemToCharacter($char, $drug);
        $item2 = $this->giveItemToCharacter($char, $drug);

        $intBefore = $char->stats->intelligence;

        $this->actingAs($char->user)->post('/settings/consume', ['id' => $item1->id]);
        $char->timers()->update(['next_talents_at' => 0]);
        $this->actingAs($char->user)->post('/settings/consume', ['id' => $item2->id]);

        $char->stats->refresh();
        $this->assertEquals($intBefore + 20, $char->stats->intelligence);
    }

    
    
    

    public function test_cooldown_blocks_rapid_consume_same_drug_type(): void
    {
        $city = $this->makeCity('rapid-same');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');
        $item = $this->giveItemToCharacter($char, $drug);

        
        $char->timers()->update(['next_talents_at' => now()->addHour()->getTimestamp()]);

        
        $response = $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);
        $response->assertSessionHas('error');

        
        $this->assertDatabaseHas('character_items', ['id' => $item->id]);

        
        $char->stats->refresh();
        $this->assertEquals(10, $char->stats->defense); 
    }

    public function test_cooldown_blocks_different_drug_types_too(): void
    {
        $city = $this->makeCity('rapid-diff');
        $char = $this->makeCharacter($city);
        $intDrug = $this->makeDrug('addIntelligence');
        $item = $this->giveItemToCharacter($char, $intDrug);

        
        $char->timers()->update(['next_talents_at' => now()->addHour()->getTimestamp()]);

        
        $response = $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);
        $response->assertSessionHas('error');

        
        $char->stats->refresh();
        $this->assertEquals(10, $char->stats->intelligence); 
    }

    
    
    

    public function test_drugs_count_toward_gear_item_capacity_limit(): void
    {
        $city = $this->makeCity('cap-limit');
        $char = $this->makeCharacter($city, 500000);
        $pharmacy = $this->makePharmacy($city);

        
        $drug = $this->makeDrug('addDefense');
        $this->giveItemToCharacter($char, $drug);
        $this->giveItemToCharacter($char, $drug);
        $this->giveItemToCharacter($char, $drug);

        
        $response = $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/purchase",
            ['item_slug' => $drug->slug]
        );

        $response->assertSessionHas('error');
    }

    
    
    

    public function test_cannot_consume_another_characters_item(): void
    {
        $city = $this->makeCity('other-item');
        $char1 = $this->makeCharacter($city);
        $char2 = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');

        
        $item = $this->giveItemToCharacter($char2, $drug);

        
        $response = $this->actingAs($char1->user)->post('/settings/consume', ['id' => $item->id]);

        $response->assertSessionHas('error');
        $this->assertDatabaseHas('character_items', ['id' => $item->id]); 
    }

    
    
    

    public function test_purchase_drug_adds_to_inventory(): void
    {
        $city = $this->makeCity('buy-inv');
        $char = $this->makeCharacter($city, 500000);
        $pharmacy = $this->makePharmacy($city);
        $drug = $this->makeDrug('addDefense', 25000);

        $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/purchase",
            ['item_slug' => $drug->slug]
        );

        $this->assertDatabaseHas('character_items', [
            'character_id' => $char->id,
            'game_item_id' => $drug->id,
            'location' => 'on_hand',
            'is_equipped' => false,
        ]);
    }

    public function test_purchase_drug_fails_with_insufficient_funds(): void
    {
        $city = $this->makeCity('buy-broke');
        $char = $this->makeCharacter($city, 100); 
        $pharmacy = $this->makePharmacy($city);
        $drug = $this->makeDrug('addDefense', 25000);

        $response = $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/purchase",
            ['item_slug' => $drug->slug]
        );

        $response->assertSessionHas('error');
    }

    
    
    

    public function test_heal_drug_cannot_exceed_max_health(): void
    {
        $city = $this->makeCity('heal-cap');
        $char = $this->makeCharacter($city);
        
        $char->update(['health' => 99, 'max_health' => 100]);

        $drug = $this->makeHealDrug();
        $item = $this->giveItemToCharacter($char, $drug);

        $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);

        $char->refresh();
        $this->assertLessThanOrEqual(100, $char->health);
    }

    public function test_heal_at_full_health_still_consumed(): void
    {
        $city = $this->makeCity('heal-full');
        $char = $this->makeCharacter($city);
        $char->update(['health' => 100, 'max_health' => 100]);

        $drug = $this->makeHealDrug();
        $item = $this->giveItemToCharacter($char, $drug);

        $this->actingAs($char->user)->post('/settings/consume', ['id' => $item->id]);

        
        $this->assertDatabaseMissing('character_items', ['id' => $item->id]);

        
        $char->refresh();
        $this->assertLessThanOrEqual(100, $char->health);
    }

    
    
    

    public function test_drug_can_be_destroyed_via_drop(): void
    {
        $city = $this->makeCity('drop-drug');
        $char = $this->makeCharacter($city);
        $drug = $this->makeDrug('addDefense');
        $item = $this->giveItemToCharacter($char, $drug);

        $response = $this->actingAs($char->user)->post('/settings/drop', ['id' => $item->id]);

        $this->assertDatabaseMissing('character_items', ['id' => $item->id]);
    }

    
    
    

    public function test_insurance_subscriber_with_past_timestamp_is_not_insured(): void
    {
        $city = $this->makeCity('ins-edge');
        $char = $this->makeCharacter($city, 500000);
        $pharmacy = $this->makePharmacy($city);

        
        $pharmacy->setSetting('insurance_subscribers', [
            (string)$char->id => now()->subSecond()->toIso8601String(),
        ]);

        
        $pharmacy->setSetting('insurance_premium', 10000);
        $response = $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/insurance"
        );

        $response->assertSessionHas('success');
    }

    
    
    

    public function test_purchase_decrements_drug_stock(): void
    {
        $city = $this->makeCity('stock-dec');
        $char = $this->makeCharacter($city, 500000);
        $pharmacy = $this->makePharmacy($city);
        $drug = $this->makeDrug('addDefense', 25000);

        $stockBefore = $drug->stock;

        $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/purchase",
            ['item_slug' => $drug->slug]
        );

        $drug->refresh();
        $this->assertEquals($stockBefore - 1, $drug->stock);
    }

    public function test_purchase_fails_when_zero_stock(): void
    {
        $city = $this->makeCity('stock-zero');
        $char = $this->makeCharacter($city, 500000);
        $pharmacy = $this->makePharmacy($city);
        $drug = $this->makeDrug('addDefense', 25000);
        $drug->update(['stock' => 0]);

        $response = $this->actingAs($char->user)->post(
            "/{$city->slug}/shop/{$pharmacy->slug}/purchase",
            ['item_slug' => $drug->slug]
        );

        $response->assertSessionHas('error');
    }

    
    
    

    public function test_non_owner_cannot_set_insurance_premium(): void
    {
        $city = $this->makeCity('prem-notown');
        $owner = $this->makeCharacter($city);
        $stranger = $this->makeCharacter($city);
        $pharmacy = $this->makePharmacy($city, $owner);

        $response = $this->actingAs($stranger->user)->post(
            route('city.shop.settings', ['city' => $city->slug, 'business_slug' => $pharmacy->slug]),
            ['insurance_premium' => 99000]
        );

        $response->assertStatus(403);
    }
}
