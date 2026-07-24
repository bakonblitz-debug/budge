<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NetWorthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Records a net-worth snapshot for every user.
 *
 * Idempotent per day — NetWorthService::snapshot() uses updateOrCreate on as_of,
 * so running this command multiple times on the same day is safe.
 *
 * Mirrors the login/logout pattern used by DemoSeeder so that BelongsToUser-scoped
 * queries resolve correctly for each user.
 */
class SnapshotNetWorthCommand extends Command
{
    protected $signature = 'networth:snapshot';

    protected $description = 'Record a point-in-time net-worth snapshot for every user (daily, idempotent).';

    public function handle(): int
    {
        User::query()->each(function (User $user): void {
            try {
                Auth::login($user);
                app(NetWorthService::class)->snapshot();
            } finally {
                Auth::logout();
            }
        });

        $this->info('Net-worth snapshots recorded for all users.');

        return self::SUCCESS;
    }
}
