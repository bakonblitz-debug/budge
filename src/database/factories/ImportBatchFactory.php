<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bank_account_id' => BankAccount::factory(),
            'period_year' => 2026,
            'period_month' => 4,
            'filename' => 'statement.pdf',
            'bank_format' => 'nbc_bank_pdf',
            'total_rows' => 0,
            'imported_count' => 0,
            'updated_count' => 0,
            'duplicate_count' => 0,
            'skipped_count' => 0,
            'status' => 'completed',
        ];
    }

    public function forAccount(BankAccount $account): static
    {
        return $this->state([
            'bank_account_id' => $account->id,
            'user_id' => $account->user_id,
        ]);
    }

    public function processing(): static
    {
        return $this->state(['status' => 'processing']);
    }

    public function failed(string $message = 'Test failure'): static
    {
        return $this->state(['status' => 'failed', 'error_message' => $message]);
    }
}
