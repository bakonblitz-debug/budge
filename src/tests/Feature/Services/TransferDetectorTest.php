<?php

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferDetector;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->chequing = BankAccount::factory()->create(['user_id' => $this->user->id, 'type' => 'chequing']);
    $this->savings = BankAccount::factory()->create(['user_id' => $this->user->id, 'type' => 'savings']);
});

/** Helper: make a transaction on a given account/amount/date. */
function txn(int $accountId, int $userId, float $amount, string $date): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $userId,
        'bank_account_id' => $accountId,
        'amount' => $amount,
        'transaction_date' => $date,
        'hash' => bin2hex(random_bytes(16)),
    ]);
}

it('links an equal-magnitude opposite-sign pair across accounts', function () {
    $out = txn($this->chequing->id, $this->user->id, -500.00, '2026-04-10 10:00:00');
    $in = txn($this->savings->id, $this->user->id, 500.00, '2026-04-11 09:00:00');

    $created = app(TransferDetector::class)->detect(3);

    expect($created)->toBe(1)
        ->and($out->fresh()->transfer_id)->not->toBeNull()
        ->and($in->fresh()->transfer_id)->toBe($out->fresh()->transfer_id);

    $transfer = Transfer::first();
    expect($transfer->from_transaction_id)->toBe($out->id)
        ->and($transfer->to_transaction_id)->toBe($in->id)
        ->and($transfer->detected_via)->toBe('auto');
});

it('does not link transactions on the same account', function () {
    txn($this->chequing->id, $this->user->id, -500.00, '2026-04-10 10:00:00');
    txn($this->chequing->id, $this->user->id, 500.00, '2026-04-11 09:00:00');

    expect(app(TransferDetector::class)->detect(3))->toBe(0);
    expect(Transfer::count())->toBe(0);
});

it('does not link mismatched amounts', function () {
    txn($this->chequing->id, $this->user->id, -500.00, '2026-04-10 10:00:00');
    txn($this->savings->id, $this->user->id, 480.00, '2026-04-11 09:00:00');

    expect(app(TransferDetector::class)->detect(3))->toBe(0);
});

it('does not link pairs outside the day window', function () {
    txn($this->chequing->id, $this->user->id, -500.00, '2026-04-10 10:00:00');
    txn($this->savings->id, $this->user->id, 500.00, '2026-04-20 09:00:00');

    expect(app(TransferDetector::class)->detect(3))->toBe(0);
});

it('excludes linked transfers from spending totals', function () {
    $out = txn($this->chequing->id, $this->user->id, -500.00, '2026-04-10 10:00:00');
    $in = txn($this->savings->id, $this->user->id, 500.00, '2026-04-11 09:00:00');
    // a real expense that should remain counted
    txn($this->chequing->id, $this->user->id, -42.00, '2026-04-12 09:00:00');

    app(TransferDetector::class)->detect(3);

    $spend = (float) Transaction::query()
        ->whereNull('transfer_id')
        ->where('amount', '<', 0)
        ->sum('amount');

    expect($spend)->toBe(-42.00);
});

it('unlinks a transfer and clears both legs', function () {
    $out = txn($this->chequing->id, $this->user->id, -500.00, '2026-04-10 10:00:00');
    $in = txn($this->savings->id, $this->user->id, 500.00, '2026-04-11 09:00:00');

    $detector = app(TransferDetector::class);
    $detector->detect(3);
    $transfer = Transfer::first();

    $detector->unlink($transfer);

    expect(Transfer::count())->toBe(0)
        ->and($out->fresh()->transfer_id)->toBeNull()
        ->and($in->fresh()->transfer_id)->toBeNull();
});

it('does not link an inflow far in the past (signed diffInDays must not pass the window)', function () {
    // Carbon v3 diffInDays is signed: an inflow BEFORE the outflow yields a
    // negative diff, which is always <= a positive day window unless abs()'d.
    txn($this->chequing->id, $this->user->id, -500.00, '2026-04-10 10:00:00');
    txn($this->savings->id, $this->user->id, 500.00, '2026-01-05 09:00:00'); // 95 days earlier

    expect(app(TransferDetector::class)->detect(3))->toBe(0);
    expect(Transfer::count())->toBe(0);
});

it('picks the nearest candidate leg, not the oldest, when several match', function () {
    $out = txn($this->chequing->id, $this->user->id, -500.00, '2026-04-10 10:00:00');
    // Far-past candidate (would wrongly win under a signed, unabsed sort).
    $farPast = txn($this->savings->id, $this->user->id, 500.00, '2026-01-05 09:00:00');
    // Next-day candidate — the true nearest leg.
    $nextDay = txn($this->savings->id, $this->user->id, 500.00, '2026-04-11 09:00:00');

    app(TransferDetector::class)->detect(365);

    expect($out->fresh()->transfer_id)->not->toBeNull();
    $transfer = Transfer::first();
    expect($transfer->to_transaction_id)->toBe($nextDay->id)
        ->and($farPast->fresh()->transfer_id)->toBeNull();
});

it('does not reuse an already-linked leg', function () {
    // Two outflows but only one matching inflow — only one transfer possible.
    txn($this->chequing->id, $this->user->id, -500.00, '2026-04-10 10:00:00');
    txn($this->chequing->id, $this->user->id, -500.00, '2026-04-10 11:00:00');
    txn($this->savings->id, $this->user->id, 500.00, '2026-04-11 09:00:00');

    expect(app(TransferDetector::class)->detect(3))->toBe(1);
});
