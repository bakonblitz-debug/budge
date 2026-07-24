<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ai\DuplicateDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * AI-assisted duplicate detection across overlapping statement imports.
 * Deterministically pairs same-account, same-amount, near-date transactions,
 * lets Claude adjudicate the ambiguous ones, and — with --apply — marks the
 * more recently imported row as excluded.
 */
class DedupAiCommand extends Command
{
    protected $signature = 'budget:dedup-ai
        {--user= : User id to run as (defaults to the first user)}
        {--account= : Limit to a single bank account id}
        {--days=3 : Date window (in days) for candidate pairs (capped at 6, below the shortest recurring cadence, so regular pays/bills are never flagged)}
        {--threshold=0.8 : Minimum confidence to treat a pair as a duplicate}
        {--apply : Mark confirmed duplicates as excluded}';

    protected $description = 'Find near-duplicate transactions from overlapping imports using Claude, and optionally exclude them.';

    public function handle(DuplicateDetector $detector): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            $this->error('No user found to run as. Pass --user=<id>.');

            return self::FAILURE;
        }

        Auth::login($user);

        $duplicates = $detector->findDuplicates(
            dayWindow: (int) $this->option('days'),
            threshold: (float) $this->option('threshold'),
            bankAccountId: $this->option('account') ? (int) $this->option('account') : null,
        );

        if ($duplicates === []) {
            $this->info('No likely duplicates found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Keep #', 'Keep', 'Duplicate #', 'Duplicate', 'Confidence'],
            collect($duplicates)->map(fn (array $d): array => [
                $d['keep']->id,
                Str::limit($d['keep']->transaction_date->toDateString().' '.$d['keep']->description, 36),
                $d['duplicate']->id,
                Str::limit($d['duplicate']->transaction_date->toDateString().' '.$d['duplicate']->description, 36),
                number_format($d['confidence'], 2),
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->comment('Dry run. Re-run with --apply to mark duplicates as excluded.');

            return self::SUCCESS;
        }

        $excluded = 0;
        foreach ($duplicates as $d) {
            $d['duplicate']->update(['is_excluded' => true]);
            $excluded++;
        }

        $this->info("Marked {$excluded} duplicate(s) as excluded.");

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        return $this->option('user')
            ? User::query()->find((int) $this->option('user'))
            : User::query()->orderBy('id')->first();
    }
}
