<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\Liability;
use App\Models\NetWorthSnapshot;
use App\Models\Transaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes net worth and records point-in-time snapshots.
 *
 * Net worth = spendable cash (chequing + savings) + credit-card / line-of-credit
 *           balances (signed: negative = owed) + manual assets − manual liabilities.
 * All account balances use one signed convention: positive = money you have,
 * negative = money you owe.
 */
class NetWorthService
{
    private const CASH_TYPES = ['chequing', 'savings'];

    private const CREDIT_TYPES = ['credit_card', 'line_of_credit'];

    /**
     * Current net-worth breakdown.
     *
     * @return array{
     *   cash: float, assets: float, liabilities: float, net_worth: float,
     *   credit_owed: float, credit_overpaid: float
     * }
     */
    public function current(): array
    {
        $cash = round($this->cashBalance(), 2);
        // Fetched once and reused for balance/owed/overpaid — these used to be 3
        // independent calls to latestBalancesFor() (3 queries for the same data).
        $creditBalances = $this->latestBalancesFor(self::CREDIT_TYPES);
        $creditBalance = round($creditBalances->sum(), 2);
        $assets = round((float) Asset::query()->where('is_active', true)->sum('current_value'), 2);
        $manualLiabilities = round((float) Liability::query()->where('is_active', true)->sum('balance'), 2);
        $creditOwed = round($creditBalances->map(fn (float $b): float => max(-$b, 0.0))->sum(), 2);
        $creditOverpaid = round($creditBalances->map(fn (float $b): float => max($b, 0.0))->sum(), 2);

        return [
            'cash' => $cash,
            'assets' => $assets,
            'liabilities' => round($manualLiabilities + $creditOwed, 2),
            'credit_owed' => $creditOwed,
            'credit_overpaid' => $creditOverpaid,
            // Signed-balance convention: a credit account's balance folds straight
            // in (negative = owed reduces net worth; a positive overpayment adds).
            'net_worth' => round($cash + $creditBalance + $assets - $manualLiabilities, 2),
        ];
    }

    /**
     * Upsert today's snapshot (one row per day). Returns the saved snapshot.
     */
    public function snapshot(?Carbon $asOf = null): NetWorthSnapshot
    {
        $asOf ??= Carbon::now();
        $current = $this->current();

        return NetWorthSnapshot::updateOrCreate(
            ['as_of' => $asOf->toDateString()],
            [
                'total_cash' => $current['cash'],
                'total_assets' => $current['assets'],
                'total_liabilities' => $current['liabilities'],
                'net_worth' => $current['net_worth'],
                'metadata' => ['credit_owed' => $current['credit_owed'], 'credit_overpaid' => $current['credit_overpaid']],
            ],
        );
    }

    /**
     * Spendable cash: latest known balance of chequing + savings accounts.
     * Public: {@see CashFlowForecaster} delegates its forecast starting
     * balance here so the two never disagree on "current cash".
     */
    public function cashBalance(): float
    {
        return $this->latestBalancesFor(self::CASH_TYPES)->sum();
    }

    /**
     * Latest balance per account of the given types (balance_after fallback to
     * current_balance), keyed by account id.
     *
     * @param  array<int, string>  $types
     * @return Collection<int, float>
     */
    private function latestBalancesFor(array $types)
    {
        $accounts = BankAccount::query()->whereIn('type', $types)->get(['id', 'current_balance']);

        $latest = Transaction::query()
            ->whereIn('bank_account_id', $accounts->pluck('id'))
            ->whereNotNull('balance_after')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->get(['bank_account_id', 'balance_after'])
            ->groupBy('bank_account_id')
            ->map(fn ($rows) => (float) $rows->first()->balance_after);

        return $accounts->mapWithKeys(fn (BankAccount $a) => [
            $a->id => $latest->get($a->id, (float) $a->current_balance),
        ]);
    }
}
