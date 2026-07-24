<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Services\Ai\SpendingAnalyzer;
use App\Support\Frequency;
use App\Support\IncomeFallback;
use App\Support\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic "where to cut spending" engine.
 *
 * Reuses {@see SpendingAnalyzer::aggregate()} for the monthly/category/merchant
 * rollups (same filters: amount<0, transfer_id null, is_excluded=false, only
 * dollars that left the account), then derives ACTIONABLE, quantified cut
 * opportunities entirely in PHP — no AI involved. The optional AI narrative
 * lives in a separate, fully-optional layer (the controller), never here.
 *
 * Naming merchants IS allowed: this is the user's own data shown only to them,
 * unlike the privacy-constrained AI analyzer which must stay category-only.
 *
 * Read-only.
 */
class SpendingCutAnalyzer
{
    /**
     * Category classification by (lowercased) name. A category is treated as
     * discretionary if its own name OR its parent's name matches DISCRETIONARY;
     * essential if it matches ESSENTIAL. Anything unrecognised defaults to
     * discretionary (the conservative "could be cut, surface it" choice).
     *
     * This is the single tunable mapping — adjust these arrays to reclassify.
     *
     * @var array<int, string>
     */
    public const DISCRETIONARY = [
        'restaurants', 'dining', 'entertainment', 'shopping',
        'subscriptions', 'other', 'hobbies', 'travel', 'alcohol',
        'coffee', 'takeout', 'fast food',
    ];

    /** @var array<int, string> */
    public const ESSENTIAL = [
        'rent', 'mortgage', 'utilities', 'internet', 'groceries', 'gas',
        'fuel', 'insurance', 'car payments', 'car payment', 'public transit',
        'transit', 'transport', 'health', 'healthcare', 'medical', 'investments',
        'savings', 'taxes', 'childcare', 'education', 'debt', 'loan',
    ];

    /**
     * Fraction of discretionary spend we model as realistically cuttable. A
     * deliberately conservative blanket estimate (not per-category) so the
     * headline "potential savings" stays defensible.
     */
    private const CUTTABLE_FRACTION = 0.30;

    /**
     * A category's basis-month spend is treated as a one-time spike when it
     * exceeds its prior-months median by this multiple. 1.5× ("50% over the
     * usual") is a deliberately loose bar — move-in months blow past it easily,
     * normal noise does not.
     *
     * Public so {@see CategorySpikeDetector} (the forecaster's burn-side reuse of
     * the SAME per-month spike model) references one source of truth rather than
     * duplicating the constant.
     */
    public const SPIKE_MULTIPLE = 1.5;

    /**
     * Minimum dollar excess over the spike threshold before we bother flagging,
     * so a category that usually costs $4 and hit $10 isn't surfaced as a
     * "move-in spike" — the absolute excess is immaterial.
     *
     * Public: shared per-month floor reused by {@see CategorySpikeDetector}, both
     * as the per-month excess floor AND (there) as the minimum baseline a category
     * must have before its spikes are judged at all.
     */
    public const SPIKE_MIN_EXCESS = 50.0;

    /**
     * Months of history to look back over when computing each variable
     * category's baseline (median of the PRIOR months in this window).
     *
     * Public so {@see CategorySpikeDetector} reuses the same lookback depth.
     */
    public const BASELINE_LOOKBACK_MONTHS = 6;

    /**
     * One-time-purchase detector tunables. The detection heuristic itself lives
     * in {@see OneTimePurchaseDetector}; here we only keep the reporting-window
     * lookback and the cap on how many suggestions to surface.
     */
    private const ONE_TIME_LOOKBACK_MONTHS = 6;

    /** Cap on how many suggestions we surface (sorted by amount desc). */
    private const ONE_TIME_MAX_SUGGESTIONS = 15;

    public function __construct(
        private readonly SpendingAnalyzer $aggregator,
        private readonly ReportingPeriod $reportingPeriod,
        private readonly OneTimePurchaseDetector $oneTimeDetector,
    ) {}

