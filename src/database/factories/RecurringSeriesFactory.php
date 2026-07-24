<?php

namespace Database\Factories;

use App\Models\RecurringSeries;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringSeries>
 */
class RecurringSeriesFactory extends Factory
{
    protected $model = RecurringSeries::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => null,
            'merchant_key' => fake()->company(),
            'cadence' => 'monthly',
            'expected_amount' => fake()->randomFloat(2, 5, 100),
            'last_amount' => fake()->randomFloat(2, 5, 100),
            'amount_increased' => false,
            'occurrences' => 3,
            'last_seen_at' => now()->subDays(5)->toDateString(),
            'next_expected_at' => now()->addDays(25)->toDateString(),
            'status' => 'active',
            'is_manual' => false,
        ];
    }

    /** A series the user dismissed as a false positive. */
    public function dismissed(): static
    {
        return $this->state(fn (): array => ['status' => 'dismissed']);
    }

    /** A series the user promoted into a fixed expense. */
    public function converted(): static
    {
        return $this->state(fn (): array => ['status' => 'converted']);
    }

    /** A series the user added by hand from a candidate suggestion. */
    public function manual(): static
    {
        return $this->state(fn (): array => ['is_manual' => true]);
    }
}
