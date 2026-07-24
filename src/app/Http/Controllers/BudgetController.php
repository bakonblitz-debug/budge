<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\ReportingPeriod;
use App\Support\Frequency;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class BudgetController extends Controller
{
    private const PERIODS = ['monthly', 'weekly', 'yearly'];

    public function __construct(private readonly ReportingPeriod $reportingPeriod) {}

    public function index()
    {
        // Anchor budget windows to the latest month with imported data
        // (statements lag ~1 month), not the live calendar month.
        $now = $this->reportingPeriod->anchor();

        $budgets = Budget::query()
            ->with('category:id,name,icon,color,parent_id')
            ->with('category.parent:id,name')
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get();

        // Pre-compute children IDs per parent so we can roll up spending
        // when a budget targets a parent category.
        $childrenByParent = Category::query()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id')
            ->map(fn ($rows) => $rows->pluck('id')->all());

        $rows = $budgets->map(function (Budget $b) use ($now, $childrenByParent) {
            $window = $this->currentWindow($b->period, $now);

            // For a budget on a parent category, include child-category spending too
            $categoryIds = array_merge(
                [$b->category_id],
                $childrenByParent->get($b->category_id, []),
            );

            $spent = (float) Transaction::query()
                ->expense()
                ->whereIn('category_id', $categoryIds)
                ->whereBetween('transaction_date', [$window['start'], $window['end']])
                ->sum(DB::raw('-amount'));

            $amount = (float) $b->amount;
            $remaining = round($amount - $spent, 2);
            $progress = $amount > 0 ? min(100, round($spent / $amount * 100, 1)) : 0;

            return [
                'id' => $b->id,
                'amount' => $amount,
                'period' => $b->period,
                'period_label' => $window['label'],
                'start_date' => optional($b->start_date)->format('Y-m-d'),
                'end_date' => optional($b->end_date)->format('Y-m-d'),
                'is_active' => $b->is_active,
                'spent' => round($spent, 2),
                'remaining' => $remaining,
                'progress' => $progress,
                'status' => $this->status($spent, $amount),
                'category' => $b->category ? [
                    'id' => $b->category->id,
                    'name' => $b->category->name,
                    'icon' => $b->category->icon,
                    'color' => $b->category->color,
                    'parent_name' => $b->category->parent?->name,
                ] : null,
            ];
        });

        $monthlyEquivalent = round($rows->where('is_active', true)->sum(function ($r) {
            return Frequency::budgetPeriodMonthlyAmount((float) $r['amount'], $r['period']);
        }), 2);

        // "Monthly spent so far" is this calendar month's ACTUAL spend across all
        // budgeted categories — never a per-period spend rescaled by 52/12 or /12
        // (that scales an accumulated window total by a rate, which is wrong: a
        // partial/empty anchor week reads $0, a single week reads a full month's rate).
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $monthlySpent = round($budgets->where('is_active', true)->sum(function (Budget $b) use ($monthStart, $monthEnd, $childrenByParent) {
            $categoryIds = array_merge([$b->category_id], $childrenByParent->get($b->category_id, []));

            return (float) Transaction::query()
                ->expense()
                ->whereIn('category_id', $categoryIds)
                ->whereBetween('transaction_date', [$monthStart, $monthEnd])
                ->sum(DB::raw('-amount'));
        }), 2);

        return Inertia::render('Budgets/Index', [
            'title' => 'Budgets',
            'budgets' => $rows->values(),
            'totals' => [
                'monthly_budgeted' => $monthlyEquivalent,
                'monthly_spent' => $monthlySpent,
            ],
            'periodPartial' => $this->reportingPeriod->currentMonthMeta()['likely_partial'],
            'categories' => Category::query()
                ->with('parent:id,name')
                ->select('id', 'name', 'parent_id', 'icon', 'color')
                ->orderByUsage()
                ->get(),
            'periods' => self::PERIODS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateBudget($request);
        Budget::create($validated);

        return back()->with(['message' => 'Budget added.', 'type' => 'success']);
    }

    public function update(Request $request, Budget $budget)
    {
        $validated = $this->validateBudget($request, $budget->id);
        $budget->update($validated);

        return back()->with(['message' => 'Budget updated.', 'type' => 'success']);
    }

    public function destroy(Budget $budget)
    {
        $budget->delete();

        return back()->with(['message' => 'Budget deleted.', 'type' => 'success']);
    }

    private function validateBudget(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id'),
                Rule::unique('budgets', 'category_id')
                    ->where(fn ($q) => $q->where('is_active', true))
                    ->ignore($ignoreId),
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],
            'period' => ['required', Rule::in(self::PERIODS)],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],
        ], [
            'category_id.unique' => 'There is already an active budget for that category.',
        ]);
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, label: string}
     */
    private function currentWindow(string $period, CarbonImmutable $now): array
    {
        return match ($period) {
            'weekly' => [
                'start' => $now->startOfWeek(),
                'end' => $now->endOfWeek(),
                'label' => 'This week',
            ],
            'yearly' => [
                'start' => $now->startOfYear(),
                'end' => $now->endOfYear(),
                'label' => $now->format('Y'),
            ],
            default => [
                'start' => $now->startOfMonth(),
                'end' => $now->endOfMonth(),
                'label' => $now->format('F Y'),
            ],
        };
    }

    private function status(float $spent, float $amount): string
    {
        if ($amount <= 0) {
            return 'unset';
        }
        $ratio = $spent / $amount;
        if ($ratio >= 1.0) {
            return 'over';
        }
        if ($ratio >= 0.85) {
            return 'warning';
        }

        return 'ok';
    }
}
