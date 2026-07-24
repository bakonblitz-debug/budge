<?php

use App\Models\Transaction;
use App\Models\User;
use App\Services\ReportingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('anchors to the latest transaction date for the current user', function () {
    // "Today" is an empty, in-progress month with no data.
    Carbon::setTestNow('2026-06-07 10:00:00');

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'transaction_date' => '2026-04-15 00:00:00',
        'hash' => 'rp-old',
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'transaction_date' => '2026-05-22 00:00:00',
        'hash' => 'rp-latest',
    ]);

    $anchor = app(ReportingPeriod::class)->anchor();

    expect($anchor)->toBeInstanceOf(CarbonImmutable::class)
        ->and($anchor->format('Y-m-d'))->toBe('2026-05-22')
        ->and($anchor->format('Y-m'))->toBe('2026-05');
});

it('falls back to now() when the user has no transactions', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    $anchor = app(ReportingPeriod::class)->anchor();

    expect($anchor)->toBeInstanceOf(CarbonImmutable::class)
        ->and($anchor->format('Y-m-d'))->toBe('2026-06-07');
});

it('is scoped to the authenticated user', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    // Another user's later transaction must not leak into the anchor.
    $other = User::factory()->create();
    Transaction::factory()->create([
        'user_id' => $other->id,
        'transaction_date' => '2026-06-01 00:00:00',
        'hash' => 'rp-other',
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'transaction_date' => '2026-05-10 00:00:00',
        'hash' => 'rp-mine',
    ]);

    $anchor = app(ReportingPeriod::class)->anchor();

    expect($anchor->format('Y-m-d'))->toBe('2026-05-10');
});

it('flags the anchor month as partial when data stops well before month end', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'transaction_date' => '2026-05-08 00:00:00', // 8th of a 31-day month
        'hash' => 'rp-partial',
    ]);

    $meta = app(ReportingPeriod::class)->currentMonthMeta();

    expect($meta['likely_partial'])->toBeTrue()
        ->and($meta['days_in_month'])->toBe(31)
        ->and($meta['days_with_data'])->toBe(8);
});

it('does not flag a month with data through (near) the end', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'transaction_date' => '2026-05-30 00:00:00', // 30th of a 31-day month
        'hash' => 'rp-complete',
    ]);

    expect(app(ReportingPeriod::class)->currentMonthMeta()['likely_partial'])->toBeFalse();
});

it('windowStart is overflow-safe from a day-31 anchor', function () {
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'transaction_date' => '2026-03-31 00:00:00', // day-31 anchor
        'hash' => 'rp-w31',
    ]);

    $period = app(ReportingPeriod::class);

    // Plain subMonths(1)->startOfMonth() from Mar 31 rolls into "Mar 3" (Feb
    // has no 31st) before startOfMonth() masks it back to Mar 1 — same month,
    // NOT one month back. subMonthsNoOverflow correctly lands in February.
    expect($period->windowStart(1)->format('Y-m-d'))->toBe('2026-02-01')
        ->and($period->windowStart(3)->format('Y-m-d'))->toBe('2025-12-01')
        ->and($period->anchorMonth())->toBe('2026-03')
        ->and($period->previousMonthKey())->toBe('2026-02');
});
