<?php

use App\Support\Frequency;

it('converts every cadence to its monthly-equivalent, pinning the existing factors', function () {
    expect(Frequency::Weekly->monthlyAmount(100))->toBe(100 * 52 / 12)
        ->and(Frequency::BiWeekly->monthlyAmount(100))->toBe(100 * 26 / 12)
        ->and(Frequency::Monthly->monthlyAmount(100))->toBe(100.0)
        ->and(Frequency::BiMonthly->monthlyAmount(100))->toBe(50.0)
        ->and(Frequency::Quarterly->monthlyAmount(90))->toBe(30.0)
        ->and(Frequency::SemiAnnual->monthlyAmount(60))->toBe(10.0)
        ->and(Frequency::Yearly->monthlyAmount(120))->toBe(10.0)
        ->and(Frequency::OneTime->monthlyAmount(500))->toBe(0.0);
});

it('backs each case to its DB column value', function () {
    expect(Frequency::from('weekly'))->toBe(Frequency::Weekly)
        ->and(Frequency::from('bi_weekly'))->toBe(Frequency::BiWeekly)
        ->and(Frequency::from('monthly'))->toBe(Frequency::Monthly)
        ->and(Frequency::from('bi_monthly'))->toBe(Frequency::BiMonthly)
        ->and(Frequency::from('quarterly'))->toBe(Frequency::Quarterly)
        ->and(Frequency::from('semi_annual'))->toBe(Frequency::SemiAnnual)
        ->and(Frequency::from('yearly'))->toBe(Frequency::Yearly)
        ->and(Frequency::from('one_time'))->toBe(Frequency::OneTime);
});

it('tryFrom returns null for an unrecognized cadence, letting callers fall back to their own default', function () {
    expect(Frequency::tryFrom('fortnightly'))->toBeNull();
});

it('converts every pay cadence to its monthly-equivalent net pay, pinning UserProfile\'s existing factors', function () {
    expect(Frequency::payMonthlyAmount(1000, 'bi_weekly'))->toBe(1000 * 26 / 12)
        ->and(Frequency::payMonthlyAmount(1000, 'weekly'))->toBe(1000 * 52 / 12)
        ->and(Frequency::payMonthlyAmount(1000, 'semi_monthly'))->toBe(2000.0)
        ->and(Frequency::payMonthlyAmount(1000, 'monthly'))->toBe(1000.0);
});

it('defaults an unrecognized pay cadence to zero, never treating it as monthly', function () {
    expect(Frequency::payMonthlyAmount(1000, 'fortnightly'))->toBe(0.0)
        ->and(Frequency::payMonthlyAmount(1000, null))->toBe(0.0);
});

it('converts every budget-period window to its monthly-equivalent amount, pinning BudgetController\'s existing factors', function () {
    expect(Frequency::budgetPeriodMonthlyAmount(100, 'weekly'))->toBe(100 * 52 / 12)
        ->and(Frequency::budgetPeriodMonthlyAmount(120, 'yearly'))->toBe(10.0)
        ->and(Frequency::budgetPeriodMonthlyAmount(100, 'monthly'))->toBe(100.0);
});
