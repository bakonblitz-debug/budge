<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\EnvelopePool;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnvelopePool>
 */
class EnvelopePoolFactory extends Factory
{
    protected $model = EnvelopePool::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'name' => fake()->words(2, true).' pool',
            'monthly_accrual' => fake()->randomFloat(2, 20, 300),
            'current_balance' => 0,
            'start_date' => now()->subMonths(6)->toDateString(),
            'is_active' => true,
        ];
    }

    public function forCategory(Category $category): static
    {
        return $this->state([
            'category_id' => $category->id,
            'user_id' => $category->user_id,
        ]);
    }
}
