<?php

use App\Models\IncomeEntry;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('computes income distribution stats over the last 12 months only', function () {
    // Two recent pays, in-window.
    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'amount' => 2000, 'pay_date' => now()->subMonth()->toDateString()]);
    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'amount' => 2000, 'pay_date' => now()->subMonths(2)->toDateString()]);
    // An old source far outside the window with a skewing amount — must be ignored.
    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'amount' => 50, 'pay_date' => now()->subYears(2)->toDateString()]);

    $this->get('/income')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Income/Index')
            ->where('stats.count', 2)
            ->where('stats.median', fn ($v) => (float) $v === 2000.0)
            ->where('stats.window_label', 'last 12 months'));
});
