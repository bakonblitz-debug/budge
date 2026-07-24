<?php

namespace App\Services;

use App\Models\Transaction;
use App\Support\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deterministic detector for a move-in "spree": a DISCRETIONARY category whose
 * spend spikes for one or more months above its normal level — repeated large
 * buys at the same store (furniture, hardware) that the per-transaction
 * {@see OneTimePurchaseDetector} misses because each merchant recurs >2× and is
 * therefore treated as "frequent". No AI.
 *
 * It strips only the per-month EXCESS over the category's own prior baseline,
 * leaving the everyday level in burn — so {@see CashFlowForecaster} stops
 * projecting a one-time capital cost as a recurring habit (a false cash cliff).
 *
 * It deliberately reuses, rather than re-declares, the existing spike model:
 *  - the per-MONTH spike test + constants live on {@see SpendingCutAnalyzer}
 *    (`SPIKE_MULTIPLE`, `SPIKE_MIN_EXCESS`, `BASELINE_LOOKBACK_MONTHS`);
 *  - the discretionary / money-movement classification lives on
 *    {@see OneTimePurchaseDetector} (`isDiscretionary()` / `isMoneyMovement()` /
 *    `categoryMeta()`), injected here.
 *
 * Eligibility (all must hold) + detection, per category present in the window:
 *  - Discretionary, and NOT a money-movement category. Protects essentials
 *    (groceries, transport) and money movement (transfers, investments) — those
 *    are never candidates and keep their full spend in burn.
 *  - baseline_monthly ≥ SPIKE_MIN_EXCESS: a category whose NORMAL monthly level
 *    is below the very excess floor used to judge spikes is too lumpy to call;
 *    its month-to-month noise would masquerade as a spike, so it is gated out.
 *  - ≥ 2 prior months with spend (else no defensible baseline).
 *  - Per-MONTH over EVERY in-window month (partial first/last included): flag a
 *    month when month_spend > baseline_monthly × SPIKE_MULTIPLE AND
 *    (month_spend − baseline_monthly) ≥ SPIKE_MIN_EXCESS. The unprorated
 *    full-month baseline is the bar, so a low partial trailing stub falls below
 *    it (not flagged) while a partial month carrying a huge buy clears it.
 *
 * Composition note (why $excludeTransactionIds exists): the forecaster's burn
 * total already removes one-time + active-investment rows. To avoid subtracting
 * those dollars twice, the in-window bucketing here must run over the SAME
 * population — so callers pass those ids and they are excluded from the in-window
 * spend. The prior-month BASELINE is left RAW on purpose: excluding rows there
 * would lower the bar and strip MORE (less conservative).
 *
 * Read-only.
 */
class CategorySpikeDetector
{
    public function __construct(
        private readonly OneTimePurchaseDetector $oneTime,
    ) {}

