<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'parent_id' => null,
            'name' => fake()->word(),
            'icon' => 'mdi-tag',
            'color' => fake()->hexColor(),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function childOf(Category $parent): static
    {
        return $this->state([
            'parent_id' => $parent->id,
            'user_id' => $parent->user_id,
        ]);
    }

    public function kind(?string $kind): static
    {
        return $this->state(['kind' => $kind]);
    }
}
