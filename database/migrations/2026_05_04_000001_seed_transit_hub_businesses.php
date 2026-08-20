<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        $cities = [
            'New York' => [
                'slug' => 'new-york',
                'image_url' => 'https://images.thedirector.app/businesses/nyctransithub.jpg',
            ],
            'Tokyo' => [
                'slug' => 'tokyo',
                'image_url' => 'https://images.thedirector.app/businesses/tokyotransithub.jpg',
            ],
            'Seoul' => [
                'slug' => 'seoul',
                'image_url' => 'https://images.thedirector.app/businesses/seoultransithub.jpg',
            ],
        ];

        foreach ($cities as $cityName => $meta) {
            $cityId = DB::table('cities')->where('name', $cityName)->value('id');

            if (!$cityId) {
                continue;
            }

            DB::table('businesses')->insertOrIgnore([
                'city_id' => $cityId,
                'code' => 'transit-hub',
                'name' => "{$cityName} Transit Hub",
                'slug' => "{$meta['slug']}-transit-hub",
                'description' => 'Oversees all transit operations for the city.',
                'image_url' => $meta['image_url'],
                'is_purchasable' => false,
                'base_price' => null,
                'sort_order' => 10,
                'is_active' => true,
                'owner_id' => null,
                'balance' => 0,
                'data' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('businesses')->where('code', 'transit-hub')->delete();
    }
};
