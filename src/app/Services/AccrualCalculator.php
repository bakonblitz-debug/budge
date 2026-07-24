<?php

namespace App\Services;

use App\Models\FixedExpense;
use App\Models\Transaction;
use App\Support\Frequency;
use App\Support\MerchantKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Daily proration of fixed expenses.
 *
 * Given a set of FixedExpense rows (rent $1500/mo since the 1st, etc.) this
 * service answers: as of today, how much "should" have been spent on each
 * expense, and how much actually has been spent in the matching category?
 *
 * Useful when you want to know mid-month whether you're tracking your
 * monthly obligations correctly: e.g. on May 15, you've accrued ~half of
 * the month's rent obligation; if you've already paid the full rent that's
 * fine (overpaid against accrual), if you've paid nothing that's a warning.
 *
 * No persisted accrual table — everything is computed in real time from
 * fixed_expenses + transactions. The pragmatic choice for a per-user app
 * where the query is cheap.
 */
class AccrualCalculator
{
    /**
     * Compute accrual data for every active fixed expense as of $asOf.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function snapshot(?CarbonImmutable $asOf = null): Collection
    {
        $asOf = $asOf ?? CarbonImmutable::now();

        $expenses = FixedExpense::query()
            ->with('category:id,name,icon,color,parent_id')
            ->with('category.parent:id,name')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->computeMany($expenses, $asOf);
    }

    /**
     * @return array<string, mixed>
     */
    public function computeOne(FixedExpense $expense, CarbonImmutable $asOf): array
    {
        return $this->computeMany(collect([$expense]), $asOf)->first();
    }

    /**
     * Compute accrual rows for a set of expenses as of $asOf, attributing
     * spend across ALL of them in one pass (see {@see attributeSpend()}) so a
     * single transaction can never be counted "spent" by more than one
     * expense — the concern computing each expense in isolation can't see.
     *
     * @param  Collection<int, FixedExpense>  $expenses
     * @return Collection<int, array<string, mixed>>
     */
    private function computeMany(Collection $expenses, CarbonImmutable $asOf): Collection
    {
        $asOf = $asOf->startOfDay();
        $expenses = $expenses->values();

        $periods = $expenses->map(fn (FixedExpense $e) => $this->periodFor($e, $asOf));

        $spentByExpense = $this->attributeSpend($expenses, $periods);

        return $expenses->map(function (FixedExpense $e, int $i) use ($periods, $spentByExpense, $asOf) {
            $period = $periods[$i];
            [$spent, $matchedCount] = $spentByExpense[$e->id] ?? [0.0, 0];

            return $this->row($e, $period['start'], $period['effectiveEnd'], $period['accrued'], $spent, $asOf, $matchedCount);
        });
    }

    /**
     * The current billing period and prorated accrual for one expense as of
     * $asOf (already start-of-day'd). `periodStart`/`lastDay` are null for a
     * not-yet-started or degenerate (end before start) expense — nothing to
     * attribute spend against in that case.
     *
     * @return array{start: CarbonImmutable, effectiveEnd: CarbonImmutable, accrued: float, periodStart: ?CarbonImmutable, lastDay: ?CarbonImmutable}
     */
    private function periodFor(FixedExpense $expense, CarbonImmutable $asOf): array
    {
        $startDate = ($expense->start_date
            ? CarbonImmutable::parse($expense->start_date)
            : $asOf)->startOfDay();

        // Not started yet — nothing accrued.
        if ($startDate->greaterThan($asOf)) {
            return ['start' => $startDate, 'effectiveEnd' => $asOf, 'accrued' => 0.0, 'periodStart' => null, 'lastDay' => null];
        }

        $endDate = $expense->end_date ? CarbonImmutable::parse($expense->end_date)->startOfDay() : null;

        // A degenerate window (ended before it started) accrues nothing.
        if ($endDate && $endDate->lessThan($startDate)) {
            return ['start' => $startDate, 'effectiveEnd' => $asOf, 'accrued' => 0.0, 'periodStart' => null, 'lastDay' => null];
        }

        // Cap the snapshot instant at end_date so an ended-but-still-active
        // expense reports its last active period, never a future/empty one.
        // This also guarantees periodStart <= effectiveAsOf < periodEnd, so the
        // proration denominator (totalDays) is always positive.
        $effectiveAsOf = $endDate ? $asOf->min($endDate) : $asOf;

        [$periodStart, $periodEnd] = $this->currentPeriod($startDate, $effectiveAsOf, $expense->frequency);

        // Inclusive last day we measure to: the snapshot day, but never past the
        // period's final day. Both accrued and spent use this same upper bound.
        $lastDay = $effectiveAsOf->min($periodEnd->subDay());

        $totalDays = (int) $periodStart->diffInDays($periodEnd);
        $daysElapsed = (int) $periodStart->diffInDays($lastDay) + 1;
        $daysElapsed = max(0, min($daysElapsed, $totalDays));

        $accrued = round((float) $expense->amount * $daysElapsed / $totalDays, 2);

        return ['start' => $periodStart, 'effectiveEnd' => $lastDay, 'accrued' => $accrued, 'periodStart' => $periodStart, 'lastDay' => $lastDay];
    }

