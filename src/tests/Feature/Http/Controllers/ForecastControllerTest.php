<?php

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
    $this->account = BankAccount::factory()->chequing()->create([
        'user_id' => $this->user->id,
        'current_balance' => 3000,
    ]);

    // Latest transaction date is the spending anchor the baseline counts back from.
    Transaction::factory()->expense(40)->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $this->account->id,
        'transaction_date' => '2026-06-01 10:00:00',
    ]);
});

it('renders the forecast with a spending baseline', function () {
    $this->get('/forecast')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Forecast/Index')
            ->has('forecast.baseline.daily')
            ->has('forecast.baseline.start'));
});

it('counts the baseline window back from the latest transaction for a months preset', function () {
    // anchor = 2026-06-01, 2 months back = 2026-04-01.
    $this->get('/forecast?baseline_months=2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('forecast.baseline.start', '2026-04-01'));
});

it('honours an explicit baseline start date', function () {
    $this->get('/forecast?baseline_start=2026-05-15')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('forecast.baseline.start', '2026-05-15'));
});

it('still respects the horizon days param', function () {
    $this->get('/forecast?days=90')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('forecast.horizon_days', 90));
});
