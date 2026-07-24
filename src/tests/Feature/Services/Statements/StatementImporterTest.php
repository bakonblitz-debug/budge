<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Statements\Contracts\StatementParserInterface;
use App\Services\Statements\Dto\ParsedTransaction;
use App\Services\Statements\ParserRegistry;
use App\Services\Statements\StatementImporter;

/**
 * In-memory parser that returns a fixed, configurable set of ParsedTransaction
 * rows — lets the importer tests drive exact positional/upsert scenarios
 * without depending on PDF fixture contents.
 */
function fakeParser(string $format, array $rows): StatementParserInterface
{
    return new class($format, $rows) implements StatementParserInterface
    {
        public function __construct(private string $format, private array $rows) {}

        public function getFormat(): string
        {
            return $this->format;
        }

        public function getDisplayName(): string
        {
            return 'Fake';
        }

        public function getSupportedAccountTypes(): array
        {
            return ['chequing'];
        }

        public function getAcceptedExtensions(): array
        {
            return ['pdf'];
        }

        public function parse(string $filePath): array
        {
            return $this->rows;
        }
    };
}

/** Register a fake parser on the shared singleton registry and return its format. */
function registerFakeParser(array $rows, string $format = 'fake_pdf'): string
{
    app(ParserRegistry::class)->register(fakeParser($format, $rows));

    return $format;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->account = BankAccount::factory()->chequing()->create(['user_id' => $this->user->id]);
    $this->importer = app(StatementImporter::class);
});

it('imports a bank PDF, persists transactions, and marks the batch completed', function () {
    $batch = $this->importer->import(
        filePath: fixturePath('nbc_bank_sample.pdf'),
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: 'nbc_bank_pdf',
    );

    expect($batch->status)->toBe('completed');
    expect($batch->imported_count)->toBe(36);
    expect($batch->bank_account_id)->toBe($this->account->id);
    expect(Transaction::query()->count())->toBe(36);
    // Every imported row gets a cleaned merchant name (built-in cleanup at least).
    expect(Transaction::query()->whereNull('merchant_name')->count())->toBe(0);
});

it('imports a credit card PDF onto a credit card account', function () {
    $card = BankAccount::factory()->creditCard()->create(['user_id' => $this->user->id]);

    $batch = $this->importer->import(
        filePath: fixturePath('nbc_credit_card_sample.pdf'),
        bankAccountId: $card->id,
        year: 2026,
        month: 5,
        format: 'nbc_credit_card_pdf',
    );

    expect($batch->status)->toBe('completed');
    expect($batch->imported_count)->toBe(64);
});

it('upserts the same batch for the same account+period without duplicating rows', function () {
    $first = $this->importer->import(
        filePath: fixturePath('nbc_bank_sample.pdf'),
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: 'nbc_bank_pdf',
    );

    expect($first->imported_count)->toBe(36);

    $second = $this->importer->import(
        filePath: fixturePath('nbc_bank_sample.pdf'),
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: 'nbc_bank_pdf',
    );

    // The batch is upserted (same row, not deleted/recreated) and every row is
    // matched on re-import → no duplicates, all updated.
    expect(ImportBatch::query()->count())->toBe(1);
    expect($second->id)->toBe($first->id);
    expect($second->imported_count)->toBe(0);
    expect($second->updated_count)->toBe(36);
    expect(Transaction::query()->count())->toBe(36);
});

it('upserts an existing matching transaction instead of duplicating it', function () {
    // A transaction already exists that collides (by date+desc+amount) with one
    // in the PDF, but carries a different balance_after.
    $existingDate = '2026-04-09 00:00:00';
    $existingDesc = 'MOBILE VIR.DEBIT';
    $existingAmount = -500.00;
    $hash = Transaction::generateHash($existingDate, $existingDesc, $existingAmount);

    $original = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $this->account->id,
        'transaction_date' => $existingDate,
        'description' => $existingDesc,
        'amount' => $existingAmount,
        'balance_after' => 1.00,
        'hash' => $hash,
    ]);

    $batch = $this->importer->import(
        filePath: fixturePath('nbc_bank_sample.pdf'),
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: 'nbc_bank_pdf',
    );

    // 36 rows in PDF; 1 matches our pre-existing row → 35 created, 1 updated, no dupes.
    expect($batch->imported_count)->toBe(35);
    expect($batch->updated_count)->toBe(1);
    expect(Transaction::query()->count())->toBe(36);

    // The matched row had its balance refreshed from the statement.
    expect((float) $original->fresh()->balance_after)->not->toBe(1.00);
});

