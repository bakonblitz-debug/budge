<?php

namespace App\Services;

use App\Models\Category;
use App\Models\NetWorthSnapshot;
use App\Models\SavingsMilestone;
use App\Models\Transaction;
use App\Support\Stats;
use Carbon\CarbonImmutable;

class InsightsService
{
    /** @var array<int, int>|null Memoized income category ids. */
    private ?array $incomeCategoryIdsCache = null;

    /** @var array<int, int>|null Memoized excluded-kind category ids. */
    private ?array $excludedCategoryIdsCache = null;

    /** @var array<int, int>|null Memoized excluded + investment + saving category ids (the spending exclusion set). */
    private ?array $spendingExcludedCategoryIdsCache = null;

    /** @var array<int, array<int, array{ym: string, income: float, spending: float, net: float}>> Memoized by. */
    private array $monthlyTrendCache = [];

    /** @var array{cash: float, assets: float, liabilities: float, net_worth: float, credit_owed: float, credit_overpaid: float}|null */
    private ?array $currentNetWorthCache = null;

    public function __construct(
        private readonly ReportingPeriod $reportingPeriod,
        private readonly SpendingCutAnalyzer $spendingCutAnalyzer,
        private readonly NetWorthService $netWorthService,
        private readonly CashFlowForecaster $cashFlowForecaster,
    ) {}

    /** @return array<int, int> income-kind category ids + their children. */
    private function incomeCategoryIds(): array
    {
        if ($this->incomeCategoryIdsCache !== null) {
            return $this->incomeCategoryIdsCache;
        }

        $this->incomeCategoryIdsCache = Category::idsOfKind('income');

        return $this->incomeCategoryIdsCache;
    }

    /**
     * @return array<int, int> excluded-kind (transfer / inter-account) category ids + their children.
     */
    private function excludedCategoryIds(): array
    {
        return $this->excludedCategoryIdsCache ??= Category::idsOfKind('excluded');
    }

    /**
     * Ids never counted as SPENDING: transfers, plus investment- and
     * saving-kind categories. A contribution to an investment or savings
     * account is money moved, not money spent (BEA/Fidelity treat it as
     * savings) — counting it as spending understates the savings rate.
     *
     * @return array<int, int>
     */
    private function spendingExcludedCategoryIds(): array
    {
        return $this->spendingExcludedCategoryIdsCache ??= array_values(array_unique(array_merge(
            $this->excludedCategoryIds(),
            Category::idsOfKind('investment'),
            Category::idsOfKind('saving'),
        )));
    }

    /** Memoized net-worth current figures (prevents redundant queries per request). */
    private function currentNetWorth(): array
    {
        return $this->currentNetWorthCache ??= $this->netWorthService->current();
    }

    /** @return array<int, array{ym: string, income: float, spending: float, net: float}> */
    public function monthlyTrend(int $months = 12): array
    {
        return $this->monthlyTrendCache[$months] ??= $this->buildMonthlyTrend($months);
    }

    /** @return array<int, array{ym: string, income: float, spending: float, net: float}> */
    private function buildMonthlyTrend(int $months): array
    {
        $anchor = $this->reportingPeriod->anchor();
        $end = $anchor->startOfMonth()->addMonthNoOverflow();          // exclusive
        $start = $end->subMonthsNoOverflow($months);
        $incomeIds = $this->incomeCategoryIds();
        $excludedIds = $this->spendingExcludedCategoryIds();

        $rows = Transaction::query()
            ->real()
            ->where('transaction_date', '>=', $start)->where('transaction_date', '<', $end)
            ->get(['category_id', 'transaction_date', 'amount']);

        $keys = [];
        for ($c = $start; $c->lessThan($end); $c = $c->addMonthNoOverflow()) {
            $keys[$c->format('Y-m')] = ['income' => 0.0, 'spending' => 0.0];
        }
        foreach ($rows as $t) {
            $ym = CarbonImmutable::parse($t->transaction_date)->format('Y-m');
            if (! isset($keys[$ym])) {
                continue;
            }
            $amt = (float) $t->amount;
            if ($amt > 0 && in_array((int) $t->category_id, $incomeIds, true)) {
                $keys[$ym]['income'] += $amt;
            } elseif ($amt < 0 && ! in_array((int) $t->category_id, $excludedIds, true)) {
                // Dashboard parity: excluded-kind categories (transfers) never
                // count as spending here either, nor do investment/saving-kind
                // outflows (contributions are savings, not spending — see
                // spendingExcludedCategoryIds()). Income side is untouched —
                // it already resolves by kind, not by exclusion.
                $keys[$ym]['spending'] += -$amt;
            }
        }

        return array_map(fn (string $ym) => [
            'ym' => $ym,
            'income' => round($keys[$ym]['income'], 2),
            'spending' => round($keys[$ym]['spending'], 2),
            'net' => round($keys[$ym]['income'] - $keys[$ym]['spending'], 2),
        ], array_keys($keys));
    }

