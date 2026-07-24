<?php

namespace App\Services;

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Support\Frequency;
use App\Support\IncomeFallback;
use App\Support\Stats;
use Carbon\CarbonImmutable;

/**
 * Deterministic Need / Want / Investment / Saving meter vs a base ruleset
 * (50 / 30 / 10 / 10). No AI.
 *
 * The headline is built from ACTUAL transactions grouped by each category's
 * EFFECTIVE kind (`category.kind ?? parent.kind`) over a fixed window of complete
 * calendar months — so it can never double-count or silently drop spend. Three
 * deterministic adjustments make it a STEADY-STATE view rather than a spiky one:
 *
 *  1. In-window-median lump strip: a category month whose spend exceeds its own
 *     in-window median × {@see SPIKE_MULTIPLE} by at least {@see LUMP_FLOOR} is a
 *     one-time lump (move-in furniture, an $8k investment top-up); the excess over
 *     the median is removed. The median over the window's months is itself robust
 *     to a few lump months, and needs NO prior-month baseline (so it works on a
 *     window anchored at the user's very first month of data).
 *  2. Zero-occurrence add-back: an active fixed/recurring obligation whose category
 *     has ZERO in-window actual transactions (its payments are entirely misfiled
 *     elsewhere — e.g. rent paid by Interac transfer) is injected at its
 *     monthly-equivalent. A category seen in >=1 month is already represented, so
 *     no add-back (no double-count).
 *  3. Median income basis: income = the median of the window's monthly deposit
 *     totals, which ignores both one-off windfalls (tax refunds) and bi-weekly
 *     3-paycheque months by construction.
 *
 * `saving` is the STEADY-STATE residual (income − need − want − investment). It is
 * NOT the actual cash drawdown: stripped lumps were real outflows, so the residual
 * looks less negative than the bank balance fell. Callers must surface
 * {@see stripped} and label saving "steady-state" so a user is never told they
 * saved money they actually spent.
 *
 * Read-only.
 */
class BudgetRuleAnalyzer
{
    /** Base ruleset percentages. The 20 of 50/30/20 is split saving/investment. */
    public const TARGETS = ['need' => 50, 'want' => 30, 'investment' => 10, 'saving' => 10];

    /** Reuse SpendingCutAnalyzer's spike multiple (a month > median × this is elevated). */
    public const SPIKE_MULTIPLE = SpendingCutAnalyzer::SPIKE_MULTIPLE;

    /**
     * Absolute floor on a month's excess over its category median before it's
     * treated as a one-time LUMP (not ordinary variance). $50 (the spike floor)
     * over-strips normal month-to-month wobble at this window scale; $1,000
     * isolates genuine move-in / top-up lumps.
     */
    public const LUMP_FLOOR = 1000.0;

    /** Months of complete history the meter averages over. */
    private const WINDOW_MONTHS = 12;

    /** The three spend kinds (saving is a residual, not a spend bucket). */
    private const SPEND_KINDS = ['need', 'want', 'investment'];

    public function __construct(
        private readonly ReportingPeriod $reportingPeriod,
    ) {}

