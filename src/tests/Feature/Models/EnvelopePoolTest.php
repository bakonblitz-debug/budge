<?php

use App\Models\Category;
use App\Models\EnvelopePool;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Carbon::setTestNow('2026-06-15 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow(null);
});

function poolTxn(User $user, ?Category $category, float $signed, string $date, array $overrides = []): Transaction
{
    static $seq = 0;
    $seq++;

    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'category_id' => $category?->id,
        'transaction_date' => $date.' 10:00:00',
        'description' => 'POOL'.$seq,
        'amount' => $signed,
        'hash' => 'pool'.$seq,
        'is_excluded' => false,
    ], $overrides));
}

it('excludes inter-account transfers from pool spend', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Car']);
    $pool = EnvelopePool::factory()->forCategory($cat)->create([
        'monthly_accrual' => 100, 'start_date' => '2026-01-01',
    ]);

    $base = $pool->calculated_balance;                       // accrued, no spend
    poolTxn($this->user, $cat, -100, '2026-03-10');          // real spend
    $afterSpend = $pool->calculated_balance;

    // A transfer (transfer_id set) sitting in the same category must NOT count.
    $transfer = Transfer::factory()->create(['user_id' => $this->user->id]);
    poolTxn($this->user, $cat, -500, '2026-03-12', ['transfer_id' => $transfer->id]);
    $afterTransfer = $pool->calculated_balance;

    expect(round($base - $afterSpend, 2))->toBe(100.0)       // the real $100 counted
        ->and($afterTransfer)->toBe($afterSpend);            // the $500 transfer ignored
});

it('rolls up child-category spend when the pool targets a parent', function () {
    $parent = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transport']);
    $child = Category::factory()->childOf($parent)->create(['name' => 'Gas']);
    $pool = EnvelopePool::factory()->forCategory($parent)->create([
        'monthly_accrual' => 100, 'start_date' => '2026-01-01',
    ]);

    poolTxn($this->user, $parent, -100, '2026-03-10');
    $afterParent = $pool->calculated_balance;
    poolTxn($this->user, $child, -50, '2026-03-11');         // child spend rolls up
    $afterChild = $pool->calculated_balance;

    expect(round($afterParent - $afterChild, 2))->toBe(50.0);
});

it('does not count spend from unrelated categories (catch-all safety)', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Vacation']);
    $other = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    $pool = EnvelopePool::factory()->forCategory($cat)->create([
        'monthly_accrual' => 100, 'start_date' => '2026-01-01',
    ]);

    poolTxn($this->user, $cat, -100, '2026-03-10');
    $afterOwn = $pool->calculated_balance;
    poolTxn($this->user, $other, -999, '2026-03-11');        // unrelated category
    $afterOther = $pool->calculated_balance;

    expect($afterOther)->toBe($afterOwn);
});

it('ignores excluded, positive, and pre-start transactions', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Gifts']);
    $pool = EnvelopePool::factory()->forCategory($cat)->create([
        'monthly_accrual' => 100, 'start_date' => '2026-01-01',
    ]);

    $base = $pool->calculated_balance;
    poolTxn($this->user, $cat, -200, '2025-12-15');                       // pre-start
    poolTxn($this->user, $cat, -300, '2026-03-10', ['is_excluded' => true]); // excluded
    poolTxn($this->user, $cat, 400, '2026-03-11');                        // refund/positive

    expect($pool->calculated_balance)->toBe($base);
});
