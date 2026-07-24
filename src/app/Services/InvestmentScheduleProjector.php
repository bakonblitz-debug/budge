<?php

namespace App\Services;

use App\Models\Category;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Support\MerchantKey;
use App\Support\Stats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Projects recurring investment contributions (RRSP / TFSA / PLACEMENT, etc.)
 * as SCHEDULED outflow events for the cash-flow forecast, instead of letting
 * them smear into the flat "everyday burn" baseline.
 *
 * Why derive fresh from transactions rather than reuse {@see RecurringDetector}:
 * the persisted {@see RecurringSeries} for investments are unreliable
 * (ended / miscategorised), and RecurringDetector's CV-regularity gate drops
 * exactly those streams. So this read-only projector groups category-investment
 * debits by normalized merchant + amount, infers a cadence from the median gap,
 * and applies the SAME staleness gate RecurringDetector uses
 * (`lastSeen->diffInDays(now) > canonicalDays * 2 ⇒ ended`).
 *
 * One single active/stale decision drives everything: a stream is "active" iff
 * it passes the staleness gate. The set of ACTIVE stream merchant-keys, the ids
 * of their in-window transactions, and the projected future events are all
 * derived from that one decision, so the forecaster's burn-exclusion and
 * event-projection can never disagree:
 *
 *  - ACTIVE  → excluded from burn (by tx id) AND projected as events.
 *  - STALE   → projected nothing AND left in burn (a paused stream's history is
 *              real everyday spend, not a future commitment).
 *
 * Read-only; persists nothing.
 */
class InvestmentScheduleProjector
{
    /** Category names (own or parent, lowercased) treated as investments. */
    private const INVESTMENT_TOKENS = ['investments', 'investment'];

    /** Months of history scanned to infer investment cadence + amount. */
    private const LOOKBACK_MONTHS = 4;

    /** Minimum occurrences to infer a cadence at all. */
    private const MIN_OCCURRENCES = 2;

    /** @var array<int, int>|null Memoized (this is called repeatedly per request via {@see activeStreams()}). */
    private ?array $investmentCategoryIdsCache = null;

