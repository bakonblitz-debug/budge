<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TransferDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-link inter-account transfer pairs for a user. Deterministic: matches
 * opposite-sign, equal-magnitude transactions across accounts within a day window.
 */
class DetectTransfersCommand extends Command
{
    protected $signature = 'budget:detect-transfers
        {--user= : User id to run as (defaults to the first user)}
        {--days=3 : Max days between the two legs of a transfer}';

    protected $description = 'Find and link inter-account transfers so they are excluded from spending.';

    public function handle(TransferDetector $detector): int
    {
        $user = $this->option('user')
            ? User::query()->find((int) $this->option('user'))
            : User::query()->orderBy('id')->first();

        if (! $user) {
            $this->error('No user found to run as. Pass --user=<id>.');

            return self::FAILURE;
        }

        Auth::login($user);

        $count = $detector->detect((int) $this->option('days'));

        $this->info($count === 0
            ? 'No new transfers found.'
            : "Linked {$count} transfer".($count === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
