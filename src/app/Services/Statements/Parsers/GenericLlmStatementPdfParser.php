<?php

namespace App\Services\Statements\Parsers;

use App\Services\Ai\AnthropicClient;
use App\Services\Statements\AbstractStatementParser;
use App\Services\Statements\Dto\ParsedTransaction;
use App\Services\Statements\Exceptions\StatementParseException;
use RuntimeException;

/**
 * Bank-agnostic statement parser backed by Claude.
 *
 * Where the NBC parsers are hand-tuned to one bank's exact PDF layout, this one
 * works on ANY bank's statement (chequing, savings, credit card, line of credit)
 * without a bespoke sample: it extracts the PDF text with `pdftotext -layout`,
 * redacts account/card numbers, and asks Claude (structured output) to return the
 * transaction rows. It is the universal fallback while deterministic per-bank
 * parsers are built out for high-volume banks.
 *
 * Privacy (Loi 25 / PIPEDA): the model must read the statement body, but account
 * and card numbers are masked before the text leaves the server, and the output
 * schema never asks for them — so no account identifier is sent or returned.
 */
class GenericLlmStatementPdfParser extends AbstractStatementParser
{
    /**
     * Cap on extracted characters sent to the model. A monthly statement is
     * typically well under this; anything larger is rejected rather than silently
     * truncated (truncation would drop real transactions).
     */
    private const MAX_TEXT_LENGTH = 100_000;

    public function __construct(private readonly AnthropicClient $client) {}

    public function getFormat(): string
    {
        return 'generic_ai_pdf';
    }

    public function getDisplayName(): string
    {
        return 'Other bank — Auto-detect (PDF, AI)';
    }

    public function getSupportedAccountTypes(): array
    {
        return ['chequing', 'savings', 'credit_card', 'line_of_credit', 'other'];
    }

    public function getAcceptedExtensions(): array
    {
        return ['pdf'];
    }

    public function parse(string $filePath): array
    {
        $this->assertReadableFile($filePath);

        $text = $this->extractPdfText($filePath);

        if (mb_strlen(trim($text)) < 30) {
            throw new StatementParseException('The PDF contained no readable text to analyze.');
        }

        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new StatementParseException('This statement is too large for auto-detect. Try a single statement period, or ask for a dedicated parser for this bank.');
        }

        $redacted = $this->redactSensitiveNumbers($text);

        try {
            $response = $this->client->messages([
                'model' => config('services.anthropic.models.extract'),
                'max_tokens' => 8000,
                'system' => [[
                    'type' => 'text',
                    'text' => $this->systemPrompt(),
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
                'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
                'messages' => [[
                    'role' => 'user',
                    'content' => "Extract every transaction from this bank statement:\n\n".$redacted,
                ]],
            ]);
        } catch (RuntimeException $e) {
            // AnthropicClient throws on a missing key or a failed API call; keep
            // the user-facing message safe and generic.
            throw new StatementParseException('Could not analyze the statement with the AI parser. Please try again, or use a bank-specific format.');
        }

        return $this->mapTransactions($response);
    }

    /**
     * Mask anything that looks like an account, card, or transit number before
     * the text leaves the server: runs of 7+ digits, and grouped card numbers
     * (e.g. "4500 1234 5678 9012" or "4500-1234-5678-9012"). Amounts (<=6 digits
     * before the decimal) and dates (4-digit years) are left intact.
     */
    protected function redactSensitiveNumbers(string $text): string
    {
        // Grouped 4x4 card numbers, space- or dash-separated.
        $text = preg_replace('/\b(?:\d{4}[ -]){3}\d{4}\b/', '[redacted]', $text) ?? $text;

        // Any remaining run of 7 or more consecutive digits.
        $text = preg_replace('/\d{7,}/', '[redacted]', $text) ?? $text;

        return $text;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return ParsedTransaction[]
     *
     * @throws StatementParseException
     */
    private function mapTransactions(array $response): array
    {
        $text = $this->client->firstText($response);
        $data = $text !== null ? json_decode($text, true) : null;

        if (! is_array($data) || ! is_array($data['transactions'] ?? null)) {
            throw new StatementParseException('The AI parser returned no usable transactions.');
        }

        $rows = [];

        foreach ($data['transactions'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $date = $this->normalizeDate((string) ($row['transaction_date'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));

            if ($date === null || $description === '' || ! array_key_exists('amount', $row) || ! is_numeric($row['amount'])) {
                continue;
            }

            $balance = (isset($row['balance_after']) && is_numeric($row['balance_after']))
                ? round((float) $row['balance_after'], 2)
                : null;

            $rows[] = new ParsedTransaction(
                transactionDate: $date.' 00:00:00',
                description: $this->sanitizeDescription($description),
                amount: round((float) $row['amount'], 2),
                balanceAfter: $balance,
            );
        }

        if ($rows === []) {
            throw new StatementParseException('No transactions could be read from this statement.');
        }

        return $rows;
    }

    /** Accept a model-supplied date only if it is a real YYYY-MM-DD calendar date. */
    private function normalizeDate(string $raw): ?string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($raw), $m)) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You extract transactions from a single bank or credit-card statement. You receive the raw text of one statement (account and card numbers are already redacted). Return every transaction row, and nothing else.

            For each transaction provide:
            - transaction_date: the transaction (not posting) date, as YYYY-MM-DD. The statement header gives the period and year — use it to resolve dates that omit the year, including a December→January rollover.
            - description: the merchant or transaction description, cleaned of column artifacts. Do not include amounts, balances, or redaction markers in the description.
            - amount: a signed number. NEGATIVE for money leaving the account (purchases, withdrawals, fees, transfers out). POSITIVE for money entering it (deposits, refunds, credit-card payments, interest). On a credit-card statement, purchases are negative and payments/credits are positive.
            - balance_after: the running account balance after the row if the statement shows one; otherwise null (credit-card statements usually have no per-row balance).

            Rules: include only real transaction rows. Exclude opening/closing balance lines, subtotals, summary boxes, interest-rate notices, and rewards-point lines. Do not invent rows or amounts that are not present. If the document is not a bank or credit-card statement, return an empty transactions array.
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
            'required' => ['transactions'],
            'properties' => [
                'transactions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['transaction_date', 'description', 'amount'],
                        'properties' => [
                            'transaction_date' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'amount' => ['type' => 'number'],
                            'balance_after' => ['type' => ['number', 'null']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
