<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CategoryRule>
 */
class CategoryRuleFactory extends Factory
{
    protected $model = CategoryRule::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category_id' => Category::factory(),
            'match_type' => 'contains',
            'match_value' => strtoupper(fake()->word()),
            'priority' => 100,
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

    public function contains(string $value): static
    {
        return $this->state(['match_type' => 'contains', 'match_value' => $value]);
    }

    public function startsWith(string $value): static
    {
        return $this->state(['match_type' => 'starts_with', 'match_value' => $value]);
    }

    public function exact(string $value): static
    {
        return $this->state(['match_type' => 'exact', 'match_value' => $value]);
    }

    public function regex(string $value): static
    {
        return $this->state(['match_type' => 'regex', 'match_value' => $value]);
    }

    public function priority(int $priority): static
    {
        return $this->state(['priority' => $priority]);
    }
}
