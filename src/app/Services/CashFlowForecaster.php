<?php

namespace App\Services;

use App\Models\FixedExpense;
use App\Models\IncomeEntry;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Projects a daily cash balance forward from today, so the user can see whether
 * they'll dip below zero before their next paycheque.
 *
 * Deterministic event model, no AI: it starts from current cash (chequing +
 * savings), then applies upcoming income (IncomeEntry), fixed expenses
 * (FixedExpense, by frequency + due day), and detected recurring charges
 * (RecurringSeries) on their expected dates.
 */
class CashFlowForecaster
{
    private const MAX_STEPS = 5000;

    /** Months of history used for the spending baseline when none is specified. */
    private const DEFAULT_BASELINE_MONTHS = 3;

    public function __construct(
        private readonly OneTimePurchaseDetector $oneTimeDetector,
        private readonly InvestmentScheduleProjector $investments,
        private readonly CategorySpikeDetector $spikeDetector,
        private readonly NetWorthService $netWorth,
        private readonly ReportingPeriod $reportingPeriod,
    ) {}

    /**
     * @param  ?string  $baselineStart  ISO date; everyday-spend burn is learned from transactions on/after this date.
     * @param  ?int  $baselineMonths  Alternative to $baselineStart: months of history back from the latest transaction.
     * @return array{
     *   start_balance: float, horizon_days: int,
     *   timeline: array<int, array{date: string, balance: float}>,
     *   lowest: array{date: string, balance: float},
     *   shortfall_date: ?string,
     *   events: array<int, array{date: string, label: string, amount: float, kind: string}>,
     *   baseline: array{daily: float, start: string, end: string, window_days: int, total: float, monthly: float}
     * }
     *
     * Event `kind` is one of: income, expense, recurring, investment. Investment
     * contributions (cat investments, e.g. RRSP/TFSA/PLACEMENT) are projected as
     * their own scheduled outflows (kind=investment) and removed from the learned
     * burn, since pausing them is a real lever distinct from unavoidable spend.
     */
    public function forecast(int $days = 60, ?string $baselineStart = null, ?int $baselineMonths = null): array
    {
        $today = Carbon::now()->startOfDay();
        $end = $today->copy()->addDays($days);

        $startBalance = $this->netWorth->cashBalance();
        $events = $this->collectEvents($today, $end);

        // Everyday/variable spend, learned from recent history and applied as a
        // flat daily drain on top of the scheduled events.
        $baseline = $this->baseline($baselineStart, $baselineMonths);
        $dailyBurn = $baseline['daily'];

        // Sum the day's deltas, walk forward day by day.
        $deltaByDate = [];
        foreach ($events as $e) {
            $deltaByDate[$e['date']] = ($deltaByDate[$e['date']] ?? 0) + $e['amount'];
        }

        // Day 0 (today) is the starting point: today's scheduled events apply, but
        // NOT a full day's burn (the day is already partway through and the start
        // balance is "as of now"). Burn is then charged once per following day, so a
        // $days-horizon forecast applies exactly $days days of burn — not $days+1.
        $balance = round($startBalance + ($deltaByDate[$today->toDateString()] ?? 0), 2);
        $timeline = [['date' => $today->toDateString(), 'balance' => $balance]];
        $lowest = ['date' => $today->toDateString(), 'balance' => $balance];
        $shortfall = $balance < 0 ? $today->toDateString() : null;

        for ($cursor = $today->copy()->addDay(); $cursor->lte($end); $cursor->addDay()) {
            $dateStr = $cursor->toDateString();
            $balance += ($deltaByDate[$dateStr] ?? 0) - $dailyBurn;
            $balance = round($balance, 2);

            $timeline[] = ['date' => $dateStr, 'balance' => $balance];

            if ($balance < $lowest['balance']) {
                $lowest = ['date' => $dateStr, 'balance' => $balance];
            }
            if ($shortfall === null && $balance < 0) {
                $shortfall = $dateStr;
            }
        }

        return [
            'start_balance' => round($startBalance, 2),
            'horizon_days' => $days,
            'timeline' => $timeline,
            'lowest' => $lowest,
            'shortfall_date' => $shortfall,
            'events' => $events,
            'baseline' => $baseline,
        ];
    }

