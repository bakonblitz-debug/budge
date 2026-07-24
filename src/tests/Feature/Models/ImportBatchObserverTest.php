<?php

use App\Models\BankAccount;
use App\Models\ImportBatch;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('rescans recurring series when an import batch completes', function () {
    // GitHub-like: a series stale-marked 'ended' from an old scan, but real
    // charges continued right up to a recent date. Nothing re-runs detect()
    // until an import batch flips to 'completed'.
    $now = Carbon::now();
    foreach ([90, 60, 30, 0] as $daysAgo) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'description' => 'GITHUB',
            'merchant_name' => 'GITHUB',
            'amount' => -10.00,
            'transaction_date' => $now->copy()->subDays($daysAgo)->toDateTimeString(),
            'hash' => bin2hex(random_bytes(16)),
        ]);
    }

    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'github',
        'cadence' => 'monthly',
        'expected_amount' => 10.00,
        'status' => 'ended',
    ]);

    $account = BankAccount::factory()->create(['user_id' => $this->user->id]);
    $batch = ImportBatch::factory()->processing()->forAccount($account)->create();

    $batch->update(['status' => 'completed']);

    expect(RecurringSeries::where('merchant_key', 'github')->first()->status)->toBe('active');
});

it('does not rescan when a batch is created already completed or moves to failed', function () {
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'github',
        'status' => 'ended',
    ]);

    $account = BankAccount::factory()->create(['user_id' => $this->user->id]);

    // Created directly as completed (no status *change*) — observer's
    // `updated` hook should not fire from creation.
    ImportBatch::factory()->forAccount($account)->create();

    expect(RecurringSeries::where('merchant_key', 'github')->first()->status)->toBe('ended');

    $batch = ImportBatch::factory()->processing()->forAccount($account)->create(['period_month' => 5]);
    $batch->update(['status' => 'failed']);

    expect(RecurringSeries::where('merchant_key', 'github')->first()->status)->toBe('ended');
});
