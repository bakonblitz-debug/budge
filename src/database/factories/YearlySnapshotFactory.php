<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\YearlySnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<YearlySnapshot>
 */
class YearlySnapshotFactory extends Factory
{
    protected $model = YearlySnapshot::class;

    public function definition(): array
    {
        $income = fake()->randomFloat(2, 40000, 90000);
        $expenses = $income * fake()->randomFloat(2, 0.6, 0.9);
        $fixed = $expenses * 0.6;
        $savings = $income - $expenses;

        return [
            'user_id' => User::factory(),
            'year' => (int) now()->year,
            'total_income' => $income,
            'total_expenses' => $expenses,
            'total_fixed' => $fixed,
            'total_variable' => $expenses - $fixed,
            'net_savings' => $savings,
            'savings_rate' => round($savings / $income * 100, 2),
            'expense_ratio' => round($expenses / $income * 100, 2),
            'life_direction' => 'stable',
            'metadata' => [],
            'calculated_at' => now(),
        ];
    }

    public function forYear(int $year): static
    {
        return $this->state(['year' => $year]);
    }

    public function improving(): static
    {
        return $this->state(['life_direction' => 'improving']);
    }

    public function declining(): static
    {
        return $this->state(['life_direction' => 'declining']);
    }
}
