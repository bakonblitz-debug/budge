<?php

namespace App\Services\Statements\Dto;

/**
 * Normalized output of any statement parser.
 *
 * Amounts are signed: negative = money out (expense / withdrawal / purchase),
 * positive = money in (income / deposit / refund / payment credit).
 */
final class ParsedTransaction
{
    public function __construct(
        public readonly string $transactionDate, // 'Y-m-d' or 'Y-m-d H:i:s'
        public readonly string $description,
        public readonly float $amount,
        public readonly ?float $balanceAfter = null,
        public readonly ?string $sourceAccountNumber = null, // hint when one PDF has many accounts
        public readonly ?string $reference = null,
    ) {}

    public function toArray(): array
    {
        return [
            'transaction_date' => $this->transactionDate,
            'description' => $this->description,
            'amount' => $this->amount,
            'balance_after' => $this->balanceAfter,
            'source_account_number' => $this->sourceAccountNumber,
            'reference' => $this->reference,
        ];
    }
}
