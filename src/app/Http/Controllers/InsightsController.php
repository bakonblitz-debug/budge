<?php

namespace App\Http\Controllers;

use App\Services\Ai\AnthropicClient;
use App\Services\BudgetRuleAnalyzer;
use App\Services\InsightsService;
use App\Services\SpendingCutAnalyzer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Insights page — metrics-rich view of how money is working and saving.
 *
 * Thin controller: composes the `insights` payload from the read-only
 * {@see InsightsService} (which reuses SpendingCutAnalyzer, BudgetRuleAnalyzer,
 * NetWorthService, CashFlowForecaster). The AI summary is an OPTIONAL deferred
 * Inertia prop — only runs on an explicit partial reload, degrades to null
 * when no ANTHROPIC_API_KEY is configured.
 */
class InsightsController extends Controller
{
    public function __construct(
        private readonly InsightsService $insightsService,
        private readonly BudgetRuleAnalyzer $budgetRule,
        private readonly SpendingCutAnalyzer $spendingCutAnalyzer,
        private readonly AnthropicClient $client,
    ) {}

    public function index(Request $request): Response
    {
        $savingHealth = $this->insightsService->savingHealth();

        $insights = [
            'trend' => $this->insightsService->monthlyTrend(),
            'windfalls' => $this->insightsService->payAndWindfalls(),
            'saving_health' => $savingHealth,
            'net_worth' => $this->insightsService->netWorth(),
            'goal_progress' => $this->insightsService->goalProgress($savingHealth['velocity']),
        ];

        return Inertia::render('Insights/Index', [
            'insights' => $insights,
            'budgetRule' => $this->budgetRule->analyze(),
            'cutting' => $this->spendingCutAnalyzer->analyze(),
            // Computed only on an explicit partial reload (router.reload({ only:
            // ['aiSummary'] })). Returns null when AI is unconfigured.
            'aiSummary' => Inertia::optional(fn (): ?string => $this->aiSummary($insights)),
        ]);
    }

    /**
     * Turn the insights into a short plain-language summary.
     * Fully optional: null when no API key, or if the call fails.
     *
     * @param  array<string, mixed>  $insights
     */
    private function aiSummary(array $insights): ?string
    {
        if ((string) config('services.anthropic.key') === '') {
            return null;
        }

        try {
            $response = $this->client->messages([
                'model' => config('services.anthropic.models.analyze'),
                'max_tokens' => 1024,
                'system' => [[
                    'type' => 'text',
                    'text' => $this->systemPrompt(),
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
                'messages' => [[
                    'role' => 'user',
                    'content' => "Here are the computed financial insights for this person. Write a brief summary:\n"
                        .json_encode($insights, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                ]],
            ]);
        } catch (Throwable) {
            return null;
        }

        $text = $this->client->firstText($response);

        return $text !== null && trim($text) !== '' ? trim($text) : null;
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are a blunt, practical personal-finance coach. You receive computed financial insights including saving health, income trends, net worth, and windfalls.

            Write 3–5 sentences of plain-language guidance. Lead with the single most actionable insight in concrete dollars. Cite the actual numbers provided; never invent figures. Be encouraging but direct. Do not output JSON, lists, or headings — just a short paragraph.
            PROMPT;
    }
}