    /**
     * The current billing period [periodStart, periodEnd) containing $asOf,
     * anchored on $start. Half-open: periodStart <= asOf < periodEnd.
     *
     * Fixed-length frequencies use pure day arithmetic. Calendar frequencies
     * step whole months from $start with overflow clamping, so a bill due on the
     * 31st re-snaps to day 31 whenever the destination month is long enough
     * (Jan 31 -> Feb 28 -> Mar 31), rather than drifting off the anchor.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function currentPeriod(CarbonImmutable $start, CarbonImmutable $asOf, string $frequency): array
    {
        return match ($frequency) {
            'weekly' => $this->fixedLengthPeriod($start, $asOf, 7),
            'bi_weekly' => $this->fixedLengthPeriod($start, $asOf, 14),
            'quarterly' => $this->calendarPeriod($start, $asOf, 3),
            'yearly' => $this->calendarPeriod($start, $asOf, 12),
            default => $this->calendarPeriod($start, $asOf, 1),
        };
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function fixedLengthPeriod(CarbonImmutable $start, CarbonImmutable $asOf, int $length): array
    {
        $periodIndex = intdiv((int) $start->diffInDays($asOf), $length);
        $periodStart = $start->addDays($periodIndex * $length);

        return [$periodStart, $periodStart->addDays($length)];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function calendarPeriod(CarbonImmutable $start, CarbonImmutable $asOf, int $stepMonths): array
    {
        for ($i = 0; ; $i++) {
            $periodStart = $start->addMonthsNoOverflow($stepMonths * $i);
            $periodEnd = $start->addMonthsNoOverflow($stepMonths * ($i + 1));

            if ($periodStart->lessThanOrEqualTo($asOf) && $asOf->lessThan($periodEnd)) {
                return [$periodStart, $periodEnd];
            }
        }
    }

    /**
     * Attribute every in-window transaction to AT MOST ONE expense in a single
     * pass across all of them — the one with the longest (most specific)
     * matching pattern. Without this, overlapping patterns (e.g. "bell
     * mobility" and "bell") both match one BELL MOBILITY charge and it gets
     * double-counted as "spent" on both rows.
     *
     * Matching uses each expense's `match_pattern` (falling back to its `name`)
     * as a normalized SUBSTRING of the transaction merchant/description — so a
     * user-typed name ("Gym Membership", pattern "GOODLIFE") matches a different
     * bank merchant ("GOODLIFE FITNESS #221") without renaming the expense. No
     * category fallback: that would reintroduce the mis-attribution this
     * matcher exists to remove.
     *
     * @param  Collection<int, FixedExpense>  $expenses
     * @param  Collection<int, array<string, mixed>>  $periods  keyed 1:1 with $expenses (see periodFor())
     * @return array<int, array{0: float, 1: int}> [spent, matchedCount] keyed by expense id
     */
    private function attributeSpend(Collection $expenses, Collection $periods): array
    {
        $candidates = $expenses->map(function (FixedExpense $e, int $i) use ($periods) {
            $period = $periods[$i];
            $target = MerchantKey::normalize($e->match_pattern ?: $e->name);

            if ($period['periodStart'] === null || $target === '') {
                return null;
            }

            return [
                'expense' => $e,
                'target' => $target,
                'start' => $period['periodStart'],
                'end' => $period['lastDay']->endOfDay(),
            ];
        })->filter()->values();

        if ($candidates->isEmpty()) {
            return [];
        }

        $rangeStart = $candidates->reduce(
            fn (?CarbonImmutable $carry, array $c) => $carry === null || $c['start']->lessThan($carry) ? $c['start'] : $carry
        );
        $rangeEnd = $candidates->reduce(
            fn (?CarbonImmutable $carry, array $c) => $carry === null || $c['end']->greaterThan($carry) ? $c['end'] : $carry
        );

        $transactions = Transaction::query()
            ->expense()
            ->whereBetween('transaction_date', [$rangeStart, $rangeEnd])
            ->get(['amount', 'merchant_name', 'description', 'transaction_date']);

        $spend = [];

        foreach ($transactions as $t) {
            $merchant = MerchantKey::normalize($t->merchant_name ?: $t->description);

            $best = $candidates
                ->filter(fn (array $c): bool => str_contains($merchant, $c['target'])
                    && $t->transaction_date->between($c['start'], $c['end']))
                ->sortByDesc(fn (array $c): int => mb_strlen($c['target']))
                ->first();

            if (! $best) {
                continue;
            }

            $id = $best['expense']->id;
            $spend[$id] ??= [0.0, 0];
            $spend[$id][0] += -(float) $t->amount;
            $spend[$id][1]++;
        }

        foreach ($spend as $id => [$amount, $count]) {
            $spend[$id][0] = round($amount, 2);
        }

        return $spend;
    }

