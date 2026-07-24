<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\ImportBatch;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $date = fake()->dateTimeBetween('-3 months', 'now')->format('Y-m-d H:i:s');
        $description = strtoupper(fake()->company());
        $amount = -1 * fake()->randomFloat(2, 5, 200);
        $balance = fake()->randomFloat(2, 100, 10000);

        return [
            'user_id' => User::factory(),
            'bank_account_id' => BankAccount::factory(),
            'import_batch_id' => null,
            'category_id' => null,
            'transaction_date' => $date,
            'description' => $description,
            'amount' => $amount,
            'balance_after' => $balance,
            'hash' => Transaction::generateHash($date, $description, $amount),
            'is_excluded' => false,
        ];
    }

    public function expense(float $magnitude): static
    {
        return $this->state(fn ($attrs) => [
            'amount' => -abs($magnitude),
            'hash' => Transaction::generateHash($attrs['transaction_date'], $attrs['description'], -abs($magnitude)),
        ]);
    }

    public function income(float $magnitude): static
    {
        return $this->state(fn ($attrs) => [
            'amount' => abs($magnitude),
            'hash' => Transaction::generateHash($attrs['transaction_date'], $attrs['description'], abs($magnitude)),
        ]);
    }

    public function forBatch(ImportBatch $batch): static
    {
        return $this->state([
            'import_batch_id' => $batch->id,
            'bank_account_id' => $batch->bank_account_id,
            'user_id' => $batch->user_id,
        ]);
    }

    public function categorized(Category $category): static
    {
        return $this->state(['category_id' => $category->id]);
    }
}