it('keeps both same-day repeated rows and stays idempotent on re-import (positional)', function () {
    $rows = [
        new ParsedTransaction('2026-04-10 00:00:00', 'TIM HORTONS', -2.50, 100.00),
        new ParsedTransaction('2026-04-10 00:00:00', 'TIM HORTONS', -2.50, 97.50),
    ];
    $format = registerFakeParser($rows);

    $first = $this->importer->import(
        filePath: 'irrelevant.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: $format,
    );

    expect($first->imported_count)->toBe(2);
    expect(Transaction::query()->count())->toBe(2);

    // Re-import the identical statement → both rows match positionally, no new rows.
    $second = $this->importer->import(
        filePath: 'irrelevant.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: $format,
    );

    expect($second->imported_count)->toBe(0);
    expect($second->updated_count)->toBe(2);
    expect(Transaction::query()->count())->toBe(2);
});

it('creates surplus same-key rows when the statement has more than the DB (DB=1/stmt=2)', function () {
    // One existing row matching the key.
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $this->account->id,
        'transaction_date' => '2026-04-12 00:00:00',
        'description' => 'METRO',
        'amount' => -10.00,
        'balance_after' => 50.00,
        'hash' => Transaction::generateHash('2026-04-12 00:00:00', 'METRO', -10.00),
    ]);

    $rows = [
        new ParsedTransaction('2026-04-12 00:00:00', 'METRO', -10.00, 40.00),
        new ParsedTransaction('2026-04-12 00:00:00', 'METRO', -10.00, 30.00),
    ];
    $format = registerFakeParser($rows, 'fake_surplus_pdf');

    $batch = $this->importer->import(
        filePath: 'irrelevant.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: $format,
    );

    // 1 updated + 1 created.
    expect($batch->updated_count)->toBe(1);
    expect($batch->imported_count)->toBe(1);
    expect(Transaction::query()->count())->toBe(2);
});

it('leaves surplus existing same-key rows untouched (DB=3/stmt=2)', function () {
    foreach ([60.00, 50.00, 40.00] as $balance) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'bank_account_id' => $this->account->id,
            'transaction_date' => '2026-04-15 00:00:00',
            'description' => 'COUCHE-TARD',
            'amount' => -5.00,
            'balance_after' => $balance,
            'hash' => Transaction::generateHash('2026-04-15 00:00:00', 'COUCHE-TARD', -5.00),
        ]);
    }

    $rows = [
        new ParsedTransaction('2026-04-15 00:00:00', 'COUCHE-TARD', -5.00, 999.00),
        new ParsedTransaction('2026-04-15 00:00:00', 'COUCHE-TARD', -5.00, 888.00),
    ];
    $format = registerFakeParser($rows, 'fake_undershoot_pdf');

    $batch = $this->importer->import(
        filePath: 'irrelevant.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: $format,
    );

    expect($batch->updated_count)->toBe(2);
    expect($batch->imported_count)->toBe(0);
    expect(Transaction::query()->count())->toBe(3);

    // The 3rd existing row (last by id, balance 40.00) was never touched.
    $third = Transaction::query()->orderBy('id')->get()->last();
    expect((float) $third->balance_after)->toBe(40.00);
});

it('preserves user edits while refreshing bank-sourced fields on re-import', function () {
    $manualCategory = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Manual']);

    $rows = [new ParsedTransaction('2026-04-20 00:00:00', 'SHELL OIL', -45.00, 200.00)];
    $format = registerFakeParser($rows, 'fake_preserve_pdf');

    $this->importer->import(
        filePath: 'irrelevant.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: $format,
    );

    $txn = Transaction::query()->firstOrFail();
    $originalBatchId = $txn->import_batch_id;

    // User edits the row.
    $txn->update([
        'category_id' => $manualCategory->id,
        'notes' => 'Road trip',
        'is_excluded' => true,
    ]);

    // Re-import with a refreshed balance + a tweaked description (re-normalized merchant).
    $rows2 = [new ParsedTransaction('2026-04-20 12:00:00', 'SHELL OIL', -45.00, 175.00)];
    app(ParserRegistry::class)->register(fakeParser($format, $rows2));

    $this->importer->import(
        filePath: 'irrelevant.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: $format,
    );

    expect(Transaction::query()->count())->toBe(1);

    $fresh = $txn->fresh();
    // Preserved user edits:
    expect($fresh->category_id)->toBe($manualCategory->id);
    expect($fresh->notes)->toBe('Road trip');
    expect($fresh->is_excluded)->toBeTrue();
    expect($fresh->import_batch_id)->toBe($originalBatchId);
    // Refreshed bank-sourced field:
    expect((float) $fresh->balance_after)->toBe(175.00);
});