    /**
     * Produce the full deterministic cut analysis.
     *
     * @return array{
     *     window_months: int,
     *     current_month: string,
     *     discretionary_total: float,
     *     essential_total: float,
     *     subscription_cost: float,
     *     potential_monthly_savings: float,
     *     cut_opportunities: array<int, array{
     *         category: string,
     *         monthly_spend: float,
     *         share_of_discretionary: float,
     *         mom_growth_pct: float|null,
     *         top_merchants: array<int, array{description: string, total: float, count: int}>
     *     }>,
     *     over_budget: array<int, array{category: string, spent: float, budget: float, overage: float}>,
     *     subscriptions: array<int, array{merchant: string, cadence: string, monthly_cost: float}>,
     *     projection: array{
     *         basis_month: string,
     *         fixed_monthly: float,
     *         variable_monthly: float,
     *         variable_monthly_normalized: float,
     *         income_monthly: float,
     *         income_warning: ?string,
     *         flags: array<int, array{category: string, last_month: float, baseline: float, excess: float, note: string}>,
     *         as_is: array{monthly: float, three_month: float, annual: float, projected_net_annual: float},
     *         normalized: array{monthly: float, three_month: float, annual: float, projected_net_annual: float}
     *     },
     *     one_time_suggestions: array<int, array{id: int, transaction_date: string, merchant: string, category: string, amount: float, reason: string}>
     * }
     */
    public function analyze(int $months = 3): array
    {
        // Anchor to the latest month with imported data (statements lag ~1
        // month), not the live calendar month. Rolls forward as data imports.
        $anchor = $this->reportingPeriod->anchor();
        $currentMonth = $this->reportingPeriod->anchorMonth();
        $previousMonth = $this->reportingPeriod->previousMonthKey();

        $aggregates = $this->aggregator->aggregate($months, $anchor);

        $categoryMeta = $this->categoryMeta();
        $categoryKinds = $this->categoryKinds();

        // Per-category spend for the CURRENT (anchored) month, plus merchant breakdown.
        $perCategory = $this->currentMonthByCategory($months, $currentMonth, $anchor);

        $discretionaryTotal = 0.0;
        $essentialTotal = 0.0;
        $unclassifiedTotal = 0.0;
        $opportunities = [];

        foreach ($perCategory as $categoryName => $data) {
            $spend = round($data['spend'], 2);
            $class = $this->classify($categoryName, $categoryMeta, $categoryKinds);

            if ($class === 'essential') {
                $essentialTotal += $spend;

                continue;
            }

            // A named category we can't confidently classify is NOT assumed
            // cuttable — surfaced on its own line rather than inflating the
            // discretionary headline and "potential savings".
            if ($class === 'unclassified') {
                $unclassifiedTotal += $spend;

                continue;
            }

            $discretionaryTotal += $spend;

            $prevSpend = $aggregates['category_by_month'][$previousMonth][$categoryName] ?? null;
            $momGrowth = ($prevSpend !== null && $prevSpend > 0)
                ? round(($spend - $prevSpend) / $prevSpend * 100, 1)
                : null;

            $opportunities[] = [
                'category' => $categoryName,
                'monthly_spend' => $spend,
                'share_of_discretionary' => 0.0, // filled once totals are known
                'mom_growth_pct' => $momGrowth,
                'top_merchants' => array_slice($data['merchants'], 0, 5),
            ];
        }

        $discretionaryTotal = round($discretionaryTotal, 2);
        $essentialTotal = round($essentialTotal, 2);
        $unclassifiedTotal = round($unclassifiedTotal, 2);

        // Rank by spend desc and fill the share-of-discretionary figure.
        usort($opportunities, fn (array $a, array $b): int => $b['monthly_spend'] <=> $a['monthly_spend']);
        foreach ($opportunities as &$opp) {
            $opp['share_of_discretionary'] = $discretionaryTotal > 0
                ? round($opp['monthly_spend'] / $discretionaryTotal * 100, 1)
                : 0.0;
        }
        unset($opp);

        $subscriptions = $this->subscriptions();
        $subscriptionCost = round(array_sum(array_column($subscriptions, 'monthly_cost')), 2);

        $overBudget = $this->overBudget($anchor);

        $potentialSavings = round($discretionaryTotal * self::CUTTABLE_FRACTION, 2);

        return [
            'window_months' => $months,
            'current_month' => $currentMonth,
            'discretionary_total' => $discretionaryTotal,
            'essential_total' => $essentialTotal,
            'unclassified_total' => $unclassifiedTotal,
            'subscription_cost' => $subscriptionCost,
            'potential_monthly_savings' => $potentialSavings,
            'cut_opportunities' => array_values($opportunities),
            'over_budget' => $overBudget,
            'subscriptions' => $subscriptions,
            'projection' => $this->projection(),
            'one_time_suggestions' => $this->oneTimeSuggestions(),
        ];
    }

