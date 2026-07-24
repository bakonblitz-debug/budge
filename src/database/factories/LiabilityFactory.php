<?php

namespace Database\Factories;

use App\Models\Liability;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Liability>
 */
class LiabilityFactory extends Factory
{
    protected $model = Liability::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'type' => 'loan',
            'institution' => fake()->company(),
            'balance' => fake()->randomFloat(2, 1000, 50000),
            'interest_rate' => fake()->randomFloat(2, 1, 10),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