it('fills a previously-null category from rules on re-import', function () {
    $gas = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Gas']);
    $gas->rules()->create([
        'user_id' => $this->user->id,
        'match_type' => 'contains',
        'match_value' => 'PETRO',
        'priority' => 100,
        'is_active' => true,
    ]);

    // First import with NO matching rule → category stays null.
    $rows = [new ParsedTransaction('2026-04-22 00:00:00', 'PETRO CANADA', -60.00, 100.00)];
    $format = registerFakeParser($rows, 'fake_fillcat_pdf');

    // Build an importer that has not yet cached the rule.
    $importerNoRule = app(StatementImporter::class);

    // Add the rule AFTER the first importer cached its (empty) rules, but the
    // first import uses the rule-aware importer below to keep it simple: instead
    // null the category manually to simulate "previously uncategorized".
    $importerNoRule->import(
        filePath: 'irrelevant.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: $format,
    );

    $txn = Transaction::query()->firstOrFail();
    $txn->update(['category_id' => null]);

    // Re-import with a fresh importer that has the rule cached.
    $importerWithRule = app(StatementImporter::class);
    app(ParserRegistry::class)->register(fakeParser($format, $rows));

    $importerWithRule->import(
        filePath: 'irrelevant.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: $format,
    );

    expect($txn->fresh()->category_id)->toBe($gas->id);
});

it('upserts overlapping rows across a multi-month re-import without duplicating', function () {
    // Import March.
    $march = [
        new ParsedTransaction('2026-03-05 00:00:00', 'RENT', -1675.00, 500.00),
        new ParsedTransaction('2026-03-15 00:00:00', 'GROCERY', -80.00, 420.00),
    ];
    $format = registerFakeParser($march, 'fake_ytd_pdf');

    $this->importer->import(
        filePath: 'irrelevant.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 3,
        format: $format,
    );

    expect(Transaction::query()->count())->toBe(2);

    // Now import an April batch whose statement also re-lists March's rows (YTD).
    $ytd = [
        new ParsedTransaction('2026-03-05 00:00:00', 'RENT', -1675.00, 500.00),
        new ParsedTransaction('2026-03-15 00:00:00', 'GROCERY', -80.00, 420.00),
        new ParsedTransaction('2026-04-05 00:00:00', 'RENT', -1675.00, 800.00),
    ];
    app(ParserRegistry::class)->register(fakeParser($format, $ytd));

    $batch = $this->importer->import(
        filePath: 'irrelevant.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: $format,
    );

    // The two March rows matched, only the new April row was created.
    expect($batch->updated_count)->toBe(2);
    expect($batch->imported_count)->toBe(1);
    expect(Transaction::query()->count())->toBe(3);
});

it('auto-categorizes transactions when matching rules exist', function () {
    $housing = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Housing']);
    $housing->rules()->create([
        'user_id' => $this->user->id,
        'match_type' => 'contains',
        'match_value' => 'PRET PMT',
        'priority' => 100,
        'is_active' => true,
    ]);

    // Importer caches rules at construction — get a fresh instance
    $importer = app(StatementImporter::class);

    $importer->import(
        filePath: fixturePath('nbc_bank_sample.pdf'),
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: 'nbc_bank_pdf',
    );

    $loanPayments = Transaction::query()->where('description', 'like', '%PRET PMT%')->get();
    expect($loanPayments)->not->toBeEmpty();
    foreach ($loanPayments as $t) {
        expect($t->category_id)->toBe($housing->id);
    }
});

it('rejects an unknown format', function () {
    // Format is resolved before the file is read, so no fixture is needed here.
    expect(fn () => $this->importer->import(
        filePath: 'unused.pdf',
        bankAccountId: $this->account->id,
        year: 2026,
        month: 4,
        format: 'fake_format',
    ))->toThrow(InvalidArgumentException::class);
});