    /**
     * Detect discretionary category spikes inside an explicit window.
     *
     * @param  array<int, int>  $excludeCategoryIds  categories that may never be flagged
     *                                               (e.g. the forecaster's modelled + investment categories).
     * @param  array<int, int>  $excludeTransactionIds  ids already removed from the caller's
     *                                                  total (one-time + active-investment), excluded from the IN-WINDOW bucket so the
     *                                                  same dollars aren't subtracted twice. NOT applied to the baseline lookback.
     * @return Collection<int, array{category_id: int, category: string, baseline_monthly: float, window_spend: float, stripped_excess: float, months: array<int, array{ym: string, spend: float, excess: float}>}>
     */
    public function detect(
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $excludeCategoryIds = [],
        array $excludeTransactionIds = [],
    ): Collection {
        $windowQuery = Transaction::query()
            ->expense()
            ->whereNotNull('category_id')
            ->whereBetween('transaction_date', [$start->startOfDay(), $end->endOfDay()])
            ->with('category:id,name');

        if ($excludeCategoryIds !== []) {
            $windowQuery->whereNotIn('category_id', $excludeCategoryIds);
        }

        if ($excludeTransactionIds !== []) {
            $windowQuery->whereNotIn('id', $excludeTransactionIds);
        }

        $windowRows = $windowQuery->get(['id', 'category_id', 'transaction_date', 'amount']);

        if ($windowRows->isEmpty()) {
            return collect();
        }

        $meta = $this->oneTime->categoryMeta();
        $byCategory = $windowRows->groupBy('category_id');
        $baselineByCategoryMonth = $this->baselineMonthlyTotals($start, $byCategory->keys()->all());

        $results = collect();

        foreach ($byCategory as $categoryId => $rows) {
            $categoryName = $rows->first()->category?->name ?? 'Uncategorized';

            // Discretionary-only, never money movement.
            if (! $this->oneTime->isDiscretionary($categoryName, $meta)
                || $this->oneTime->isMoneyMovement($categoryName, $meta)) {
                continue;
            }

            // Baseline = median of prior months WITH spend; need ≥2 to judge a "usual" level.
            $priorMonths = array_values($baselineByCategoryMonth[(int) $categoryId] ?? []);

            if (count($priorMonths) < 2) {
                continue;
            }

            $baselineMonthly = Stats::median($priorMonths);

            // Density guard: too-low a normal level is indistinguishable from a spike.
            if ($baselineMonthly < SpendingCutAnalyzer::SPIKE_MIN_EXCESS) {
                continue;
            }

            [$flaggedMonths, $strippedExcess] = $this->flagMonths($rows, $baselineMonthly);

            if ($flaggedMonths === []) {
                continue;
            }

            $results->push([
                'category_id' => (int) $categoryId,
                'category' => $categoryName,
                'baseline_monthly' => round($baselineMonthly, 2),
                'window_spend' => round($rows->sum(fn (Transaction $t): float => abs((float) $t->amount)), 2),
                'stripped_excess' => round($strippedExcess, 2),
                'months' => $flaggedMonths,
            ]);
        }

        return $results;
    }

    /**
     * Per-month in-window spend vs the full-month baseline; flag + sum the excess
     * of months that individually clear the spike threshold.
     *
     * @param  Collection<int, Transaction>  $rows
     * @return array{0: array<int, array{ym: string, spend: float, excess: float}>, 1: float}
     */
    private function flagMonths(Collection $rows, float $baselineMonthly): array
    {
        $monthSpend = [];

        foreach ($rows as $t) {
            $ym = $t->transaction_date->format('Y-m');
            $monthSpend[$ym] = ($monthSpend[$ym] ?? 0) + abs((float) $t->amount);
        }

        ksort($monthSpend);

        $bar = $baselineMonthly * SpendingCutAnalyzer::SPIKE_MULTIPLE;
        $flagged = [];
        $strippedExcess = 0.0;

        foreach ($monthSpend as $ym => $spend) {
            $excess = $spend - $baselineMonthly;

            if ($spend > $bar && $excess >= SpendingCutAnalyzer::SPIKE_MIN_EXCESS) {
                $flagged[] = ['ym' => $ym, 'spend' => round($spend, 2), 'excess' => round($excess, 2)];
                $strippedExcess += $excess;
            }
        }

        return [$flagged, $strippedExcess];
    }

    /**
     * RAW monthly spend totals (no $excludeTransactionIds) per category over the
     * BASELINE_LOOKBACK_MONTHS complete calendar months immediately BEFORE the
     * window-start month — the prior "normal" level the spike is measured against.
     *
     * @param  array<int, int>  $categoryIds
     * @return array<int, array<string, float>> [categoryId => ['Y-m' => total]]
     */
    private function baselineMonthlyTotals(CarbonImmutable $start, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $baselineEnd = $start->startOfMonth()->subDay()->endOfDay();
        $baselineStart = $start->startOfMonth()
            ->subMonthsNoOverflow(SpendingCutAnalyzer::BASELINE_LOOKBACK_MONTHS)
            ->startOfDay();

        $rows = Transaction::query()
            ->expense()
            ->whereIn('category_id', $categoryIds)
            ->whereBetween('transaction_date', [$baselineStart, $baselineEnd])
            ->get(['category_id', 'transaction_date', 'amount']);

        $totals = [];

        foreach ($rows as $t) {
            $categoryId = (int) $t->category_id;
            $ym = $t->transaction_date->format('Y-m');
            $totals[$categoryId][$ym] = ($totals[$categoryId][$ym] ?? 0) + abs((float) $t->amount);
        }

        return $totals;
    }
}
