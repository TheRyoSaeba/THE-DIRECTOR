<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;


class DevSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const ACCOUNTS = [
        ['username' => 'dev_alpha@test.local',   'email' => 'dev_alpha@test.local',   'last_ip' => '10.0.0.1'],
        ['username' => 'dev_bravo@test.local',   'email' => 'dev_bravo@test.local',   'last_ip' => '10.0.0.2'],
        ['username' => 'dev_charlie@test.local', 'email' => 'dev_charlie@test.local', 'last_ip' => '10.0.0.3'],
        ['username' => 'dev_delta@test.local',   'email' => 'dev_delta@test.local',   'last_ip' => '10.0.0.4'],
        ['username' => 'dev_echo@test.local',    'email' => 'dev_echo@test.local',    'last_ip' => '10.0.0.5'],
    ];

    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command->error('DevSeeder must not be run outside local environment.');
            return;
        }

        foreach (self::ACCOUNTS as $i => $account) {
            $user = User::updateOrCreate(
                ['email' => $account['email']],
                [
                    'username'              => $account['username'],
                    'password'              => Hash::make(self::PASSWORD),
                    
                    'google_id'             => 'dev_fake_' . ($i + 1),
                    'google_token'          => null,
                    'google_refresh_token'  => null,
                    'last_ip'               => $account['last_ip'],
                ]
            );

            $this->command->info("  [DEV] {$account['email']} — id {$user->id}");
        }

        $this->command->info('');
        $this->command->info('  Password for all accounts: ' . self::PASSWORD);
        $this->command->info('  Login at /dev-login  (local env only)');
    }
}
