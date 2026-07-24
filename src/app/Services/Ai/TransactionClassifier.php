<?php

namespace App\Services\Ai;

use App\Models\Category;
use App\Models\CategoryRule;

/**
 * LLM fallback for transactions the deterministic CategoryMatcher couldn't
 * classify. The stable category taxonomy + existing rules go in a prompt-cached
 * system prefix; the batch of unmatched descriptions is the volatile user turn.
 *
 * Each result carries an optional suggested CategoryRule so the deterministic
 * layer can *learn* — once persisted, the same merchant matches for free on
 * future imports and never hits the API again.
 *
 * Privacy: only transaction descriptions are sent — never amounts, balances,
 * or account numbers.
 */
class TransactionClassifier
{
    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * @param  array<int, string>  $descriptions  Transaction descriptions (deduped internally).
     * @return array<int, array{description: string, category_id: int|null, confidence: float, suggested_rule: array{match_type: string, match_value: string}|null}>
     */
    public function classify(array $descriptions): array
    {
        $descriptions = array_values(array_unique(array_filter(
            $descriptions,
            fn ($d): bool => trim((string) $d) !== '',
        )));

        if ($descriptions === []) {
            return [];
        }

        $response = $this->client->messages([
            'model' => config('services.anthropic.models.classify'),
            'max_tokens' => 4096,
            'system' => $this->systemBlocks(),
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
            'messages' => [[
                'role' => 'user',
                'content' => "Classify these transaction descriptions:\n"
                    .json_encode($descriptions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
        ]);

        return $this->parse($response);
    }

    /**
     * The stable, prompt-cached prefix: instructions + category taxonomy +
     * existing rules. Cached so repeated import batches reuse it (when the
     * prefix clears the model's minimum cacheable size — ~4096 tokens on Haiku).
     *
     * @return array<int, array<string, mixed>>
     */
    private function systemBlocks(): array
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'parent_id'])
            ->keyBy('id');

        $catLines = $categories
            ->map(function (Category $c) use ($categories): string {
                $parent = $c->parent_id ? ($categories[$c->parent_id]->name ?? null) : null;

                return $parent ? "{$c->id}: {$parent} › {$c->name}" : "{$c->id}: {$c->name}";
            })
            ->implode("\n");

        $ruleLines = CategoryRule::query()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->get(['category_id', 'match_type', 'match_value'])
            ->map(fn (CategoryRule $r): string => "category {$r->category_id} when description {$r->match_type} \"{$r->match_value}\"")
            ->implode("\n");

        $text = <<<PROMPT
            You categorize personal bank and credit-card transaction descriptions for a budgeting app.

            You are given raw transaction descriptions (merchant strings as they appear on a statement, often noisy — e.g. "AMZNMKTPCA*B7 TORONTO ON", "SQ *COFFEE BAR"). For each, choose the single best category from the list below.

            CATEGORIES (id: name):
            {$catLines}

            EXISTING DETERMINISTIC RULES (already applied before you — treat them as labeled examples of how this user categorizes):
            {$ruleLines}

            For every description return:
            - category_id: the best-matching category id from the list above, or null if you genuinely cannot tell.
            - confidence: 0.0–1.0. Be honest; reserve values above 0.8 for clear merchant matches.
            - suggested_rule: when you are confident the merchant will recur with a stable substring, propose {match_type, match_value} so the app can save a deterministic rule and match it for free next time. match_type is one of contains | starts_with | ends_with | exact. match_value is the shortest stable, distinctive substring (e.g. "AMZNMKTP", "NETFLIX"). Use null when no stable substring exists.

            Only use category ids that appear in the list above. Return exactly one entry per input description, in the same order.
            PROMPT;

        return [[
            'type' => 'text',
            'text' => $text,
            'cache_control' => ['type' => 'ephemeral'],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['classifications'],
            'properties' => [
                'classifications' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['description', 'category_id', 'confidence', 'suggested_rule'],
                        'properties' => [
                            'description' => ['type' => 'string'],
                            'category_id' => ['type' => ['integer', 'null']],
                            'confidence' => ['type' => 'number'],
                            'suggested_rule' => [
                                'type' => ['object', 'null'],
                                'additionalProperties' => false,
                                'required' => ['match_type', 'match_value'],
                                'properties' => [
                                    'match_type' => ['type' => 'string', 'enum' => ['contains', 'starts_with', 'ends_with', 'exact']],
                                    'match_value' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    private function parse(array $response): array
    {
        $text = $this->client->firstText($response);
        if ($text === null) {
            return [];
        }

        $data = json_decode($text, true);
        $rows = is_array($data) ? ($data['classifications'] ?? []) : [];

        $validIds = array_flip(
            Category::query()->where('is_active', true)->pluck('id')->all()
        );

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $categoryId = $row['category_id'] ?? null;
            if ($categoryId !== null && ! isset($validIds[$categoryId])) {
                $categoryId = null; // guard against a hallucinated id
            }

            $rule = $row['suggested_rule'] ?? null;
            if (is_array($rule) && trim((string) ($rule['match_value'] ?? '')) !== '') {
                $rule = [
                    'match_type' => in_array($rule['match_type'] ?? null, ['contains', 'starts_with', 'ends_with', 'exact'], true)
                        ? $rule['match_type']
                        : 'contains',
                    'match_value' => (string) $rule['match_value'],
                ];
            } else {
                $rule = null;
            }

            $out[] = [
                'description' => (string) ($row['description'] ?? ''),
                'category_id' => $categoryId !== null ? (int) $categoryId : null,
                'confidence' => (float) ($row['confidence'] ?? 0),
                'suggested_rule' => $rule,
            ];
        }

        return $out;
    }
}
