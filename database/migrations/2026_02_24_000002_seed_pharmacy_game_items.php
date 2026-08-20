<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration 
{
    public function up(): void
    {
        $now = now();

        DB::table('game_items')->insert([
            [
                'name' => 'Zepbound',
                'slug' => 'zepbound',
                'type' => 'item',
                'slot' => 'item',
                'description' => 'The best tool for a man who needs more resillience',
                'image_url' => 'https://images.thedirector.app/items/Consumable/zepbound.png',
                'price' => 25000,
                'is_active' => true,
                'stock' => 10,
                'max_stock' => 10,
                'restock_at' => null,
                'durability' => null,
                'offense' => 0,
                'defense' => 0,
                'intelligence' => 0,
                'influence' => 0,
                'luck' => 0,
                'data' => json_encode([
                    'consumable' => true,
                    'effect' => 'addDefense',
                    'amount' => 10,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Modafinil',
                'slug' => 'modafinil',
                'type' => 'item',
                'slot' => 'item',
                'description' => 'A sharper, faster, clearer you',
                'image_url' => 'https://images.thedirector.app/items/Consumable/modafinil.png',
                'price' => 25000,
                'is_active' => true,
                'stock' => 10,
                'max_stock' => 10,
                'restock_at' => null,
                'durability' => null,
                'offense' => 0,
                'defense' => 0,
                'intelligence' => 0,
                'influence' => 0,
                'luck' => 0,
                'data' => json_encode([
                    'consumable' => true,
                    'effect' => 'addIntelligence',
                    'amount' => 10,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Morphine Sulfate',
                'slug' => 'morphine',
                'type' => 'item',
                'slot' => 'item',
                'description' => 'You need healing and you need it now.',
                'image_url' => 'https://images.thedirector.app/items/Consumable/morphine1.png',
                'price' => 75000,
                'is_active' => true,
                'stock' => 3,
                'max_stock' => 3,
                'restock_at' => null,
                'durability' => null,
                'offense' => 0,
                'defense' => 0,
                'intelligence' => 0,
                'influence' => 0,
                'luck' => 0,
                'data' => json_encode([
                    'consumable' => true,
                    'effect' => 'heal',
                    'min' => 10,
                    'max' => 30,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('game_items')
            ->whereIn('slug', ['zepbound', 'modafinil', 'morphine'])
            ->delete();
    }
};
