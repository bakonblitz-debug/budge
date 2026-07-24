<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\CarbonImmutable;

/**
 * App-wide "reporting period" anchor.
 *
 * Bank/credit-card statements lag ~1 month, so on (say) June 7 there are no
 * June transactions yet. Anchoring spending views to {@see CarbonImmutable::now()}
 * would show an empty in-progress month. Instead, consumers anchor to the latest
 * month that actually has imported data — the user's most recent
 * `transaction_date` — and roll forward automatically as new statements import.
 *
 * This single date is the reference "today" for period math; consumers derive
 * month/week/year windows from it. Read-only.
 */
class ReportingPeriod
{
    /**
     * The reference date for period math: the latest `transaction_date` among
     * the current user's transactions (auto-scoped by {@see Transaction}'s
     * BelongsToUser global scope), as a {@see CarbonImmutable}. Falls back to
     * the real now() when the user has no transactions yet.
     */
    public function anchor(): CarbonImmutable
    {
        $latest = Transaction::max('transaction_date');

        return $latest !== null
            ? CarbonImmutable::parse($latest)
            : CarbonImmutable::now();
    }

    /**
     * Metadata about the anchor month for honest "partial period" labeling.
     * Statements usually import as whole months, so this rarely fires — but when
     * the latest data stops well before month end, period totals/trends compare
     * a short month against full ones, and consumers should mark them partial.
     *
     * `days_with_data` uses the anchor day (latest transaction) as the month's
     * data coverage; `likely_partial` is true when that stops materially short
     * of the month end (more than 3 days early).
     *
     * @return array{label: string, days_with_data: int, days_in_month: int, likely_partial: bool}
     */
    public function currentMonthMeta(): array
    {
        $anchor = $this->anchor();
        $daysInMonth = $anchor->daysInMonth;
        $daysWithData = $anchor->day;

        return [
            'label' => $anchor->translatedFormat('F Y'),
            'days_with_data' => $daysWithData,
            'days_in_month' => $daysInMonth,
            'likely_partial' => $daysWithData < ($daysInMonth - 3),
        ];
    }

    /**
     * The start of a lookback window ending at the anchor month, always via
     * `subMonthsNoOverflow()` — never plain `subMonths()`. A plain overflow-
     * prone subtraction from a day-29–31 anchor rolls into the WRONG month
     * (e.g. Mar 31 minus 1 month = Mar 3, not Feb), silently shrinking the
     * window by a month and corrupting month-over-month comparisons.
     */
    public function windowStart(int $monthsBack): CarbonImmutable
    {
        return $this->anchor()->subMonthsNoOverflow($monthsBack)->startOfMonth();
    }

    /** The anchor month as `Y-m`. */
    public function anchorMonth(): string
    {
        return $this->anchor()->format('Y-m');
    }

    /** The month immediately before the anchor month, as `Y-m` (overflow-safe). */
    public function previousMonthKey(): string
    {
        return $this->anchor()->subMonthNoOverflow()->format('Y-m');
    }
}
