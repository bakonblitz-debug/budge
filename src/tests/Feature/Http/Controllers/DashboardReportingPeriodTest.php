<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('anchors dashboard metrics to the latest data month, not the empty live month', function () {
    // "Today" is June 7 — an empty, in-progress month (statements lag ~1 month).
    Carbon::setTestNow('2026-06-07 10:00:00');

    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    // Seeded data lives in May 2026 (the latest month with imported data).
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $groceries->id,
        'transaction_date' => '2026-05-15 00:00:00',
        'description' => 'LOBLAWS',
        'amount' => -120.00,
        'hash' => 'dash-may',
        'is_excluded' => false,
    ]);
    // An April transaction that must NOT count toward the May anchor window.
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $groceries->id,
        'transaction_date' => '2026-04-10 00:00:00',
        'description' => 'METRO',
        'amount' => -80.00,
        'hash' => 'dash-apr',
        'is_excluded' => false,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('periodLabel', 'May 2026')
            ->where('metrics.expenses', fn ($v) => (float) $v === 120.0)
            ->has('spendingByCategory', 1)
            ->where('spendingByCategory.0.spent', fn ($v) => (float) $v === 120.0)
        );
});
