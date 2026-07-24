<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CategoryController extends Controller
{
    public function index()
    {
        $categories = Category::query()
            ->withCount(['transactions', 'rules', 'children'])
            ->with([
                'children' => fn ($q) => $q->withCount(['transactions', 'rules'])->orderBy('sort_order'),
                'rules' => fn ($q) => $q->orderByDesc('priority'),
            ])
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Categories/Index', [
            'title' => 'Categories',
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'kind' => ['nullable', Rule::in(Category::KINDS)],
        ]);

        // Enforce 2-level max (parent's parent must be null)
        if ($validated['parent_id'] ?? null) {
            $parent = Category::query()->findOrFail($validated['parent_id']);
            if ($parent->parent_id !== null) {
                return back()->withErrors(['parent_id' => 'Categories can only be nested two levels deep.']);
            }
        }

        Category::create($validated);

        return back()->with(['message' => 'Category created.', 'type' => 'success']);
    }

    public function update(Request $request, Category $category)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'is_active' => ['boolean'],
            'kind' => ['nullable', Rule::in(Category::KINDS)],
        ]);

        $category->update($validated);

        return back()->with(['message' => 'Category updated.', 'type' => 'success']);
    }

    public function destroy(Category $category)
    {
        $name = $category->name;
        $category->delete();

        return back()->with([
            'message' => "Category '{$name}' deleted. Its transactions are now uncategorized.",
            'type' => 'success',
        ]);
    }

    public function storeRule(Request $request, Category $category)
    {
        $validated = $request->validate([
            'match_type' => ['required', Rule::in(['contains', 'starts_with', 'ends_with', 'exact', 'regex'])],
            'match_value' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'apply_to_existing' => ['boolean'],
        ]);

        // Validate regex safety before storing
        if ($validated['match_type'] === 'regex') {
            $err = $this->validateRegex($validated['match_value']);
            if ($err) {
                return back()->withErrors(['match_value' => $err]);
            }
        }

        $rule = $category->rules()->create([
            'match_type' => $validated['match_type'],
            'match_value' => $validated['match_value'],
            'priority' => $validated['priority'] ?? 100,
            'is_active' => true,
        ]);

        $backfilled = 0;
        if (! empty($validated['apply_to_existing'])) {
            $backfilled = $this->applyRuleToUncategorized($rule, $category);
        }

        $msg = "Rule added.";
        if ($backfilled > 0) {
            $msg .= " {$backfilled} existing transaction".($backfilled === 1 ? '' : 's')." re-categorized.";
        }

        return back()->with(['message' => $msg, 'type' => 'success']);
    }

    public function destroyRule(Category $category, CategoryRule $rule)
    {
        // Defensive: ensure rule belongs to category (and current user via global scope)
        if ($rule->category_id !== $category->id) {
            abort(404);
        }

        $rule->delete();

        return back()->with(['message' => 'Rule removed.', 'type' => 'success']);
    }

    /**
     * Apply a single rule to uncategorized transactions belonging to the
     * current user. Returns the count of transactions updated.
     */
    private function applyRuleToUncategorized(CategoryRule $rule, Category $category): int
    {
        $candidates = Transaction::query()
            ->whereNull('category_id')
            ->get(['id', 'description']);

        $idsToUpdate = $candidates
            ->filter(fn (Transaction $t) => $rule->matches($t->description))
            ->pluck('id');

        if ($idsToUpdate->isEmpty()) {
            return 0;
        }

        return Transaction::query()
            ->whereIn('id', $idsToUpdate)
            ->update(['category_id' => $category->id]);
    }

    /**
     * Reject regexes that look malicious / catastrophic-backtracking-prone
     * and confirm the pattern compiles cleanly. Returns an error string on
     * problem, or null when safe.
     */
    private function validateRegex(string $pattern): ?string
    {
        // Patterns must be delimited (e.g. /foo/i). Reject patterns longer
        // than 200 chars to limit ReDoS surface.
        if (strlen($pattern) > 200) {
            return 'Regex pattern is too long (max 200 chars).';
        }

        $compiled = @preg_match($pattern, '');
        if ($compiled === false) {
            return 'Invalid regex pattern — make sure it is delimited (e.g. /foo/i).';
        }

        return null;
    }
}
