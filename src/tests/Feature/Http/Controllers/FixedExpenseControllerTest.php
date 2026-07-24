<?php

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashFlowForecaster;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

/** Seed a bi-weekly stream of $count categorized charges ending today. */
function seedStream(int $userId, string $merchant, float $amount, int $count, int $categoryId): void
{
    $now = Carbon::now();
    for ($i = 0; $i < $count; $i++) {
        Transaction::factory()->create([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'description' => $merchant,
            'merchant_name' => $merchant,
            'amount' => -abs($amount),
            'transaction_date' => $now->copy()->subDays($i * 14)->toDateTimeString(),
            'hash' => bin2hex(random_bytes(16)),
        ]);
    }
}

it('passes suggested fixed-expense candidates to the index page', function () {
    $transfers = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transfers']);
    seedStream($this->user->id, 'PLACEMENT NBI', 300.00, 6, $transfers->id);

    $this->get('/fixed-expenses')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('FixedExpenses/Index')
            ->has('suggestions')
            ->where('suggestions.0.merchant', 'Placement Nbi')
            ->where('suggestions.0.suggested_cadence', 'bi_weekly')
        );
});

it('creates a fixed expense from a suggestion via the store endpoint', function () {
    $transfers = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transfers']);

    $this->post('/fixed-expenses', [
        'name' => 'Placement Nbi',
        'amount' => 300.00,
        'frequency' => 'bi_weekly',
        'category_id' => $transfers->id,
        'due_day' => 15,
        'start_date' => Carbon::now()->toDateString(),
        'is_active' => true,
        'sort_order' => 0,
    ])->assertRedirect();

    $expense = FixedExpense::where('name', 'Placement Nbi')->first();
    expect($expense)->not->toBeNull()
        ->and((float) $expense->amount)->toBe(300.00)
        ->and($expense->frequency)->toBe('bi_weekly')
        ->and($expense->category_id)->toBe($transfers->id)
        ->and($expense->is_active)->toBeTrue();
});

it('deactivates a matching active RecurringSeries when a FixedExpense is created (CFF-1)', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Subscriptions']);
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'merchant_key' => 'netflix',
        'expected_amount' => 15.99,
        'cadence' => 'monthly',
        'status' => 'active',
    ]);

    $this->post('/fixed-expenses', [
        'name' => 'Netflix',
        'amount' => 15.99,
        'frequency' => 'monthly',
        'category_id' => $category->id,
        'start_date' => Carbon::now()->toDateString(),
        'is_active' => true,
    ])->assertRedirect();

    expect($series->fresh()->status)->toBe('converted');
});

it('leaves an active RecurringSeries untouched when a new FixedExpense does not match it', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Subscriptions']);
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'merchant_key' => 'spotify',
        'expected_amount' => 10.99,
        'cadence' => 'monthly',
        'status' => 'active',
    ]);

    $this->post('/fixed-expenses', [
        'name' => 'Rent',
        'amount' => 1675.00,
        'frequency' => 'monthly',
        'category_id' => $category->id,
        'start_date' => Carbon::now()->toDateString(),
        'is_active' => true,
    ])->assertRedirect();

    expect($series->fresh()->status)->toBe('active');
});

it('does not project two outflows for a bill once its matching series is converted (CFF-1)', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Subscriptions']);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'merchant_key' => 'netflix',
        'expected_amount' => 15.99,
        'cadence' => 'monthly',
        'status' => 'active',
        'next_expected_at' => Carbon::now()->addDays(5)->toDateString(),
    ]);

    $this->post('/fixed-expenses', [
        'name' => 'Netflix',
        'amount' => 15.99,
        'frequency' => 'monthly',
        'category_id' => $category->id,
        'due_day' => (int) Carbon::now()->addDays(5)->day,
        'start_date' => Carbon::now()->toDateString(),
        'is_active' => true,
    ])->assertRedirect();

    $forecast = app(CashFlowForecaster::class)->forecast(60);
    $netflixEvents = collect($forecast['events'])->filter(
        fn (array $e) => str_contains(strtolower($e['label']), 'netflix'),
    );

    // The converted series must no longer emit its own "recurring" event —
    // only the FixedExpense's "expense" event(s) should appear.
    expect($netflixEvents)->not->toBeEmpty()
        ->and($netflixEvents->pluck('kind')->unique()->all())->toBe(['expense']);
});
