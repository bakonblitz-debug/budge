<?php

namespace Database\Factories;

use App\Models\MerchantAlias;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantAlias>
 */
class MerchantAliasFactory extends Factory
{
    protected $model = MerchantAlias::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'match_type' => 'contains',
            'match_value' => strtoupper(fake()->word()),
            'display_name' => fake()->company(),
            'priority' => 0,
            'is_active' => true,
        ];
    }
}
