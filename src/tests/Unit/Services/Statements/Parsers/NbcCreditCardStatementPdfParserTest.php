<?php

use App\Services\Statements\Exceptions\StatementParseException;
use App\Services\Statements\Parsers\NbcCreditCardStatementPdfParser;

beforeEach(function () {
    $this->parser = new NbcCreditCardStatementPdfParser();
});

it('declares correct metadata', function () {
    expect($this->parser->getFormat())->toBe('nbc_credit_card_pdf');
    expect($this->parser->getSupportedAccountTypes())->toBe(['credit_card']);
    expect($this->parser->getAcceptedExtensions())->toBe(['pdf']);
});

it('parses the real NBC Mastercard fixture', function () {
    $rows = $this->parser->parse(fixturePath('nbc_credit_card_sample.pdf'));

    // Statement summary printed: PURCHASES/OTHER = 2000.00, PAYMENTS/CREDITS = 3000.00
    expect($rows)->toHaveCount(64);
});

it('signs purchases negative and credits positive', function () {
    $rows = collect($this->parser->parse(fixturePath('nbc_credit_card_sample.pdf')));

    $purchaseTotal = $rows->where('amount', '<', 0)->sum('amount');
    $creditTotal = $rows->where('amount', '>', 0)->sum('amount');

    expect($purchaseTotal)->toBeWithin(-2000.00);
    expect($creditTotal)->toBeWithin(3000.00);
});

it('captures the transaction reference', function () {
    $rows = collect($this->parser->parse(fixturePath('nbc_credit_card_sample.pdf')));

    expect($rows->first()->reference)->not->toBeEmpty();
    // Most NBC references are 10 alphanumeric chars
    expect(strlen($rows->first()->reference))->toBeGreaterThanOrEqual(6);
});

it('resolves dates from the statement period', function () {
    $rows = $this->parser->parse(fixturePath('nbc_credit_card_sample.pdf'));

    // Period was 2026-04-10 to 2026-05-10
    foreach ($rows as $row) {
        expect($row->transactionDate)->toMatch('/^2026-(04|05)-/');
    }
});

it('skips MONTANT ORIGINAL EN DEVISE continuation lines', function () {
    $rows = collect($this->parser->parse(fixturePath('nbc_credit_card_sample.pdf')));

    foreach ($rows as $row) {
        expect($row->description)->not->toContain('MONTANT ORIGINAL');
    }
});

it('rejects non-Mastercard PDFs', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'notcard_');
    file_put_contents($tmp, "%PDF-1.4\nNot a credit card document\n%%EOF");

    expect(fn () => $this->parser->parse($tmp))
        ->toThrow(StatementParseException::class);

    @unlink($tmp);
});

it('does not source-tag rows (single-account format)', function () {
    $rows = $this->parser->parse(fixturePath('nbc_credit_card_sample.pdf'));

    foreach ($rows as $row) {
        expect($row->sourceAccountNumber)->toBeNull();
    }
});
