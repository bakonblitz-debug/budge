<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Transfer;
use Illuminate\Support\Facades\DB;

/**
 * Deterministically links the two legs of an inter-account transfer.
 *
 * A transfer is a pair of transactions where money leaves one account and an
 * equal amount enters another within a few days (chequing → investments, a card
 * payment, etc.). These are not real expense/income, so linking them lets every
 * aggregation exclude them with `whereNull('transfer_id')`.
 *
 * No AI: matching is exact-magnitude, opposite-sign, different-account, within a
 * small day window — chosen to keep false positives near zero.
 */
class TransferDetector
{
    /**
     * Scan the current user's unlinked transactions and auto-link transfer pairs.
     *
     * @return int number of transfers created
     */
    public function detect(int $dayWindow = 3): int
    {
        $unlinked = Transaction::query()
            ->real()
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get(['id', 'user_id', 'bank_account_id', 'amount', 'transaction_date']);

        $outflows = $unlinked->filter(fn (Transaction $t): bool => (float) $t->amount < 0);
        $inflows = $unlinked->filter(fn (Transaction $t): bool => (float) $t->amount > 0)->values();

        $usedInflowIds = [];
        $created = 0;

        foreach ($outflows as $out) {
            $magnitude = round(abs((float) $out->amount), 2);

            $match = $inflows
                ->reject(fn (Transaction $in): bool => in_array($in->id, $usedInflowIds, true))
                ->filter(fn (Transaction $in): bool => $in->bank_account_id !== $out->bank_account_id
                    && round((float) $in->amount, 2) === $magnitude
                    && abs($out->transaction_date->diffInDays($in->transaction_date)) <= $dayWindow)
                ->sortBy(fn (Transaction $in): int => abs($out->transaction_date->diffInDays($in->transaction_date)))
                ->first();

            if (! $match) {
                continue;
            }

            $this->link($out, $match, 'auto');
            $usedInflowIds[] = $match->id;
            $created++;
        }

        return $created;
    }

    /**
     * Link two transactions as a transfer (out = money leaving, in = money
     * entering) and stamp both legs with the new transfer id.
     */
    public function link(Transaction $out, Transaction $in, string $detectedVia = 'manual', ?string $note = null): Transfer
    {
        return DB::transaction(function () use ($out, $in, $detectedVia, $note): Transfer {
            $transfer = Transfer::create([
                'from_transaction_id' => $out->id,
                'to_transaction_id' => $in->id,
                'detected_via' => $detectedVia,
                'note' => $note,
            ]);

            Transaction::query()
                ->whereIn('id', [$out->id, $in->id])
                ->update(['transfer_id' => $transfer->id]);

            return $transfer;
        });
    }

    /** Unlink a transfer: clear both legs and delete the transfer record. */
    public function unlink(Transfer $transfer): void
    {
        DB::transaction(function () use ($transfer): void {
            Transaction::query()
                ->where('transfer_id', $transfer->id)
                ->update(['transfer_id' => null]);

            $transfer->delete();
        });
    }
}
