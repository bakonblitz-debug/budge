<?php

use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\Liability;
use App\Models\NetWorthSnapshot;
use App\Models\User;
use App\Services\NetWorthService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('computes net worth as cash + assets − liabilities − credit owed', function () {
    BankAccount::factory()->chequing()->create(['user_id' => $this->user->id, 'current_balance' => 1000]);
    BankAccount::factory()->savings()->create(['user_id' => $this->user->id, 'current_balance' => 500]);
    BankAccount::factory()->creditCard()->create(['user_id' => $this->user->id, 'current_balance' => -300]); // owe $300

    Asset::factory()->create(['user_id' => $this->user->id, 'current_value' => 50000]);
    Asset::factory()->create(['user_id' => $this->user->id, 'current_value' => 1000]);
    Liability::factory()->create(['user_id' => $this->user->id, 'balance' => 20000]);

    $current = app(NetWorthService::class)->current();

    expect($current['cash'])->toBe(1500.0)
        ->and($current['assets'])->toBe(51000.0)
        ->and($current['credit_owed'])->toBe(300.0)
        ->and($current['liabilities'])->toBe(20300.0)
        ->and($current['net_worth'])->toBe(32200.0);
});

it('counts a negative credit-card balance as debt that reduces net worth', function () {
    BankAccount::factory()->chequing()->create(['user_id' => $this->user->id, 'current_balance' => 2000]);
    // Negative balance = money owed (the app's signed-balance convention).
    BankAccount::factory()->creditCard()->create(['user_id' => $this->user->id, 'current_balance' => -1735.24]);

    $current = app(NetWorthService::class)->current();

    expect($current['credit_owed'])->toBe(1735.24)
        ->and($current['net_worth'])->toBe(264.76); // 2000 − 1735.24
});

it('treats a positive (overpaid) credit-card balance as an asset, not a debt', function () {
    BankAccount::factory()->chequing()->create(['user_id' => $this->user->id, 'current_balance' => 1000]);
    BankAccount::factory()->creditCard()->create(['user_id' => $this->user->id, 'current_balance' => 200]);

    $current = app(NetWorthService::class)->current();

    expect($current['credit_owed'])->toBe(0.0)
        ->and($current['net_worth'])->toBe(1200.0); // 1000 + 200 overpayment
});

it('surfaces credit_overpaid so the breakdown reconciles to net_worth (NW-1)', function () {
    BankAccount::factory()->chequing()->create(['user_id' => $this->user->id, 'current_balance' => 1000]);
    BankAccount::factory()->creditCard()->create(['user_id' => $this->user->id, 'current_balance' => 200]); // overpaid
    Asset::factory()->create(['user_id' => $this->user->id, 'current_value' => 500]);
    Liability::factory()->create(['user_id' => $this->user->id, 'balance' => 100]);

    $current = app(NetWorthService::class)->current();

    expect($current['credit_overpaid'])->toBe(200.0)
        ->and(round(
            $current['cash'] + $current['assets'] + $current['credit_overpaid'] - $current['liabilities'],
            2,
        ))->toBe($current['net_worth']);
});

it('reports credit_overpaid as 0.0 when no credit account is overpaid', function () {
    BankAccount::factory()->chequing()->create(['user_id' => $this->user->id, 'current_balance' => 1000]);
    BankAccount::factory()->creditCard()->create(['user_id' => $this->user->id, 'current_balance' => -300]);

    $current = app(NetWorthService::class)->current();

    expect($current['credit_overpaid'])->toBe(0.0)
        ->and(round(
            $current['cash'] + $current['assets'] + $current['credit_overpaid'] - $current['liabilities'],
            2,
        ))->toBe($current['net_worth']);
});

it('excludes inactive assets and liabilities', function () {
    Asset::factory()->create(['user_id' => $this->user->id, 'current_value' => 5000, 'is_active' => false]);
    Liability::factory()->create(['user_id' => $this->user->id, 'balance' => 1000, 'is_active' => false]);

    $current = app(NetWorthService::class)->current();

    expect($current['assets'])->toBe(0.0)
        ->and($current['liabilities'])->toBe(0.0);
});

it('upserts one snapshot per day', function () {
    Asset::factory()->create(['user_id' => $this->user->id, 'current_value' => 10000]);

    $service = app(NetWorthService::class);
    $service->snapshot();
    $service->snapshot();

    expect(NetWorthSnapshot::count())->toBe(1)
        ->and((float) NetWorthSnapshot::first()->net_worth)->toBe(10000.0);
});
