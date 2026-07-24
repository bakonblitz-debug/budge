<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Support\Frequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FixedExpense extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'category_id', 'name', 'match_pattern', 'amount', 'frequency', 'due_day',
        'start_date', 'end_date', 'is_active', 'sort_order', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function getMonthlyAmountAttribute(): float
    {
        $amount = (float) $this->amount;

        return Frequency::tryFrom($this->frequency)?->monthlyAmount($amount) ?? $amount;
    }
}
