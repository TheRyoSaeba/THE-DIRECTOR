<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Character;
use App\Models\City;
use App\Models\OutfitItem;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class TurlingtonsWearsTest extends TestCase
{
    use DatabaseTransactions;

    protected $user;
    protected $character;
    protected $city;
    protected $turlingtons;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->city = City::factory()->create(['slug' => 'tokyo']);
        $this->character = Character::factory()->create([
            'user_id' => $this->user->id,
            'city_id' => $this->city->id,
            'cash_on_hand' => 1000000,
        ]);

        $this->turlingtons = Business::create([
            'city_id' => $this->city->id,
            'code' => 'turlington',
            'name' => "Turlington's Wears",
            'slug' => 'turlington',
            'is_purchasable' => true,
            'base_price' => 500000,
        ]);
    }


    public function can_purchase_clothing_item()
    {
        $item = OutfitItem::create([
            'name' => 'Test Hat',
            'slug' => 'hat-test',
            'slot' => 'head',
            'price' => 100,
            'is_active' => true,
            'stock' => 10,
            'max_stock' => 10,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('city.shop.purchase', [
            'city' => $this->city->slug,
            'business_slug' => $this->turlingtons->slug
        ]), [
            'item_slug' => $item->slug,
        ]);

        $response->assertSessionHas('success');
        $this->assertTrue($this->character->fresh()->ownsItem($item->slug));
        $this->assertEquals(1000000 - 100, $this->character->fresh()->cash_on_hand);
        $this->assertEquals(9, $item->fresh()->stock);
    }


    public function cannot_purchase_item_out_of_stock()
    {
        $item = OutfitItem::create([
            'name' => 'Sold Out Hat',
            'slug' => 'hat-sold-out',
            'slot' => 'head',
            'price' => 100,
            'is_active' => true,
            'stock' => 0,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('city.shop.purchase', [
            'city' => $this->city->slug,
            'business_slug' => $this->turlingtons->slug
        ]), [
            'item_slug' => $item->slug,
        ]);

        $response->assertSessionHas('error', 'Item is out of stock.');
        $this->assertFalse($this->character->fresh()->ownsItem($item->slug));
    }


    public function owner_can_restock_shop()
    {
        $this->turlingtons->update(['owner_id' => $this->character->id]);

        $item = OutfitItem::create([
            'name' => 'Empty Hat',
            'slug' => 'hat-empty',
            'slot' => 'head',
            'price' => 100,
            'is_active' => true,
            'stock' => 0,
            'max_stock' => 50,
        ]);

        $response = $this->actingAs($this->user)
            ->post(route('city.shop.restock', [
            'city' => $this->city->slug,
            'business_slug' => $this->turlingtons->slug
        ]));

        $response->assertSessionHas('success');
        $this->assertEquals(50, $item->fresh()->stock);
        $this->assertNotNull($this->turlingtons->getSetting('manual_restock_at'));
    }


    public function cannot_restock_on_cooldown()
    {
        $this->turlingtons->update(['owner_id' => $this->character->id]);
        $this->turlingtons->setSetting('manual_restock_at', now()->addHours(1)->toIso8601String());

        $response = $this->actingAs($this->user)
            ->post(route('city.shop.restock', [
            'city' => $this->city->slug,
            'business_slug' => $this->turlingtons->slug
        ]));

        $response->assertSessionHas('error', 'Restock is on cooldown.');
    }


    public function can_equip_and_unequip_item_via_settings()
    {
        $item = OutfitItem::create([
            'name' => 'Test Hat',
            'slug' => 'hat-test',
            'slot' => 'head',
            'price' => 100,
            'is_active' => true,
        ]);

        $this->character->addToInventory($item->slug);

        $response = $this->actingAs($this->user)
            ->post(route('settings.equip'), [
            'item_slug' => $item->slug,
            'slot' => 'head',
        ]);

        $response->assertSessionHas('success');
        $this->assertEquals('hat-test', $this->character->fresh()->outfit['head']);

        $response = $this->actingAs($this->user)
            ->post(route('settings.unequip'), [
            'slot' => 'head',
        ]);

        $response->assertSessionHas('success');
        $this->assertArrayNotHasKey('head', $this->character->fresh()->outfit ?? []);
    }
}
