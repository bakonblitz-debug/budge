<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transfer>
 */
class TransferFactory extends Factory
{
    protected $model = Transfer::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'from_transaction_id' => Transaction::factory(),
            'to_transaction_id' => Transaction::factory(),
            'detected_via' => 'manual',
            'note' => null,
        ];
    }
}
