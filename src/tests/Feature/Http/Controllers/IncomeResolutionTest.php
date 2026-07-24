<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('counts statement income from every income-kind tree, not just the first', function () {
    $account = BankAccount::factory()->create(['user_id' => $this->user->id]);

    // The real DB has duplicate top-level "Income" trees; name-based ->first()
    // resolution silently counts only one of them. Resolving by kind must capture both.
    $incomeA = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);
    $incomeB = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);

    Transaction::factory()->income(2000)->categorized($incomeA)->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $account->id,
        'transaction_date' => now()->subWeek(),
    ]);
    Transaction::factory()->income(1500)->categorized($incomeB)->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $account->id,
        'transaction_date' => now()->subWeek(),
    ]);

    $this->get('/income')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Income/Index')
            ->where('totals.last_12_weeks', fn ($v) => (float) $v === 3500.0));
});

it('excludes is_excluded income transactions from the income ledger totals', function () {
    $account = BankAccount::factory()->create(['user_id' => $this->user->id]);
    $income = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);

    foreach ([2000, 2000, 2000] as $amt) {
        Transaction::factory()->income($amt)->categorized($income)->create([
            'user_id' => $this->user->id, 'bank_account_id' => $account->id, 'transaction_date' => now()->subWeek(),
        ]);
    }
    // An excluded duplicate import must NOT pollute the totals.
    Transaction::factory()->income(2000)->categorized($income)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $account->id,
        'transaction_date' => now()->subWeek(), 'is_excluded' => true,
    ]);

    $this->get('/income')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Income/Index')
            ->where('totals.last_12_weeks', fn ($v) => (float) $v === 6000.0));
});

it('counts statement income filed under a child of an income category, even if the child is untagged', function () {
    $account = BankAccount::factory()->create(['user_id' => $this->user->id]);

    $incomeParent = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);
    // A child added later via the UI gets no kind — it must still be treated as income.
    $bonus = Category::factory()->kind(null)->childOf($incomeParent)->create(['name' => 'Bonus']);

    Transaction::factory()->income(800)->categorized($bonus)->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $account->id,
        'transaction_date' => now()->subWeek(),
    ]);

    $this->get('/income')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Income/Index')
            ->where('totals.last_12_weeks', fn ($v) => (float) $v === 800.0));
});
