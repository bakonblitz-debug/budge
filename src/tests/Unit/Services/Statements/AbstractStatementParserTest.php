<?php

use App\Services\Statements\AbstractStatementParser;
use App\Services\Statements\Dto\ParsedTransaction;

// Anonymous subclass exposes protected helpers for testing.
function makeParser(): AbstractStatementParser
{
    return new class extends AbstractStatementParser {
        public function getFormat(): string { return 'test'; }
        public function getDisplayName(): string { return 'Test'; }
        public function getSupportedAccountTypes(): array { return ['chequing']; }
        public function getAcceptedExtensions(): array { return ['pdf']; }
        public function parse(string $filePath): array { return []; }

        public function expose_parseNumber($v) { return $this->parseNumber($v); }
        public function expose_parseFrenchMonth($v) { return $this->parseFrenchMonth($v); }
        public function expose_sanitize($v) { return $this->sanitizeDescription($v); }
    };
}

dataset('numbers', [
    ['1234.56', 1234.56],
    ['1,234.56', 1234.56],   // English with thousands sep
    ['1 234,56', 1234.56],   // French (regular space)
    ["1\xC2\xA0234,56", 1234.56], // French with non-breaking space
    ['$1,529.70-', -1529.70], // trailing minus
    ['-42.00', -42.00],
    ['500,00', 500.00],      // French decimal
    ['42', 42.0],
    ['', null],
    [null, null],
    ['not a number', null],
]);

it('parses numbers in French and English formats', function ($input, $expected) {
    $p = makeParser();
    $result = $p->expose_parseNumber($input);

    if ($expected === null) {
        expect($result)->toBeNull();
    } else {
        expect($result)->toBe((float) $expected);
    }
})->with('numbers');

dataset('frenchMonths', [
    ['JAN', 1], ['FEV', 2], ['MAR', 3], ['AVR', 4], ['MAI', 5],
    ['JUN', 6],  // NBC abbreviates juin (June) as JUN
    ['JUI', 7],  // NBC abbreviates juillet (July) as JUI
    ['JUL', 7],  // alias: NBC never prints JUL, but keep it mapped to July
    ['AOU', 8], ['SEP', 9], ['OCT', 10], ['NOV', 11], ['DEC', 12],
    ['avr', 4], ['Mai', 5], ['FÉV', 2], ['DÉC', 12],
    ['XXX', null], ['', null],
]);

it('parses French month abbreviations', function ($input, $expected) {
    expect(makeParser()->expose_parseFrenchMonth($input))->toBe($expected);
})->with('frenchMonths');

it('strips control characters from descriptions', function () {
    $dirty = "MERCHANT\x00 NAME\x07\nXX";
    expect(makeParser()->expose_sanitize($dirty))->toBe('MERCHANT NAME XX');
});

it('collapses whitespace', function () {
    expect(makeParser()->expose_sanitize("   A    B   \t   C  "))->toBe('A B C');
});

it('truncates descriptions to 255 chars', function () {
    $long = str_repeat('A', 300);
    expect(strlen(makeParser()->expose_sanitize($long)))->toBe(255);
});

it('ParsedTransaction toArray maps fields', function () {
    $t = new ParsedTransaction('2026-05-01 00:00:00', 'X', -10.5, 100.0, '00-000-01', 'REF1');
    expect($t->toArray())->toBe([
        'transaction_date' => '2026-05-01 00:00:00',
        'description' => 'X',
        'amount' => -10.5,
        'balance_after' => 100.0,
        'source_account_number' => '00-000-01',
        'reference' => 'REF1',
    ]);
});
