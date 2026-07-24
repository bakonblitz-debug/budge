<?php

namespace App\Services;

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Support\Frequency;
use App\Support\MerchantKey;
use App\Support\Stats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Detects recurring spend (subscriptions, memberships, regular bills) from
 * transaction history. Deterministic, no AI: it groups expenses by normalized
 * merchant, then keeps groups whose dates fall on a regular cadence with a
 * reasonably stable amount.
 */
class RecurringDetector
{
    private const MIN_OCCURRENCES = 3;

    /**
     * Fixed-expense suggestions demand a longer track record than subscriptions:
     * 4+ regular occurrences, so a one-off lump sum (e.g. an $8,000 deposit) and
     * brief two/three-charge blips never surface as recurring commitments.
     */
    private const MIN_FIXED_EXPENSE_OCCURRENCES = 4;

    /** Acceptable spread of the gaps between charges (stddev / mean). */
    private const MAX_INTERVAL_CV = 0.30;

    /** Max relative swing of an individual charge from the median to still count. */
    private const MAX_AMOUNT_DEVIATION = 0.50;

    /** Flag a price increase when the latest charge exceeds prior typical by this. */
    private const PRICE_INCREASE_RATIO = 1.10;

    /**
     * cadence => [minMeanDays, maxMeanDays, canonicalDays]. Public: the
     * canonical home {@see InvestmentScheduleProjector} reuses (same cadence
     * inference, mirrored there).
     */
    public const CADENCE_BANDS = [
        'weekly' => [6, 8, 7],
        'bi_weekly' => [12, 16, 14],
        'monthly' => [26, 35, 30],
        'bi_monthly' => [50, 70, 60],
        'quarterly' => [80, 100, 91],
        'semi_annual' => [165, 200, 182],
        'yearly' => [330, 400, 365],
    ];

    /**
     * Rebuild the current user's recurring series from their transactions.
     *
     * @return int number of active series after the scan
     */
    public function detect(): int
    {
        $now = Carbon::now();

        $groups = $this->groupedExpenses();

        // Keys the user already dismissed or converted to a fixed expense —
        // never resurrect these on a rescan.
        $skippedKeys = RecurringSeries::query()
            ->whereIn('status', ['dismissed', 'converted'])
            ->pluck('merchant_key')
            ->all();

        $detectedKeys = [];

        foreach ($groups as $key => $rows) {
            if ($key === '' || $rows->count() < self::MIN_OCCURRENCES) {
                continue;
            }

            if (in_array($key, $skippedKeys, true)) {
                continue;
            }

            $series = $this->analyze($key, $rows, $now);
            if ($series === null) {
                continue;
            }

            RecurringSeries::updateOrCreate(['merchant_key' => $key], $series);
            $detectedKeys[] = $key;
        }

        // Manual series whose merchant matched a detected group were already
        // updated above like any other series. The rest (too few/irregular
        // real charges, or none at all) still need reconciling against
        // reality — a manual add doesn't get a permanent pass just because
        // detect() can't find a clean cadence for it.
        RecurringSeries::query()
            ->where('is_manual', true)
            ->where('status', 'active')
            ->when($detectedKeys !== [], fn ($q) => $q->whereNotIn('merchant_key', $detectedKeys))
            ->get()
            ->each(function (RecurringSeries $series) use ($groups, $now) {
                $this->reconcileManual($series, $groups->get($series->merchant_key), $now);
            });

        // Anything previously auto-detected but no longer recurring is marked
        // ended. Dismissed/converted series and manual series (reconciled
        // above) are left untouched here.
        RecurringSeries::query()
            ->where('status', 'active')
            ->where('is_manual', false)
            ->when($detectedKeys !== [], fn ($q) => $q->whereNotIn('merchant_key', $detectedKeys))
            ->update(['status' => 'ended']);

        return RecurringSeries::query()->where('status', 'active')->count();
    }