    private function dailyRate(FixedExpense $e): float
    {
        $amount = (float) $e->amount;

        // 365.25 (incl. leap year average) keeps yearly→daily within a few cents
        return match ($e->frequency) {
            'weekly' => $amount / 7,
            'bi_weekly' => $amount / 14,
            'monthly' => $amount * 12 / 365.25,
            'quarterly' => $amount * 4 / 365.25,
            'yearly' => $amount / 365.25,
            default => $amount * 12 / 365.25,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(
        FixedExpense $e,
        CarbonImmutable $start,
        CarbonImmutable $effectiveEnd,
        float $accrued,
        float $spent,
        CarbonImmutable $asOf,
        int $matchedCount = 0,
    ): array {
        $variance = round($spent - $accrued, 2);
        $monthly = $this->monthlyEquivalent($e);

        $status = match (true) {
            $accrued === 0.0 => 'pending',
            abs($variance) <= max(5.0, $accrued * 0.05) => 'on_track',
            $variance > 0 => 'ahead',
            default => 'behind',
        };

        // A monthly lump-sum bill (rent, car, hydro) is paid in one shot on its
        // due day — it does NOT dribble out daily. Before the due day passes,
        // the daily-prorated accrual outrunning an unpaid bill is not a real
        // shortfall, so show neutral "upcoming" rather than a red "behind". Once
        // the due day has passed and it's still unpaid, "behind" is genuine.
        // Scoped to monthly + due_day, where due_day fully determines the date.
        if ($status === 'behind' && $e->frequency === 'monthly' && $e->due_day !== null) {
            $effectiveDueDay = min((int) $e->due_day, $asOf->daysInMonth);
            if ($asOf->day < $effectiveDueDay) {
                $status = 'upcoming';
            }
        }

        return [
            'id' => $e->id,
            'name' => $e->name,
            'amount' => (float) $e->amount,
            'frequency' => $e->frequency,
            'monthly' => $monthly,
            'daily_rate' => round($this->dailyRate($e), 4),
            'start_date' => $start->format('Y-m-d'),
            'effective_end' => $effectiveEnd->format('Y-m-d'),
            // Carbon v3 diffInDays is signed: a not-started expense passes
            // effectiveEnd < start (asOf before start_date), which would
            // otherwise surface as a negative elapsed-day count.
            'days_elapsed' => max(0, (int) floor($start->diffInDays($effectiveEnd)) + 1),
            'accrued' => $accrued,
            'spent' => $spent,
            'variance' => $variance,
            'status' => $status,
            'matched_count' => $matchedCount,
            // True only when the bill reads "behind" AND nothing matched its
            // pattern — i.e. it's past due with no detected payment, a likely
            // match-pattern problem rather than a real shortfall. A not-yet-due
            // bill ("upcoming") with no payment is NOT flagged.
            'unmatched' => $status === 'behind' && $matchedCount === 0,
            'match_pattern' => $e->match_pattern,
            'category' => $e->category ? [
                'id' => $e->category->id,
                'name' => $e->category->name,
                'icon' => $e->category->icon,
                'color' => $e->category->color,
                'parent_name' => $e->category->parent?->name,
            ] : null,
        ];
    }

    private function monthlyEquivalent(FixedExpense $e): float
    {
        $amount = (float) $e->amount;

        return round(Frequency::tryFrom($e->frequency)?->monthlyAmount($amount) ?? $amount, 2);
    }
}
