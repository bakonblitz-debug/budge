<?php

namespace Database\Factories;

use App\Models\NetWorthSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NetWorthSnapshot>
 */
class NetWorthSnapshotFactory extends Factory
{
    protected $model = NetWorthSnapshot::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'as_of' => now()->toDateString(),
            'total_cash' => 5000,
            'total_assets' => 50000,
            'total_liabilities' => 20000,
            'net_worth' => 35000,
            'metadata' => null,
        ];
    }
}