    /**
     * Reconcile a manual series that had no clean detected group this scan:
     * end it once its real charges (or its own creation, if it never got a
     * single matching charge) are more than ~2 cadences stale. A series just
     * added within one cadence with no charges yet gets grace — the first
     * charge may simply not have posted.
     *
     * Staleness is measured against the interval the real charges ACTUALLY
     * ran at, not the stored cadence — a manual entry's cadence is user input
     * and is often wrong (e.g. a monthly stream mislabelled bi_monthly), which
     * would otherwise keep a dead series alive for an extra cadence.
     *
     * @param  ?Collection<int, Transaction>  $rows  real transactions matching this merchant, if any
     */
    private function reconcileManual(RecurringSeries $series, ?Collection $rows, Carbon $now): void
    {
        $cadenceDays = self::CADENCE_BANDS[$series->cadence][2] ?? 30;

        if ($rows !== null && $rows->isNotEmpty()) {
            $dates = $rows->map(fn (Transaction $t) => $t->transaction_date->copy()->startOfDay())
                ->sort()->values();

            // With 2+ charges, trust the observed cadence over the stored one.
            $interval = $dates->count() >= 2
                ? max(1, (int) round($dates->first()->diffInDays($dates->last()) / ($dates->count() - 1)))
                : $cadenceDays;

            if ($dates->last()->diffInDays($now) > $interval * 2) {
                $series->update(['status' => 'ended']);
            }

            return;
        }

        if ($series->created_at->diffInDays($now) > $cadenceDays) {
            $series->update(['status' => 'ended']);
        }
    }

    /**
     * Near-miss groups the user could promote to a tracked subscription: ≥2
     * occurrences with a stable amount, that are NOT already an active or
     * dismissed series. Each entry carries a suggested cadence + sample rows.
     *
     * @return array<int, array{merchant_key: string, occurrences: int, expected_amount: float, suggested_cadence: ?string, samples: array<int, array{date: string, amount: float}>}>
     */
    public function candidates(): array
    {
        $now = Carbon::now();
        $groups = $this->groupedExpenses();

        // Keys the user already tracks (active) or rejected (dismissed) are hidden.
        $trackedKeys = RecurringSeries::query()
            ->whereIn('status', ['active', 'dismissed'])
            ->pluck('merchant_key')
            ->all();

        $candidates = [];

        foreach ($groups as $key => $rows) {
            if ($key === '' || in_array($key, $trackedKeys, true)) {
                continue;
            }

            if ($rows->count() < 2 || $rows->count() >= self::MIN_OCCURRENCES) {
                // 3+ regular occurrences are handled by detect(); show only near misses.
                if ($rows->count() < 2) {
                    continue;
                }
                // A 3+ group that detect() rejected (irregular) can still be a candidate.
                if ($rows->count() >= self::MIN_OCCURRENCES && $this->analyze($key, $rows, $now) !== null) {
                    continue;
                }
            }

            $magnitudes = $rows->map(fn (Transaction $t) => abs((float) $t->amount))->all();
            $median = Stats::median($magnitudes);
            if ($median <= 0) {
                continue;
            }
            $maxDeviation = max(array_map(fn (float $m): float => abs($m - $median) / $median, $magnitudes));
            if ($maxDeviation > self::MAX_AMOUNT_DEVIATION) {
                continue;
            }

            $sorted = $rows->sortBy(fn (Transaction $t) => $t->transaction_date->getTimestamp())->values();
            $candidates[] = [
                'merchant_key' => $key,
                'occurrences' => $sorted->count(),
                'expected_amount' => round($median, 2),
                'suggested_cadence' => $this->suggestCadence($sorted),
                'samples' => $sorted->reverse()->take(3)->values()->map(fn (Transaction $t): array => [
                    'date' => $t->transaction_date->toDateString(),
                    'amount' => round(abs((float) $t->amount), 2),
                ])->all(),
            ];
        }

        usort($candidates, fn ($a, $b) => $b['occurrences'] <=> $a['occurrences']);

        return $candidates;
    }

