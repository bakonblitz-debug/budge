<?php

namespace App\Services\Statements\Contracts;

use App\Services\Statements\Dto\ParsedTransaction;

interface StatementParserInterface
{
    /**
     * Stable machine-readable identifier (e.g. 'nbc_bank_pdf', 'nbc_credit_card_pdf').
     * Stored on the import_batches.bank_format column.
     */
    public function getFormat(): string;

    /**
     * Human-readable label shown in the UI dropdown.
     */
    public function getDisplayName(): string;

    /**
     * Account types this parser is appropriate for.
     * One of: 'chequing', 'savings', 'credit_card', 'line_of_credit', 'other'.
     */
    public function getSupportedAccountTypes(): array;

    /**
     * Accepted file extensions, lower-cased, without leading dot (e.g. ['pdf']).
     */
    public function getAcceptedExtensions(): array;

    /**
     * Parse a file and return normalized transactions.
     *
     * @param  string  $filePath  Absolute path to the uploaded file
     * @return ParsedTransaction[]
     *
     * @throws \App\Services\Statements\Exceptions\StatementParseException
     */
    public function parse(string $filePath): array;
}