    /**
     * Deterministic detector for likely one-time purchases the user may want to
     * exclude — large, infrequent, non-recurring buys (move-in furniture,
     * hardware, U-Haul, etc.) that distort the trend/forecast. Unlike the
     * projection's per-category spike flags, this works at the TRANSACTION level
     * across the whole lookback window, so a big purchase that landed in an
     * EARLIER month (not the basis month) is still surfaced.
     *
     * Heuristic (all conditions must hold), over the last ~6 months from the
     * reporting anchor, on expenses only (amount<0, transfer null, not excluded):
     *
     *  - Large outlier: abs(amount) ≥ max($200 floor, 4 × median window expense).
     *  - Infrequent / non-recurring: the merchant — grouped by the same
     *    noise-stripped key the recurring detector uses — appears ≤2 times in the
     *    window AND is not an active {@see RecurringSeries}. This is what splits a
     *    one-off furniture buy from regular groceries or a subscription.
     *  - Non-fixed category: skip anything in a fixed-expense category (rent, a
     *    car payment); a large infrequent buy in any other category is valid.
     *    Discretionary categories are preferred (sorted first within equal-ish
     *    amounts) but a large infrequent buy in any non-fixed category counts.
     *
     * Sorted by amount desc, capped at {@see ONE_TIME_MAX_SUGGESTIONS}.
     *
     * Read-only.
     *
     * @return array<int, array{id: int, transaction_date: string, merchant: string, category: string, amount: float, reason: string}>
     */
    public function oneTimeSuggestions(int $months = self::ONE_TIME_LOOKBACK_MONTHS): array
    {
        $anchor = $this->reportingPeriod->anchor();
        $since = $this->reportingPeriod->windowStart($months);
        $until = $anchor->endOfMonth();

        // The detection heuristic lives in OneTimePurchaseDetector (shared with
        // CashFlowForecaster). Here we run it over the reporting window, then
        // DECORATE each row with the human-readable reason and final shape — the
        // presentation concern stays in the analyzer.
        $suggestions = $this->oneTimeDetector
            ->detect($since, $until)
            ->map(fn (array $row): array => [
                'id' => $row['id'],
                'transaction_date' => $row['transaction_date'],
                'merchant' => $row['merchant'],
                'category' => $row['category'],
                'amount' => $row['amount'],
                'reason' => $this->oneTimeReason($row['amount'], $row['discretionary'], $row['occurrences']),
            ])
            ->all();

        // Largest first (the spend that distorts the forecast most), discretionary
        // ahead of essential-but-infrequent when amounts tie.
        usort($suggestions, function (array $a, array $b): int {
            return $b['amount'] <=> $a['amount'];
        });

        return array_slice($suggestions, 0, self::ONE_TIME_MAX_SUGGESTIONS);
    }

    /**
     * Human-readable reason a transaction was surfaced as a one-time candidate.
     */
    private function oneTimeReason(float $amount, bool $discretionary, int $occurrences): string
    {
        $frequency = $occurrences <= 1 ? 'one-off' : 'rare';
        $kind = $discretionary ? 'discretionary' : 'non-fixed';

        return "Large {$frequency} {$kind} purchase — likely one-time / move-in";
    }