    /**
     * Category ids treated as investments: resolved by `kind = 'investment'`
     * (plus children) when any category is tagged. No migration backfills
     * `kind='investment'` (unlike income), so an untagged user falls back to
     * the original name-token match (own name OR parent name matches
     * {@see INVESTMENT_TOKENS}) — keep the fallback, don't delete it, or an
     * untagged user silently loses investment tracking into everyday burn.
     *
     * @return array<int, int>
     */
    public function investmentCategoryIds(): array
    {
        if ($this->investmentCategoryIdsCache !== null) {
            return $this->investmentCategoryIdsCache;
        }

        $byKind = Category::idsOfKind('investment');
        if ($byKind !== []) {
            return $this->investmentCategoryIdsCache = $byKind;
        }

        $categories = Category::query()->get(['id', 'name', 'parent_id']);
        $byId = $categories->keyBy('id');

        return $this->investmentCategoryIdsCache = $categories
            ->filter(function (Category $c) use ($byId): bool {
                if ($this->matchesInvestment($c->name)) {
                    return true;
                }
                $parent = $c->parent_id !== null ? $byId->get($c->parent_id) : null;

                return $parent !== null && $this->matchesInvestment($parent->name);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Merchant-keys (normalized, lowercased) of investment streams that are
     * ACTIVE as of $today (pass the staleness gate). This is the authoritative
     * set consumed by burn-exclusion, event-projection, AND the forecaster's
     * recurring-loop guard.
     *
     * @return array<int, string>
     */
    public function activeStreamKeys(CarbonImmutable $today, CarbonImmutable $anchor): array
    {
        return $this->activeStreams($today, $anchor)
            ->pluck('key')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Transaction ids, within the lookback window, belonging to ACTIVE
     * investment streams — what the forecaster excludes from the burn sum.
     *
     * @return array<int, int>
     */
    public function activeStreamTransactionIds(CarbonImmutable $today, CarbonImmutable $anchor): array
    {
        return $this->activeStreams($today, $anchor)
            ->flatMap(fn (array $s): array => $s['ids'])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Future investment outflow events within [today, end], one per occurrence
     * of each ACTIVE stream.
     *
     * @return array<int, array{date: string, label: string, amount: float, kind: string}>
     */
    public function events(CarbonImmutable $today, CarbonImmutable $end, ?CarbonImmutable $anchor = null): array
    {
        $anchor ??= $today;
        $events = [];

        foreach ($this->activeStreams($today, $anchor) as $stream) {
            foreach ($this->occurrences($stream['lastSeen'], $stream['cadence'], $today, $end) as $date) {
                $events[] = [
                    'date' => $date->toDateString(),
                    'label' => $this->label($stream['key']),
                    'amount' => -$stream['amount'],
                    'kind' => 'investment',
                ];
            }
        }

        return $events;
    }

    /**
     * The active investment streams as of $today, each carrying its merchant
     * key, go-forward amount, inferred cadence, last-seen date, and the ids of
     * its in-window transactions. The single active/stale decision lives here.
     *
     * @return Collection<int, array{key: string, amount: float, cadence: string, lastSeen: CarbonImmutable, ids: array<int, int>}>
     */
    private function activeStreams(CarbonImmutable $today, CarbonImmutable $anchor): Collection
    {
        $investmentCategoryIds = $this->investmentCategoryIds();

        if ($investmentCategoryIds === []) {
            return collect();
        }

        $since = $anchor->subMonthsNoOverflow(self::LOOKBACK_MONTHS)->startOfMonth();

        $rows = Transaction::query()
            ->expense()
            ->whereIn('category_id', $investmentCategoryIds)
            ->whereBetween('transaction_date', [$since, $anchor->endOfDay()])
            ->get(['id', 'transaction_date', 'amount', 'merchant_name', 'description']);

        if ($rows->isEmpty()) {
            return collect();
        }

        // Group by merchant key + rounded amount so "PLACEMENT NBC $75" and
        // "PLACEMENT NBC $60" separate into distinct streams (live data shape).
        $groups = $rows->groupBy(
            fn (Transaction $t): string => MerchantKey::for($t).'|'.number_format(abs((float) $t->amount), 2, '.', ''),
        );

        $streams = collect();

        foreach ($groups as $group) {
            if ($group->count() < self::MIN_OCCURRENCES) {
                continue;
            }

            $sorted = $group->sortBy(fn (Transaction $t) => $t->transaction_date->getTimestamp())->values();
            $dates = $sorted->map(fn (Transaction $t) => CarbonImmutable::parse($t->transaction_date->toDateString()));

            $gaps = [];
            for ($i = 1; $i < $dates->count(); $i++) {
                $gaps[] = $dates[$i - 1]->diffInDays($dates[$i]);
            }

            $cadence = RecurringDetector::matchCadence(Stats::median($gaps));
            if ($cadence === null) {
                continue;
            }

            $lastSeen = $dates->last();
            $canonicalDays = RecurringDetector::CADENCE_BANDS[$cadence][2];

            // === SINGLE ACTIVE/STALE DECISION (mirrors RecurringDetector) ===
            // Staleness is measured against the ANCHOR (the latest available data
            // date), not wall-clock $today. Bank statements lag ~1 month, so a
            // biweekly stream whose last contribution is the most recent data we
            // have is still LIVE — measuring from $today would mis-flag every
            // stream as stale purely because of import lag. A genuinely paused
            // stream stopped well BEFORE the anchor and is still caught.
            $staleReference = $anchor->gt($today) ? $today : $anchor;
            if ($lastSeen->diffInDays($staleReference) > $canonicalDays * 2) {
                continue; // paused/stale: not projected, left in burn.
            }

            $streams->push([
                'key' => MerchantKey::for($sorted->first()),
                'amount' => round(Stats::median(
                    $sorted->map(fn (Transaction $t): float => abs((float) $t->amount))->all(),
                ), 2),
                'cadence' => $cadence,
                'lastSeen' => $lastSeen,
                'ids' => $sorted->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            ]);
        }

        return $streams;
    }

    /**
     * Occurrence dates of a cadence at/after $today within [$today, $end],
     * stepping forward from $anchor (the last actual contribution).
     *
     * @return array<int, CarbonImmutable>
     */
    private function occurrences(CarbonImmutable $anchor, string $cadence, CarbonImmutable $today, CarbonImmutable $end): array
    {
        $dates = [];
        // Start one cadence step PAST the last actual contribution: $anchor is the
        // last contribution that already happened, so emitting it would re-project a
        // real outflow as a future one (double-count). A genuine future occurrence
        // that lands on today still survives (it is reached by stepping forward).
        $cursor = $this->step($anchor, $cadence);
        $guard = 0;

        // Advance to the first occurrence on/after today.
        while ($cursor->lt($today) && $guard++ < 5000) {
            $cursor = $this->step($cursor, $cadence);
        }
        while ($cursor->lte($end) && $guard++ < 5000) {
            $dates[] = $cursor;
            $cursor = $this->step($cursor, $cadence);
        }

        return $dates;
    }

    private function step(CarbonImmutable $from, string $cadence): CarbonImmutable
    {
        return match ($cadence) {
            'weekly' => $from->addWeek(),
            'bi_weekly' => $from->addWeeks(2),
            'monthly' => $from->addMonthNoOverflow(),
            'bi_monthly' => $from->addMonthsNoOverflow(2),
            'quarterly' => $from->addMonthsNoOverflow(3),
            'semi_annual' => $from->addMonthsNoOverflow(6),
            'yearly' => $from->addYear(),
            default => $from->addMonthNoOverflow(),
        };
    }

    private function matchesInvestment(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), self::INVESTMENT_TOKENS, true);
    }

    /** Human label for an investment event, e.g. "Placement Nbi (investment)". */
    private function label(string $key): string
    {
        $name = $key === '' ? 'Investment' : ucwords($key);

        return "{$name} (investment)";
    }
}
