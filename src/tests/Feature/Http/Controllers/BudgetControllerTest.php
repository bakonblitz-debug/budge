<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;

/**
 * Pins BudgetController's period→monthly conversion (weekly x52/12, monthly
 * x1, yearly /12) ahead of delegating to Frequency::budgetPeriodMonthlyAmount() —
 * a behavior-neutral consolidation, so these totals must not change.
 */
beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('converts each budget period to its monthly equivalent unchanged', function () {
    $weekly = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    $monthly = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Rent']);
    $yearly = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Insurance']);

    Budget::factory()->forCategory($weekly)->period('weekly')->create(['amount' => 100, 'is_active' => true]);
    Budget::factory()->forCategory($monthly)->period('monthly')->create(['amount' => 100, 'is_active' => true]);
    Budget::factory()->forCategory($yearly)->period('yearly')->create(['amount' => 120, 'is_active' => true]);

    // weekly: 100 * 52/12 = 433.33; monthly: 100; yearly: 120/12 = 10 -> 543.33
    $expected = round(100 * 52 / 12 + 100 + 120 / 12, 2);

    $this->get('/budgets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Budgets/Index')
            ->where('totals.monthly_budgeted', fn ($v) => (float) $v === $expected));
});

it('excludes inactive budgets from the monthly-equivalent total', function () {
    $active = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    $inactive = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Old Gym']);

    Budget::factory()->forCategory($active)->period('monthly')->create(['amount' => 50, 'is_active' => true]);
    Budget::factory()->forCategory($inactive)->period('monthly')->create(['amount' => 999, 'is_active' => false]);

    $this->get('/budgets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Budgets/Index')
            ->where('totals.monthly_budgeted', fn ($v) => (float) $v === 50.0));
});
