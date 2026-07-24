<?php

use App\Services\Statements\Exceptions\StatementParseException;
use App\Services\Statements\Parsers\NbcBankStatementPdfParser;

beforeEach(function () {
    $this->parser = new NbcBankStatementPdfParser();
});

it('declares correct metadata', function () {
    expect($this->parser->getFormat())->toBe('nbc_bank_pdf');
    expect($this->parser->getSupportedAccountTypes())->toContain('chequing', 'savings', 'line_of_credit');
    expect($this->parser->getAcceptedExtensions())->toBe(['pdf']);
});

it('parses the real NBC bank statement fixture', function () {
    $rows = $this->parser->parse(fixturePath('nbc_bank_sample.pdf'));

    expect($rows)->toHaveCount(36);
});

it('tags each transaction with its source account number', function () {
    $rows = $this->parser->parse(fixturePath('nbc_bank_sample.pdf'));

    $accounts = collect($rows)->groupBy('sourceAccountNumber')->map->count();

    // chequing 00-000-01 has 32 transactions; line of credit 00-000-02 has 4
    expect($accounts->get('00-000-01'))->toBe(32);
    expect($accounts->get('00-000-02'))->toBe(4);
});

it('signs amounts correctly by deriving from balance delta', function () {
    $rows = collect($this->parser->parse(fixturePath('nbc_bank_sample.pdf')));

    // A clear expense: MOBILE VIR.DEBIT — balance went down
    $debit = $rows->first(fn ($r) => str_starts_with($r->description, 'MOBILE VIR.DEBIT'));
    expect($debit->amount)->toBeLessThan(0);

    // A clear income: REMB. IMPOT CANADA — balance went up
    $credit = $rows->first(fn ($r) => str_starts_with($r->description, 'REMB. IMPOT CANADA'));
    expect($credit->amount)->toBeGreaterThan(0);
    expect($credit->amount)->toBeWithin(1234.56);
});

it('resolves dates against the statement header', function () {
    $rows = $this->parser->parse(fixturePath('nbc_bank_sample.pdf'));

    // Statement date is 2026-05-07. April rows → 2026, May rows → 2026.
    $aprilRows = collect($rows)->filter(fn ($r) => str_starts_with($r->transactionDate, '2026-04-'));
    $mayRows = collect($rows)->filter(fn ($r) => str_starts_with($r->transactionDate, '2026-05-'));

    expect($aprilRows->count())->toBeGreaterThan(0);
    expect($mayRows->count())->toBeGreaterThan(0);
});

it('rejects non-NBC PDFs', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'fakepdf_');
    file_put_contents($tmp, "%PDF-1.4\nNot an NBC document at all\n%%EOF");

    expect(fn () => $this->parser->parse($tmp))
        ->toThrow(StatementParseException::class);

    @unlink($tmp);
});

it('refuses to parse an empty file', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'empty_');

    expect(fn () => $this->parser->parse($tmp))
        ->toThrow(StatementParseException::class, 'empty');

    @unlink($tmp);
});

it('sanitizes descriptions — no control characters in output', function () {
    $rows = $this->parser->parse(fixturePath('nbc_bank_sample.pdf'));

    foreach ($rows as $row) {
        expect(preg_match('/[\x00-\x1F\x7F]/', $row->description))
            ->toBe(0, "Description has control chars: {$row->description}");
    }
});

/**
 * Invoke the private resolveYears() pass with synthetic, year-less rows so we
 * can exercise the chronological-wrap year resolution in isolation. The row
 * shape mirrors exactly what extractTransactions() builds before resolution.
 *
 * @param  array<int, array{m:int,d:int}>  $monthDays  chronological (month, day) pairs
 * @return App\Services\Statements\Dto\ParsedTransaction[]
 */
