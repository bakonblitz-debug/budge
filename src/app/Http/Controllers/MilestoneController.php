<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\SavingsMilestone;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Tracks savings milestones — the "$1K → $100K" progression.
 *
 * "Savings" here means net liquid worth: sum of asset balances minus debt
 * balances. We compute it from the most recent transaction balance per
 * account (or the stored current_balance if no transactions yet), inverting
 * credit-style accounts since their positive balance is money owed.
 *
 * Milestones auto-mark as reached when current savings cross the target;
 * a one-shot scan on /milestones updates reached_at timestamps. The user
 * can also manage them by hand.
 */
class MilestoneController extends Controller
{
    private const DEFAULT_TARGETS = [
        ['name' => '$1K saved', 'target_amount' => 1000],
        ['name' => '$5K saved', 'target_amount' => 5000],
        ['name' => '$10K saved', 'target_amount' => 10000],
        ['name' => '$25K saved', 'target_amount' => 25000],
        ['name' => '$50K saved', 'target_amount' => 50000],
        ['name' => '$100K saved', 'target_amount' => 100000],
    ];

    public function index()
    {
        if (SavingsMilestone::query()->doesntExist()) {
            $this->seedDefaults();
        }

        $currentSavings = $this->computeCurrentSavings();
        $this->markReached($currentSavings);

        $milestones = SavingsMilestone::query()
            ->orderBy('target_amount')
            ->get()
            ->map(function (SavingsMilestone $m) use ($currentSavings) {
                $target = (float) $m->target_amount;
                $progress = $target > 0 ? min(100, max(0, $currentSavings / $target * 100)) : 0;

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'target_amount' => $target,
                    'reached_at' => optional($m->reached_at)->format('Y-m-d'),
                    'is_reached' => (bool) $m->is_reached,
                    'progress' => round($progress, 1),
                    'remaining' => max(0, round($target - $currentSavings, 2)),
                ];
            });

        $rate = $this->recentSavingsRate();
        $nextUnreached = $milestones->firstWhere('is_reached', false);
        $eta = null;
        if ($nextUnreached && $rate > 0) {
            $monthsToNext = $nextUnreached['remaining'] / $rate;
            $eta = [
                'months' => round($monthsToNext, 1),
                // round (not ceil): an estimate shouldn't claim a later date than
                // the linear projection actually implies.
                'date' => CarbonImmutable::now()->addMonths((int) round($monthsToNext))->format('M Y'),
                'monthly_rate' => round($rate, 2),
            ];
        }

        return Inertia::render('Milestones/Index', [
            'title' => 'Savings milestones',
            'currentSavings' => round($currentSavings, 2),
            'milestones' => $milestones->values(),
            'nextMilestone' => $nextUnreached,
            'eta' => $eta,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'target_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        SavingsMilestone::create($validated + ['is_reached' => false]);

        return back()->with(['message' => 'Milestone added.', 'type' => 'success']);
    }

    public function update(Request $request, SavingsMilestone $milestone)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'target_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        $milestone->update($validated);

        return back()->with(['message' => 'Milestone updated.', 'type' => 'success']);
    }

    public function destroy(SavingsMilestone $milestone)
    {
        $milestone->delete();

        return back()->with(['message' => 'Milestone deleted.', 'type' => 'success']);
    }

    private function seedDefaults(): void
    {
        foreach (self::DEFAULT_TARGETS as $i => $t) {
            SavingsMilestone::create($t + ['is_reached' => false, 'sort_order' => $i]);
        }
    }

    /**
     * Mark milestones as reached if the current savings number passes
     * their target. Stamps reached_at if not already set.
     */
    private function markReached(float $currentSavings): void
    {
        $unreached = SavingsMilestone::query()
            ->where('is_reached', false)
            ->where('target_amount', '<=', $currentSavings)
            ->get();

        foreach ($unreached as $m) {
            $m->update([
                'is_reached' => true,
                'reached_at' => $m->reached_at ?? CarbonImmutable::now(),
            ]);
        }
    }

    /**
     * Net savings = sum of asset-account balances minus sum of credit-style
     * account balances (which represent money owed).
     */
    private function computeCurrentSavings(): float
    {
        $accounts = BankAccount::query()->get();

        $latestByAccount = Transaction::query()
            ->select('bank_account_id', 'balance_after')
            ->whereIn('bank_account_id', $accounts->pluck('id'))
            ->whereNotNull('balance_after')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('bank_account_id')
            ->map(fn ($rows) => $rows->first());

        $total = 0.0;
        foreach ($accounts as $account) {
            $latest = $latestByAccount->get($account->id);
            $balance = $latest
                ? (float) $latest->balance_after
                : (float) $account->current_balance;

            if (in_array($account->type, ['credit_card', 'line_of_credit'], true)) {
                $total -= $balance;
            } else {
                $total += $balance;
            }
        }

        return $total;
    }

    /**
     * Estimate the monthly savings rate from the last ~90 days of transactions.
     * Income minus expenses (excluding transfers), monthly-equivalent.
     * Returns 0 if no signal is available.
     */
    private function recentSavingsRate(): float
    {
        $since = CarbonImmutable::now()->subDays(90)->format('Y-m-d');

        // Same transfer-exclusion logic as the dashboard
        $transferIds = \App\Models\Category::query()
            ->whereNull('parent_id')
            ->where('name', 'Transfers')
            ->pluck('id');

        if ($transferIds->isNotEmpty()) {
            $transferIds = \App\Models\Category::query()
                ->whereIn('id', $transferIds)
                ->orWhereIn('parent_id', $transferIds)
                ->pluck('id');
        }

        $net = (float) Transaction::query()
            ->where('transaction_date', '>=', $since)
            ->when($transferIds->isNotEmpty(), fn ($q) => $q->whereNotIn('category_id', $transferIds))
            ->sum('amount');

        // Per month: $net spans 90 days, so /3 gives monthly equivalent
        return $net / 3;
    }
}