    /**
     * @return array{
     *   window: array{months: int, start: string, end: string, label: string},
     *   income_monthly: float, income_basis: string, income_warning: ?string,
     *   targets: array<string, int>,
     *   kinds: array<string, array{amount: float, pct: float, target_pct: int, status: string}>,
     *   saving_basis: string,
     *   unclassified_monthly: float,
     *   unclassified_count: int,
     *   added_back: array<int, array{label: string, kind: string, monthly_equivalent: float}>,
     *   stripped: array{lump_total: float, by_kind: array<string, float>},
     *   commitments: array<int, array{label: string, cadence: string, native_amount: float, monthly_equivalent: float, kind: ?string, source: string}>,
     *   by_month: array<int, array{ym: string, need: float, want: float, investment: float}>,
     *   period: array{label: string, likely_partial: bool}
     * }
     */
    public function analyze(): array
    {
        $window = $this->resolveWindow();
        $months = $window['month_keys'];
        $monthCount = max(1, count($months));

        $effKind = $this->effectiveKindByCategory();

        // Per-category monthly expense series (abs), keyed [categoryId][ym] = total.
        $expenseSeries = $this->expenseSeriesByCategory($window['start'], $window['end']);
        $categoriesWithActuals = array_keys($expenseSeries);

        // Aggregate per kind, applying the per-category lump strip.
        $kindMonthly = ['need' => 0.0, 'want' => 0.0, 'investment' => 0.0];
        $strippedByKind = ['need' => 0.0, 'want' => 0.0, 'investment' => 0.0];
        $unclassifiedTotal = 0.0;
        $byMonth = array_fill_keys($months, ['need' => 0.0, 'want' => 0.0, 'investment' => 0.0]);

        foreach ($expenseSeries as $categoryId => $series) {
            $kind = $effKind[$categoryId] ?? null;

            if ($kind === 'excluded') {
                continue; // transfers / between-accounts money movement: never spend.
            }

            $monthly = $this->fillSeries($series, $months);

            if ($kind === null) {
                $unclassifiedTotal += array_sum($monthly);

                continue; // shown separately, not in the 4-way math.
            }

            if (! in_array($kind, self::SPEND_KINDS, true)) {
                continue; // 'saving' kind is residual-only; ignore as a spend bucket.
            }

            // Median of ACTIVE (non-zero) months only. Zero-padding the dense
            // series would drag the median to 0 for a sparse recurring bill
            // (e.g. a quarterly charge in 4 of 12 months), collapsing the bar to 0
            // so every legitimate charge reads as a one-time lump and gets stripped.
            $activeMonths = array_values(array_filter($monthly, fn (float $v): bool => $v > 0.0));
            $median = $activeMonths === [] ? 0.0 : Stats::median($activeMonths);
            $bar = $median * self::SPIKE_MULTIPLE;

            foreach ($monthly as $ym => $monthTotal) {
                $excess = $monthTotal - $median;
                $strip = ($monthTotal > $bar && $excess >= self::LUMP_FLOOR) ? $excess : 0.0;

                $kindMonthly[$kind] += $monthTotal - $strip;
                $strippedByKind[$kind] += $strip;
                $byMonth[$ym][$kind] += $monthTotal - $strip;
            }
        }

        // Convert window sums → monthly figures.
        foreach (self::SPEND_KINDS as $kind) {
            $kindMonthly[$kind] = $kindMonthly[$kind] / $monthCount;
            $strippedByKind[$kind] = round($strippedByKind[$kind] / $monthCount, 2);
        }
        $unclassifiedMonthly = round($unclassifiedTotal / $monthCount, 2);
        $byMonth = $this->averagedByMonth($byMonth, $months);

        // Zero-occurrence add-back (rent-like fully-misfiled obligations).
        $addedBack = $this->addBack($effKind, $categoriesWithActuals, $kindMonthly);

        // Income (median monthly deposit) + residual saving.
        [$incomeMonthly, $incomeBasis, $incomeWarning] = $this->incomeMonthly($window['start'], $window['end'], $months);

        $needM = round($kindMonthly['need'], 2);
        $wantM = round($kindMonthly['want'], 2);
        $investM = round($kindMonthly['investment'], 2);
        $savingM = round($incomeMonthly - $needM - $wantM - $investM, 2);

        $kinds = [
            'need' => $this->kindRow($needM, $incomeMonthly, 'need'),
            'want' => $this->kindRow($wantM, $incomeMonthly, 'want'),
            'investment' => $this->kindRow($investM, $incomeMonthly, 'investment'),
            'saving' => $this->kindRow($savingM, $incomeMonthly, 'saving'),
        ];

        $meta = $this->reportingPeriod->currentMonthMeta();

        return [
            'window' => [
                'months' => $monthCount,
                'start' => $window['start']->toDateString(),
                'end' => $window['end']->subDay()->toDateString(),
                'label' => $window['start']->translatedFormat('M Y').' – '.$window['end']->subMonth()->translatedFormat('M Y'),
            ],
            'income_monthly' => round($incomeMonthly, 2),
            'income_basis' => $incomeBasis,
            'income_warning' => $incomeWarning,
            'targets' => self::TARGETS,
            'kinds' => $kinds,
            'saving_basis' => 'steady_state_residual',
            'unclassified_monthly' => $unclassifiedMonthly,
            'unclassified_count' => $this->unclassifiedTransactionCount($window['start'], $window['end']),
            'added_back' => $addedBack,
            'stripped' => [
                'lump_total' => round(array_sum($strippedByKind), 2),
                'by_kind' => $strippedByKind,
            ],
            'commitments' => $this->commitments($effKind),
            'by_month' => $byMonth,
            'period' => [
                'label' => $meta['label'],
                'likely_partial' => $meta['likely_partial'],
            ],
        ];
    }