    /**
     * Learn an average daily "everyday spend" from recent history. Excludes
     * transfers and excluded transactions, and any category already modelled as
     * a fixed expense or detected recurring charge — those bills are projected
     * as their own scheduled events, so counting them here too would
     * double-charge the forecast.
     *
     * Public so InsightsService and the forecast share one burn figure.
     *
     * @return array{daily: float, start: string, end: string, window_days: int, total: float, monthly: float}
     */
    public function baseline(?string $baselineStart = null, ?int $baselineMonths = null): array
    {
        $today = Carbon::now()->startOfDay();
        $anchor = $this->spendingAnchor();
        $start = $this->resolveBaselineStart($anchor, $baselineStart, $baselineMonths);
        $excluded = $this->modelledCategoryIds();
        $investmentCats = $this->investments->investmentCategoryIds();

        // One-time spikes (furniture, move-in) must not be learned as a recurring
        // daily habit. The detector's median bar is computed over the FULL window
        // population; excludeCategoryIds only gates which rows may be flagged, so
        // investment/modelled rows are never themselves dropped as "one-time".
        $oneTimeIds = $this->oneTimeDetector
            ->detect(
                CarbonImmutable::parse($start->toDateString()),
                CarbonImmutable::parse($anchor->toDateString()),
                array_values(array_unique(array_merge($excluded, $investmentCats))),
            )
            ->pluck('id')
            ->all();

        // ACTIVE investment streams are projected as scheduled events (collectEvents),
        // so their history must leave the burn — but ONLY active streams. A paused/
        // stale stream's rows stay in burn (it isn't projected either), so the
        // contribution never vanishes from both. Keyed by tx id (the active set),
        // never a blanket category clause, to keep burn and events in lockstep.
        $activeInvestmentIds = $this->investments->activeStreamTransactionIds(
            CarbonImmutable::parse($today->toDateString()),
            CarbonImmutable::parse($anchor->toDateString()),
        );

        $query = Transaction::query()
            ->expense()
            ->whereBetween('transaction_date', [$start->copy()->startOfDay(), $anchor->copy()->endOfDay()]);

        if ($excluded !== []) {
            // Keep uncategorised spend; only drop categories with a scheduled event.
            $query->where(function ($q) use ($excluded) {
                $q->whereNull('category_id')->orWhereNotIn('category_id', $excluded);
            });
        }

        if ($oneTimeIds !== []) {
            $query->whereNotIn('id', $oneTimeIds);
        }

        if ($activeInvestmentIds !== []) {
            $query->whereNotIn('id', $activeInvestmentIds);
        }

        // Move-in SPREE: repeated large discretionary buys at one store (furniture)
        // that the per-transaction one-time detector keeps because each merchant
        // recurs >2× (so they look "frequent"). Strip only the per-month EXCESS over
        // each discretionary category's normal level. Computed over the SAME
        // population $total sums (one-time + active-investment ids excluded), so the
        // same dollars are never subtracted twice. The category's everyday level
        // stays in burn; essentials / money-movement categories are never candidates.
        $spikeExcess = $this->spikeDetector
            ->detect(
                CarbonImmutable::parse($start->toDateString()),
                CarbonImmutable::parse($anchor->toDateString()),
                array_values(array_unique(array_merge($excluded, $investmentCats))),
                array_values(array_unique(array_merge($oneTimeIds, $activeInvestmentIds))),
            )
            ->sum('stripped_excess');

        $total = round(max(0.0, abs((float) $query->sum('amount')) - $spikeExcess), 2);
        $windowDays = max(1, (int) $start->copy()->startOfDay()->diffInDays($anchor->copy()->startOfDay()) + 1);
        $daily = round($total / $windowDays, 2);

        return [
            'daily' => $daily,
            'start' => $start->toDateString(),
            'end' => $anchor->toDateString(),
            'window_days' => $windowDays,
            'total' => $total,
            'monthly' => round($daily * (365.25 / 12), 2),
        ];
    }

