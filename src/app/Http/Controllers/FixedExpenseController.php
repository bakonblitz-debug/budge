<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\RecurringSeries;
use App\Services\RecurringDetector;
use App\Support\Frequency;
use App\Support\MerchantKey;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class FixedExpenseController extends Controller
{
    private const FREQUENCIES = ['weekly', 'bi_weekly', 'monthly', 'quarterly', 'yearly'];

    public function __construct(private readonly RecurringDetector $detector) {}

    public function index()
    {
        $expenses = FixedExpense::query()
            ->with('category:id,name,icon,color,parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (FixedExpense $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'match_pattern' => $e->match_pattern,
                'amount' => (float) $e->amount,
                'frequency' => $e->frequency,
                'monthly_amount' => round($e->monthly_amount, 2),
                'due_day' => $e->due_day,
                'start_date' => optional($e->start_date)->format('Y-m-d'),
                'end_date' => optional($e->end_date)->format('Y-m-d'),
                'is_active' => $e->is_active,
                'notes' => $e->notes,
                'category' => $e->category ? [
                    'id' => $e->category->id,
                    'name' => $e->category->name,
                    'icon' => $e->category->icon,
                    'color' => $e->category->color,
                ] : null,
            ]);

        $monthlyTotal = round($expenses->where('is_active', true)->sum('monthly_amount'), 2);

        return Inertia::render('FixedExpenses/Index', [
            'title' => 'Fixed expenses',
            'expenses' => $expenses,
            'monthlyTotal' => $monthlyTotal,
            'suggestions' => $this->detector->fixedExpenseCandidates(),
            'categories' => Category::query()
                ->with('parent:id,name')
                ->select('id', 'name', 'parent_id', 'icon', 'color')
                ->orderByUsage()
                ->get(),
            'frequencies' => self::FREQUENCIES,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateExpense($request);
        $expense = FixedExpense::create($validated);

        $this->deactivateMatchingSeries($expense);

        return back()->with(['message' => "Fixed expense '{$validated['name']}' added.", 'type' => 'success']);
    }

    public function update(Request $request, FixedExpense $fixedExpense)
    {
        $validated = $this->validateExpense($request);
        $fixedExpense->update($validated);

        return back()->with(['message' => "'{$fixedExpense->name}' updated.", 'type' => 'success']);
    }

    public function destroy(FixedExpense $fixedExpense)
    {
        $name = $fixedExpense->name;
        $fixedExpense->delete();

        return back()->with(['message' => "'{$name}' deleted.", 'type' => 'success']);
    }

    /**
     * A manually-added FixedExpense duplicating an already-detected active
     * RecurringSeries would otherwise double-count in the cash-flow forecast
     * (both emit their own scheduled outflow). Mirrors
     * {@see SubscriptionController::convert()} in reverse: deactivate the
     * series when a new FixedExpense matches it by merchant key + amount.
     */
    private function deactivateMatchingSeries(FixedExpense $expense): void
    {
        $key = MerchantKey::normalize($expense->name);
        $monthlyAmount = Frequency::tryFrom($expense->frequency)?->monthlyAmount((float) $expense->amount)
            ?? (float) $expense->amount;

        RecurringSeries::query()
            ->where('status', 'active')
            ->get()
            ->first(function (RecurringSeries $series) use ($key, $monthlyAmount): bool {
                if (MerchantKey::normalize($series->merchant_key) !== $key) {
                    return false;
                }

                $seriesMonthly = Frequency::tryFrom($series->cadence)?->monthlyAmount((float) $series->expected_amount)
                    ?? (float) $series->expected_amount;

                return abs($seriesMonthly - $monthlyAmount) < 1.0;
            })
            ?->update(['status' => 'converted']);
    }

    private function validateExpense(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'match_pattern' => ['nullable', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'frequency' => ['required', Rule::in(self::FREQUENCIES)],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }
}
