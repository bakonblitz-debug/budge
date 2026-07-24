<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserProfile>
 */
class UserProfileFactory extends Factory
{
    protected $model = UserProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'gross_salary' => fake()->randomFloat(2, 50000, 110000),
            'net_pay_per_period' => fake()->randomFloat(2, 1500, 2500),
            'pay_frequency' => 'bi_weekly',
            'budget_start_date' => now()->startOfYear()->toDateString(),
            'currency' => 'CAD',
            'province' => 'QC',
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }
}
