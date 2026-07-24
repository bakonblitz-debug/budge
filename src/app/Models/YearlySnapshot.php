<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class YearlySnapshot extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'year', 'total_income', 'total_expenses', 'total_fixed', 'total_variable',
        'net_savings', 'savings_rate', 'expense_ratio', 'life_direction',
        'metadata', 'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'total_income' => 'decimal:2',
            'total_expenses' => 'decimal:2',
            'total_fixed' => 'decimal:2',
            'total_variable' => 'decimal:2',
            'net_savings' => 'decimal:2',
            'savings_rate' => 'decimal:2',
            'expense_ratio' => 'decimal:2',
            'metadata' => 'array',
            'calculated_at' => 'datetime',
        ];
    }
}