    /**
     * Recurring fixed-expense suggestions for the Fixed Expenses page: regular,
     * stable-amount outflows the user should formalise as commitments — most
     * notably recurring investment contributions (PLACEMENT / TFSA / RRSP) that
     * are categorised as Transfers and so slip past the spending lenses.
     *
     * Differences from candidates() (subscriptions):
     *  - Groups by recurringKey(merchant) + rounded amount, so two streams under
     *    one merchant name ($75 vs $60 PLACEMENT NBC) separate into distinct
     *    candidates instead of looking irregular and getting dropped.
     *  - Includes Transfer-category / investment charges (they're the point).
     *  - Requires 4+ regular occurrences, so a one-off lump sum is excluded.
     *  - Hides streams already represented by an active FixedExpense (best-effort
     *    normalized-name match — won't catch a differently-named duplicate) or by
     *    an active RecurringSeries (so subscriptions don't clutter the list).
     *
     * @return array<int, array{merchant: string, amount: float, suggested_cadence: string, occurrences: int, category_id: ?int, category_name: ?string, sample: array<int, array{date: string, amount: float}>}>
     */
    public function fixedExpenseCandidates(): array
    {
        $now = Carbon::now();

        $groups = Transaction::query()
            ->expense()
            ->get(['transaction_date', 'amount', 'category_id', 'merchant_name', 'description'])
            ->groupBy(fn (Transaction $t): string => MerchantKey::for($t).'|'.round(abs((float) $t->amount), 2));

        // Best-effort dedupe: normalized name of every active fixed expense, and
        // every active recurring series' merchant_key.
        $trackedNames = FixedExpense::query()
            ->where('is_active', true)
            ->pluck('name')
            ->map(fn (string $name): string => MerchantKey::normalize($name))
            ->all();

        $trackedSeriesKeys = RecurringSeries::query()
            ->where('status', 'active')
            ->pluck('merchant_key')
            ->all();

        $categoryNames = Category::query()->pluck('name', 'id')->all();

        $candidates = [];

        foreach ($groups as $key => $rows) {
            if ($rows->count() < self::MIN_FIXED_EXPENSE_OCCURRENCES) {
                continue;
            }

            // recurringKey is the part of the group key before the trailing amount.
            $merchantKey = (string) substr($key, 0, (int) strrpos($key, '|'));
            if ($merchantKey === '') {
                continue;
            }

            if (in_array($merchantKey, $trackedSeriesKeys, true)) {
                continue;
            }
            if (in_array(MerchantKey::normalize($merchantKey), $trackedNames, true)) {
                continue;
            }

            $analysis = $this->analyze($merchantKey, $rows, $now);
            if ($analysis === null) {
                continue;
            }

            $sorted = $rows->sortBy(fn (Transaction $t) => $t->transaction_date->getTimestamp())->values();
            $categoryId = $sorted->reverse()->firstWhere('category_id', '!=', null)?->category_id;

            $candidates[] = [
                'merchant' => ucwords($merchantKey),
                'amount' => (float) $analysis['expected_amount'],
                'suggested_cadence' => $analysis['cadence'],
                'occurrences' => (int) $analysis['occurrences'],
                'category_id' => $categoryId,
                'category_name' => $categoryId !== null ? ($categoryNames[$categoryId] ?? null) : null,
                'sample' => $sorted->reverse()->take(3)->values()->map(fn (Transaction $t): array => [
                    'date' => $t->transaction_date->toDateString(),
                    'amount' => round(abs((float) $t->amount), 2),
                ])->all(),
            ];
        }

        usort($candidates, fn ($a, $b) => $this->monthlyEquivalent($b) <=> $this->monthlyEquivalent($a));

        return $candidates;
    }

    /** Monthly-equivalent cost of a candidate, for sorting most-expensive first. */
    private function monthlyEquivalent(array $candidate): float
    {
        $amount = (float) $candidate['amount'];

        return Frequency::tryFrom($candidate['suggested_cadence'])?->monthlyAmount($amount) ?? $amount;
    }

    /**
     * Group the current user's expense transactions by the stable recurring key.
     *
     * @return Collection<string, Collection<int, Transaction>>
     */
    private function groupedExpenses(): Collection
    {
        return Transaction::query()
            ->expense()
            ->get(['transaction_date', 'amount', 'category_id', 'merchant_name', 'description'])
            ->groupBy(fn (Transaction $t): string => MerchantKey::for($t));
    }

    /**
     * Best-effort cadence guess for a candidate from its average gap; null when
     * there's only one gap that fits no band (still a valid manual-add candidate).
     *
     * @param  Collection<int, Transaction>  $sorted
     */
    private function suggestCadence(Collection $sorted): ?string
    {
        if ($sorted->count() < 2) {
            return null;
        }

        $dates = $sorted->map(fn (Transaction $t) => $t->transaction_date->copy()->startOfDay())->values();
        $intervals = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $intervals[] = $dates[$i - 1]->diffInDays($dates[$i]);
        }

        $median = Stats::median($intervals);