    /**
     * The window: the WINDOW_MONTHS complete calendar months ending at the last
     * complete month (the partial anchor month is excluded). Capped to available
     * history (min 1 month).
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable, month_keys: array<int, string>}
     */
    private function resolveWindow(): array
    {
        $anchor = $this->reportingPeriod->anchor();
        $partial = $this->reportingPeriod->currentMonthMeta()['likely_partial'];

        $lastCompleteStart = $partial
            ? $anchor->startOfMonth()->subMonthNoOverflow()
            : $anchor->startOfMonth();

        $end = $lastCompleteStart->addMonthNoOverflow(); // exclusive: first day after last complete month
        $start = $lastCompleteStart->subMonthsNoOverflow(self::WINDOW_MONTHS - 1);

        // Don't start before the user's first transaction month.
        $earliest = Transaction::min('transaction_date');
        if ($earliest !== null) {
            $earliestStart = CarbonImmutable::parse($earliest)->startOfMonth();
            if ($earliestStart->greaterThan($start)) {
                $start = $earliestStart;
            }
        }
        if ($start->greaterThanOrEqualTo($end)) {
            $start = $end->subMonthNoOverflow();
        }

        $keys = [];
        for ($cursor = $start; $cursor->lessThan($end); $cursor = $cursor->addMonthNoOverflow()) {
            $keys[] = $cursor->format('Y-m');
        }

        return ['start' => $start, 'end' => $end, 'month_keys' => $keys];
    }

    /**
     * Effective kind per category id: own kind, else parent's kind, else null.
     *
     * @return array<int, ?string>
     */
    private function effectiveKindByCategory(): array
    {
        $byId = Category::query()->get(['id', 'kind', 'parent_id'])->keyBy('id');

        return $byId->mapWithKeys(function (Category $c) use ($byId): array {
            $kind = $c->kind ?? ($c->parent_id !== null ? $byId->get($c->parent_id)?->kind : null);

            return [$c->id => $kind];
        })->all();
    }

    /**
     * Per-category monthly expense totals (abs) over [start, end). A null
     * `category_id` row is aggregated under the sentinel key 0 (no real
     * category has this id) rather than dropped — {@see effectiveKindByCategory()}
     * has no entry for id 0, so `$effKind[0] ?? null` resolves to null and it
     * routes into the same VISIBLE "unclassified" bucket as a real category
     * with no kind set, instead of silently vanishing from the meter.
     *
     * @return array<int, array<string, float>> [categoryId => ['Y-m' => total]] (0 = uncategorized)
     */
    private function expenseSeriesByCategory(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = Transaction::query()
            ->expense()
            ->where('transaction_date', '>=', $start)
            ->where('transaction_date', '<', $end)
            ->get(['category_id', 'transaction_date', 'amount']);

        $series = [];
        foreach ($rows as $t) {
            $cid = $t->category_id !== null ? (int) $t->category_id : 0;
            $ym = $t->transaction_date->format('Y-m');
            $series[$cid][$ym] = ($series[$cid][$ym] ?? 0) + abs((float) $t->amount);
        }

        return $series;
    }

    /**
     * Count of null-`category_id` expense transactions in the window — the
     * transaction-level counterpart to the null-category dollars folded into
     * `unclassified_monthly`, surfaced so the UI can offer a "categorize
     * these" shortcut to `TransactionController@index?category=none`.
     */
    private function unclassifiedTransactionCount(CarbonImmutable $start, CarbonImmutable $end): int
    {
        return Transaction::query()
            ->expense()
            ->whereNull('category_id')
            ->where('transaction_date', '>=', $start)
            ->where('transaction_date', '<', $end)
            ->count();
    }

    /**
     * Fill a category's sparse {ym=>total} into a dense series over all window
     * months (0 for months with no spend) so the median sees the real zeros.
     *
     * @param  array<string, float>  $series
     * @param  array<int, string>  $months
     * @return array<string, float>
     */
    private function fillSeries(array $series, array $months): array
    {
        $dense = [];
        foreach ($months as $ym) {
            $dense[$ym] = $series[$ym] ?? 0.0;
        }

        return $dense;
    }

