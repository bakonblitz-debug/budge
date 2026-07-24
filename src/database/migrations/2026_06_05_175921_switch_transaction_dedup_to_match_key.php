<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Switch duplicate detection from a UNIQUE hash (epoch+desc+amount+balance)
     * to a non-unique date(day)+description+amount match key used for idempotent
     * positional upserts during import.
     *
     * Order matters: drop the UNIQUE index BEFORE recomputing hashes, otherwise
     * rows that collide under the coarser recipe would violate the constraint.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_hash_unique');
        });

        // Recompute every existing row's hash to the new recipe. In a migration
        // auth() is null so the BelongsToUser global scope is inactive, but we
        // strip scopes explicitly to be safe.
        Transaction::withoutGlobalScopes()->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $row->hash = Transaction::generateHash(
                    (string) $row->transaction_date,
                    $row->description,
                    (float) $row->amount,
                );
                $row->saveQuietly();
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->index(['bank_account_id', 'hash'], 'transactions_account_hash_index');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->unsignedInteger('updated_count')->default(0)->after('imported_count');
        });
    }

    /**
     * Best-effort reversal. The hash values are not recomputed back to the old
     * recipe (balance_after-inclusive), and re-adding a UNIQUE index on hash may
     * fail if intentional duplicates now exist — so it is intentionally omitted.
     */
    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('updated_count');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex('transactions_account_hash_index');
        });
    }
};
