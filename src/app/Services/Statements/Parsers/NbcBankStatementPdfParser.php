<?php

namespace App\Services\Statements\Parsers;

use App\Services\Statements\AbstractStatementParser;
use App\Services\Statements\Dto\ParsedTransaction;
use App\Services\Statements\Exceptions\StatementParseException;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Parses NBC personal-banking statement PDFs (chequing / savings / line of credit).
 *
 * Layout notes:
 *  - Statement date in header: "Votre relevé en date du YYYY-MM-DD".
 *  - One PDF may contain multiple account sections, each preceded by an
 *    account header block (N° de compte / N° de transit / Type de compte / ...).
 *  - Transaction rows print as: date / description / [Retraits OR Dépôts] / Soldes.
 *  - The Retraits vs Dépôts column distinction is positional in the PDF and
 *    is lost during plain-text extraction. We recover the sign of the amount
 *    by tracking the running balance: balance went down → expense, balance
 *    went up → income.
 *  - Date column uses French month abbreviations (07 AVR, 01 MAI, ...). Year
 *    is not on the row — we resolve it from the statement date in the header,
 *    handling the December → January boundary.
 */
class NbcBankStatementPdfParser extends AbstractStatementParser
{
    public function getFormat(): string
    {
        return 'nbc_bank_pdf';
    }

    public function getDisplayName(): string
    {
        return 'NBC — Bank statement (PDF)';
    }

    public function getSupportedAccountTypes(): array
    {
        return ['chequing', 'savings', 'line_of_credit'];
    }

    public function getAcceptedExtensions(): array
    {
        return ['pdf'];
    }

    public function parse(string $filePath): array
    {
        $this->assertReadableFile($filePath);

        try {
            $pdf = (new PdfParser())->parseFile($filePath);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            throw new StatementParseException('The PDF could not be opened — it may be corrupted or password-protected.');
        }

        if (! str_contains($text, 'BANQUE NATIONALE DU CANADA')) {
            throw new StatementParseException("This doesn't look like an NBC bank statement.");
        }

        $statementDate = $this->extractStatementDate($text);
        if (! $statementDate) {
            throw new StatementParseException('Could not find a statement date in the PDF.');
        }

        return $this->extractTransactions($text, $statementDate);
    }

    /** Find the YYYY-MM-DD statement date printed in the header. */
    private function extractStatementDate(string $text): ?\DateTimeImmutable
    {
        if (preg_match('/relev[ée]\s+en\s+date\s+du\s+(\d{4})-(\d{2})-(\d{2})/iu', $text, $m)) {
            try {
                return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]));
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Walk the extracted text and emit one ParsedTransaction per data row.
     *
     * @return ParsedTransaction[]
     */
    private function extractTransactions(string $text, \DateTimeImmutable $statementDate): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_map('trim', $lines);

        // Each pending row keeps its raw (month, day) and a marker for which
        // account section it belongs to; the calendar year is resolved in a
        // second pass (resolveYears) once the full chronological span of each
        // section is known. This handles 12-month / YTD statements where a
        // single statement-date heuristic mis-years one month.
        $rows = [];                 // array<array{m:int,d:int,section:int,description:string,amount:float,balanceAfter:?float,account:?string}>
        $currentAccount = null;     // last-seen account number (e.g. "00-000-01")
        $sectionIndex = 0;          // increments per account section (date sequence resets)
        $previousBalance = null;    // running balance for sign-recovery
        $inTransactionTable = false;
        $pendingDate = null;        // ['m' => int, 'd' => int]
        $pendingDescParts = [];

        $accountHeaderRe = '/^(\d{2}-\d{3}-\d{2})$/';
        $dateLineRe = '/^(\d{1,2})\s+([A-ZÉÈÀÂÊÎÔÛÇ]{3})$/u';
        $tableHeaderMarker = 'Retraits ($)';
        $skipLines = [
            'Total', 'Sommaire des frais', 'Total des frais',
            'Valeurs ($)', 'Soldes ($)', 'Retraits ($)', 'Dépôts ($)',
            'Date', 'Description',
        ];
        $skipDescriptionStarts = [
            'SOLDE PRECEDENT', 'SOLDE REPORTE', 'TAUX EN VIGUEUR',
            'LE COMPTE A CHANGE',
        ];

