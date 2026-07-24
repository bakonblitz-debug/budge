<?php

use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('real() excludes transfer legs and manually excluded rows', function () {
    $transfer = Transfer::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->create(['user_id' => $this->user->id, 'hash' => 'real-1', 'transfer_id' => null, 'is_excluded' => false]);
    Transaction::factory()->create(['user_id' => $this->user->id, 'hash' => 'real-2', 'transfer_id' => $transfer->id, 'is_excluded' => false]);
    Transaction::factory()->create(['user_id' => $this->user->id, 'hash' => 'real-3', 'transfer_id' => null, 'is_excluded' => true]);

    $real = Transaction::query()->real()->pluck('hash')->all();

    expect($real)->toBe(['real-1']);
});

it('expense() is real() plus amount < 0', function () {
    $transfer = Transfer::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->expense(50)->create(['user_id' => $this->user->id, 'hash' => 'exp-1', 'transfer_id' => null, 'is_excluded' => false]);
    Transaction::factory()->income(50)->create(['user_id' => $this->user->id, 'hash' => 'exp-2', 'transfer_id' => null, 'is_excluded' => false]);
    Transaction::factory()->expense(50)->create(['user_id' => $this->user->id, 'hash' => 'exp-3', 'transfer_id' => $transfer->id, 'is_excluded' => false]);
    Transaction::factory()->expense(50)->create(['user_id' => $this->user->id, 'hash' => 'exp-4', 'transfer_id' => null, 'is_excluded' => true]);

    $expenses = Transaction::query()->expense()->pluck('hash')->all();

    expect($expenses)->toBe(['exp-1']);
});
