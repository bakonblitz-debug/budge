<?php

namespace Database\Factories;

use App\Models\SavingsMilestone;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavingsMilestone>
 */
class SavingsMilestoneFactory extends Factory
{
    protected $model = SavingsMilestone::class;

    public function definition(): array
    {
        $target = fake()->randomElement([1000, 5000, 10000, 25000, 50000, 100000]);

        return [
            'user_id' => User::factory(),
            'name' => '$'.number_format($target / 1000).'K saved',
            'target_amount' => $target,
            'reached_at' => null,
            'is_reached' => false,
            'sort_order' => 0,
        ];
    }

    public function reached(): static
    {
        return $this->state([
            'is_reached' => true,
            'reached_at' => now(),
        ]);
    }

    public function target(float $amount): static
    {
        return $this->state(['target_amount' => $amount]);
    }
}
