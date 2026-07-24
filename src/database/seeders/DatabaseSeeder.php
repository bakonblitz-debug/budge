<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'dev@budgetapp.local'],
            [
                'name' => 'Dev',
                'password' => bcrypt('dev'),
                'email_verified_at' => now(),
            ],
        );

        $this->call(DemoSeeder::class);
    }
}
