<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IncomeEntry extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'source', 'amount', 'frequency', 'pay_date', 'is_net', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'pay_date' => 'date',
            'is_net' => 'boolean',
        ];
    }
}
