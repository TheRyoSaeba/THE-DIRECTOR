<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $tokyoId = DB::table('cities')->where('slug', 'tokyo')->value('id');

        if ($tokyoId) {
            DB::table('businesses')
                ->where('city_id', $tokyoId)
                ->where(function ($q) {
                    $q->where('code', 'ILIKE', 'turlington')
                      ->orWhere('code', 'ILIKE', 'shop-turlington');
                })
                ->update([
                    'code' => 'shop-plaza-ginza',
                    'name' => 'Plaza Ginza',
                    'slug' => 'plaza-ginza',
                    'description' => 'Streets so dazzling you\'ll forget you spent a fortune.',
                    'image_url' => 'https://images.thedirector.app/businesses/ginzaplaza.jpg',
                ]);

            DB::table('businesses')
                ->where('city_id', '!=', $tokyoId)
                ->where(function ($q) {
                    $q->where('code', 'ILIKE', 'turlington')
                      ->orWhere('code', 'ILIKE', 'shop-turlington');
                })
                ->delete();
        }
    }

    public function down(): void
    {
        $tokyoId = DB::table('cities')->where('slug', 'tokyo')->value('id');

        if ($tokyoId) {
            DB::table('businesses')
                ->where('city_id', $tokyoId)
                ->where('code', 'shop-plaza-ginza')
                ->update([
                    'code' => 'turlington',
                    'name' => "Turlington's Wears",
                    'slug' => 'turlington',
                    'description' => 'Currently the only clothing store in Tokyo! Get all your fashion needs here.',
                    'image_url' => '/images/businesses/turlingtons.webp',
                ]);
        }

        $cities = DB::table('cities')->where('slug', '!=', 'tokyo')->get();
        foreach ($cities as $city) {
            $exists = DB::table('businesses')
                ->where('city_id', $city->id)
                ->where('code', 'turlington')
                ->exists();

            if (!$exists) {
                DB::table('businesses')->insert([
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
                    'sort_order' => 10,
                    'balance' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
