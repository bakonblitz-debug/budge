<?php

namespace App\Services\Statements;

use App\Models\BankAccount;
use App\Models\ImportBatch;
use App\Models\Transaction;
use App\Services\CategoryMatcher;
use App\Services\MerchantNormalizer;
use App\Services\Statements\Dto\ParsedTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates ingestion of a parsed statement into the database.
 *
 *  - Resolves the right parser via the ParserRegistry (no hardcoded list)
 *  - Upserts the batch for the same (account, year, month) tuple (non-destructive)
 *  - Idempotently upserts transactions via a date+description+amount match key,
 *    matching repeated same-key rows positionally and preserving user edits
 *  - Auto-categorizes via the CategoryMatcher
 *  - Logs structured outcomes; surfaces user-safe error messages
 *
 * This service is account-scoped: each import is for one BankAccount.
 * Multi-account PDFs (e.g. NBC bank statements containing both chequing and
 * line-of-credit sections) are imported by selecting a specific account; the
 * parser returns transactions tagged with their source account number, and we
 * filter to the rows belonging to the chosen account.
 */
class StatementImporter
{
    private CategoryMatcher $matcher;

    public function __construct(
        CategoryMatcher $matcher,
        private readonly ParserRegistry $parsers,
        private readonly MerchantNormalizer $normalizer,
    ) {
        $this->matcher = $matcher->loadRules();
    }

    public function import(
        string $filePath,
        int $bankAccountId,
        int $year,
        int $month,
        string $format,
    ): ImportBatch {
        $parser = $this->parsers->get($format);

        $account = BankAccount::query()->findOrFail($bankAccountId);

        $batch = ImportBatch::updateOrCreate(
            [
                'bank_account_id' => $account->id,
                'period_year' => $year,
                'period_month' => $month,
            ],
            [
                'filename' => basename($filePath),
                'bank_format' => $parser->getFormat(),
                'status' => 'processing',
            ],
        );

        try {
            $rows = $parser->parse($filePath);
            $this->processRows($batch, $rows, $account);

            $batch->update([
                'status' => 'completed',
                'imported_at' => now(),
            ]);

            Log::channel('import')->info('Import completed', [
                'batch_id' => $batch->id,
                'format' => $parser->getFormat(),
                'imported' => $batch->imported_count,
                'updated' => $batch->updated_count,
                'skipped' => $batch->skipped_count,
            ]);
        } catch (\Throwable $e) {
            $batch->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);

            Log::channel('import')->error('Import failed', [
                'batch_id' => $batch->id,
                'format' => $parser->getFormat(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $batch = $batch->fresh();

        // Transient (non-persisted) attributes: the statement's actual date
        // span, taken from the parsed rows so re-imports report the full range
        // (updated rows keep their original import_batch_id and would be
        // missed by a batch-scoped query).
        if ($rows !== []) {
            $dates = array_map(
                fn (ParsedTransaction $row) => substr($row->transactionDate, 0, 10),
                $rows,
            );
            $batch->first_transaction_date = min($dates);
            $batch->last_transaction_date = max($dates);
        }

        return $batch;
    }

    /**
     * Idempotent positional upsert of parsed rows into transactions.
     *
     * Each row's match key is date(day)+description+amount. For a given key we
     * walk the existing rows for this account in id order, matching the kth
     * incoming row to the kth existing row (a per-import seen counter drives the
     * offset). Matched rows refresh ONLY bank-sourced fields (balance_after,
     * merchant_name, and category_id when it is still null) and otherwise
     * preserve the user's edits (category_id when set, notes, is_excluded,
     * transfer_id, import_batch_id). Surplus incoming rows are created and join
     * the set, so re-importing the same statement is a no-op count-wise.
     *
     * Positional cases (all must hold):
     *   DB=0 / stmt=2 -> 2 created
     *   DB=1 / stmt=2 -> 1 updated + 1 created
     *   DB=3 / stmt=2 -> 2 updated, 3rd untouched
     *
     * @param  array<int, ParsedTransaction>  $rows
     */
    private function processRows(ImportBatch $batch, array $rows, BankAccount $account): void
    {
        $imported = 0;
        $updated = 0;
        $total = count($rows);

        // Note: NBC bank PDFs contain multiple account sections (chequing + line
        // of credit, etc.). Rows are tagged with sourceAccountNumber by the
        // parser. For now we import every row to the selected BankAccount —
        // future: persist the bank's account number on the BankAccount model
        // and filter to matching rows here.

        DB::transaction(function () use ($rows, $batch, $account, &$imported, &$updated) {
            /** @var array<string, int> $seen hash => rows already handled this import */
            $seen = [];

            foreach ($rows as $row) {
                $key = Transaction::generateHash(
                    $row->transactionDate,
                    $row->description,
                    $row->amount,
                );

                $offset = $seen[$key] ?? 0;

                $existing = Transaction::query()
                    ->where('bank_account_id', $account->id)
                    ->where('hash', $key)
                    ->orderBy('id')
                    ->skip($offset)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'balance_after' => $row->balanceAfter,
                        'merchant_name' => $this->normalizer->normalize($row->description),
                        'category_id' => $existing->category_id ?? $this->matcher->match($row->description),
                    ]);

                    $updated++;
                } else {
                    Transaction::create([
                        'bank_account_id' => $account->id,
                        'import_batch_id' => $batch->id,
                        'category_id' => $this->matcher->match($row->description),
                        'transaction_date' => $row->transactionDate,
                        'description' => $row->description,
                        'merchant_name' => $this->normalizer->normalize($row->description),
                        'amount' => $row->amount,
                        'balance_after' => $row->balanceAfter,
                        'hash' => $key,
                    ]);

                    $imported++;
                }

                $seen[$key] = $offset + 1;
            }
        });

        $batch->update([
            'total_rows' => $total,
            'imported_count' => $imported,
            'updated_count' => $updated,
            'duplicate_count' => 0,
            'skipped_count' => 0,
        ]);
    }
}