    /**
     * Deterministic "if spending continues as-is" forward projection.
     *
     * Anchored to the latest complete data month (the basis month) — NOT the
     * live calendar month — so it ignores the empty in-progress month and the
     * older, different-life-regime months. Built from two parts:
     *
     *  - fixed_monthly: the go-forward truth. Sum of every ACTIVE FixedExpense
     *    normalized to a monthly figure (so a new apartment's rent, once entered
     *    as a fixed expense, is reflected immediately — independent of what the
     *    basis month's transactions happened to contain).
     *  - variable_monthly: the basis month's spend in every category that is NOT
     *    the target of an active FixedExpense (so fixed costs that also show up
     *    as transactions aren't double-counted). Honors the same filters as the
     *    rest of the engine (amount<0, transfer null, not excluded) — the
     *    per-transaction exclude toggle is the user's lever to drop specific
     *    move-in purchases from the run-rate.
     *
     * One-time spikes: each variable category's basis-month spend is compared to
     * a baseline = median of its spend over the PRIOR months in a ~6-month
     * lookback. Exceeding baseline × 1.5 by a material dollar amount (and with
     * ≥2 prior months of history) flags it as likely one-time / move-in.
     * variable_monthly_normalized strips each flagged category back to baseline.
     *
     * Two projections (as_is keeps spikes, normalized removes them), each giving
     * monthly / three_month (×3) / annual (×12) figures plus projected_net_annual.
     *
     * Simplifying assumptions, stated plainly:
     *  - "Fixed" = the exact category_ids referenced by active FixedExpenses.
     *    Everything else is variable.
     *  - Income run-rate = the user's MAINTAINED monthly net income, projected
     *    flat at ×12, resolved via {@see resolveMonthlyIncome()}. We deliberately
     *    avoid the basis month's actual deposits here: a bi-weekly cheque lands 2
     *    or 3 times in a month, so a single month of transactions swings income by
     *    ~50% and makes projected_net_annual unreliable. The maintained figure
     *    (a UserProfile's net pay, or summed IncomeEntry rows) is stable. The
     *    basis-month deposits remain only as a last-resort fallback.
     *
     * Read-only.
     *
     * @return array{
     *     basis_month: string,
     *     fixed_monthly: float,
     *     variable_monthly: float,
     *     variable_monthly_normalized: float,
     *     income_monthly: float,
     *     income_warning: ?string,
     *     flags: array<int, array{category: string, last_month: float, baseline: float, excess: float, note: string}>,
     *     as_is: array{monthly: float, three_month: float, annual: float, projected_net_annual: float},
     *     normalized: array{monthly: float, three_month: float, annual: float, projected_net_annual: float}
     * }
     */
    public function projection(): array
    {
        $anchor = $this->reportingPeriod->anchor();
        $basisMonth = $anchor->format('Y-m');

        $fixedExpenses = FixedExpense::query()
            ->where('is_active', true)
            ->get(['id', 'category_id', 'amount', 'frequency']);

        $fixedMonthly = round(
            $fixedExpenses->sum(fn (FixedExpense $f): float => $f->monthly_amount),
            2,
        );

        // Category ids that are the target of an active fixed expense → treated
        // as "fixed" and excluded from the variable run-rate to avoid double-
        // counting a cost that's already in fixed_monthly.
        $fixedCategoryIds = $fixedExpenses
            ->pluck('category_id')
            ->filter()
            ->unique()
            ->all();

        // Basis-month variable spend per category (anchor month, variable
        // categories only), honoring the standard filters.
        $variableByCategory = $this->basisMonthVariableByCategory($basisMonth, $fixedCategoryIds);
        $variableMonthly = round(array_sum($variableByCategory), 2);

        // Baselines from the prior months in the lookback window (anchor-aware).
        $categoryByMonth = $this->aggregator
            ->aggregate(self::BASELINE_LOOKBACK_MONTHS, $anchor)['category_by_month'];

        $flags = [];
        $variableNormalized = $variableByCategory;

        foreach ($variableByCategory as $categoryName => $lastMonthSpend) {
            $priorSpends = $this->priorMonthSpends($categoryByMonth, $categoryName, $basisMonth);

            // Need at least two prior months of data to judge a "usual" level.
            if (count($priorSpends) < 2) {
                continue;
            }

            $baseline = Stats::median($priorSpends);

            if ($baseline <= 0.0) {
                continue;
            }

            $excess = $lastMonthSpend - $baseline;

            if ($lastMonthSpend <= $baseline * self::SPIKE_MULTIPLE || $excess < self::SPIKE_MIN_EXCESS) {
                continue;
            }

            $flags[] = [
                'category' => $categoryName,
                'last_month' => round($lastMonthSpend, 2),
                'baseline' => round($baseline, 2),
                'excess' => round($excess, 2),
                'note' => 'likely one-time / move-in',
            ];

            // Normalized run-rate strips the excess back to the usual level.
            $variableNormalized[$categoryName] = $baseline;
        }

        $variableMonthlyNormalized = round(array_sum($variableNormalized), 2);

        ['monthly' => $incomeMonthly, 'warning' => $incomeWarning] = $this->resolveMonthlyIncome($basisMonth);

        return [
            'basis_month' => $basisMonth,
            'fixed_monthly' => $fixedMonthly,
            'variable_monthly' => $variableMonthly,
            'variable_monthly_normalized' => $variableMonthlyNormalized,
            'income_monthly' => round($incomeMonthly, 2),
            'income_warning' => $incomeWarning,
            'flags' => $flags,
            'as_is' => $this->projectForward($fixedMonthly + $variableMonthly, $incomeMonthly),
            'normalized' => $this->projectForward($fixedMonthly + $variableMonthlyNormalized, $incomeMonthly),
        ];
    }

