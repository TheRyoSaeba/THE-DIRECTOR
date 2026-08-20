<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $items = [
            [
                'name' => 'CX-717',
                'slug' => 'cx-717',
                'description' => 'An investigational ampakine quietly routed through corporate channels.',
                'image_url' => 'https://images.thedirector.app/items/Consumable/cx717.png',
                'effect' => 'reduceStudyTimer',
            ],
            [
                'name' => 'TAK-925',
                'slug' => 'tak-925',
                'description' => 'An experimental orexin agonist for people who need to stop feeling like luggage.',
                'image_url' => 'https://images.thedirector.app/items/Consumable/tak-925.png',
                'effect' => 'reduceTravelTimer',
            ],
        ];

        foreach ($items as $item) {
            DB::table('game_items')->updateOrInsert(
                ['slug' => $item['slug']],
                [
                    'name' => $item['name'],
                    'type' => 'item',
                    'slot' => 'item',
                    'description' => $item['description'],
                    'image_url' => $item['image_url'],
                    'price' => 900_000,
                    'is_active' => true,
                    'stock' => null,
                    'max_stock' => null,
                    'restock_at' => null,
                    'durability' => null,
                    'offense' => 0,
                    'defense' => 0,
                    'intelligence' => 0,
                    'influence' => 0,
                    'luck' => 0,
                    'data' => json_encode([
                        'consumable' => true,
                        'corporate_only' => true,
                        'pack_units' => 3,
                        'effect' => $item['effect'],
                        'amount_seconds' => 60,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('game_items')
            ->whereIn('slug', ['cx-717', 'tak-925'])
            ->delete();
    }
};