    /**
     * Reference "today" for spending history: latest transaction date
     * (statements lag), else now. Delegates to {@see ReportingPeriod} (the
     * single canonical anchor) instead of re-querying `Transaction::max()`.
     */
    private function spendingAnchor(): Carbon
    {
        return Carbon::parse($this->reportingPeriod->anchor()->toDateString())->startOfDay();
    }

    /** Resolve the baseline window start from an explicit date, a months-back count, or the default. */
    private function resolveBaselineStart(Carbon $anchor, ?string $baselineStart, ?int $baselineMonths): Carbon
    {
        if ($baselineStart !== null && $baselineStart !== '') {
            $start = Carbon::parse($baselineStart)->startOfDay();
        } elseif ($baselineMonths !== null && $baselineMonths > 0) {
            $start = $anchor->copy()->subMonthsNoOverflow($baselineMonths)->startOfDay();
        } else {
            $start = $anchor->copy()->subMonthsNoOverflow(self::DEFAULT_BASELINE_MONTHS)->startOfDay();
        }

        // Never start after the anchor, so the window is always at least one day.
        return $start->greaterThan($anchor) ? $anchor->copy()->startOfDay() : $start;
    }

    /**
     * Category ids already represented by a scheduled event (active fixed
     * expense or active recurring series), excluded from the everyday burn.
     *
     * @return array<int, int>
     */
    private function modelledCategoryIds(): array
    {
        $fixed = FixedExpense::query()->where('is_active', true)->whereNotNull('category_id')->pluck('category_id');
        $recurring = RecurringSeries::query()->where('status', 'active')->whereNotNull('category_id')->pluck('category_id');

        return $fixed->merge($recurring)->unique()->values()->all();
    }

    /**
     * Build the dated income/expense events within the window.
     *
     * @return array<int, array{date: string, label: string, amount: float, kind: string}>
     */
    private function collectEvents(Carbon $start, Carbon $end): array
    {
        $events = [];

        // Income (positive).
        foreach (IncomeEntry::query()->get() as $income) {
            foreach ($this->expand($income->pay_date, $income->frequency, $start, $end, null) as $date) {
                $events[] = [
                    'date' => $date->toDateString(),
                    'label' => $income->source,
                    'amount' => abs((float) $income->amount),
                    'kind' => 'income',
                ];
            }
        }

        // Fixed expenses (negative).
        foreach (FixedExpense::query()->where('is_active', true)->get() as $expense) {
            $anchor = $this->fixedExpenseAnchor($expense, $start);
            // Never emit an occurrence before the expense's own start_date (a
            // future-dated bill must not be projected from today). Acts as a lower
            // bound for every frequency, not just monthly+due_day.
            $lowerBound = $expense->start_date && $expense->start_date->greaterThan($start)
                ? $expense->start_date->copy()->startOfDay()
                : $start;
            foreach ($this->expand($anchor, $expense->frequency, $lowerBound, $end, $expense->end_date, $expense->due_day) as $date) {
                $events[] = [
                    'date' => $date->toDateString(),
                    'label' => $expense->name,
                    'amount' => -abs((float) $expense->amount),
                    'kind' => 'expense',
                ];
            }
        }

        // Active investment streams (cat investments), derived fresh from
        // transaction history and projected on the same today..end axis.
        $today = CarbonImmutable::parse($start->toDateString());
        $anchor = CarbonImmutable::parse($this->spendingAnchor()->toDateString());
        $endImmutable = CarbonImmutable::parse($end->toDateString());

        // The projector OWNS scheduling for streams it actively projects. The
        // recurring loop below skips a series ONLY when the projector claims its
        // merchant_key — so a DB-active investment series the projector drops as
        // stale still emits via its persisted next_expected_at (no silent drop),
        // and an active stream is never emitted twice (no double-count).
        $projectedKeys = $this->investments->activeStreamKeys($today, $anchor);

        foreach ($this->investments->events($today, $endImmutable, $anchor) as $event) {
            $events[] = $event;
        }

        // Detected recurring charges (negative) — only those still active.
        foreach (RecurringSeries::query()->where('status', 'active')->get() as $series) {
            if (! $series->next_expected_at) {
                continue;
            }
            if ($series->category_id === null) {
                // A null-category series isn't excluded from the everyday burn
                // (burn is dropped by category_id), so projecting it here too would
                // double-charge it. Leave it represented in burn only.
                continue;
            }
            if (in_array(mb_strtolower((string) $series->merchant_key), $projectedKeys, true)) {
                continue; // projector owns this active investment stream.
            }
            foreach ($this->expand($series->next_expected_at, $series->cadence, $start, $end, null) as $date) {
                $events[] = [
                    'date' => $date->toDateString(),
                    'label' => $series->merchant_key,
                    'amount' => -abs((float) $series->expected_amount),
                    'kind' => 'recurring',
                ];
            }
        }

        return $events;
    }

