<?php

namespace App\Observers;

use App\Models\ImportBatch;
use App\Services\RecurringDetector;

/**
 * Keeps recurring-series detection fresh without touching the import
 * pipeline itself: a completed import can revive a merchant that had gone
 * quiet (or introduce a new one), so recurring_series would otherwise sit
 * stale until the user happens to visit the Subscriptions page or a rescan
 * command runs. Hooking the model event here — rather than a call inside
 * StatementImporter — keeps the importer untouched and runs in the same
 * authenticated request, so RecurringDetector's user-scoping just works.
 */
class ImportBatchObserver
{
    public function updated(ImportBatch $batch): void
    {
        if ($batch->wasChanged('status') && $batch->status === 'completed') {
            app(RecurringDetector::class)->detect();
        }
    }
}
