<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A manually-tracked debt (mortgage, loan…) that subtracts from net worth.
 * Credit-card / line-of-credit balances are tracked via bank_accounts instead.
 */
class Liability extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'name', 'type', 'institution', 'balance', 'interest_rate', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
