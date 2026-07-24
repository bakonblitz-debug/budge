<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\Transfer;
use App\Services\TransferDetector;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TransferController extends Controller
{
    public function __construct(private readonly TransferDetector $detector) {}

    /** Manually link two transactions (one outflow, one inflow) as a transfer. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'transaction_ids' => ['required', 'array', 'size:2'],
            'transaction_ids.*' => ['integer', Rule::exists('transactions', 'id')],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $legs = Transaction::query()
            ->whereIn('id', $validated['transaction_ids'])
            ->get();

        if ($legs->count() !== 2) {
            throw ValidationException::withMessages(['transaction_ids' => 'Both transactions must exist.']);
        }
        if ($legs->whereNotNull('transfer_id')->isNotEmpty()) {
            throw ValidationException::withMessages(['transaction_ids' => 'One of those is already part of a transfer.']);
        }

        $out = $legs->firstWhere(fn (Transaction $t): bool => (float) $t->amount < 0);
        $in = $legs->firstWhere(fn (Transaction $t): bool => (float) $t->amount > 0);

        if (! $out || ! $in) {
            throw ValidationException::withMessages([
                'transaction_ids' => 'A transfer needs one outgoing and one incoming transaction.',
            ]);
        }

        $this->detector->link($out, $in, 'manual', $validated['note'] ?? null);

        return back()->with(['message' => 'Transactions linked as a transfer.', 'type' => 'success']);
    }

    public function destroy(Transfer $transfer)
    {
        $this->detector->unlink($transfer);

        return back()->with(['message' => 'Transfer unlinked.', 'type' => 'success']);
    }

    /** Auto-detect and link transfer pairs across the user's accounts. */
    public function detect(Request $request)
    {
        $count = $this->detector->detect((int) $request->integer('days', 3));

        return back()->with([
            'message' => $count === 0
                ? 'No new transfers found.'
                : "Linked {$count} transfer".($count === 1 ? '' : 's').'.',
            'type' => 'success',
        ]);
    }
}
