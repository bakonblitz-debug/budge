<?php

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);

    $this->chequing = BankAccount::factory()->create(['user_id' => $this->user->id, 'type' => 'chequing']);
    $this->savings = BankAccount::factory()->create(['user_id' => $this->user->id, 'type' => 'savings']);
});

function leg(int $accountId, int $userId, float $amount): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $userId,
        'bank_account_id' => $accountId,
        'amount' => $amount,
        'transaction_date' => '2026-04-10 10:00:00',
        'hash' => bin2hex(random_bytes(16)),
    ]);
}

it('links two selected transactions as a transfer', function () {
    $out = leg($this->chequing->id, $this->user->id, -500.00);
    $in = leg($this->savings->id, $this->user->id, 500.00);

    $this->post('/transfers', ['transaction_ids' => [$out->id, $in->id]])
        ->assertRedirect();

    expect(Transfer::count())->toBe(1)
        ->and($out->fresh()->transfer_id)->not->toBeNull()
        ->and($in->fresh()->transfer_id)->toBe($out->fresh()->transfer_id);
});

it('rejects linking two same-direction transactions', function () {
    $a = leg($this->chequing->id, $this->user->id, -500.00);
    $b = leg($this->savings->id, $this->user->id, -500.00);

    $this->post('/transfers', ['transaction_ids' => [$a->id, $b->id]])
        ->assertSessionHasErrors('transaction_ids');

    expect(Transfer::count())->toBe(0);
});

it('unlinks a transfer', function () {
    $out = leg($this->chequing->id, $this->user->id, -500.00);
    $in = leg($this->savings->id, $this->user->id, 500.00);
    $this->post('/transfers', ['transaction_ids' => [$out->id, $in->id]]);
    $transfer = Transfer::first();

    $this->delete("/transfers/{$transfer->id}")->assertRedirect();

    expect(Transfer::count())->toBe(0)
        ->and($out->fresh()->transfer_id)->toBeNull();
});

it('auto-detects transfers via the endpoint', function () {
    leg($this->chequing->id, $this->user->id, -500.00);
    leg($this->savings->id, $this->user->id, 500.00);

    $this->post('/transfers/detect')->assertRedirect();

    expect(Transfer::count())->toBe(1);
});
