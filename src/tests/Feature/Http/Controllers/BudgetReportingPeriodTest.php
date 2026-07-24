<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('anchors budget spend and period label to the latest data month', function () {
    // "Today" is June 7 — an empty, in-progress month.
    Carbon::setTestNow('2026-06-07 10:00:00');

    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);
    Budget::factory()->forCategory($restaurants)->create(['amount' => 300, 'period' => 'monthly']);

    // Spend lands in May (the latest data month).
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $restaurants->id,
        'transaction_date' => '2026-05-12 00:00:00',
        'description' => 'DINER',
        'amount' => -200.00,
        'hash' => 'bud-may',
        'is_excluded' => false,
    ]);
    // June would be empty under a now()-anchor; April is outside the May window.
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $restaurants->id,
        'transaction_date' => '2026-04-12 00:00:00',
        'description' => 'OLD DINER',
        'amount' => -999.00,
        'hash' => 'bud-apr',
        'is_excluded' => false,
    ]);

    $this->get('/budgets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Budgets/Index')
            ->has('budgets', 1)
            ->where('budgets.0.period_label', 'May 2026')
            ->where('budgets.0.spent', fn ($v) => (float) $v === 200.0)
        );
});
