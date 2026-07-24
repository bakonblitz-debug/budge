<?php

use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\NetWorthSnapshot;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-06-19');
});

afterEach(fn () => Carbon::setTestNow(null));

it('snapshots net worth for every user and is idempotent', function () {
    // User 1: chequing $3000 + asset $20000 → net_worth ≈ 23000
    $u1 = User::factory()->create(['onboarding_completed_at' => now()]);
    BankAccount::factory()->chequing()->create(['user_id' => $u1->id, 'current_balance' => 3000]);
    Asset::factory()->create(['user_id' => $u1->id, 'current_value' => 20000, 'is_active' => true]);

    // User 2: chequing $1000
    $u2 = User::factory()->create(['onboarding_completed_at' => now()]);
    BankAccount::factory()->chequing()->create(['user_id' => $u2->id, 'current_balance' => 1000]);

    // Run the command once
    $this->artisan('networth:snapshot')->assertOk();

    // Each user should have exactly 1 snapshot for today
    $snap1 = NetWorthSnapshot::withoutGlobalScopes()->where('user_id', $u1->id)->get();
    $snap2 = NetWorthSnapshot::withoutGlobalScopes()->where('user_id', $u2->id)->get();

    expect($snap1)->toHaveCount(1)
        ->and((float) $snap1->first()->net_worth)->toBeWithin(23000.0, 1.0)
        ->and($snap2)->toHaveCount(1)
        ->and((float) $snap2->first()->net_worth)->toBeWithin(1000.0, 1.0);

    // Running again should not duplicate snapshots (idempotent via updateOrCreate)
    $this->artisan('networth:snapshot')->assertOk();

    expect(NetWorthSnapshot::withoutGlobalScopes()->where('user_id', $u1->id)->count())->toBe(1)
        ->and(NetWorthSnapshot::withoutGlobalScopes()->where('user_id', $u2->id)->count())->toBe(1);
});
