<?php

namespace App\Models;

use App\Support\Frequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'gross_salary',
        'net_pay_per_period',
        'pay_frequency',
        'budget_start_date',
        'currency',
        'province',
    ];

    protected function casts(): array
    {
        return [
            'gross_salary' => 'decimal:2',
            'net_pay_per_period' => 'decimal:2',
            'budget_start_date' => 'date',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getMonthlyNetAttribute(): float
    {
        return Frequency::payMonthlyAmount((float) $this->net_pay_per_period, $this->pay_frequency);
    }

    public function getAnnualNetAttribute(): float
    {
        return match ($this->pay_frequency) {
            'bi_weekly' => $this->net_pay_per_period * 26,
            'weekly' => $this->net_pay_per_period * 52,
            'semi_monthly' => $this->net_pay_per_period * 24,
            'monthly' => $this->net_pay_per_period * 12,
            default => 0,
        };
    }
}
