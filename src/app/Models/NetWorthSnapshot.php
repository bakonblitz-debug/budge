<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A point-in-time net-worth record used to chart the trend over time.
 */
class NetWorthSnapshot extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'as_of', 'total_cash', 'total_assets', 'total_liabilities', 'net_worth', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'date:Y-m-d',
            'total_cash' => 'decimal:2',
            'total_assets' => 'decimal:2',
            'total_liabilities' => 'decimal:2',
            'net_worth' => 'decimal:2',
            'metadata' => 'array',
        ];
    }
}
