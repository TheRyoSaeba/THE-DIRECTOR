<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\City;
use Illuminate\Database\Seeder;

class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        $commonServices = [
            [
                'code' => 'bank',
                'name' => 'National Bank',
                'slug' => 'bank',
                'description' => 'Deposit, withdraw, and transfer funds',
                'icon' => 'building-2',
                'is_purchasable' => true,
                'base_price' => 500000,
                'owner_title' => 'Owner',
                'leader_career_code' => 'banking',
                'leader_title' => 'State Bank Chairman',
                'sort_order' => 1,
            ],
            [
                'code' => 'hospital',
                'name' => 'General Hospital',
                'slug' => 'hospital',
                'description' => 'Medical treatment and healing',
                'icon' => 'heart-pulse',
                'is_purchasable' => true,
                'base_price' => 400000,
                'owner_title' => 'Owner',
                'leader_career_code' => 'healthcare',
                'leader_title' => 'Surgeon General',
                'sort_order' => 2,
            ],
            [
                'code' => 'police',
                'name' => 'Police HQ',
                'slug' => 'police',
                'description' => 'Law enforcement and bounties',
                'icon' => 'shield',
                'is_purchasable' => false,
                'leader_career_code' => 'police',
                'leader_title' => 'Commissioner General',
                'sort_order' => 3,
            ],
            [
                'code' => 'city-hall',
                'name' => 'City Hall',
                'slug' => 'city-hall',
                'description' => 'Government services and elections',
                'icon' => 'landmark',
                'is_purchasable' => false,
                'leader_career_code' => 'politics',
                'leader_title' => 'Mayor',
                'sort_order' => 4,
            ],
        ];

        foreach (City::all() as $city) {
            foreach ($commonServices as $service) {
                $businessData = array_merge($service, ['city_id' => $city->id]);

                if ($service['code'] === 'bank') {
                    $businessData['balance'] = $city->bank_vault ?? 0;
                    $businessData['data'] = [
                        'loan_rate' => $city->loan_rate ?? 5,
                        'loan_amount' => $city->loan_amount ?? 50000,
                    ];
                }

                Business::updateOrCreate(
                ['city_id' => $city->id, 'code' => $service['code']],
                    $businessData
                );
            }

            if ($city->slug === 'tokyo') {
                Business::updateOrCreate(
                ['city_id' => $city->id, 'code' => 'pachinko'],
                [
                    'name' => 'Golden Dragon Pachinko',
                    'slug' => 'pachinko',
                    'description' => 'A deafening neon paradise of silver balls and broken dreams.',
                    'icon' => 'gamepad-2',
                    'is_purchasable' => true,
                    'base_price' => 2500000,
                    'owner_title' => 'Parlor Manager',
                    'data' => [
                        'cost_per_ball' => 100,
                        'jackpot_chance' => 0.05,
                        'owner_greed' => 0.0,
                        'payout_multiplier' => 5,
                    ],
                    'sort_order' => 10,
                ]
                );
            }
        }
    }
}
