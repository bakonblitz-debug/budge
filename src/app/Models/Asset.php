<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A manually-tracked asset (investment, property, vehicle…) that contributes to
 * net worth but isn't a statement-imported bank account.
 */
class Asset extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'name', 'type', 'institution', 'current_value', 'notes', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'current_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
