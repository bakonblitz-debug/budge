<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
    $this->account = BankAccount::factory()->create(['user_id' => $this->user->id, 'type' => 'chequing']);

    // `popular` is given a worse sort_order than `rare`, so if the picker were
    // still ordered by sort_order alone it would come second — proving that
    // usage frequency takes precedence.
    $this->popular = Category::factory()->create([
        'user_id' => $this->user->id, 'name' => 'Popular', 'sort_order' => 99,
    ]);
    $this->rare = Category::factory()->create([
        'user_id' => $this->user->id, 'name' => 'Rare', 'sort_order' => 0,
    ]);

    Transaction::factory()->count(3)->categorized($this->popular)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $this->account->id,
    ]);
    Transaction::factory()->categorized($this->rare)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $this->account->id,
    ]);
});

it('orders the transactions page category options by usage', function () {
    $this->get('/transactions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.id', $this->popular->id)
            ->where('categories.1.id', $this->rare->id));
});

it('orders the categorize page category options by usage', function () {
    $this->get('/categorize')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.id', $this->popular->id)
            ->where('categories.1.id', $this->rare->id));
});

it('counts only the current user\'s transactions when ranking categories', function () {
    // Another user hammering a category must not influence this user's order.
    $other = User::factory()->create();
    $otherAccount = BankAccount::factory()->create(['user_id' => $other->id]);
    $otherCategory = Category::factory()->create(['user_id' => $other->id]);
    Transaction::factory()->count(10)->categorized($otherCategory)->create([
        'user_id' => $other->id, 'bank_account_id' => $otherAccount->id,
    ]);

    $this->get('/transactions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.id', $this->popular->id)
            ->where('categories.1.id', $this->rare->id)
            ->has('categories', 2));
});
