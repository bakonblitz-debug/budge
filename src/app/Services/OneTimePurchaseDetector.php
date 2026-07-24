<?php

namespace App\Services;

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Support\MerchantKey;
use App\Support\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deterministic detector for likely one-time purchases — large, infrequent,
 * non-recurring buys (move-in furniture, hardware, U-Haul) that distort the
 * spending trend and the cash-flow forecast. No AI.
 *
 * Extracted from {@see SpendingCutAnalyzer::oneTimeSuggestions()} so both that
 * analyzer AND {@see CashFlowForecaster} can reuse the SAME heuristic without
 * duplicating the constants. The detector is window-parameterized: callers pass
 * the exact `[start, end]` window to evaluate, so the forecaster can run it over
 * its own baseline window rather than a fixed reporting period.
 *
 * Heuristic (all must hold), on expenses only (amount<0, transfer null, not
 * excluded) inside the window:
 *
 *  - Large outlier: abs(amount) >= max($200 floor, 4 × median window expense).
 *    The median is ALWAYS computed over the FULL fetched expense population —
 *    never deflated by {@see detect()}'s $excludeCategoryIds — so the "large
 *    outlier" bar stays stable and ordinary spend can't be promoted into a
 *    one-time by removing big debits from the population.
 *  - Infrequent / non-recurring: the merchant (grouped by the same noise-stripped
 *    key the recurring detector uses) appears <= 2 times in the window AND is not
 *    an active {@see RecurringSeries}.
 *  - Non-fixed category: skip anything in a fixed-expense category.
 *  - Not money movement: transfers / investments / savings / debt are never
 *    "purchases" and are skipped.
 *  - Not in $excludeCategoryIds: a per-call candidate gate (e.g. the forecaster
 *    passes its investment + modelled categories so they are never themselves
 *    flagged out of the burn).
 *
 * Read-only.
 */
class OneTimePurchaseDetector
{
    /** Absolute floor: nothing under this is ever a one-time. */
    private const ONE_TIME_DOLLAR_FLOOR = 200.0;

    /** Large-outlier multiple: abs(amount) >= k × median expense in the window. */
    private const ONE_TIME_MEDIAN_MULTIPLE = 4.0;

    /** A merchant appearing more than this many times in the window is "frequent" → not one-time. */
    private const ONE_TIME_MAX_MERCHANT_OCCURRENCES = 2;

    /**
     * Category names (lowercased, matched against the category OR its parent)
     * that move money rather than spend it.
     *
     * @var array<int, string>
     */
    private const MONEY_MOVEMENT_TOKENS = [
        'transfers', 'transfer', 'investments', 'investment', 'savings',
        'debt', 'loan', 'taxes', 'tax',
    ];

    /**
     * Detect one-time purchases inside an explicit window.
     *
     * @param  array<int, int>  $excludeCategoryIds  category ids whose rows may NEVER be
     *                                               FLAGGED one-time (candidate gate only). Does NOT affect the median, which is
     *                                               always computed over the full fetched expense population in the window — so the
     *                                               "large outlier" bar can't be deflated by removing big debits. Defaults to []
     *                                               so existing callers are unaffected.
     * @return Collection<int, array{id: int, transaction_date: string, amount: float, category_id: ?int, category: string, merchant: string, discretionary: bool, occurrences: int}>
     */
    public function detect(CarbonImmutable $start, CarbonImmutable $end, array $excludeCategoryIds = []): Collection
    {
        $transactions = Transaction::query()
            ->expense()
            ->whereBetween('transaction_date', [$start->startOfDay(), $end->endOfDay()])
            ->with('category:id,name')
            ->get(['id', 'category_id', 'transaction_date', 'amount', 'merchant_name', 'description']);

        if ($transactions->isEmpty()) {
            return collect();
        }

        // Window median expense magnitude over the FULL population → the relative
        // "large outlier" bar. Never deflated by $excludeCategoryIds.
        $median = Stats::median(
            $transactions->map(fn (Transaction $t): float => abs((float) $t->amount))->all(),
        );
        $amountThreshold = max(self::ONE_TIME_DOLLAR_FLOOR, $median * self::ONE_TIME_MEDIAN_MULTIPLE);

        $occurrences = $transactions
            ->groupBy(fn (Transaction $t): string => MerchantKey::for($t))
            ->map(fn ($rows): int => $rows->count());

        $activeRecurringKeys = RecurringSeries::query()
            ->where('status', 'active')
            ->pluck('merchant_key')
            ->map(fn (string $k): string => mb_strtolower($k))
            ->all();

        $fixedCategoryIds = FixedExpense::query()
            ->where('is_active', true)
            ->pluck('category_id')
            ->filter()
            ->unique()
            ->all();

        $categoryMeta = $this->categoryMeta();

        $suggestions = collect();

        foreach ($transactions as $t) {
            $amount = abs((float) $t->amount);

            if ($amount < $amountThreshold) {
                continue;
            }

            // Per-call candidate gate: investment / modelled categories the caller
            // never wants flagged (e.g. the forecaster's burn-eligible exclusions).
            if ($t->category_id !== null && in_array($t->category_id, $excludeCategoryIds, true)) {
                continue;
            }

            if ($t->category_id !== null && in_array($t->category_id, $fixedCategoryIds, true)) {
                continue;
            }

            $key = MerchantKey::for($t);

            if (($occurrences[$key] ?? 0) > self::ONE_TIME_MAX_MERCHANT_OCCURRENCES) {
                continue;
            }

            if (in_array($key, $activeRecurringKeys, true)) {
                continue;
            }

            $categoryName = $t->category?->name ?? 'Uncategorized';

            if ($this->isMoneyMovement($categoryName, $categoryMeta)) {
                continue;
            }

            $suggestions->push([
                'id' => (int) $t->id,
                'transaction_date' => $t->transaction_date->toDateString(),
                'amount' => round($amount, 2),
                'category_id' => $t->category_id !== null ? (int) $t->category_id : null,
                'category' => $categoryName,
                'merchant' => $t->merchant_name ?: $t->description,
                'discretionary' => $this->isDiscretionary($categoryName, $categoryMeta),
                'occurrences' => $occurrences[$key] ?? 1,
            ]);
        }

        return $suggestions;
    }

    /**
     * Map of category name (lowercased) → parent name (lowercased) so a child
     * category inherits its parent's classification.
     *
     * Public so sibling read-only services (e.g. {@see CategorySpikeDetector})
     * can reuse the SAME discretionary/money-movement classification instead of
     * re-declaring the keyword lists.
     *
     * @return array<string, string|null>
     */
    public function categoryMeta(): array
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
     * @param  array<string, string|null>  $categoryMeta
     */
    public function isMoneyMovement(string $categoryName, array $categoryMeta): bool
    {
        $name = mb_strtolower($categoryName);
        $parent = $categoryMeta[$name] ?? null;

        return in_array($name, self::MONEY_MOVEMENT_TOKENS, true)
            || ($parent !== null && in_array($parent, self::MONEY_MOVEMENT_TOKENS, true));
    }

    /**
     * @param  array<string, string|null>  $categoryMeta
     */
    public function isDiscretionary(string $categoryName, array $categoryMeta): bool
    {
        $name = mb_strtolower($categoryName);
        $parent = $categoryMeta[$name] ?? null;

        return in_array($name, SpendingCutAnalyzer::DISCRETIONARY, true)
            || ($parent !== null && in_array($parent, SpendingCutAnalyzer::DISCRETIONARY, true));
    }
}
