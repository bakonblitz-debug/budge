<?php

namespace App\Services\Ai;

use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * Finds near-duplicate transactions that slip past the import-time hash guard.
 *
 * Exact re-imports are already blocked by Transaction's UNIQUE hash
 * (epoch|description|amount|balance_after). This catches the *fuzzy* cases that
 * arise from overlapping statement uploads — a pending charge that later posts
 * with a different date/balance, a merchant string that changed slightly, an
 * off-by-a-day timestamp.
 *
 * Strategy: a cheap deterministic pass pairs transactions with the same account
 * and amount within a small date window (and a differing hash); only those
 * genuinely-ambiguous pairs are sent to Claude to adjudicate. The model never
 * scans the whole ledger.
 *
 * Privacy: only date, description, and amount are sent — no balances or
 * account numbers.
 */
class DuplicateDetector
{
    /**
     * Hard cap on the candidate date window. Real duplicates from overlapping
     * statement imports (a pending charge that later posts, an off-by-a-day
     * timestamp) land within a few days. The shortest legitimate recurring
     * cadence is weekly (7 days), so capping strictly below that guarantees a
     * recurring paycheque, bill, or subscription at the same amount can never be
     * mistaken for a duplicate — no matter how wide a window the caller requests.
     */
    private const MAX_WINDOW_DAYS = 6;

    public function __construct(private readonly AnthropicClient $client) {}

    /**
     * @return array<int, array{keep: Transaction, duplicate: Transaction, confidence: float}>
     */
    public function findDuplicates(int $dayWindow = 3, float $threshold = 0.8, ?int $bankAccountId = null): array
    {
        $pairs = $this->candidatePairs($dayWindow, $bankAccountId);
        if ($pairs === []) {
            return [];
        }

        $verdicts = $this->adjudicate($pairs);

        $confirmed = [];
        foreach ($pairs as $i => [$a, $b]) {
            $verdict = $verdicts[$i] ?? null;
            if ($verdict === null || ! $verdict['is_duplicate'] || $verdict['confidence'] < $threshold) {
                continue;
            }

            // The higher id is the more recently imported row — treat it as the duplicate.
            [$keep, $duplicate] = $a->id <= $b->id ? [$a, $b] : [$b, $a];

            $confirmed[] = [
                'keep' => $keep,
                'duplicate' => $duplicate,
                'confidence' => $verdict['confidence'],
            ];
        }

        return $confirmed;
    }

    /**
     * Deterministic candidate pairs: same bank account + amount, within
     * $dayWindow days, different hash, neither already excluded.
     *
     * @return array<int, array{0: Transaction, 1: Transaction}>
     */
    public function candidatePairs(int $dayWindow = 3, ?int $bankAccountId = null): array
    {
        // Clamp below the shortest recurring cadence so two legitimate same-amount
        // recurrences (a bi-weekly/semi-monthly pay, a monthly bill) are never paired.
        $dayWindow = min(max(1, $dayWindow), self::MAX_WINDOW_DAYS);

        $query = Transaction::query()->where('is_excluded', false);
        if ($bankAccountId) {
            $query->where('bank_account_id', $bankAccountId);
        }

        $transactions = $query->get([
            'id', 'bank_account_id', 'transaction_date', 'description', 'amount', 'hash',
        ]);

        $pairs = [];

        $transactions
            ->groupBy(fn (Transaction $t): string => $t->bank_account_id.'|'.(string) $t->amount)
            ->each(function (Collection $group) use ($dayWindow, &$pairs): void {
                if ($group->count() < 2) {
                    return;
                }

                $items = $group->values();
                $count = $items->count();

                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $a = $items[$i];
                        $b = $items[$j];

                        if ($a->hash === $b->hash) {
                            continue; // exact dup — already prevented at import
                        }

                        if (abs($a->transaction_date->diffInDays($b->transaction_date)) <= $dayWindow) {
                            $pairs[] = [$a, $b];
                        }
                    }
                }
            });

        return $pairs;
    }

    /**
     * Ask Claude to judge each candidate pair. Returns verdicts keyed by the
     * same index as the input $pairs.
     *
     * @param  array<int, array{0: Transaction, 1: Transaction}>  $pairs
     * @return array<int, array{is_duplicate: bool, confidence: float}>
     */
    private function adjudicate(array $pairs): array
    {
        $items = [];
        foreach ($pairs as $i => [$a, $b]) {
            $items[] = [
                'index' => $i,
                'a' => $this->summarize($a),
                'b' => $this->summarize($b),
            ];
        }

        $response = $this->client->messages([
            'model' => config('services.anthropic.models.classify'),
            'max_tokens' => 2048,
            'system' => [[
                'type' => 'text',
                'text' => $this->systemPrompt(),
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
            'messages' => [[
                'role' => 'user',
                'content' => "Judge these candidate duplicate pairs:\n"
                    .json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
        ]);

        $text = $this->client->firstText($response);
        $data = $text !== null ? json_decode($text, true) : null;
        $rows = is_array($data) ? ($data['verdicts'] ?? []) : [];

        $verdicts = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['index'])) {
                continue;
            }
            $verdicts[(int) $row['index']] = [
                'is_duplicate' => (bool) ($row['is_duplicate'] ?? false),
                'confidence' => (float) ($row['confidence'] ?? 0),
            ];
        }

        return $verdicts;
    }

    /**
     * @return array{date: string, description: string, amount: float}
     */
    private function summarize(Transaction $t): array
    {
        return [
            'date' => $t->transaction_date->toDateString(),
            'description' => $t->description,
            'amount' => (float) $t->amount,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You decide whether two bank/credit-card transactions are the SAME real-world transaction recorded twice (a duplicate) — the kind produced when overlapping statement periods are imported.

            Each pair has the same account and the same amount and falls within a few days of each other. Judge whether they are duplicates, considering:
            - A pending charge that later posts (same merchant, date shifts by 1–2 days, amount identical) IS a duplicate.
            - The same merchant whose description differs only in noise/formatting IS a duplicate.
            - Two genuinely separate purchases that happen to share an amount and fall close together are NOT duplicates (e.g. buying the same coffee on two different days at a recurring price).
            - A recurring charge or deposit that repeats at a regular interval (a paycheque, rent, subscription, insurance, or other bill) is NOT a duplicate even when the amount is identical — those are separate real events spaced a cadence apart (e.g. weekly, every two weeks, twice a month, monthly), not the same transaction recorded twice.

            For each pair return its index, is_duplicate (true/false), and confidence (0.0–1.0). Reserve confidence above 0.8 for clear cases.
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
            'required' => ['verdicts'],
            'properties' => [
                'verdicts' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['index', 'is_duplicate', 'confidence'],
                        'properties' => [
                            'index' => ['type' => 'integer'],
                            'is_duplicate' => ['type' => 'boolean'],
                            'confidence' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