        return $median > 0 ? self::matchCadence($median) : null;
    }

    /**
     * Fold intervals that span a whole-number multiple (2-3x) of the median
     * cadence back down to one cycle's length, so a subscription that skips
     * an occasional billing month (e.g. a 61-day gap in a ~30-day series)
     * doesn't fail the regularity gate below. See SUB-2.
     *
     * Guarded against folding a genuinely irregular series into a fake
     * cadence: only applied when regular (un-folded) intervals are already
     * the majority (>=2 of them, and at least as many as the folded count).
     * Otherwise the raw intervals are returned unchanged.
     *
     * Accepted limitation (SUB-3, not fixed here): this needs >=2 un-folded
     * intervals, i.e. >=4 charges. A 3-charge series has only 2 intervals
     * total, so a single skipped month (e.g. `[30, 60]`) can never satisfy
     * the guard and the series still rejects. Documented, not chased.
     *
     * @param  array<int, float|int>  $intervals
     * @return array<int, float>
     */
    private function foldSkippedIntervals(array $intervals, float $median): array
    {
        $folded = [];
        $unfoldedCount = 0;
        $foldedCount = 0;

        foreach ($intervals as $interval) {
            $m = (int) round($interval / $median);
            if ($m >= 2 && $m <= 3 && abs($interval / $m - $median) <= 0.20 * $median) {
                $folded[] = $interval / $m;
                $foldedCount++;

                continue;
            }

            $folded[] = (float) $interval;
            if ($m === 1) {
                $unfoldedCount++;
            }
        }

        if ($unfoldedCount < 2 || $foldedCount > $unfoldedCount) {
            return array_map(fn ($v): float => (float) $v, $intervals);
        }

        return $folded;
    }

    /**
     * Decide whether a merchant's transactions form a recurring series and, if
     * so, return the attributes to persist. Null = not recurring.
     *
     * @param  Collection<int, Transaction>  $rows
     * @return array<string, mixed>|null
     */
    private function analyze(string $key, Collection $rows, Carbon $now): ?array
    {
        $sorted = $rows->sortBy(fn (Transaction $t) => $t->transaction_date->getTimestamp())->values();

        $dates = $sorted->map(fn (Transaction $t) => $t->transaction_date->copy()->startOfDay());
        $intervals = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $intervals[] = $dates[$i - 1]->diffInDays($dates[$i]);
        }

        $medianInterval = Stats::median($intervals);
        if ($medianInterval <= 0) {
            return null;
        }

        $foldedIntervals = $this->foldSkippedIntervals($intervals, $medianInterval);

        // Gaps must be regular (on the skip-folded intervals).
        if (Stats::cv($foldedIntervals) > self::MAX_INTERVAL_CV) {
            return null;
        }

        $cadence = self::matchCadence(Stats::median($foldedIntervals));
        if ($cadence === null) {
            return null;
        }

        // Amount must be reasonably stable.
        $magnitudes = $sorted->map(fn (Transaction $t) => abs((float) $t->amount))->all();
        $median = Stats::median($magnitudes);
        if ($median <= 0) {
            return null;
        }
        $maxDeviation = max(array_map(fn (float $m): float => abs($m - $median) / $median, $magnitudes));
        if ($maxDeviation > self::MAX_AMOUNT_DEVIATION) {
            return null;
        }

        $lastAmount = (float) abs((float) $sorted->last()->amount);
        $priorMedian = Stats::median(array_slice($magnitudes, 0, -1));
        $amountIncreased = $priorMedian > 0 && $lastAmount > $priorMedian * self::PRICE_INCREASE_RATIO;

        $lastSeen = $dates->last();
        $nextExpected = $this->nextDate($lastSeen, $cadence);
        $canonicalDays = self::CADENCE_BANDS[$cadence][2];
        $status = $lastSeen->diffInDays($now) > $canonicalDays * 2 ? 'ended' : 'active';

        return [
            'category_id' => $sorted->reverse()->firstWhere('category_id', '!=', null)?->category_id,
            'cadence' => $cadence,
            'expected_amount' => round($median, 2),
            'last_amount' => round($lastAmount, 2),
            'amount_increased' => $amountIncreased,
            'occurrences' => $sorted->count(),
            'last_seen_at' => $lastSeen->toDateString(),
            'next_expected_at' => $nextExpected->toDateString(),
            'status' => $status,
        ];
    }

    /** Public: the canonical cadence-band match, reused by {@see InvestmentScheduleProjector}. */
    public static function matchCadence(float $meanInterval): ?string
    {
        foreach (self::CADENCE_BANDS as $cadence => [$min, $max]) {
            if ($meanInterval >= $min && $meanInterval <= $max) {
                return $cadence;
            }
        }

        return null;
    }

    private function nextDate(Carbon $from, string $cadence): Carbon
    {
        return match ($cadence) {
            'weekly' => $from->copy()->addWeek(),
            'bi_weekly' => $from->copy()->addWeeks(2),
            'monthly' => $from->copy()->addMonthNoOverflow(),
            'bi_monthly' => $from->copy()->addMonthsNoOverflow(2),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3),
            'semi_annual' => $from->copy()->addMonthsNoOverflow(6),
            'yearly' => $from->copy()->addYear(),
        };
    }
}
