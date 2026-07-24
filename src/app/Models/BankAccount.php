<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'name', 'type', 'credit_limit', 'current_balance', 'currency', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function importBatches()
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function isCreditType(): bool
    {
        return in_array($this->type, ['credit_card', 'line_of_credit']);
    }

    public function getAvailableCreditAttribute(): ?float
    {
        if (! $this->isCreditType() || ! $this->credit_limit) return null;
        return $this->credit_limit - abs($this->current_balance);
    }
}
