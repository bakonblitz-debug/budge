<?php

namespace Database\Factories;

use App\Models\IncomeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomeEntry>
 */
class IncomeEntryFactory extends Factory
{
    protected $model = IncomeEntry::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'source' => fake()->company(),
            'amount' => fake()->randomFloat(2, 500, 3000),
            'frequency' => 'bi_weekly',
            'pay_date' => now()->toDateString(),
            'is_net' => true,
            'notes' => null,
        ];
    }

    public function biWeekly(): static
    {
        return $this->state(['frequency' => 'bi_weekly']);
    }

    public function monthly(): static
    {
        return $this->state(['frequency' => 'monthly']);
    }

    public function gross(): static
    {
        return $this->state(['is_net' => false]);
    }
}