function resolveYearsFor(NbcBankStatementPdfParser $parser, array $monthDays, string $statementDate, int $section = 1): array
{
    $rows = [];
    foreach ($monthDays as $md) {
        $rows[] = [
            'm' => $md['m'],
            'd' => $md['d'],
            'section' => $section,
            'description' => sprintf('ROW %02d-%02d', $md['m'], $md['d']),
            'amount' => -1.00,
            'balanceAfter' => 0.0,
            'account' => '00-000-01',
        ];
    }

    $method = new ReflectionMethod($parser, 'resolveYears');

    return $method->invoke($parser, $rows, new DateTimeImmutable($statementDate));
}

it('resolves a 12-month YTD span without mis-yearing statementMonth+1', function () {
    // Statement dated 2025-06-30 (statementMonth = 6), covering Jul 2024 → Jun 2025.
    // Chronological rows: Jul..Dec 2024, then Jan..Jun 2025.
    $monthDays = [];
    for ($m = 7; $m <= 12; $m++) {
        $monthDays[] = ['m' => $m, 'd' => 15];
    }
    for ($m = 1; $m <= 6; $m++) {
        $monthDays[] = ['m' => $m, 'd' => 15];
    }

    $rows = resolveYearsFor($this->parser, $monthDays, '2025-06-30');
    $dates = collect($rows)->map(fn ($r) => substr($r->transactionDate, 0, 10))->values();

    // The bug: July (statementMonth + 1) used to resolve to 2025-07 (wrong year, future).
    expect($dates->first(fn ($d) => str_starts_with($d, '2024-07')))->toBe('2024-07-15');
    expect($dates->contains('2025-07-15'))->toBeFalse();

    // August..December also belong to the prior year.
    expect($dates)->toContain('2024-08-15', '2024-12-15');
    // January..June belong to the statement year.
    expect($dates)->toContain('2025-01-15', '2025-06-15');

    // No row may be dated after the statement date.
    foreach ($dates as $d) {
        expect($d <= '2025-06-30')->toBeTrue("Row {$d} is after the statement date");
    }
});

it('resolves a span crossing the December → January boundary', function () {
    // Statement dated 2026-01-15 (statementMonth = 1), spanning Dec 2025 → Jan 2026.
    $monthDays = [
        ['m' => 12, 'd' => 20],
        ['m' => 12, 'd' => 28],
        ['m' => 1, 'd' => 3],
        ['m' => 1, 'd' => 10],
    ];

    $rows = resolveYearsFor($this->parser, $monthDays, '2026-01-15');
    $dates = collect($rows)->map(fn ($r) => substr($r->transactionDate, 0, 10))->values();

    expect($dates->all())->toBe(['2025-12-20', '2025-12-28', '2026-01-03', '2026-01-10']);

    foreach ($dates as $d) {
        expect($d <= '2026-01-15')->toBeTrue("Row {$d} is after the statement date");
    }
});

it('still resolves a single-month span anchored to the statement date', function () {
    // Statement dated 2026-05-07 — the existing fixture scenario (Apr → May 2026).
    $monthDays = [
        ['m' => 4, 'd' => 8],
        ['m' => 4, 'd' => 30],
        ['m' => 5, 'd' => 1],
        ['m' => 5, 'd' => 7],
    ];

    $rows = resolveYearsFor($this->parser, $monthDays, '2026-05-07');
    $dates = collect($rows)->map(fn ($r) => substr($r->transactionDate, 0, 10))->values();

    expect($dates->all())->toBe(['2026-04-08', '2026-04-30', '2026-05-01', '2026-05-07']);
});

it('resolves each account section independently', function () {
    // Two sections with different spans should not bleed years into each other.
    $first = resolveYearsFor($this->parser, [
        ['m' => 11, 'd' => 5],
        ['m' => 12, 'd' => 5],
        ['m' => 1, 'd' => 5],
    ], '2026-01-31', section: 1);

    $second = resolveYearsFor($this->parser, [
        ['m' => 1, 'd' => 9],
    ], '2026-01-31', section: 2);

    expect(collect($first)->map(fn ($r) => substr($r->transactionDate, 0, 10))->all())
        ->toBe(['2025-11-05', '2025-12-05', '2026-01-05']);
    expect(substr($second[0]->transactionDate, 0, 10))->toBe('2026-01-09');
});
