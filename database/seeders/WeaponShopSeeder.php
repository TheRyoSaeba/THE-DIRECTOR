<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\GameItem;

class WeaponShopSeeder extends Seeder
{
    
    public function run(): void
    {
        $items = [
            [
                'name' => 'Police Baton',
                'slug' => 'police-baton',
                'type' => 'weapon',
                'slot' => 'weapon',
                'description' => 'Standard issue enforcement tool. Reliable but limited range.',
                'image_url' => '/images/weapons/Police Baton.png',
                'price' => 1500,
                'offense' => 5,
                'durability' => 50,
                'stock' => 10,
                'max_stock' => 10,
            ],
            [
                'name' => 'Unmarked Pistol',
                'slug' => 'unmarked-pistol',
                'type' => 'weapon',
                'slot' => 'weapon',
                'description' => 'A clean, untraceable sidearm. Perfect for clandestine operations.',
                'image_url' => '/images/weapons/unmarkedpistol .png',
                'price' => 5000,
                'offense' => 15,
                'durability' => 100,
                'stock' => 5,
                'max_stock' => 5,
            ],
            [
                'name' => 'Rex Aureus Rifle',
                'slug' => 'rex-aureus-rifle',
                'type' => 'weapon',
                'slot' => 'weapon',
                'description' => 'A royal piece of hardware. High stopping power and precision.',
                'image_url' => '/images/weapons/Rex Aureus Rifle .png',
                'price' => 25000,
                'offense' => 45,
                'durability' => 150,
                'stock' => 3,
                'max_stock' => 3,
            ],
            [
                'name' => 'Savior Legacy Sniper',
                'slug' => 'savior-legacy-sniper',
                'type' => 'weapon',
                'slot' => 'weapon',
                'description' => 'For those who prefer to solve problems from a distance.',
                'image_url' => '/images/weapons/Savior legacy sniper .png',
                'price' => 75000,
                'offense' => 80,
                'durability' => 75,
                'stock' => 2,
                'max_stock' => 2,
            ],
            [
                'name' => 'Kevlar Armor',
                'slug' => 'kevlar-armor',
                'type' => 'armor',
                'slot' => 'armor',
                'description' => 'Standard protection against small arms fire.',
                'image_url' => '/images/weapons/Kevlar Armor.png',
                'price' => 10000,
                'defense' => 50,
                'durability' => 100,
                'stock' => 5,
                'max_stock' => 5,
            ],
            [
                'name' => 'UHMWPE Armor',
                'slug' => 'uhmwpe-armor',
                'type' => 'armor',
                'slot' => 'armor',
                'description' => 'Advanced lightweight protection for high-stakes encounters.',
                'image_url' => '/images/weapons/UHMWPE Armor .png',
                'price' => 45000,
                'defense' => 100,
                'durability' => 150,
                'stock' => 3,
                'max_stock' => 3,
            ],
        ];

        foreach ($items as $item) {
            GameItem::updateOrCreate(['slug' => $item['slug']], $item);
        }
    }
}
