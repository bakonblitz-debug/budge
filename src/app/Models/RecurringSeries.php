<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recurring spend commitment detected from transaction history.
 */
class RecurringSeries extends Model
{
    use BelongsToUser, HasFactory;

    protected $table = 'recurring_series';

    protected $fillable = [
        'category_id', 'merchant_key', 'cadence', 'expected_amount', 'last_amount',
        'amount_increased', 'occurrences', 'last_seen_at', 'next_expected_at', 'status',
        'is_manual',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:2',
            'last_amount' => 'decimal:2',
            'amount_increased' => 'boolean',
            'is_manual' => 'boolean',
            'last_seen_at' => 'date',
            'next_expected_at' => 'date',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
