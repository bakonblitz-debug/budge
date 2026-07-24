<?php

namespace App\Services;

use App\Models\CategoryRule;
use Illuminate\Support\Collection;

class CategoryMatcher
{
    private Collection $rules;

    public function __construct()
    {
        $this->rules = collect();
    }

    /**
     * Load active rules for the current user, ordered by priority (highest first).
     */
    public function loadRules(): self
    {
        $this->rules = CategoryRule::where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        return $this;
    }

    /**
     * Find the matching category_id for a transaction description.
     * Returns null if no rule matches.
     */
    public function match(string $description): ?int
    {
        if ($this->rules->isEmpty()) {
            $this->loadRules();
        }

        foreach ($this->rules as $rule) {
            if ($rule->matches($description)) {
                return $rule->category_id;
            }
        }

        return null;
    }

    /**
     * Match multiple descriptions at once. Returns [description => category_id|null].
     */
    public function matchBatch(array $descriptions): array
    {
        if ($this->rules->isEmpty()) {
            $this->loadRules();
        }

        $results = [];

        foreach ($descriptions as $desc) {
            $results[$desc] = $this->match($desc);
        }

        return $results;
    }
}