    /**
     * Project a monthly spend run-rate forward, with a flat income run-rate.
     *
     * @return array{monthly: float, three_month: float, annual: float, projected_net_annual: float}
     */
    private function projectForward(float $monthlySpend, float $monthlyIncome): array
    {
        $monthlySpend = round($monthlySpend, 2);

        return [
            'monthly' => $monthlySpend,
            'three_month' => round($monthlySpend * 3, 2),
            'annual' => round($monthlySpend * 12, 2),
            'projected_net_annual' => round($monthlyIncome * 12 - $monthlySpend * 12, 2),
        ];
    }

    /**
     * Basis-month spend per category name, restricted to VARIABLE categories
     * (those not targeted by an active fixed expense). Same filters as the rest
     * of the engine: amount<0, transfer null, not excluded, categorized.
     *
     * @param  array<int, int>  $fixedCategoryIds
     * @return array<string, float>
     */
    private function basisMonthVariableByCategory(string $basisMonth, array $fixedCategoryIds): array
    {
        $query = Transaction::query()
            ->expense()
            ->whereNotNull('category_id')
            ->whereYear('transaction_date', (int) substr($basisMonth, 0, 4))
            ->whereMonth('transaction_date', (int) substr($basisMonth, 5, 2))
            ->with('category:id,name');

        if ($fixedCategoryIds !== []) {
            $query->whereNotIn('category_id', $fixedCategoryIds);
        }

        $byCategory = [];

        foreach ($query->get(['id', 'category_id', 'amount']) as $t) {
            $name = $t->category?->name ?? 'Uncategorized';
            $byCategory[$name] = ($byCategory[$name] ?? 0) + abs((float) $t->amount);
        }

        return $byCategory;
    }

    /**
     * The monthly net income to project forward, chosen from a graceful
     * fallback chain so the projection's income line stays STABLE month to month
     * (the basis month's raw deposits swing ~50% with 2-vs-3 bi-weekly cheques):
     *
     *  1. Any {@see IncomeEntry} rows with `is_net=true` → the sum of their
     *     monthly-equivalents (each `amount` converted by its `frequency`).
     *  2. Else a {@see UserProfile} for the current user → its `monthly_net`
     *     accessor (net pay per period converted by pay frequency).
     *  3. Else — only if there truly are IncomeEntry rows but none is
     *     `is_net=true` — sum them anyway (gross), flagged with a warning
     *     (never let is_net filtering collapse income to $0).
     *  4. Else the legacy behaviour: the basis month's actual income
     *     transactions (positive, transfer null, not excluded).
     *
     * Steps 1–3 are {@see IncomeFallback}, shared with BudgetRuleAnalyzer so
     * the two never disagree given the same IncomeEntry/UserProfile data.
     *
     * @return array{monthly: float, warning: ?string}
     */
    private function resolveMonthlyIncome(string $basisMonth): array
    {
        $fallback = IncomeFallback::resolve();

        if (! $fallback['found']) {
            return ['monthly' => $this->basisMonthIncome($basisMonth), 'warning' => null];
        }

        return ['monthly' => $fallback['monthly'], 'warning' => $fallback['warning']];
    }

