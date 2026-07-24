<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavingsMilestone extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'name', 'target_amount', 'reached_at', 'is_reached', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'reached_at' => 'datetime',
            'is_reached' => 'boolean',
        ];
    }
}
