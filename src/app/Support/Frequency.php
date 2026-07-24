<?php

namespace App\Support;

use App\Http\Controllers\BudgetController;
use App\Models\UserProfile;

/**
 * Cadence → monthly-equivalent amount, shared by every expense-cadence and
 * income-entry call site that used to re-implement this match expression
 * (FixedExpense, AccrualCalculator, SpendingCutAnalyzer, RecurringDetector,
 * BudgetRuleAnalyzer). Backed to the DB values used across those columns.
 *
 * Factors are copied byte-for-byte from the fullest existing implementation
 * (BudgetRuleAnalyzer::monthlyEquivalent) — this is a pure consolidation, not
 * a correction. Deliberately excludes UserProfile::monthly_net (has a
 * different value set — `semi_monthly` — and a different "unknown ⇒ 0"
 * default) and BudgetController's budget-period window, which is a date
 * range, not an amount cadence.
 *
 * Callers that need to preserve a "default => $amount" fallback for an
 * unrecognized/invalid cadence string (the current behavior everywhere this
 * was inlined) should use `Frequency::tryFrom($cadence)?->monthlyAmount($amount)
 * ?? $amount` rather than `::from()`, which throws on an unknown value.
 */
enum Frequency: string
{
    case Weekly = 'weekly';
    case BiWeekly = 'bi_weekly';
    case Monthly = 'monthly';
    case BiMonthly = 'bi_monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Yearly = 'yearly';
    case OneTime = 'one_time';

    public function monthlyAmount(float $amount): float
    {
        return match ($this) {
            self::Weekly => $amount * 52 / 12,
            self::BiWeekly => $amount * 26 / 12,
            self::Monthly => $amount,
            self::BiMonthly => $amount / 2,
            self::Quarterly => $amount / 3,
            self::SemiAnnual => $amount / 6,
            self::Yearly => $amount / 12,
            self::OneTime => 0.0,
        };
    }

    /**
     * Pay-cadence → monthly-equivalent net pay, for {@see UserProfile::getMonthlyNetAttribute()}.
     *
     * Kept separate from monthlyAmount() rather than overloading it: pay
     * cadence includes `semi_monthly` (paid twice a month — not one of this
     * enum's cases, and distinct from `bi_monthly`/every-two-months), and an
     * unrecognized cadence must default to $0 (no income — a deliberate
     * safety), whereas monthlyAmount()'s default arm doesn't exist and its
     * callers that need a fallback treat an unknown cadence as "monthly"
     * (`Frequency::tryFrom($x)?->monthlyAmount($amount) ?? $amount`), which
     * would be the wrong default here.
     */
    public static function payMonthlyAmount(float $amount, ?string $payFrequency): float
    {
        return match ($payFrequency) {
            'bi_weekly' => $amount * 26 / 12,
            'weekly' => $amount * 52 / 12,
            'semi_monthly' => $amount * 2,
            'monthly' => $amount,
            default => 0.0,
        };
    }

    /**
     * Budget-window cadence → monthly-equivalent amount, for
     * {@see BudgetController}. A budget period is a
     * date window (only monthly/weekly/yearly), not the expense/income
     * cadence monthlyAmount() covers, so it's kept separate rather than
     * folded into that method's case list.
     */
    public static function budgetPeriodMonthlyAmount(float $amount, string $period): float
    {
        return match ($period) {
            'weekly' => $amount * 52 / 12,
            'yearly' => $amount / 12,
            default => $amount,
        };
    }
}