    /**
     * @return array{typical_paycheque: float, stability: float, windfalls: array<int, array{date: string, description: string, amount: float}>, windfall_total: float, windfall_pct: float}
     */
    public function payAndWindfalls(int $months = 12): array
    {
        $anchor = $this->reportingPeriod->anchor();
        $end = $anchor->startOfMonth()->addMonthNoOverflow();
        $start = $end->subMonthsNoOverflow($months);

        $deposits = Transaction::query()
            ->real()->where('amount', '>=', 1.0)
            ->whereIn('category_id', $this->incomeCategoryIds())
            ->where('transaction_date', '>=', $start)->where('transaction_date', '<', $end)
            ->orderBy('transaction_date')
            ->get(['transaction_date', 'description', 'amount']);

        // Word-boundary so French "PAIEMENT" (payment, e.g. an interest/bill
        // payment) doesn't false-match "PAIE" (pay) and pollute the median.
        $isSalary = fn (string $d): bool => (bool) preg_match('/\b(SALAIRE|PAIE|PAYROLL)\b/i', $d);
        $salaryAmounts = $deposits->filter(fn ($t) => $isSalary((string) $t->description))->map(fn ($t) => (float) $t->amount)->values()->all();
        $median = $salaryAmounts === [] ? 0.0 : Stats::median($salaryAmounts);
        $bandLo = $median * 0.60;
        $bandHi = $median * 1.40;

        $windfalls = [];
        foreach ($deposits as $t) {
            $amt = (float) $t->amount;
            $isPay = $isSalary((string) $t->description) && $median > 0 && $amt >= $bandLo && $amt <= $bandHi;
            if ($isPay) {
                continue;
            }
            $windfalls[] = ['date' => CarbonImmutable::parse($t->transaction_date)->toDateString(), 'description' => (string) $t->description, 'amount' => round($amt, 2)];
        }
        $windfallTotal = round(array_sum(array_column($windfalls, 'amount')), 2);
        $totalIn = round((float) $deposits->sum('amount'), 2);

        $payOnly = array_values(array_filter($salaryAmounts, fn ($a) => $a >= $bandLo && $a <= $bandHi));
        $stability = round(Stats::cv($payOnly), 4);

        return [
            'typical_paycheque' => round($median, 2),
            'stability' => $stability,
            'windfalls' => $windfalls,
            'windfall_total' => $windfallTotal,
            'windfall_pct' => $totalIn > 0 ? round($windfallTotal / $totalIn * 100, 1) : 0.0,
        ];
    }

    /**
     * Saving-health snapshot.
     *
     * - steady: derived from SpendingCutAnalyzer::projection() (stable income + as-is spend).
     * - actual_month: the anchor month's figures from monthlyTrend().
     * - velocity: average net across the full trend window.
     * - runway_months: cash / baseline monthly burn; null when burn = 0 (no divide-by-zero).
     *
     * @return array{
     *   steady: array{income: float, spending: float, net: float, savings_rate: float, projected_year: float},
     *   actual_month: array{income: float, spending: float, net: float, savings_rate: float},
     *   velocity: float,
     *   runway_months: ?float,
     * }
     */
    public function savingHealth(): array
    {
        $projection = $this->spendingCutAnalyzer->projection();
        $steadyIncome = (float) $projection['income_monthly'];
        $steadySpending = (float) $projection['as_is']['monthly'];
        $steadyNet = round($steadyIncome - $steadySpending, 2);
        $steadySavingsRate = $steadyIncome > 0 ? round($steadyNet / $steadyIncome * 100, 1) : 0.0;
        $projectedYear = round($steadyNet * 12, 2);

        $trend = $this->monthlyTrend();
        $anchor = $this->reportingPeriod->anchor();
        $anchorYm = $anchor->format('Y-m');
        $anchorRow = collect($trend)->firstWhere('ym', $anchorYm)
            ?? ['income' => 0.0, 'spending' => 0.0, 'net' => 0.0];
        $actualIncome = (float) $anchorRow['income'];
        $actualSpending = (float) $anchorRow['spending'];
        $actualNet = (float) $anchorRow['net'];
        $actualSavingsRate = $actualIncome > 0 ? round($actualNet / $actualIncome * 100, 1) : 0.0;

        $nets = array_column($trend, 'net');
        $velocity = count($nets) > 0 ? round(array_sum($nets) / count($nets), 2) : 0.0;

        $baseline = $this->cashFlowForecaster->baseline();
        $monthlyBurn = (float) $baseline['monthly'];
        $cash = (float) $this->currentNetWorth()['cash'];
        $runwayMonths = $monthlyBurn > 0 ? round($cash / $monthlyBurn, 1) : null;

        return [
            'steady' => [
                'income' => round($steadyIncome, 2),
                'spending' => round($steadySpending, 2),
                'net' => $steadyNet,
                'savings_rate' => $steadySavingsRate,
                'projected_year' => $projectedYear,
            ],
            'actual_month' => [
                'income' => round($actualIncome, 2),
                'spending' => round($actualSpending, 2),
                'net' => round($actualNet, 2),
                'savings_rate' => $actualSavingsRate,
            ],
            'velocity' => $velocity,
            'runway_months' => $runwayMonths,
        ];
    }

