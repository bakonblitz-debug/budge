<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Models\User;
use App\Services\MerchantNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * (Re)compute transactions.merchant_name from the current alias rules + cleanup.
 * Run after editing merchant aliases to apply them to existing transactions.
 */
class NormalizeMerchantsCommand extends Command
{
    protected $signature = 'budget:normalize-merchants
        {--user= : User id to run as (defaults to the first user)}';

    protected $description = 'Recompute the clean merchant name for all transactions.';

    public function handle(MerchantNormalizer $normalizer): int
    {
        $user = $this->option('user')
            ? User::query()->find((int) $this->option('user'))
            : User::query()->orderBy('id')->first();

        if (! $user) {
            $this->error('No user found to run as. Pass --user=<id>.');

            return self::FAILURE;
        }

        Auth::login($user);
        $normalizer->loadAliases();

        $updated = 0;
        Transaction::query()
            ->orderBy('id')
            ->chunkById(500, function ($transactions) use ($normalizer, &$updated): void {
                foreach ($transactions as $txn) {
                    $name = $normalizer->normalize($txn->description);
                    if ($name !== $txn->merchant_name) {
                        $txn->update(['merchant_name' => $name]);
                        $updated++;
                    }
                }
            });

        $this->info("Updated merchant name on {$updated} transaction(s).");

        return self::SUCCESS;
    }
}
