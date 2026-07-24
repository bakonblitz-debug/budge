<?php

use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);

    $this->account = BankAccount::factory()->create(['user_id' => $this->user->id, 'type' => 'chequing']);
});

function overrideTxn(User $user, BankAccount $account, array $attrs = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'amount' => -25.00,
        'transaction_date' => '2026-06-01 10:00:00',
        'description' => 'ORIGINAL DESC',
        'hash' => bin2hex(random_bytes(16)),
    ], $attrs));
}

it('updates category_id, is_excluded and notes', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $transaction = overrideTxn($this->user, $this->account);

    $this->patch("/transactions/{$transaction->id}", [
        'category_id' => $category->id,
        'is_excluded' => true,
        'notes' => 'Refunded later',
    ])->assertRedirect();

    $fresh = $transaction->fresh();
    expect($fresh->category_id)->toBe($category->id)
        ->and($fresh->is_excluded)->toBeTrue()
        ->and($fresh->notes)->toBe('Refunded later');
});

it('allows clearing the category by passing null', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $transaction = overrideTxn($this->user, $this->account, ['category_id' => $category->id]);

    $this->patch("/transactions/{$transaction->id}", [
        'category_id' => null,
        'is_excluded' => false,
    ])->assertRedirect();

    expect($transaction->fresh()->category_id)->toBeNull();
});

it('does not allow editing amount, transaction_date or description', function () {
    $transaction = overrideTxn($this->user, $this->account);

    $this->patch("/transactions/{$transaction->id}", [
        'is_excluded' => true,
        'amount' => -999.99,
        'transaction_date' => '2020-01-01 00:00:00',
        'description' => 'HACKED',
    ])->assertRedirect();

    $fresh = $transaction->fresh();
    expect((float) $fresh->amount)->toBe(-25.00)
        ->and($fresh->description)->toBe('ORIGINAL DESC')
        ->and($fresh->transaction_date->format('Y-m-d'))->toBe('2026-06-01');
});

it('rejects a category_id belonging to another user', function () {
    $other = User::factory()->create();
    $otherCategory = Category::factory()->create(['user_id' => $other->id]);
    $transaction = overrideTxn($this->user, $this->account);

    $this->patch("/transactions/{$transaction->id}", [
        'category_id' => $otherCategory->id,
    ])->assertSessionHasErrors('category_id');

    expect($transaction->fresh()->category_id)->toBeNull();
});

it('cannot update another user\'s transaction', function () {
    $other = User::factory()->create();
    $otherAccount = BankAccount::factory()->create(['user_id' => $other->id]);
    $transaction = overrideTxn($other, $otherAccount);

    $this->patch("/transactions/{$transaction->id}", [
        'is_excluded' => true,
    ])->assertNotFound();
});

it('excludes an is_excluded transaction from a budget\'s spent total', function () {
    $now = CarbonImmutable::now();
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    Budget::factory()->forCategory($category)->create([
        'amount' => 500.00,
        'start_date' => $now->startOfMonth()->toDateString(),
    ]);

    $included = overrideTxn($this->user, $this->account, [
        'category_id' => $category->id,
        'amount' => -40.00,
        'transaction_date' => $now->toDateTimeString(),
    ]);
    $excluded = overrideTxn($this->user, $this->account, [
        'category_id' => $category->id,
        'amount' => -60.00,
        'transaction_date' => $now->toDateTimeString(),
        'is_excluded' => true,
    ]);

    $this->get('/budgets')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Budgets/Index')
            ->where('budgets.0.spent', fn ($spent) => (float) $spent === 40.0)
        );
});

it('excludes an is_excluded transaction from dashboard category totals', function () {
    $now = CarbonImmutable::now();
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    overrideTxn($this->user, $this->account, [
        'category_id' => $category->id,
        'amount' => -40.00,
        'transaction_date' => $now->toDateTimeString(),
    ]);
    overrideTxn($this->user, $this->account, [
        'category_id' => $category->id,
        'amount' => -60.00,
        'transaction_date' => $now->toDateTimeString(),
        'is_excluded' => true,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('spendingByCategory.0.spent', fn ($spent) => (float) $spent === 40.0)
        );
});

it('filters to uncategorized transactions via ?category=none', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    $uncategorized = overrideTxn($this->user, $this->account, ['category_id' => null]);
    $categorized = overrideTxn($this->user, $this->account, ['category_id' => $category->id]);

    $this->get('/transactions?category=none')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.data.0.id', $uncategorized->id)
            ->where('totals.count', 1)
        );
});

it('still filters transactions by a numeric category id', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    overrideTxn($this->user, $this->account, ['category_id' => null]);
    $categorized = overrideTxn($this->user, $this->account, ['category_id' => $category->id]);

    $this->get("/transactions?category={$category->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('transactions.data.0.id', $categorized->id)
            ->where('totals.count', 1)
        );
});

it('guards the transaction update route with hmac', function () {
    $routes = collect(Route::getRoutes())->filter(
        fn ($r) => $r->uri() === 'transactions/{transaction}' && in_array('PATCH', $r->methods(), true),
    );

    expect($routes)->not->toBeEmpty();
    $routes->each(fn ($r) => expect($r->gatherMiddleware())->toContain('hmac'));
});