    /**
     * Net-worth snapshot: current always; trend only when ≥ 2 snapshots exist.
     *
     * @return array{
     *   current: array,
     *   has_history: bool,
     *   series: array<int, array{as_of: string, net_worth: float}>,
     *   delta_month: ?float,
     *   delta_ytd: ?float,
     * }
     */
    public function netWorth(): array
    {
        $current = $this->currentNetWorth();

        $snapshots = NetWorthSnapshot::query()
            ->orderBy('as_of')
            ->get(['as_of', 'net_worth']);

        $count = $snapshots->count();

        if ($count < 2) {
            return [
                'current' => $current,
                'has_history' => false,
                'series' => [],
                'delta_month' => null,
                'delta_ytd' => null,
            ];
        }

        $series = $snapshots->map(fn ($s) => [
            'as_of' => $s->as_of instanceof \DateTimeInterface
                ? $s->as_of->format('Y-m-d')
                : (string) $s->as_of,
            'net_worth' => round((float) $s->net_worth, 2),
        ])->values()->all();

        $newest = (float) $snapshots->last()->net_worth;
        $secondNewest = (float) $snapshots->get($count - 2)->net_worth;

        // delta_month = newest - second-newest snapshot
        $deltaMonth = round($newest - $secondNewest, 2);

        // delta_ytd: anchored on the REPORTING year (ReportingPeriod's anchor,
        // which tracks the latest imported transaction), not the real clock year
        // — the two can differ when statements lag. Baseline is the last snapshot
        // at/before Jan 1 of that year (the "start of year" value, which may be a
        // prior-year snapshot). Requires at least one snapshot actually dated in
        // the anchor year — otherwise there's nothing to call "year to date" yet.
        // No baseline at all ⇒ null (never silently fall back to newest−oldest
        // all-time, which answers a different question).
        $anchorYear = $this->reportingPeriod->anchor()->year;
        $snapshotYear = fn ($s): int => $s->as_of instanceof \DateTimeInterface
            ? (int) $s->as_of->format('Y')
            : (int) substr((string) $s->as_of, 0, 4);

        $hasInYearSnapshot = $snapshots->contains(fn ($s) => $snapshotYear($s) === $anchorYear);

        $jan1 = CarbonImmutable::create($anchorYear, 1, 1);
        $baseline = $hasInYearSnapshot
            ? $snapshots->filter(fn ($s) => CarbonImmutable::parse((string) $s->as_of)->lte($jan1))->last()
            : null;

        $deltaYtd = $baseline !== null
            ? round($newest - (float) $baseline->net_worth, 2)
            : null;

        return [
            'current' => $current,
            'has_history' => true,
            'series' => $series,
            'delta_month' => $deltaMonth,
            'delta_ytd' => $deltaYtd,
        ];
    }

    /**
     * Savings milestones progress — how far the user's net worth is toward each
     * milestone target, with an ETA based on the supplied monthly velocity.
     *
     * "Current" savings is the full net-worth figure from NetWorthService::current()
     * so it stays consistent with the net-worth panel shown on the same page.
     *
     * @param  float  $monthlyVelocity  Average monthly net (from savingHealth or monthlyTrend).
     * @return array<int, array{name: string, current: float, target: float, pct: float, eta_months: ?float}>
     */
    public function goalProgress(float $monthlyVelocity): array
    {
        $current = round((float) $this->currentNetWorth()['net_worth'], 2);

        $milestones = SavingsMilestone::query()
            ->orderBy('target_amount')
            ->get(['name', 'target_amount']);

        return $milestones->map(function (SavingsMilestone $m) use ($current, $monthlyVelocity): array {
            $target = round((float) $m->target_amount, 2);
            $pct = $target > 0 ? round(min(100.0, max(0.0, $current / $target * 100)), 1) : 0.0;
            $remaining = $target - $current;
            $etaMonths = ($monthlyVelocity > 0 && $remaining > 0)
                ? round($remaining / $monthlyVelocity, 1)
                : null;

            return [
                'name' => $m->name,
                'current' => $current,
                'target' => $target,
                'pct' => $pct,
                'eta_months' => $etaMonths,
            ];
        })->values()->all();
    }
}
