<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ai\SpendingAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Narrative spending-habit analysis over the user's categorized expenses.
 * Read-only: aggregates locally, asks Claude (Sonnet) for insights, prints them.
 */
class AnalyzeSpendingCommand extends Command
{
    protected $signature = 'budget:analyze-spending
        {--user= : User id to run as (defaults to the first user)}
        {--months=3 : Number of months to analyze}';

    protected $description = 'Generate a plain-language analysis of spending habits using Claude.';

    public function handle(SpendingAnalyzer $analyzer): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            $this->error('No user found to run as. Pass --user=<id>.');

            return self::FAILURE;
        }

        Auth::login($user);

        $months = (int) $this->option('months');
        $this->info("Analyzing the last {$months} month(s) with ".config('services.anthropic.models.analyze').'…');

        $result = $analyzer->analyze($months);

        $this->newLine();
        $this->line($result['summary']);
        $this->newLine();

        if ($result['observations'] !== []) {
            $this->table(
                ['Severity', 'Title', 'Detail'],
                collect($result['observations'])->map(fn (array $o): array => [
                    strtoupper($o['severity']),
                    $o['title'],
                    $o['detail'],
                ])->all(),
            );
        }

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        return $this->option('user')
            ? User::query()->find((int) $this->option('user'))
            : User::query()->orderBy('id')->first();
    }
}
