<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\IncomeEntry;
use App\Models\Transaction;
use App\Models\UserProfile;
use App\Services\BudgetRuleAnalyzer;
use App\Services\CashFlowForecaster;
use App\Services\NetWorthService;
use App\Services\ReportingPeriod;
use Carbon\CarbonImmutable;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(
        private readonly CashFlowForecaster $forecaster,
        private readonly NetWorthService $netWorth,
        private readonly ReportingPeriod $reportingPeriod,
        private readonly BudgetRuleAnalyzer $budgetRule,
    ) {}

    public function index()
    {
        // Anchor the period to the latest month with imported data (statements
        // lag ~1 month), not the live calendar month. recentTransactions and
        // the forecast below stay on real now() — they're intentionally live.
        $anchor = $this->reportingPeriod->anchor();
        $monthStart = $anchor->startOfMonth();
        $monthEnd = $anchor->endOfMonth();

        // Transfers (money moving between your own accounts, loan payments,
        // card payments) aren't real income or expense — exclude them from
        // the top-line metrics so the dashboard reflects actual cash flow.
        $transferCategoryIds = $this->transferCategoryIds();

        $monthTotals = Transaction::query()
            ->real()
            ->whereBetween('transaction_date', [$monthStart, $monthEnd])
            ->when($transferCategoryIds !== [], fn ($q) => $q->whereNotIn('category_id', $transferCategoryIds))
            ->selectRaw('
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as expenses,
                SUM(amount) as net,
                COUNT(*) as count
            ')
            ->first();

        $income = (float) ($monthTotals->income ?? 0);
        $expenses = (float) ($monthTotals->expenses ?? 0);
        $net = (float) ($monthTotals->net ?? 0);

        $accountBalances = $this->computeAccountBalances();

        $recent = Transaction::query()
            ->with([
                'bankAccount:id,name,type',
                'category:id,name,icon,color',
            ])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return Inertia::render('Dashboard', [
            'title' => 'Dashboard',
            'periodLabel' => $anchor->translatedFormat('F Y'),
            'periodPartial' => $this->reportingPeriod->currentMonthMeta()['likely_partial'],
            // The user's pay cadence, surfaced as context on the Income metric (a
            // bi-weekly earner's monthly income swings between 2- and 3-pay months).
            'incomeFrequency' => $this->incomeFrequency(),
            'metrics' => [
                'income' => $income,
                'expenses' => $expenses,
                'net' => $net,
                // Full net worth (cash + assets − liabilities), not just bank balances.
                'total_balance' => $this->netWorth->current()['net_worth'],
            ],
            'accountSummaries' => $accountBalances['accounts'],
            'recentTransactions' => $recent,
            'spendingByCategory' => $this->spendingByCategory($monthStart, $monthEnd, $transferCategoryIds),
            'forecast' => $this->forecastSummary(),
            'budgetRule' => $this->budgetRule->analyze(),
        ]);
    }

    /**
     * The user's income cadence for the Income metric subtitle: their stated
     * profile pay frequency, else the dominant cadence of their recurring income
     * entries (a one-time receipt is not a cadence), else null.
     */
    private function incomeFrequency(): ?string
    {
        // UserProfile is NOT auto-scoped by user (no BelongsToUser trait), so it
        // must be filtered to the current user explicitly to avoid reading another
        // user's profile.
        $profileFrequency = UserProfile::query()->where('user_id', auth()->id())->value('pay_frequency');

        if ($profileFrequency !== null) {
            return $profileFrequency;
        }

        return IncomeEntry::query()
            ->where('frequency', '!=', 'one_time')
            ->selectRaw('frequency, COUNT(*) as occurrences')
            ->groupBy('frequency')
            ->orderByDesc('occurrences')
            ->value('frequency');
    }

    /**
     * Group spending in the period by category, rolling child-category
     * spend up under each parent. Returns the parents sorted by absolute
     * spend desc, with a `children` array on each.
     *
     * @return array<int, array<string, mixed>>
     */
    private function spendingByCategory(CarbonImmutable $start, CarbonImmutable $end, array $excludeCategoryIds): array
    {
        $rows = Transaction::query()
            ->expense()
            ->whereBetween('transaction_date', [$start, $end])
            ->whereNotNull('category_id')
            ->when($excludeCategoryIds !== [], fn ($q) => $q->whereNotIn('category_id', $excludeCategoryIds))
            ->selectRaw('category_id, SUM(-amount) as spent, COUNT(*) as occurrences')
            ->groupBy('category_id')
            ->get()
            ->keyBy('category_id');

        if ($rows->isEmpty()) {
            return [];
        }

        $categories = Category::query()
            ->with('parent:id,name')
            ->whereIn('id', $rows->keys())
            ->get()
            ->keyBy('id');

        // Bucket per parent (or self-parent for top-level categories)
        $byParent = [];
        foreach ($rows as $catId => $row) {
            $cat = $categories->get($catId);
            if (! $cat) {
                continue;
            }

            $parentId = $cat->parent_id ?? $cat->id;
            $parentName = $cat->parent?->name ?? $cat->name;
            $parentIcon = $cat->parent?->icon ?? $cat->icon;
            $parentColor = $cat->parent?->color ?? $cat->color;

            if (! isset($byParent[$parentId])) {
                $byParent[$parentId] = [
                    'id' => $parentId,
                    'name' => $parentName,
                    'icon' => $parentIcon,
                    'color' => $parentColor,
                    'spent' => 0.0,
                    'occurrences' => 0,
                    'children' => [],
                ];
            }

            $byParent[$parentId]['spent'] += (float) $row->spent;
            $byParent[$parentId]['occurrences'] += (int) $row->occurrences;

            if ($cat->parent_id !== null) {
                $byParent[$parentId]['children'][] = [
                    'name' => $cat->name,
                    'spent' => round((float) $row->spent, 2),
                    'occurrences' => (int) $row->occurrences,
                ];
            } else {
                // The parent category's OWN direct transactions, surfaced as a line
                // so the children always reconcile to the parent's total.
                $byParent[$parentId]['children'][] = [
                    'name' => $cat->name.' (direct)',
                    'spent' => round((float) $row->spent, 2),
                    'occurrences' => (int) $row->occurrences,
                ];
            }
        }

        $totalSpent = array_sum(array_column($byParent, 'spent'));

        $result = array_map(function ($row) use ($totalSpent) {
            $row['spent'] = round($row['spent'], 2);
            $row['share'] = $totalSpent > 0 ? round($row['spent'] / $totalSpent * 100, 1) : 0;
            usort($row['children'], fn ($a, $b) => $b['spent'] <=> $a['spent']);

            return $row;
        }, array_values($byParent));

        usort($result, fn ($a, $b) => $b['spent'] <=> $a['spent']);

        return $result;
    }

    /**
     * Compact 30-day cash-flow outlook for the dashboard widget.
     *
     * @return array{lowest_balance: float, lowest_date: string, shortfall_date: ?string}
     */
    private function forecastSummary(): array
    {
        $forecast = $this->forecaster->forecast(30);

        return [
            'lowest_balance' => $forecast['lowest']['balance'],
            'lowest_date' => $forecast['lowest']['date'],
            'shortfall_date' => $forecast['shortfall_date'],
        ];
    }

    /**
     * IDs of excluded-kind categories (transfers / inter-account money movement)
     * and any of their children. Used to keep these out of real cash-flow
     * numbers. Resolved by kind — not by the name "Transfers" — so it captures
     * every excluded tree (the DB can hold duplicates) and stays consistent with
     * the rest of the app, which filters on kind='excluded'.
     */
    private function transferCategoryIds(): array
    {
        return Category::idsOfKind('excluded');
    }

    /**
     * @return array{total: float, accounts: array<int, array<string, mixed>>}
     */
    private function computeAccountBalances(): array
    {
        $accounts = BankAccount::query()->orderBy('sort_order')->get();

        $latestByAccount = Transaction::query()
            ->select('bank_account_id', 'balance_after', 'transaction_date')
            ->whereIn('bank_account_id', $accounts->pluck('id'))
            ->whereNotNull('balance_after')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('bank_account_id')
            ->map(fn ($rows) => $rows->first());

        $rows = [];
        $total = 0.0;

        foreach ($accounts as $account) {
            $latest = $latestByAccount->get($account->id);
            $balance = $latest
                ? (float) $latest->balance_after
                : (float) $account->current_balance;

            // Signed-balance convention across all account types: positive = money
            // you have, negative = money you owe. Credit balances fold in directly,
            // matching NetWorthService.
            $total += $balance;

            $rows[] = [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'balance' => $balance,
            ];
        }

        return ['total' => round($total, 2), 'accounts' => $rows];
    }
}
