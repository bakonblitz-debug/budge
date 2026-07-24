<?php

namespace App\Services\Ai;

use App\Models\Transaction;
use App\Services\ReportingPeriod;
use Carbon\CarbonImmutable;

/**
 * Narrative spending-habit analysis. Aggregates the user's categorized expenses
 * (monthly totals, per-category totals, month-over-month, top merchants) and
 * hands the rollup — never raw transactions — to Claude (Sonnet) for a
 * plain-language read on trends, creeping subscriptions, and category blowouts.
 *
 * Read-only. Aggregation runs in PHP (not raw SQL) so it works identically on
 * SQLite and MySQL. Privacy: only category names, merchant descriptions, and
 * rounded totals are sent — no balances or account numbers.
 */
class SpendingAnalyzer
{
    public function __construct(
        private readonly AnthropicClient $client,
        private readonly ReportingPeriod $reportingPeriod,
    ) {}

    /**
     * @return array{summary: string, observations: array<int, array{title: string, detail: string, severity: string}>}
     */
    public function analyze(int $months = 3): array
    {
        $aggregates = $this->aggregate($months);

        if ($aggregates['category_totals'] === []) {
            return ['summary' => 'No categorized spending in the selected window.', 'observations' => []];
        }

        $response = $this->client->messages([
            'model' => config('services.anthropic.models.analyze'),
            'max_tokens' => 2048,
            'system' => [[
                'type' => 'text',
                'text' => $this->systemPrompt(),
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
            'messages' => [[
                'role' => 'user',
                'content' => "Analyze this spending rollup:\n"
                    .json_encode($aggregates, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
        ]);

        return $this->parse($response);
    }

    /**
     * @param  CarbonImmutable|null  $anchor  Reference "today" for the lookback
     *                                        window. When null, defaults to
     *                                        {@see ReportingPeriod::anchor()}
     *                                        (the latest imported transaction
     *                                        date, not the live calendar
     *                                        month — statements lag).
     * @return array{window_months: int, monthly_totals: array<string, float>, category_totals: array<string, float>, category_by_month: array<string, array<string, float>>, top_merchants: array<int, array{description: string, total: float, count: int}>}
     */
    public function aggregate(int $months, ?CarbonImmutable $anchor = null): array
    {
        $reference = $anchor ?? $this->reportingPeriod->anchor();
        // subMonthsNoOverflow: a plain subMonths() from a day-29-31 anchor can
        // roll into the wrong destination month, silently shrinking the window.
        $since = $reference->subMonthsNoOverflow($months)->startOfMonth();

        $transactions = Transaction::query()
            ->expense()
            ->where('transaction_date', '>=', $since)
            ->with('category:id,name')
            ->get(['id', 'category_id', 'transaction_date', 'amount', 'description']);

        $monthlyTotals = [];
        $categoryTotals = [];
        $categoryByMonth = [];
        $merchants = [];

        foreach ($transactions as $t) {
            $ym = $t->transaction_date->format('Y-m');
            $category = $t->category?->name ?? 'Uncategorized';
            $spend = abs((float) $t->amount);

            $monthlyTotals[$ym] = ($monthlyTotals[$ym] ?? 0) + $spend;
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $spend;
            $categoryByMonth[$ym][$category] = ($categoryByMonth[$ym][$category] ?? 0) + $spend;

            $key = $t->description;
            $merchants[$key]['total'] = ($merchants[$key]['total'] ?? 0) + $spend;
            $merchants[$key]['count'] = ($merchants[$key]['count'] ?? 0) + 1;
        }

        arsort($categoryTotals);
        ksort($monthlyTotals);
        ksort($categoryByMonth);

        $topMerchants = collect($merchants)
            ->map(fn (array $m, string $desc): array => [
                'description' => $desc,
                'total' => round($m['total'], 2),
                'count' => $m['count'],
            ])
            ->sortByDesc('total')
            ->take(15)
            ->values()
            ->all();

        return [
            'window_months' => $months,
            'monthly_totals' => array_map(fn ($v): float => round($v, 2), $monthlyTotals),
            'category_totals' => array_map(fn ($v): float => round($v, 2), $categoryTotals),
            'category_by_month' => array_map(
                fn (array $cats): array => array_map(fn ($v): float => round($v, 2), $cats),
                $categoryByMonth,
            ),
            'top_merchants' => $topMerchants,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are a personal-finance analyst. You receive an aggregated rollup of one person's recent spending: monthly totals, per-category totals, and a per-month-per-category breakdown. All amounts are positive dollar figures representing money spent.

            The rollup also includes a `top_merchants` list. Treat this as a PRIVATE SIGNAL ONLY — use it to reason about what's driving a category (e.g. to recognise recurring/subscription-like charges, or to judge whether a "Transfers" total is internal money movement rather than true spending). NEVER name a merchant in your output and NEVER frame an observation around a merchant. Every finding must be expressed in terms of spending CATEGORIES.

            Produce:
            - summary: 2–4 sentences of plain-language insight into their spending habits over the window — the overall trend and the one or two categories most worth their attention.
            - observations: specific, actionable findings, each framed around a category. Each has a title, a detail (1–2 sentences with the concrete numbers that justify it), and a severity of low, medium, or high. Look especially for: categories trending up month over month, categories that look like recurring/subscription commitments, unusually large single categories, and month-over-month spikes. If the merchant signal suggests a category is dominated by internal transfers rather than real spending, say so — in category terms.

            Be concrete and cite the numbers. Do not invent data that isn't in the rollup. Do not give generic budgeting advice.
            PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['summary', 'observations'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'observations' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'detail', 'severity'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{summary: string, observations: array<int, array{title: string, detail: string, severity: string}>}
     */
    private function parse(array $response): array
    {
        $text = $this->client->firstText($response);
        $data = $text !== null ? json_decode($text, true) : null;

        if (! is_array($data)) {
            return ['summary' => '', 'observations' => []];
        }

        $observations = [];
        foreach ($data['observations'] ?? [] as $o) {
            if (! is_array($o)) {
                continue;
            }
            $observations[] = [
                'title' => (string) ($o['title'] ?? ''),
                'detail' => (string) ($o['detail'] ?? ''),
                'severity' => in_array($o['severity'] ?? null, ['low', 'medium', 'high'], true) ? $o['severity'] : 'low',
            ];
        }

        return [
            'summary' => (string) ($data['summary'] ?? ''),
            'observations' => $observations,
        ];
    }
}