    /**
     * The basis month's income: positive amounts, transfer null, not excluded.
     */
    private function basisMonthIncome(string $basisMonth): float
    {
        return (float) Transaction::query()
            ->real()
            ->where('amount', '>', 0)
            ->whereYear('transaction_date', (int) substr($basisMonth, 0, 4))
            ->whereMonth('transaction_date', (int) substr($basisMonth, 5, 2))
            ->sum('amount');
    }

    /**
     * That category's spend in each PRIOR month of the lookback (i.e. every
     * month in category_by_month except the basis month itself).
     *
     * @param  array<string, array<string, float>>  $categoryByMonth
     * @return array<int, float>
     */
    private function priorMonthSpends(array $categoryByMonth, string $categoryName, string $basisMonth): array
    {
        $spends = [];

        foreach ($categoryByMonth as $ym => $categories) {
            if ($ym === $basisMonth) {
                continue;
            }

            if (isset($categories[$categoryName])) {
                $spends[] = (float) $categories[$categoryName];
            }
        }

        return $spends;
    }

    /**
     * Current-month spend per category name with a per-category merchant
     * breakdown, derived from the same filtered transaction set the AI
     * aggregator uses (amount<0, transfer null, not excluded, categorized).
     *
     * @return array<string, array{spend: float, merchants: array<int, array{description: string, total: float, count: int}>}>
     */
    private function currentMonthByCategory(int $months, string $currentMonth, CarbonImmutable $anchor): array
    {
        $since = $this->reportingPeriod->windowStart($months);

        $transactions = Transaction::query()
            ->expense()
            ->whereNotNull('category_id')
            ->where('transaction_date', '>=', $since)
            ->with('category:id,name')
            ->get(['id', 'category_id', 'transaction_date', 'amount', 'description']);

        $byCategory = [];

        foreach ($transactions as $t) {
            if ($t->transaction_date->format('Y-m') !== $currentMonth) {
                continue;
            }

            $name = $t->category?->name ?? 'Uncategorized';
            $spend = abs((float) $t->amount);

            $byCategory[$name]['spend'] = ($byCategory[$name]['spend'] ?? 0) + $spend;
            $byCategory[$name]['merchants'][$t->description]['total']
                = ($byCategory[$name]['merchants'][$t->description]['total'] ?? 0) + $spend;
            $byCategory[$name]['merchants'][$t->description]['count']
                = ($byCategory[$name]['merchants'][$t->description]['count'] ?? 0) + 1;
        }

        foreach ($byCategory as $name => &$data) {
            $merchants = [];
            foreach ($data['merchants'] as $desc => $m) {
                $merchants[] = [
                    'description' => $desc,
                    'total' => round($m['total'], 2),
                    'count' => $m['count'],
                ];
            }
            usort($merchants, fn (array $a, array $b): int => $b['total'] <=> $a['total']);
            $data['merchants'] = $merchants;
        }
        unset($data);

        return $byCategory;
    }

    /**
     * Active recurring series normalized to a monthly figure, sorted by cost.
     *
     * @return array<int, array{merchant: string, cadence: string, monthly_cost: float}>
     */
    private function subscriptions(): array
    {
        $series = RecurringSeries::query()
            ->where('status', 'active')
            ->get(['merchant_key', 'cadence', 'expected_amount']);

        $rows = $series->map(fn (RecurringSeries $s): array => [
            'merchant' => $s->merchant_key,
            'cadence' => $s->cadence,
            'monthly_cost' => round($this->monthlyCost((float) $s->expected_amount, $s->cadence), 2),
        ])->all();

        usort($rows, fn (array $a, array $b): int => $b['monthly_cost'] <=> $a['monthly_cost']);

        return $rows;
    }

    /** Normalize a recurring charge to a monthly figure (mirrors SubscriptionController). */
    private function monthlyCost(float $amount, string $cadence): float
    {
        return Frequency::tryFrom($cadence)?->monthlyAmount($amount) ?? $amount;
    }

