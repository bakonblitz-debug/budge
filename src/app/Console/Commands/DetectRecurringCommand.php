<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RecurringDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Rebuild a user's recurring-spend series from transaction history.
 * Deterministic: regular cadence + stable amount, no AI.
 */
class DetectRecurringCommand extends Command
{
    protected $signature = 'budget:detect-recurring
        {--user= : User id to run as (defaults to the first user)}';

    protected $description = 'Detect recurring subscriptions/bills from transactions.';

    public function handle(RecurringDetector $detector): int
    {
        $user = $this->option('user')
            ? User::query()->find((int) $this->option('user'))
            : User::query()->orderBy('id')->first();

        if (! $user) {
            $this->error('No user found to run as. Pass --user=<id>.');

            return self::FAILURE;
        }

        Auth::login($user);

        $count = $detector->detect();

        $this->info("Detected {$count} active recurring series.");

        return self::SUCCESS;
    }
}