        $flush = function (?float $debitOrCredit, ?float $newBalance) use (
            &$rows, &$pendingDate, &$pendingDescParts, &$previousBalance,
            &$currentAccount, &$sectionIndex, $skipDescriptionStarts,
        ): void {
            if ($pendingDate === null || $debitOrCredit === null) {
                $pendingDate = null;
                $pendingDescParts = [];

                return;
            }

            $description = $this->sanitizeDescription(implode(' ', $pendingDescParts));

            // skip balance-only / informational lines
            foreach ($skipDescriptionStarts as $prefix) {
                if (str_starts_with($description, $prefix)) {
                    if ($newBalance !== null) {
                        $previousBalance = $newBalance;
                    }
                    $pendingDate = null;
                    $pendingDescParts = [];

                    return;
                }
            }

            $signedAmount = $this->deriveSignedAmount($debitOrCredit, $previousBalance, $newBalance);

            // Year is resolved later, once the whole section's span is known.
            $rows[] = [
                'm' => $pendingDate['m'],
                'd' => $pendingDate['d'],
                'section' => $sectionIndex,
                'description' => $description,
                'amount' => $signedAmount,
                'balanceAfter' => $newBalance,
                'account' => $currentAccount,
            ];

            if ($newBalance !== null) {
                $previousBalance = $newBalance;
            }
            $pendingDate = null;
            $pendingDescParts = [];
        };

        $numberBuffer = [];