    /**
     * Inject the monthly-equivalent of any active obligation whose category has
     * ZERO in-window actuals (fully misfiled — rent paid as a transfer).
     *
     * @param  array<int, ?string>  $effKind
     * @param  array<int, int>  $categoriesWithActuals
     * @param  array<string, float>  $kindMonthly  (mutated)
     * @return array<int, array{label: string, kind: string, monthly_equivalent: float}>
     */
    private function addBack(array $effKind, array $categoriesWithActuals, array &$kindMonthly): array
    {
        $present = array_flip($categoriesWithActuals);
        $added = [];

        $obligations = [];
        foreach (FixedExpense::query()->where('is_active', true)->whereNotNull('category_id')->get() as $fe) {
            $obligations[] = [(int) $fe->category_id, $fe->name, $this->monthlyEquivalent((float) $fe->amount, $fe->frequency)];
        }
        foreach (RecurringSeries::query()->where('status', 'active')->whereNotNull('category_id')->get() as $rs) {
            $obligations[] = [(int) $rs->category_id, $rs->merchant_key, $this->monthlyEquivalent((float) $rs->expected_amount, $rs->cadence)];
        }

        $addedCategories = [];

        foreach ($obligations as [$categoryId, $label, $monthlyEquiv]) {
            $kind = $effKind[$categoryId] ?? null;

            if ($kind === null || ! in_array($kind, self::SPEND_KINDS, true)) {
                continue; // excluded/null obligations never feed a kind.
            }
            if (isset($present[$categoryId])) {
                continue; // category represented in actuals → already counted.
            }
            if (isset($addedCategories[$categoryId])) {
                continue; // same bill modeled as both a FixedExpense and a RecurringSeries → add once (FixedExpense wins, iterated first).
            }
            $addedCategories[$categoryId] = true;

            $kindMonthly[$kind] += $monthlyEquiv;
            $added[] = [
                'label' => $label,
                'kind' => $kind,
                'monthly_equivalent' => round($monthlyEquiv, 2),
            ];
        }

        return $added;
    }

    /**
     * Active deposit months must cover at least this fraction of the window
     * before the active-only median is trusted directly. Below it, deposits
     * are sparse enough (misfiled pay, an irregular gig) that the median of
     * just the active months overstates — sum÷window is used instead. A
     * genuine bi-weekly/monthly cadence deposits in ~every window month
     * (ratio ~1.0) and is unaffected. 0.8 is a tunable heuristic, not a hard
     * law — adjust if real data shows it's miscalibrated.
     */
    private const ACTIVE_MONTH_RATIO_THRESHOLD = 0.8;

    /**
     * Income = median of the window's monthly deposit totals for the canonical
     * INCOME category only. Restricting to the Income category keeps out
     * investment redemptions, transfer-ins and refunds (positive amounts that are
     * NOT income). The median ignores one-off windfalls (tax refunds) and
     * 3-paycheque months. Falls back to recurring income_entries → UserProfile
     * when there's no Income category / no in-window deposits.
     *
     * @param  array<int, string>  $months
     * @return array{0: float, 1: string, 2: ?string} [monthly, basis, warning]
     */
    private function incomeMonthly(CarbonImmutable $start, CarbonImmutable $end, array $months): array
    {
        $incomeCategoryIds = $this->incomeCategoryIds();

        $rows = $incomeCategoryIds === [] ? collect() : Transaction::query()
            ->real()
            ->where('amount', '>', 0)
            ->whereIn('category_id', $incomeCategoryIds)
            ->where('transaction_date', '>=', $start)
            ->where('transaction_date', '<', $end)
            ->get(['transaction_date', 'amount']);

        if ($rows->isNotEmpty()) {
            $byMonth = array_fill_keys($months, 0.0);
            foreach ($rows as $t) {
                $ym = $t->transaction_date->format('Y-m');
                if (array_key_exists($ym, $byMonth)) {
                    $byMonth[$ym] += (float) $t->amount;
                }
            }

            // Active (non-zero) deposit months. Zero-padding the full window
            // would collapse the median to 0 whenever deposits land in fewer
            // than half the months (e.g. pay misfiled as transfers some
            // months), wrongly reporting $0 income.
            $activeMonths = array_values(array_filter($byMonth, fn (float $v): bool => $v > 0.0));

            if ($activeMonths !== []) {
                $windowMonths = count($months);
                $activeRatio = $windowMonths > 0 ? count($activeMonths) / $windowMonths : 0.0;

                if ($activeRatio >= self::ACTIVE_MONTH_RATIO_THRESHOLD) {
                    return [Stats::median($activeMonths), 'actual_median_window', null];
                }

                // Sparse deposits (e.g. 5 of 12 months): the active-only median
                // overstates because it ignores the months with no deposit at
                // all. Sum over the FULL window instead, and flag it.
                return [
                    array_sum($byMonth) / $windowMonths,
                    'actual_sum_window_sparse',
                    'Income deposits are sparse ('.count($activeMonths)." of {$windowMonths} window months) — this figure may be under- or over-stated.",
                ];
            }
        }

        // Fallback: maintained recurring net. Shared with SpendingCutAnalyzer
        // so the two never disagree on the same IncomeEntry/UserProfile data.
        $fallback = IncomeFallback::resolve();

        return [$fallback['monthly'], 'recurring_estimate', $fallback['warning']];
    }

