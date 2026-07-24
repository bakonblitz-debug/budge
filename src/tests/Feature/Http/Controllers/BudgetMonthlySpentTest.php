<?php

use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('reports monthly_spent as the calendar-month actual, not a rescaled per-period spend', function () {
    $account = BankAccount::factory()->create(['user_id' => $this->user->id]);
    $car = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Car']);

    Budget::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $car->id,
        'amount' => 200, 'period' => 'weekly', 'is_active' => true,
        'start_date' => now()->subYear()->toDateString(),
    ]);

    // A single $200 payment this month — the month's real spend is $200.
    Transaction::factory()->expense(200)->categorized($car)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $account->id, 'transaction_date' => now(),
    ]);

    // Buggy behavior rescales the weekly spend (200 × 52/12 ≈ 866.67); the headline
    // should be the actual month spend instead.
    $this->get('/budgets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Budgets/Index')
            ->where('totals.monthly_spent', fn ($v) => (float) $v === 200.0));
});