        $finalizeRow = function () use (&$numberBuffer, &$pendingDescParts, &$pendingDate, $flush, &$previousBalance): void {
            if ($pendingDate === null || empty($numberBuffer)) {
                $numberBuffer = [];

                return;
            }

            $count = count($numberBuffer);
            $description = trim(implode(' ', $pendingDescParts));

            // SOLDE PRECEDENT / SOLDE REPORTE = single number, the opening balance
            if (
                $count === 1
                && (str_starts_with($description, 'SOLDE PRECEDENT') || str_starts_with($description, 'SOLDE REPORTE'))
            ) {
                $previousBalance = $numberBuffer[0];
                $pendingDate = null;
                $pendingDescParts = [];
                $numberBuffer = [];

                return;
            }

            if ($count === 2) {
                $flush($numberBuffer[0], $numberBuffer[1]);
            } elseif ($count === 1) {
                // ambiguous single-number row — treat as informational, skip
                $pendingDate = null;
                $pendingDescParts = [];
            }
            // 3+ numbers = Total row or unexpected — skip
            $numberBuffer = [];
        };

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, '....')) {
                continue;
            }

            if (str_contains($line, $tableHeaderMarker)) {
                $inTransactionTable = true;
                $pendingDate = null;
                $pendingDescParts = [];
                $numberBuffer = [];
                continue;
            }

            if (preg_match($accountHeaderRe, $line, $am)) {
                $finalizeRow();
                $currentAccount = $am[1];
                $inTransactionTable = false;
                $previousBalance = null;
                $sectionIndex++;
                continue;
            }

            if (! $inTransactionTable) {
                continue;
            }

            if (in_array($line, $skipLines, true)) {
                continue;
            }

            if (preg_match($dateLineRe, $line, $dm)) {
                $finalizeRow();
                $month = $this->parseFrenchMonth($dm[2]);
                if ($month !== null) {
                    $pendingDate = ['m' => $month, 'd' => (int) $dm[1]];
                }
                continue;
            }

            $maybeNumber = $this->parseNumber($line);
            if ($maybeNumber !== null && preg_match('/^[\s\d.,$\-\xC2\xA0]+$/u', $line)) {
                $numberBuffer[] = $maybeNumber;
                continue;
            }

            // anything else is part of the description
            if ($pendingDate !== null) {
                $pendingDescParts[] = $line;
            }
        }

        // flush the last pending row
        $finalizeRow();

        return $this->resolveYears($rows, $statementDate);
    }

    /**
     * Assign a calendar year to each raw row and build the final DTOs.
     *
     * Rows arrive in chronological order within each account section, but only
     * carry month + day (no year). Anchoring to a single statement date with a
     * fixed cut mis-years exactly one month on multi-month / YTD statements.
     *
     * Instead we walk each section chronologically and increment the year every
     * time the month sequence wraps (current month < previous month, e.g.
     * Dec → Jan). That yields correct *relative* years for any span length; we
     * then shift the whole section so its last row lands on or before the
     * statement date — the statement date is the close of the covered period.
     *
     * @param  array<int, array{m:int,d:int,section:int,description:string,amount:float,balanceAfter:?float,account:?string}>  $rows
     * @return ParsedTransaction[]
     */
    private function resolveYears(array $rows, \DateTimeImmutable $statementDate): array
    {
        if ($rows === []) {
            return [];
        }

        $statementYear = (int) $statementDate->format('Y');
        $statementMonth = (int) $statementDate->format('n');

        // Group row indices by account section, preserving chronological order.
        $sections = [];
        foreach ($rows as $i => $row) {
            $sections[$row['section']][] = $i;
        }

        $relativeYearOffset = [];

        foreach ($sections as $indices) {
            // Pass 1: chronological wrap — relative year, starting at 0.
            $offset = 0;
            $previousMonth = null;
            foreach ($indices as $i) {
                $month = $rows[$i]['m'];
                if ($previousMonth !== null && $month < $previousMonth) {
                    $offset++;
                }
                $relativeYearOffset[$i] = $offset;
                $previousMonth = $month;
            }

            // Pass 2: anchor to the statement date. The last row's month belongs
            // to the statement year if month <= statementMonth, otherwise the
            // prior year (the period closed after a year boundary).
            $lastIndex = $indices[count($indices) - 1];
            $lastMonth = $rows[$lastIndex]['m'];
            $lastYear = $lastMonth <= $statementMonth ? $statementYear : $statementYear - 1;
            $shift = $lastYear - ($statementYear + $relativeYearOffset[$lastIndex]);

            foreach ($indices as $i) {
                $relativeYearOffset[$i] += $shift;
            }
        }

        $resolved = [];
        foreach ($rows as $i => $row) {
            $year = $statementYear + $relativeYearOffset[$i];
            $isoDate = $this->buildDate($year, $row['m'], $row['d'], $statementDate);

            $resolved[] = new ParsedTransaction(
                transactionDate: $isoDate.' 00:00:00',
                description: $row['description'],
                amount: $row['amount'],
                balanceAfter: $row['balanceAfter'],
                sourceAccountNumber: $row['account'],
            );
        }

        return $resolved;
    }

    /** Build a Y-m-d string, falling back to the statement date on an invalid date. */
    private function buildDate(int $year, int $month, int $day, \DateTimeImmutable $statementDate): string
    {
        try {
            return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day)))->format('Y-m-d');
        } catch (\Exception) {
            return $statementDate->format('Y-m-d');
        }
    }

    /**
     * Derive a signed amount given the raw amount column value and the
     * previous/new balance. Negative = money out (Retraits), positive = money in (Dépôts).
     *
     * If we have both balances, we trust the delta. Otherwise we default to negative
     * (most rows are expenses) but mark the magnitude only — caller may correct.
     */
    private function deriveSignedAmount(float $amount, ?float $previousBalance, ?float $newBalance): float
    {
        $magnitude = abs($amount);

        if ($previousBalance !== null && $newBalance !== null) {
            return $newBalance >= $previousBalance ? $magnitude : -$magnitude;
        }

        return -$magnitude;
    }
}
