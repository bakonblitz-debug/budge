<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportStatementRequest;
use App\Models\BankAccount;
use App\Models\ImportBatch;
use App\Models\Transaction;
use App\Services\Statements\Exceptions\StatementParseException;
use App\Services\Statements\ParserRegistry;
use App\Services\Statements\StatementImporter;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ImportController extends Controller
{
    public function __construct(
        private readonly StatementImporter $importer,
        private readonly ParserRegistry $parsers,
    ) {}

    public function index()
    {
        return Inertia::render('Transactions/Import', [
            'title' => 'Import statement',
            'bankAccounts' => BankAccount::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name', 'type']),
            'bankFormats' => $this->serializeFormats(),
            'recentImports' => ImportBatch::query()
                ->with('bankAccount:id,name')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(),
        ]);
    }

    public function store(ImportStatementRequest $request)
    {
        $file = $request->file('file');

        // Store under the user's private disk so the path is opaque and
        // controlled by Laravel — never trust the client-provided filename.
        $storedPath = $file->store('imports', 'local');
        $absolutePath = storage_path('app/private/'.$storedPath);

        try {
            $batch = $this->importer->import(
                filePath: $absolutePath,
                bankAccountId: $request->integer('bank_account_id'),
                year: $request->integer('period_year'),
                month: $request->integer('period_month'),
                format: $request->input('bank_format'),
            );

            // Count uncategorized rows from THIS batch. If any, send the user
            // to the Categorize page scoped to this batch so they can teach
            // the app what those lines mean (and a rule is saved for next time).
            $newCount = Transaction::query()
                ->where('import_batch_id', $batch->id)
                ->whereNull('category_id')
                ->distinct('description')
                ->count('description');

            $message = sprintf(
                'Import complete: %d transactions imported, %d updated.',
                $batch->imported_count,
                $batch->updated_count,
            );

            if ($batch->first_transaction_date) {
                $message .= sprintf(
                    ' Transactions from %s to %s.',
                    Carbon::parse($batch->first_transaction_date)->format('M j, Y'),
                    Carbon::parse($batch->last_transaction_date)->format('M j, Y'),
                );
            }

            if ($newCount > 0) {
                return redirect('/categorize?batch='.$batch->id)->with([
                    'message' => $message." {$newCount} new merchant".($newCount === 1 ? '' : 's')." to categorize.",
                    'type' => 'success',
                ]);
            }

            return redirect()->back()->with(['message' => $message, 'type' => 'success']);
        } catch (StatementParseException $e) {
            // Parser-thrown errors are designed to be user-safe
            return redirect()->back()->with([
                'message' => 'Import failed: '.$e->getMessage(),
                'type' => 'error',
            ]);
        } catch (\Throwable $e) {
            // Anything else: don't leak details to the UI, just log
            report($e);

            return redirect()->back()->with([
                'message' => 'Import failed due to an unexpected error. The team has been notified.',
                'type' => 'error',
            ]);
        } finally {
            @unlink($absolutePath);
        }
    }

    public function show(ImportBatch $batch)
    {
        return Inertia::render('Transactions/ImportDetail', [
            'title' => "Import: {$batch->period_label}",
            'batch' => $batch->load('bankAccount:id,name', 'transactions'),
        ]);
    }

    private function serializeFormats(): array
    {
        return array_map(
            fn ($p) => [
                'value' => $p->getFormat(),
                'title' => $p->getDisplayName(),
                'accountTypes' => $p->getSupportedAccountTypes(),
                'extensions' => $p->getAcceptedExtensions(),
            ],
            $this->parsers->all(),
        );
    }
}
