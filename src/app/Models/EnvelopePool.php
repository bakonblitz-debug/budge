<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class EnvelopePool extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'category_id', 'name', 'monthly_accrual', 'current_balance', 'start_date', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'monthly_accrual' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'start_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function getCalculatedBalanceAttribute(): float
    {
        $monthsElapsed = $this->start_date->diffInMonths(Carbon::now(), absolute: true)
            + (Carbon::now()->day / Carbon::now()->daysInMonth);

        $accrued = $monthsElapsed * $this->monthly_accrual;

        // Roll up child-category spend when the pool targets a parent (mirrors
        // BudgetController::index), so a pool on "Transport" also draws on
        // Gas/Car Maintenance. Pools should target specific categories; a future
        // pass could warn when one sits on an `excluded`/catch-all category.
        $categoryIds = array_merge(
            [$this->category_id],
            Category::where('parent_id', $this->category_id)->pluck('id')->all(),
        );

        // Same exclusions as Budget/accrual: real money that left the account
        // only — never inter-account transfers or card payments (transfer_id set),
        // excluded rows, or income.
        $spent = abs(Transaction::query()
            ->whereIn('category_id', $categoryIds)
            ->expense()
            ->where('transaction_date', '>=', $this->start_date)
            ->sum('amount'));

        return round($accrued - $spent, 2);
    }
}
