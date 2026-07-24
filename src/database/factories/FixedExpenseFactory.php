<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixedExpense>
 */
class FixedExpenseFactory extends Factory
{
    protected $model = FixedExpense::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'name' => fake()->words(2, true),
            'amount' => fake()->randomFloat(2, 20, 2000),
            'frequency' => 'monthly',
            'due_day' => fake()->numberBetween(1, 28),
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => null,
            'is_active' => true,
            'sort_order' => 0,
            'notes' => null,
        ];
    }

    public function forCategory(Category $category): static
    {
        return $this->state([
            'category_id' => $category->id,
            'user_id' => $category->user_id,
        ]);
    }

    public function frequency(string $frequency): static
    {
        return $this->state(['frequency' => $frequency]);
    }
}
