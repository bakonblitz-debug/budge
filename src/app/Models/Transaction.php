<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'bank_account_id', 'import_batch_id', 'category_id', 'transfer_id',
        'transaction_date', 'description', 'merchant_name', 'amount', 'balance_after',
        'notes', 'is_excluded', 'hash',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'datetime',
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'is_excluded' => 'boolean',
        ];
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function importBatch()
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    /** True when this row is one leg of a linked inter-account transfer. */
    public function isTransfer(): bool
    {
        return $this->transfer_id !== null;
    }

    public function isExpense(): bool
    {
        return $this->amount < 0;
    }

    public function isIncome(): bool
    {
        return $this->amount > 0;
    }

    /**
     * Real money that left/entered the account: not one leg of an inter-
     * account transfer, and not manually excluded. The base filter almost
     * every money-math query in the app applies before its own sign predicate.
     */
    public function scopeReal(Builder $query): Builder
    {
        return $query->whereNull('transfer_id')->where('is_excluded', false);
    }

    /** Real expenses only (amount < 0). Apply only where the site was already filtering amount < 0. */
    public function scopeExpense(Builder $query): Builder
    {
        return $query->real()->where('amount', '<', 0);
    }

    /**
     * Build the non-unique import match key for a transaction.
     *
     * Recipe: day-granular date + description + amount (balance_after and the
     * time-of-day are intentionally excluded). This is a MATCH KEY used by the
     * importer to upsert against existing rows — NOT a uniqueness guard. The
     * same key can legitimately repeat (e.g. two identical same-day purchases),
     * so the importer resolves collisions positionally.
     */
    public static function generateHash(string $dateTime, string $description, float $amount): string
    {
        $day = date('Y-m-d', strtotime($dateTime));

        return hash('sha256', implode('|', [$day, $description, $amount]));
    }
}
