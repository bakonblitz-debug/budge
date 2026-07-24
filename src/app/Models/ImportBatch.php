<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'bank_account_id', 'period_year', 'period_month', 'filename', 'bank_format',
        'total_rows', 'imported_count', 'updated_count', 'duplicate_count', 'skipped_count',
        'status', 'error_message', 'imported_at',
    ];

    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function getPeriodLabelAttribute(): string
    {
        return date('F Y', mktime(0, 0, 0, $this->period_month, 1, $this->period_year));
    }
}
