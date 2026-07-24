<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
{
    protected $model = BankAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['NBC Chequing', 'NBC Savings', 'NBC Mastercard']),
            'type' => 'chequing',
            'current_balance' => fake()->randomFloat(2, 0, 10000),
            'currency' => 'CAD',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function chequing(): static
    {
        return $this->state(['type' => 'chequing', 'name' => 'NBC Chequing']);
    }

    public function savings(): static
    {
        return $this->state(['type' => 'savings', 'name' => 'NBC Savings']);
    }

    public function creditCard(float $limit = 5000): static
    {
        return $this->state([
            'type' => 'credit_card',
            'name' => 'NBC Mastercard',
            'credit_limit' => $limit,
            'current_balance' => 0,
        ]);
    }
}
