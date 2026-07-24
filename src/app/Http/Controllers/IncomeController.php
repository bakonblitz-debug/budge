<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\IncomeEntry;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class IncomeController extends Controller
{
    private const FREQUENCIES = ['weekly', 'bi_weekly', 'monthly', 'one_time'];

    public function index()
    {
        $now = CarbonImmutable::now();

        // Build the unified ledger: manual entries + income-categorized transactions
        $events = $this->buildIncomeLedger();

        // Distribution stats over a bounded recent window so a long-gone income
        // source (an old job, a one-off years ago) doesn't bias the "typical
        // pay" figures. The full ledger still drives the event list and totals.
        $statsSince = $now->subYear()->format('Y-m-d');
        $recentEvents = $events->filter(
            fn (array $e): bool => $e['date'] !== null && $e['date'] >= $statsSince,
        );

        return Inertia::render('Income/Index', [
            'title' => 'Income',
            'events' => $events->take(100)->values(),
            'totals' => $this->totals($events, $now),
            'stats' => array_merge($this->stats($recentEvents), ['window_label' => 'last 12 months']),
            'frequencies' => self::FREQUENCIES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateEntry($request);
        IncomeEntry::create($validated);

        return back()->with(['message' => 'Income entry added.', 'type' => 'success']);
    }

    public function update(Request $request, IncomeEntry $income)
    {
        $validated = $this->validateEntry($request);
        $income->update($validated);

        return back()->with(['message' => 'Entry updated.', 'type' => 'success']);
    }

    public function destroy(IncomeEntry $income)
    {
        $income->delete();

        return back()->with(['message' => 'Entry deleted.', 'type' => 'success']);
    }

    private function validateEntry(Request $request): array
    {
        return $request->validate([
            'source' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'pay_date' => ['required', 'date'],
            'is_net' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * Compose the income ledger from two sources:
     *  - Manual IncomeEntry rows (for cash / off-statement income)
     *  - Transactions categorized under "Income" (parent or any child) with amount > 0
     *
     * Each event has a `source_kind` of 'manual' or 'statement' so the UI can
     * distinguish their provenance. Sorted by date desc.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildIncomeLedger(): Collection
    {
        $manual = IncomeEntry::query()
            ->orderByDesc('pay_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (IncomeEntry $e) => [
                'id' => $e->id,
                'source_kind' => 'manual',
                'date' => optional($e->pay_date)->format('Y-m-d'),
                'source' => $e->source,
                'amount' => (float) $e->amount,
                'frequency' => $e->frequency,
                'is_net' => (bool) $e->is_net,
                'notes' => $e->notes,
                'transaction_id' => null,
            ]);

        $incomeCategoryIds = $this->incomeCategoryIds();

        $statement = $incomeCategoryIds === []
            ? collect()
            : Transaction::query()
                ->real()
                ->whereIn('category_id', $incomeCategoryIds)
                ->where('amount', '>', 0)
                ->with('bankAccount:id,name')
                ->orderByDesc('transaction_date')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Transaction $t) => [
                    'id' => 'txn-'.$t->id,
                    'source_kind' => 'statement',
                    'date' => optional($t->transaction_date)->format('Y-m-d'),
                    'source' => $t->description,
                    'amount' => (float) $t->amount,
                    'frequency' => null,
                    'is_net' => true, // bank statement amounts are by definition the net
                    'notes' => $t->bank_account?->name,
                    'transaction_id' => $t->id,
                ]);

        return $manual
            ->concat($statement)
            ->sortByDesc('date')
            ->values();
    }

    /**
     * @return array<int, int>
     */
    private function incomeCategoryIds(): array
    {
        // Resolve by kind, not by name: name-based ->first() silently captured only
        // one of several duplicate "Income" trees. An income category is any
        // income-kind category, plus any child of one (children may be left
        // untagged in the UI but still belong to income).
        return Category::idsOfKind('income');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<string, float|int>
     */
    private function totals(Collection $events, CarbonImmutable $now): array
    {
        $twelveWeeksAgo = $now->subWeeks(12)->format('Y-m-d');
        $yearAgo = $now->subYear()->format('Y-m-d');

        return [
            'last_12_weeks' => round((float) $events->where('date', '>=', $twelveWeeksAgo)->sum('amount'), 2),
            'last_12_months' => round((float) $events->where('date', '>=', $yearAgo)->sum('amount'), 2),
            'count' => $events->count(),
            'manual_count' => $events->where('source_kind', 'manual')->count(),
            'statement_count' => $events->where('source_kind', 'statement')->count(),
        ];
    }

    /**
     * Compute distribution stats. Useful when income varies meaningfully
     * (bonuses, tax refunds, overtime): median + p25/p75 reveal the "typical
     * pay" while the mean is skewed by outliers.
     *
     * @param  Collection<int, array<string, mixed>>  $events
     * @return array<string, float|int|null>
     */
    private function stats(Collection $events): array
    {
        if ($events->isEmpty()) {
            return [
                'count' => 0, 'mean' => null, 'median' => null,
                'p25' => null, 'p75' => null,
                'min' => null, 'max' => null,
                'cadence_days' => null,
            ];
        }

        $amounts = $events->pluck('amount')->map(fn ($a) => (float) $a)->sort()->values();
        $n = $amounts->count();

        $percentile = function (Collection $sorted, float $p) {
            $n = $sorted->count();
            if ($n === 0) {
                return null;
            }
            if ($n === 1) {
                return $sorted->first();
            }
            $idx = $p * ($n - 1);
            $lower = (int) floor($idx);
            $upper = (int) ceil($idx);
            if ($lower === $upper) {
                return $sorted->get($lower);
            }
            $weight = $idx - $lower;

            return $sorted->get($lower) * (1 - $weight) + $sorted->get($upper) * $weight;
        };

        // Cadence: average days between consecutive events (date-sorted ascending).
        $dates = $events
            ->pluck('date')
            ->filter()
            ->map(fn ($d) => CarbonImmutable::parse($d))
            ->sort()
            ->values();

        $cadence = null;
        if ($dates->count() >= 2) {
            $gaps = [];
            for ($i = 1; $i < $dates->count(); $i++) {
                $gaps[] = $dates[$i - 1]->diffInDays($dates[$i]);
            }
            $cadence = round(array_sum($gaps) / count($gaps), 1);
        }

        return [
            'count' => $n,
            'mean' => round($amounts->sum() / $n, 2),
            'median' => round($percentile($amounts, 0.5), 2),
            'p25' => round($percentile($amounts, 0.25), 2),
            'p75' => round($percentile($amounts, 0.75), 2),
            'min' => round($amounts->first(), 2),
            'max' => round($amounts->last(), 2),
            'cadence_days' => $cadence,
        ];
    }
}
