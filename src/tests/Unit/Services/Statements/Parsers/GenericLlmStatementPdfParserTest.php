<?php

use App\Services\Ai\AnthropicClient;
use App\Services\Statements\Exceptions\StatementParseException;
use App\Services\Statements\Parsers\GenericLlmStatementPdfParser;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.anthropic.key', 'test-key');
    config()->set('services.anthropic.models.extract', 'claude-sonnet-4-6');

    $this->parser = new GenericLlmStatementPdfParser(app(AnthropicClient::class));
});

/** Build a faked Anthropic structured-output response wrapping the given rows. */
function fakeExtraction(array $transactions): array
{
    return [
        'content' => [['type' => 'text', 'text' => json_encode(['transactions' => $transactions])]],
    ];
}

it('declares correct metadata', function () {
    expect($this->parser->getFormat())->toBe('generic_ai_pdf');
    expect($this->parser->getAcceptedExtensions())->toBe(['pdf']);
    // Universal fallback: usable for every account type.
    expect($this->parser->getSupportedAccountTypes())
        ->toContain('chequing', 'credit_card', 'line_of_credit', 'savings', 'other');
});

it('maps the model output into ParsedTransaction objects', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(fakeExtraction([
        ['transaction_date' => '2026-04-15', 'description' => 'METRO PLUS', 'amount' => -42.50, 'balance_after' => 1200.00],
        ['transaction_date' => '2026-04-20', 'description' => 'PAYROLL DEPOSIT', 'amount' => 2200.00, 'balance_after' => 3400.00],
    ]), 200)]);

    $rows = $this->parser->parse(fixturePath('nbc_bank_sample.pdf'));

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->description)->toBe('METRO PLUS')
        ->and($rows[0]->amount)->toBe(-42.50)
        ->and($rows[0]->transactionDate)->toBe('2026-04-15 00:00:00')
        ->and($rows[0]->balanceAfter)->toBe(1200.00)
        ->and($rows[1]->amount)->toBe(2200.00);
});

it('drops rows with an invalid date or non-numeric amount', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(fakeExtraction([
        ['transaction_date' => '2026-13-99', 'description' => 'BAD DATE', 'amount' => -10.00],
        ['transaction_date' => '2026-04-15', 'description' => 'NO AMOUNT', 'amount' => 'oops'],
        ['transaction_date' => '2026-04-16', 'description' => 'GOOD', 'amount' => -5.00],
    ]), 200)]);

    $rows = $this->parser->parse(fixturePath('nbc_bank_sample.pdf'));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->description)->toBe('GOOD');
});

it('redacts account and card numbers before sending to the model', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(fakeExtraction([
        ['transaction_date' => '2026-04-15', 'description' => 'X', 'amount' => -1.00],
    ]), 200)]);

    $this->parser->parse(fixturePath('nbc_bank_sample.pdf'));

    Http::assertSent(function ($request) {
        $sent = $request['messages'][0]['content'];

        // Privacy guarantee: no run of 7+ digits (account/transit/card numbers)
        // survives redaction in the text that leaves the server.
        return preg_match('/\d{7,}/', $sent) === 0;
    });
});

it('masks grouped card numbers and long digit runs', function () {
    $expose = new class(app(AnthropicClient::class)) extends GenericLlmStatementPdfParser
    {
        public function redact(string $text): string
        {
            return $this->redactSensitiveNumbers($text);
        }
    };

    $redacted = $expose->redact('Card 4500 1234 5678 9012 acct 0001234567 paid 1,234.56 on 2026-04-15');

    expect($redacted)->not->toContain('4500 1234 5678 9012')
        ->and($redacted)->not->toContain('0001234567')
        // amounts and dates are preserved
        ->and($redacted)->toContain('1,234.56')
        ->and($redacted)->toContain('2026-04-15');
});

it('raises a parse exception when the API call fails', function () {
    Http::fake(['api.anthropic.com/*' => Http::response('upstream error', 500)]);

    $path = fixturePath('nbc_bank_sample.pdf'); // skips if the fixture is absent
    expect(fn () => $this->parser->parse($path))
        ->toThrow(StatementParseException::class);
});

it('raises a parse exception when no transactions come back', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(fakeExtraction([]), 200)]);

    $path = fixturePath('nbc_bank_sample.pdf'); // skips if the fixture is absent
    expect(fn () => $this->parser->parse($path))
        ->toThrow(StatementParseException::class);
});
