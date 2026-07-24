<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'type' => 'investment',
            'institution' => fake()->company(),
            'current_value' => fake()->randomFloat(2, 1000, 100000),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