    /**
     * Ids of income-kind categories (and any children of them). Income has its own
     * `kind` ('income'), distinct from 'excluded' transfers, so it's resolved by
     * kind — duplicate-safe and rename-safe — with the income_entries fallback
     * covering a user who lacks the category.
     *
     * @return array<int, int>
     */
    private function incomeCategoryIds(): array
    {
        // Resolve by kind, not by name: every income-kind category plus any child
        // of one (children may be left untagged in the UI). Duplicate-safe, and
        // consistent with the Income ledger and Dashboard resolution.
        return Category::idsOfKind('income');
    }

    /**
     * @return array{amount: float, pct: float, target_pct: int, status: string}
     */
    private function kindRow(float $amount, float $income, string $kind): array
    {
        $pct = $income > 0 ? round($amount / $income * 100, 1) : 0.0;
        $target = self::TARGETS[$kind];

        // need/want: over target is bad. investment/saving: under target is bad.
        $overIsBad = in_array($kind, ['need', 'want'], true);
        if (abs($pct - $target) < 0.05) {
            // Cent/round comparison (pct is already rounded to 1 decimal) rather
            // than raw float `===`, which can't be trusted against a computed ratio.
            $status = 'on';
        } elseif ($overIsBad) {
            $status = $pct > $target ? 'over' : 'under';
        } else {
            $status = $pct < $target ? 'under' : 'over';
        }

        return ['amount' => $amount, 'pct' => $pct, 'target_pct' => $target, 'status' => $status];
    }

    /**
     * Active recurring obligations at native cadence + monthly-equivalent, for the
     * SEPARATELY-LABELED commitments hover (informational, NOT a reconciliation of
     * the headline).
     *
     * @param  array<int, ?string>  $effKind
     * @return array<int, array{label: string, cadence: string, native_amount: float, monthly_equivalent: float, kind: ?string, source: string}>
     */
    private function commitments(array $effKind): array
    {
        $rows = [];

        foreach (FixedExpense::query()->where('is_active', true)->get() as $fe) {
            $rows[] = [
                'label' => $fe->name,
                'cadence' => $fe->frequency,
                'native_amount' => round((float) $fe->amount, 2),
                'monthly_equivalent' => round($this->monthlyEquivalent((float) $fe->amount, $fe->frequency), 2),
                'kind' => $fe->category_id !== null ? ($effKind[(int) $fe->category_id] ?? null) : null,
                'source' => 'fixed_expense',
            ];
        }
        foreach (RecurringSeries::query()->where('status', 'active')->get() as $rs) {
            $rows[] = [
                'label' => $rs->merchant_key,
                'cadence' => $rs->cadence,
                'native_amount' => round((float) $rs->expected_amount, 2),
                'monthly_equivalent' => round($this->monthlyEquivalent((float) $rs->expected_amount, $rs->cadence), 2),
                'kind' => $rs->category_id !== null ? ($effKind[(int) $rs->category_id] ?? null) : null,
                'source' => 'recurring_series',
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['monthly_equivalent'] <=> $a['monthly_equivalent']);

        return $rows;
    }

    /**
     * Normalize a native cadence amount to a monthly figure. Covers every cadence
     * used by FixedExpense.frequency, RecurringSeries.cadence, and IncomeEntry.frequency.
     */
    private function monthlyEquivalent(float $amount, string $cadence): float
    {
        return Frequency::tryFrom($cadence)?->monthlyAmount($amount) ?? $amount;
    }

    /**
     * @param  array<string, array{need: float, want: float, investment: float}>  $byMonth
     * @param  array<int, string>  $months
     * @return array<int, array{ym: string, need: float, want: float, investment: float}>
     */
    private function averagedByMonth(array $byMonth, array $months): array
    {
        $out = [];
        foreach ($months as $ym) {
            $out[] = [
                'ym' => $ym,
                'need' => round($byMonth[$ym]['need'], 2),
                'want' => round($byMonth[$ym]['want'], 2),
                'investment' => round($byMonth[$ym]['investment'], 2),
            ];
        }

        return $out;
    }
}