    /** Anchor a monthly fixed expense on its due day; otherwise use its start date. */
    private function fixedExpenseAnchor(FixedExpense $expense, Carbon $start): Carbon
    {
        $anchor = $expense->start_date ? $expense->start_date->copy() : $start->copy();

        if ($expense->frequency === 'monthly' && $expense->due_day) {
            $anchor = $start->copy()->startOfMonth()
                ->addDays(min($expense->due_day, $start->copy()->endOfMonth()->day) - 1);
        }

        return $anchor;
    }

    /**
     * Expand a recurring anchor into the occurrence dates inside [start, end].
     *
     * @param  ?int  $dueDay  For monthly cadence only: the FixedExpense's true
     *                        due day (1-31), re-applied every month so a
     *                        day-31 bill lands on 28/31/30 as months change
     *                        length, instead of freezing at whatever day the
     *                        first (possibly clamped) occurrence landed on.
     *                        Null for IncomeEntry/RecurringSeries callers,
     *                        which have no separate due-day concept — their
     *                        anchor date steps as before.
     * @return array<int, Carbon>
     */
    private function expand(Carbon $anchor, string $frequency, Carbon $start, Carbon $end, ?Carbon $hardEnd, ?int $dueDay = null): array
    {
        // Covers every IncomeEntry frequency and RecurringSeries cadence,
        // including bi_monthly and semi_annual (which the recurring detector
        // emits). Unknown cadences return null and degrade to "no events" via
        // the null guards below, rather than crashing the whole forecast.
        $step = fn (Carbon $d): ?Carbon => match ($frequency) {
            'weekly' => $d->copy()->addWeek(),
            'bi_weekly' => $d->copy()->addWeeks(2),
            'monthly' => $dueDay !== null ? $this->nextMonthlyOccurrence($d, $dueDay) : $d->copy()->addMonthNoOverflow(),
            'bi_monthly' => $d->copy()->addMonthsNoOverflow(2),
            'quarterly' => $d->copy()->addMonthsNoOverflow(3),
            'semi_annual' => $d->copy()->addMonthsNoOverflow(6),
            'yearly' => $d->copy()->addYear(),
            default => null,
        };

        if ($frequency === 'one_time') {
            $d = $anchor->copy()->startOfDay();

            return ($d->betweenIncluded($start, $end)) ? [$d] : [];
        }

        $dates = [];
        $cursor = $anchor->copy()->startOfDay();
        $guard = 0;

        while ($cursor !== null && $cursor->lt($start) && $guard++ < self::MAX_STEPS) {
            $cursor = $step($cursor);
        }
        while ($cursor !== null && $cursor->lte($end) && $guard++ < self::MAX_STEPS) {
            if (! $hardEnd || $cursor->lte($hardEnd)) {
                $dates[] = $cursor->copy();
            }
            $cursor = $step($cursor);
        }

        return $dates;
    }

    /**
     * The next month's occurrence of a monthly due-day bill, re-clamped to
     * THAT month's length every time — never chained from a previously
     * clamped day. `addMonthNoOverflow()` alone freezes: Jan 31 -> Feb 28 ->
     * (stepped again from Feb 28) -> Mar 28, never recovering to Mar 31.
     */
    private function nextMonthlyOccurrence(Carbon $d, int $dueDay): Carbon
    {
        $nextMonthStart = $d->copy()->startOfMonth()->addMonthNoOverflow();

        return $nextMonthStart->addDays(min($dueDay, $nextMonthStart->daysInMonth) - 1);
    }
}
