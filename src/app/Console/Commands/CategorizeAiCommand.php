<?php

namespace App\Console\Commands;

use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\TransactionClassifier;
use App\Services\CategoryMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AI categorization fallback. Runs Claude (Haiku) over the transaction
 * descriptions the deterministic CategoryMatcher could not classify, prints the
 * suggestions, and — with --apply — categorizes high-confidence matches and
 * saves the suggested CategoryRules so the deterministic layer learns.
 */
class CategorizeAiCommand extends Command
{
    protected $signature = 'budget:categorize-ai
        {--user= : User id to run as (defaults to the first user)}
        {--batch= : Limit to a single import batch id}
        {--limit=100 : Max distinct descriptions to classify}
        {--threshold=0.8 : Minimum confidence to auto-apply with --apply}
        {--apply : Apply high-confidence results and save suggested rules}';

    protected $description = 'Categorize transactions the deterministic rules could not match, using Claude, and learn new rules.';

    public function handle(TransactionClassifier $classifier, CategoryMatcher $matcher): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            $this->error('No user found to run as. Pass --user=<id>.');

            return self::FAILURE;
        }

        // BelongsToUser only scopes queries / sets user_id when authenticated.
        Auth::login($user);

        $threshold = (float) $this->option('threshold');

        $query = Transaction::query()->whereNull('category_id');
        if ($batch = $this->option('batch')) {
            $query->where('import_batch_id', (int) $batch);
        }

        $descriptions = $query
            ->distinct()
            ->orderBy('description')
            ->limit((int) $this->option('limit'))
            ->pluck('description')
            ->filter(fn ($d): bool => $matcher->match($d) === null) // skip anything rules already catch
            ->values()
            ->all();

        if ($descriptions === []) {
            $this->info('Nothing to classify — all transactions are categorized or already rule-matched.');

            return self::SUCCESS;
        }

        $model = config('services.anthropic.models.classify');
        $this->info('Classifying '.count($descriptions)." description(s) with {$model}…");

        $results = $classifier->classify($descriptions);

        $this->table(
            ['Description', 'Category', 'Confidence', 'Suggested rule'],
            collect($results)->map(fn (array $r): array => [
                Str::limit($r['description'], 40),
                $r['category_id'] ?? '—',
                number_format($r['confidence'], 2),
                $r['suggested_rule']
                    ? "{$r['suggested_rule']['match_type']}: {$r['suggested_rule']['match_value']}"
                    : '—',
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->comment('Dry run. Re-run with --apply to categorize and save rules.');

            return self::SUCCESS;
        }

        [$applied, $rulesCreated] = $this->apply($results, $threshold);
        $this->info("Applied {$applied} categorization(s); created {$rulesCreated} rule(s).");

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        return $this->option('user')
            ? User::query()->find((int) $this->option('user'))
            : User::query()->orderBy('id')->first();
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array{0: int, 1: int}
     */
    private function apply(array $results, float $threshold): array
    {
        $applied = 0;
        $rulesCreated = 0;

        DB::transaction(function () use ($results, $threshold, &$applied, &$rulesCreated): void {
            foreach ($results as $r) {
                if ($r['category_id'] === null || $r['confidence'] < $threshold) {
                    continue;
                }

                $applied += Transaction::query()
                    ->whereNull('category_id')
                    ->where('description', $r['description'])
                    ->update(['category_id' => $r['category_id']]);

                $rule = $r['suggested_rule'];
                if ($rule === null) {
                    continue;
                }

                $exists = CategoryRule::query()
                    ->where('category_id', $r['category_id'])
                    ->where('match_type', $rule['match_type'])
                    ->where('match_value', $rule['match_value'])
                    ->exists();

                if (! $exists) {
                    CategoryRule::create([
                        'category_id' => $r['category_id'],
                        'match_type' => $rule['match_type'],
                        'match_value' => $rule['match_value'],
                        'priority' => 50,
                        'is_active' => true,
                    ]);
                    $rulesCreated++;
                }
            }
        });

        return [$applied, $rulesCreated];
    }
}
