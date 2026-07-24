<?php

use App\Models\UserProfile;

/**
 * Pins UserProfile::monthly_net's existing per-frequency factors and its
 * deliberate "unknown pay_frequency ⇒ $0" default (no income, never
 * "treat as monthly") ahead of delegating to Frequency::payMonthlyAmount().
 *
 * Pure accessor math — no DB round-trip needed, and an unsaved model lets the
 * "unknown value" case bypass the pay_frequency DB enum constraint (which
 * only allows the 4 known values) to exercise the PHP-level default directly.
 */
function profileWith(string $payFrequency, float $netPayPerPeriod = 1000): UserProfile
{
    return new UserProfile([
        'net_pay_per_period' => $netPayPerPeriod,
        'pay_frequency' => $payFrequency,
    ]);
}

it('converts bi_weekly net pay to its monthly equivalent', function () {
    expect(profileWith('bi_weekly', 1000)->monthly_net)->toBe(1000 * 26 / 12);
});

it('converts weekly net pay to its monthly equivalent', function () {
    expect(profileWith('weekly', 1000)->monthly_net)->toBe(1000 * 52 / 12);
});

it('converts semi_monthly net pay to its monthly equivalent (period amount x2)', function () {
    expect(profileWith('semi_monthly', 1000)->monthly_net)->toBe(2000.0);
});

it('passes monthly net pay through unchanged', function () {
    expect(profileWith('monthly', 1000)->monthly_net)->toBe(1000.0);
});

it('defaults an unknown pay_frequency to zero income, never treating it as monthly', function () {
    expect(profileWith('fortnightly', 1000)->monthly_net)->toBe(0.0)
        ->and(profileWith('', 1000)->monthly_net)->toBe(0.0);
});
