<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\ImportBatch;
use App\Models\Transaction;
use App\Services\Ai\TransactionClassifier;
use App\Services\CategoryMatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * The "training" UI for category management.
 *
 *  - Unmatched view: transactions with no category, grouped by description
 *    so a single row represents N occurrences. Picking a category for one
 *    row can categorize all N + optionally create a CategoryRule that
 *    catches future imports.
 *
 *  - Matched view: transactions that DO have a category, also grouped by
 *    description, with the rule (if any) that matched. Lets the user spot
 *    miscategorizations and reassign in bulk.
 */
class CategorizationController extends Controller
{
    public function index(Request $request)
    {
        $view = $request->input('view', 'unmatched');
        $batchId = $request->integer('batch') ?: null;

        // When scoped to a batch, surface its context for the banner
        $batchContext = null;
        if ($batchId) {
            $batch = ImportBatch::query()
                ->with('bankAccount:id,name')
                ->find($batchId);
            if ($batch) {
                $batchContext = [
                    'id' => $batch->id,
                    'account_name' => $batch->bankAccount?->name,
                    'period_label' => $batch->period_label,
                    'imported_count' => $batch->imported_count,
                ];
            }
        }

        return Inertia::render('Categorize/Index', [
            'title' => 'Categorize',
            'view' => $view,
            'batchContext' => $batchContext,
            // Computed only when the client explicitly requests it via a
            // partial reload (router.reload({ only: ['suggestions'] })) — the
            // Claude call never runs on a normal page load.
            'suggestions' => Inertia::optional(fn () => $this->aiSuggestions($batchId)),
            'unmatchedGroups' => $this->groupedTransactions(matched: false, batchId: $batchId),
            'matchedGroups' => $this->groupedTransactions(matched: true, batchId: $batchId),
            'categories' => Category::query()
                ->with('parent:id,name')
                ->select('id', 'name', 'parent_id', 'icon', 'color')
                ->orderByUsage()
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'parent_name' => $c->parent?->name,
                    'icon' => $c->icon,
                    'color' => $c->color,
                ]),
            'counts' => [
                'unmatched' => $this->countScoped(matched: false, batchId: $batchId),
                'matched' => $this->countScoped(matched: true, batchId: $batchId),
            ],
        ]);
    }

    private function countScoped(bool $matched, ?int $batchId): int
    {
        $q = Transaction::query();
        if ($batchId) {
            $q->where('import_batch_id', $batchId);
        }
        if ($matched) {
            $q->whereNotNull('category_id');
        } else {
            $q->whereNull('category_id');
        }

        return $q->count();
    }

    /**
     * Apply a category to all transactions sharing a description, and
     * optionally persist a CategoryRule + retroactively categorize existing
     * matches that may differ from the exact description (e.g. "AMZN..." vs
     * "AMAZON...").
     */
    public function apply(Request $request)
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'save_rule' => ['boolean'],
            'match_type' => ['nullable', Rule::in(['contains', 'starts_with', 'ends_with', 'exact', 'regex'])],
            'match_value' => ['nullable', 'string', 'max:255'],
            'apply_to_existing' => ['boolean'],
        ]);

        $assignedToGroup = 0;
        $assignedByRule = 0;

        DB::transaction(function () use ($validated, &$assignedToGroup, &$assignedByRule) {
            // Always categorize the exact-description group the user clicked on
            $assignedToGroup = Transaction::query()
                ->where('description', $validated['description'])
                ->update(['category_id' => $validated['category_id']]);

            // Save a rule if requested
            if (! empty($validated['save_rule']) && ! empty($validated['match_value'])) {
                CategoryRule::create([
                    'category_id' => $validated['category_id'],
                    'match_type' => $validated['match_type'] ?? 'contains',
                    'match_value' => $validated['match_value'],
                    'priority' => 100,
                    'is_active' => true,
                ]);

                if (! empty($validated['apply_to_existing'])) {
                    $assignedByRule = $this->applyRuleToUncategorized(
                        $validated['category_id'],
                        $validated['match_type'] ?? 'contains',
                        $validated['match_value'],
                    );
                }
            }
        });

        $msg = "Categorized {$assignedToGroup} transaction".($assignedToGroup === 1 ? '' : 's').".";
        if ($assignedByRule > 0) {
            $msg .= " Rule applied to {$assignedByRule} more.";
        }

        return back()->with(['message' => $msg, 'type' => 'success']);
    }

    /**
     * Bulk-set a group of transactions (by description) to uncategorized.
     * Useful for fixing miscategorizations from the Matched view.
     */
    public function unset(Request $request)
    {
        $validated = $request->validate([
            'description' => ['required', 'string', 'max:255'],
        ]);

        $count = Transaction::query()
            ->where('description', $validated['description'])
            ->update(['category_id' => null]);

        return back()->with([
            'message' => "Uncategorized {$count} transaction".($count === 1 ? '' : 's').'.',
            'type' => 'success',
        ]);
    }

    /**
     * Ask Claude to suggest categories for the descriptions the deterministic
     * rules couldn't match. Returns lightweight suggestions for the UI to show;
     * nothing is applied here — the user reviews and accepts each one, which
     * flows through the existing apply() path.
     *
     * @return array{error: string|null, items: array<int, array<string, mixed>>}
     */
    private function aiSuggestions(?int $batchId): array
    {
        $matcher = app(CategoryMatcher::class);
        $classifier = app(TransactionClassifier::class);

        $query = Transaction::query()->whereNull('category_id');
        if ($batchId) {
            $query->where('import_batch_id', $batchId);
        }

        $descriptions = $query
            ->distinct()
            ->orderBy('description')
            ->limit(100)
            ->pluck('description')
            ->filter(fn ($d): bool => $matcher->match($d) === null)
            ->values()
            ->all();

        if ($descriptions === []) {
            return ['error' => null, 'items' => []];
        }

        try {
            $results = $classifier->classify($descriptions);
        } catch (\Throwable $e) {
            return ['error' => 'AI suggestions are unavailable: '.$e->getMessage(), 'items' => []];
        }

        $names = Category::query()
            ->whereIn('id', collect($results)->pluck('category_id')->filter()->unique())
            ->with('parent:id,name')
            ->get()
            ->keyBy('id');

        $items = collect($results)
            ->filter(fn (array $r): bool => $r['category_id'] !== null)
            ->map(function (array $r) use ($names): array {
                $category = $names->get($r['category_id']);

                return [
                    'description' => $r['description'],
                    'category_id' => $r['category_id'],
                    'category_name' => $category
                        ? ($category->parent ? $category->parent->name.' · '.$category->name : $category->name)
                        : null,
                    'confidence' => $r['confidence'],
                    'match_type' => $r['suggested_rule']['match_type'] ?? null,
                    'match_value' => $r['suggested_rule']['match_value'] ?? null,
                ];
            })
            ->values()
            ->all();

        return ['error' => null, 'items' => $items];
    }

    /**
     * Group transactions by description and return summary rows:
     * [{ description, count, total_amount, first_date, last_date,
     *    category, rule_match_value }]
     *
     * When $batchId is provided, only transactions from that batch are
     * considered — used by the post-import review flow to focus the
     * user on just-arrived new merchants.
     */
    private function groupedTransactions(bool $matched, ?int $batchId = null): array
    {
        $query = Transaction::query()
            ->select('description', 'category_id')
            ->selectRaw('COUNT(*) as occurrences')
            ->selectRaw('SUM(amount) as total_amount')
            ->selectRaw('MIN(transaction_date) as first_date')
            ->selectRaw('MAX(transaction_date) as last_date')
            ->groupBy('description', 'category_id')
            ->orderByDesc('occurrences')
            ->orderByDesc(DB::raw('ABS(SUM(amount))'));

        if ($batchId) {
            $query->where('import_batch_id', $batchId);
        }

        if ($matched) {
            $query->whereNotNull('category_id');
        } else {
            $query->whereNull('category_id');
        }

        $rows = $query->get();

        $categoryIds = $rows->pluck('category_id')->filter()->unique();
        $categories = Category::query()
            ->with('parent:id,name')
            ->whereIn('id', $categoryIds)
            ->get()
            ->keyBy('id');

        // Look up which rule (if any) matches each description so the
        // Matched view can show provenance.
        $rules = $matched
            ? CategoryRule::query()->orderByDesc('priority')->get()
            : collect();

        return $rows->map(function ($row) use ($categories, $rules) {
            $category = $row->category_id ? $categories->get($row->category_id) : null;

            $matchedRule = null;
            foreach ($rules as $rule) {
                if ($rule->category_id === $row->category_id && $rule->matches($row->description)) {
                    $matchedRule = [
                        'id' => $rule->id,
                        'match_type' => $rule->match_type,
                        'match_value' => $rule->match_value,
                    ];
                    break;
                }
            }

            return [
                'description' => $row->description,
                'occurrences' => (int) $row->occurrences,
                'total_amount' => (float) $row->total_amount,
                'first_date' => $row->first_date,
                'last_date' => $row->last_date,
                'category' => $category ? [
                    'id' => $category->id,
                    'name' => $category->name,
                    'parent_name' => $category->parent?->name,
                    'icon' => $category->icon,
                    'color' => $category->color,
                ] : null,
                'matched_rule' => $matchedRule,
                'suggested_pattern' => $this->suggestPattern($row->description),
            ];
        })->values()->all();
    }

    /**
     * Apply a single rule definition to uncategorized transactions
     * (without persisting the rule — caller persists separately).
     */
    private function applyRuleToUncategorized(int $categoryId, string $matchType, string $matchValue): int
    {
        $ephemeral = new CategoryRule([
            'match_type' => $matchType,
            'match_value' => $matchValue,
        ]);

        $candidates = Transaction::query()
            ->whereNull('category_id')
            ->get(['id', 'description']);

        $ids = $candidates
            ->filter(fn ($t) => $ephemeral->matches($t->description))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return Transaction::query()
            ->whereIn('id', $ids)
            ->update(['category_id' => $categoryId]);
    }

    /**
     * Guess a useful "contains" pattern from a noisy description.
     *
     * Strategy: take the longest run of letters at the start, ignoring
     * trailing numbers/punctuation. For "AMZNMKTPCA*B78OK7CV0 TORONTO ON"
     * returns "AMZNMKTPCA". For "MOBILE PAIEM/FAC" returns "MOBILE".
     */
    private function suggestPattern(string $description): string
    {
        $upper = mb_strtoupper(trim($description));

        if (preg_match('/^([A-Z][A-Z&\']{2,})/u', $upper, $m)) {
            return $m[1];
        }

        $firstWord = strtok($upper, " \t");

        return $firstWord ?: $upper;
    }
}
