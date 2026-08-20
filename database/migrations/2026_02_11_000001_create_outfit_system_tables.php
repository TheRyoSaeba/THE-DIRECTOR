<?php

use App\Models\City;
use App\Models\Business;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    
    public function up(): void
    {
        Schema::create('outfit_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('slot'); 
            $table->string('image_url')->nullable();
            $table->integer('price')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('restock_at')->nullable();
            $table->jsonb('data')->nullable(); 
            $table->timestamps();
        });

        Schema::table('characters', function (Blueprint $table) {
            if (!Schema::hasColumn('characters', 'inventory')) {
                $table->jsonb('inventory')->default('[]');
            }
        });

        $cities = City::all();
        foreach ($cities as $city) {
            if (Business::where('city_id', $city->id)->where('code', 'turlingtons')->exists()) {
                continue;
            }

            Business::create([
                'city_id' => $city->id,
                'code' => 'turlington',
                'name' => "Turlington's Wears",
                'slug' => 'turlington',
                'description' => "Currently the only clothing store in {$city->name}! Get all your fashion needs here.",
                'icon' => 'Shirt', 
                'image_url' => '/images/businesses/turlingtons.webp',
                'is_purchasable' => true,
                'base_price' => 500000,
                'is_active' => true,
                'sort_order' => 50,
                'owner_title' => 'Fashion Mongul',
                'balance' => 0,
                'data' => [],
            ]);
        }

        $items = [
            ['name' => 'Brown Fedora', 'slug' => 'fedora-brown', 'slot' => 'head', 'price' => 1500, 'image_url' => '/images/items/fedora_brown.webp'],
            ['name' => 'Black Beanie', 'slug' => 'beanie-black', 'slot' => 'head', 'price' => 500, 'image_url' => '/images/items/beanie_black.webp'],
            ['name' => 'White Tank Top', 'slug' => 'tank-white', 'slot' => 'body', 'price' => 200, 'image_url' => '/images/items/tank_white.webp'],
            ['name' => 'Leather Jacket', 'slug' => 'jacket-leather', 'slot' => 'body', 'price' => 5000, 'image_url' => '/images/items/jacket_leather.webp'],
            ['name' => 'Blue Jeans', 'slug' => 'jeans-blue', 'slot' => 'bottom', 'price' => 800, 'image_url' => '/images/items/jeans_blue.webp'],
            ['name' => 'Black Slacks', 'slug' => 'slacks-black', 'slot' => 'bottom', 'price' => 1200, 'image_url' => '/images/items/slacks_black.webp'],
            ['name' => 'Combat Boots', 'slug' => 'boots-combat', 'slot' => 'shoes', 'price' => 2500, 'image_url' => '/images/items/boots_combat.webp'],
            ['name' => 'Sneakers', 'slug' => 'sneakers-white', 'slot' => 'shoes', 'price' => 900, 'image_url' => '/images/items/sneakers_white.webp'],
            ['name' => 'Gold Chain', 'slug' => 'chain-gold', 'slot' => 'accessory', 'price' => 10000, 'image_url' => '/images/items/chain_gold.webp'],
            ['name' => 'Sunglasses', 'slug' => 'sunglasses-aviator', 'slot' => 'accessory', 'price' => 1500, 'image_url' => '/images/items/sunglasses_aviator.webp'],
        ];

        DB::table('outfit_items')->insert($items);
    }

    
    public function down(): void
    {
        Schema::dropIfExists('outfit_items');
        Schema::table('characters', function (Blueprint $table) {
            $table->dropColumn('inventory');
        });

    }
};