    /**
     * Categories whose current-period spend exceeds their active budget.
     * Mirrors BudgetController's spend query (honors transfer/excluded) and
     * rolls child spend up under a parent-category budget.
     *
     * @return array<int, array{category: string, spent: float, budget: float, overage: float}>
     */
    private function overBudget(CarbonImmutable $now): array
    {
        $budgets = Budget::query()
            ->where('is_active', true)
            ->with('category:id,name,parent_id')
            ->get();

        if ($budgets->isEmpty()) {
            return [];
        }

        $childrenByParent = Category::query()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id')
            ->map(fn ($rows) => $rows->pluck('id')->all());

        $rows = [];

        foreach ($budgets as $budget) {
            $window = $this->budgetWindow($budget->period, $now);

            $categoryIds = array_merge(
                [$budget->category_id],
                $childrenByParent->get($budget->category_id, []),
            );

            $spent = (float) Transaction::query()
                ->expense()
                ->whereIn('category_id', $categoryIds)
                ->whereBetween('transaction_date', [$window['start'], $window['end']])
                ->sum(DB::raw('-amount'));

            $amount = (float) $budget->amount;

            if ($amount > 0 && $spent > $amount) {
                $rows[] = [
                    'category' => $budget->category?->name ?? 'Uncategorized',
                    'spent' => round($spent, 2),
                    'budget' => round($amount, 2),
                    'overage' => round($spent - $amount, 2),
                ];
            }
        }

        usort($rows, fn (array $a, array $b): int => $b['overage'] <=> $a['overage']);

        return $rows;
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function budgetWindow(string $period, CarbonImmutable $now): array
    {
        return match ($period) {
            'weekly' => ['start' => $now->startOfWeek(), 'end' => $now->endOfWeek()],
            'yearly' => ['start' => $now->startOfYear(), 'end' => $now->endOfYear()],
            default => ['start' => $now->startOfMonth(), 'end' => $now->endOfMonth()],
        };
    }

    /**
     * Map of category name (lowercased) → parent name (lowercased) so a child
     * category inherits its parent's discretionary/essential classification.
     *
     * @return array<string, string|null>
     */
    private function categoryMeta(): array
    {
        return Category::query()
            ->with('parent:id,name')
            ->get(['id', 'name', 'parent_id'])
            ->mapWithKeys(fn (Category $c): array => [
                mb_strtolower($c->name) => $c->parent ? mb_strtolower($c->parent->name) : null,
            ])
            ->all();
    }

    /**
     * Map of category name (lowercased) → stored need/want `kind` (or null).
     * The single source of truth for classification once a user has set it;
     * {@see classify()} falls back to the name lists only where kind is null.
     *
     * @return array<string, string|null>
     */
    private function categoryKinds(): array
    {
        return Category::query()
            ->get(['id', 'name', 'kind'])
            ->mapWithKeys(fn (Category $c): array => [mb_strtolower($c->name) => $c->kind])
            ->all();
    }

    /**
     * Three-state classification by the category's stored `kind` when set —
     * 'discretionary' / 'essential' / 'unclassified' — falling back to the
     * (lowercased) name/parent keyword lists only when kind is null, so a
     * never-classified category behaves exactly as before. A child with a null
     * kind inherits its parent's kind. A name matching neither list (and with no
     * kind) is 'unclassified' — NOT silently assumed cuttable, keeping "potential
     * savings" honest while still surfacing the spend.
     *
     * @param  array<string, string|null>  $categoryMeta  name → parent name
     * @param  array<string, string|null>  $categoryKinds  name → kind
     */
    private function classify(string $categoryName, array $categoryMeta, array $categoryKinds): string
    {
        $name = mb_strtolower($categoryName);
        $parent = $categoryMeta[$name] ?? null;

        $kind = $categoryKinds[$name] ?? null;
        if ($kind === null && $parent !== null) {
            $kind = $categoryKinds[$parent] ?? null;
        }

        if ($kind !== null) {
            return match ($kind) {
                'want' => 'discretionary',
                'need', 'saving', 'investment' => 'essential',
                default => 'unclassified', // 'excluded' — neither cuttable nor essential
            };
        }

        $matches = fn (array $list): bool => in_array($name, $list, true)
            || ($parent !== null && in_array($parent, $list, true));

        if ($matches(self::DISCRETIONARY)) {
            return 'discretionary';
        }

        if ($matches(self::ESSENTIAL)) {
            return 'essential';
        }

        return 'unclassified';
    }
}
