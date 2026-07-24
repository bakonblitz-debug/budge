<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateTransactionRequest;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'account' => ['nullable', 'integer'],
            // A numeric category id, or the literal 'none' for uncategorized.
            'category' => ['nullable', 'string'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:income,expense'],
        ]);

        $query = Transaction::query()
            ->with([
                'bankAccount:id,name,type',
                'category:id,name,icon,color,parent_id',
                'category.parent:id,name',
            ]);

        if ($filters['account'] ?? null) {
            $query->where('bank_account_id', $filters['account']);
        }
        if (($filters['category'] ?? null) === 'none') {
            $query->whereNull('category_id');
        } elseif ($filters['category'] ?? null) {
            $query->where('category_id', (int) $filters['category']);
        }
        if ($filters['month'] ?? null) {
            $query->whereMonth('transaction_date', $filters['month']);
        }
        if ($filters['year'] ?? null) {
            $query->whereYear('transaction_date', $filters['year']);
        }
        if ($search = $filters['search'] ?? null) {
            $query->where('description', 'like', '%'.$search.'%');
        }
        if (($filters['type'] ?? null) === 'income') {
            $query->where('amount', '>', 0);
        } elseif (($filters['type'] ?? null) === 'expense') {
            $query->where('amount', '<', 0);
        }

        $transactions = $query
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // Period totals computed from the filtered (but unpaginated) set
        $totals = (clone $query)
            ->selectRaw('
                SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as expenses,
                SUM(amount) as net,
                COUNT(*) as count
            ')
            ->first();

        return Inertia::render('Transactions/Index', [
            'title' => 'Transactions',
            'transactions' => $transactions,
            'totals' => [
                'income' => (float) ($totals->income ?? 0),
                'expenses' => (float) ($totals->expenses ?? 0),
                'net' => (float) ($totals->net ?? 0),
                'count' => (int) ($totals->count ?? 0),
            ],
            'filters' => $filters,
            'bankAccounts' => BankAccount::query()
                ->orderBy('sort_order')
                ->get(['id', 'name', 'type']),
            'categories' => Category::query()
                ->with('parent:id,name')
                ->select('id', 'name', 'parent_id', 'icon', 'color')
                ->orderByUsage()
                ->get(),
        ]);
    }

    /**
     * Apply the user's manual overrides to a single transaction: recategorize,
     * exclude from budgets/analysis, or add a note. Bank-sourced fields (amount,
     * date, description, hash) are immutable here — the import is the source of
     * truth.
     */
    public function update(UpdateTransactionRequest $request, Transaction $transaction)
    {
        $transaction->update($request->validated());

        return back()->with(['message' => 'Transaction updated.', 'type' => 'success']);
    }
}
