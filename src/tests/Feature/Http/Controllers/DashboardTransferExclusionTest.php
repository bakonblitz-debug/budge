<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\IncomeEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserProfile;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('exposes the user pay frequency so the income card can show its cadence', function () {
    UserProfile::factory()->create(['user_id' => $this->user->id, 'pay_frequency' => 'bi_weekly']);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('incomeFrequency', 'bi_weekly'));
});

it('does not leak another user pay frequency when the current user has neither profile nor income', function () {
    $other = User::factory()->create();
    UserProfile::factory()->create(['user_id' => $other->id, 'pay_frequency' => 'monthly']);
    // $this->user has no profile and no income entries.

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('incomeFrequency', null));
});

it('falls back to the dominant income-entry cadence when there is no profile', function () {
    // No UserProfile, but a recurring income entry exists.
    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'frequency' => 'bi_weekly', 'pay_date' => now()->toDateString()]);
    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'frequency' => 'one_time', 'pay_date' => now()->toDateString()]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('incomeFrequency', 'bi_weekly')); // one_time ignored
});

it("includes a parent's own direct spend as a child line so children sum to the parent total", function () {
    $account = BankAccount::factory()->create(['user_id' => $this->user->id]);
    $food = Category::factory()->kind('need')->create(['user_id' => $this->user->id, 'name' => 'Food']);
    $groceries = Category::factory()->kind('need')->childOf($food)->create(['name' => 'Groceries']);

    Transaction::factory()->expense(200)->categorized($food)->create([ // parent's OWN direct spend
        'user_id' => $this->user->id, 'bank_account_id' => $account->id, 'transaction_date' => now(),
    ]);
    Transaction::factory()->expense(600)->categorized($groceries)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $account->id, 'transaction_date' => now(),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('spendingByCategory', function ($cats) {
                $food = collect($cats)->firstWhere('name', 'Food');

                return $food !== null
                    && abs((float) $food['spent'] - 800.0) < 0.01
                    && abs(collect($food['children'])->sum('spent') - (float) $food['spent']) < 0.01;
            }));
});

it('excludes transfers from every excluded-kind tree, not just the first named one', function () {
    $account = BankAccount::factory()->create(['user_id' => $this->user->id]);

    // Duplicate "Transfers" trees, both kind=excluded (matches the real DB).
    Category::factory()->kind('excluded')->create(['user_id' => $this->user->id, 'name' => 'Transfers']);
    $transfersB = Category::factory()->kind('excluded')->create(['user_id' => $this->user->id, 'name' => 'Transfers']);

    $groceries = Category::factory()->kind('need')->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    // A real expense, and a transfer filed under the SECOND transfers tree.
    Transaction::factory()->expense(100)->categorized($groceries)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $account->id, 'transaction_date' => now(),
    ]);
    Transaction::factory()->expense(500)->categorized($transfersB)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $account->id, 'transaction_date' => now(),
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('metrics.expenses', fn ($v) => (float) $v === 100.0));
});
