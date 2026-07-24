<?php

namespace App\Services\Statements\Parsers;

use App\Services\Statements\AbstractStatementParser;
use App\Services\Statements\Dto\ParsedTransaction;
use App\Services\Statements\Exceptions\StatementParseException;

/**
 * Parses NBC Mastercard credit card statement PDFs.
 *
 * Layout notes:
 *  - Statement date appears in compact YYMMDD form ("26 05 10").
 *  - Period of statement: "PÉRIODE DU RELEVÉ" → e.g. "26/04/10 AU 26/05/10".
 *  - Transaction rows print one per line as:
 *       MMDD<ref> MMDD<description...> <amount>[-]
 *    where the trailing "-" marks credits (payments, refunds, returned items).
 *  - Standard convention: store purchases as NEGATIVE (money out) and payments
 *    or refunds as POSITIVE (money in / lowers debt). The PDF prints purchases
 *    without sign and credits with a trailing "-".
 *  - Continuation lines like "MONTANT ORIGINAL EN DEVISE …" must be skipped.
 *  - Reward-points blocks and informational paragraphs must be skipped.
 */
class NbcCreditCardStatementPdfParser extends AbstractStatementParser
{
    public function getFormat(): string
    {
        return 'nbc_credit_card_pdf';
    }

    public function getDisplayName(): string
    {
        return 'NBC Mastercard — Credit card statement (PDF)';
    }

    public function getSupportedAccountTypes(): array
    {
        return ['credit_card'];
    }

    public function getAcceptedExtensions(): array
    {
        return ['pdf'];
    }

    public function parse(string $filePath): array
    {
        $this->assertReadableFile($filePath);

        $text = $this->extractPdfText($filePath);

        if (! str_contains($text, 'MASTERCARD') && ! str_contains($text, 'Mastercard')) {
            throw new StatementParseException("This doesn't look like an NBC Mastercard statement.");
        }

        $period = $this->extractStatementPeriod($text);
        if (! $period) {
            throw new StatementParseException('Could not find a statement period in the PDF.');
        }

        return $this->extractTransactions($text, $period['start'], $period['end']);
    }

    /**
     * @return array{start: \DateTimeImmutable, end: \DateTimeImmutable}|null
     */
    private function extractStatementPeriod(string $text): ?array
    {
        // matches "26/04/10 AU 26/05/10" or "26/04/10AU26/05/10" (yy/mm/dd, spaces optional)
        if (! preg_match('#(\d{2})/(\d{2})/(\d{2})\s*AU\s*(\d{2})/(\d{2})/(\d{2})#u', $text, $m)) {
            return null;
        }

        try {
            return [
                'start' => new \DateTimeImmutable(sprintf('20%02d-%02d-%02d', $m[1], $m[2], $m[3])),
                'end' => new \DateTimeImmutable(sprintf('20%02d-%02d-%02d', $m[4], $m[5], $m[6])),
            ];
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return ParsedTransaction[]
     */
    private function extractTransactions(string $text, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];

        // With pdftotext -layout, a transaction line looks like:
        //   04   16   U000000001   04 17 METRO PLUS SOMEVILLE SOMEVILLE     QC    23.76
        //   ^ MM ^ DD ^ ref          ^ posted MM/DD  ^ description              ^ amount [trailing - = credit]
        $txnRe = '/^
            \s*(\d{2})\s+(\d{2})            # 1,2: txn month, day
            \s+([A-Z0-9]{6,})               # 3:   reference code
            \s+(\d{2})\s+(\d{2})            # 4,5: posted month, day
            \s+(.+?)                        # 6:   description (greedy-min)
            \s+(-?[\d,]+\.\d{2}-?)          # 7:   amount, possibly trailing minus
            \s*$
        /xu';

        $rows = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'MONTANT ORIGINAL EN DEVISE')) {
                continue;
            }
            if (str_starts_with($line, 'POINTS ') || str_starts_with($line, 'SOLDE REPORTE DE POINTS')) {
                continue;
            }

            if (! preg_match($txnRe, $line, $m)) {
                continue;
            }

            $txnMonth = (int) $m[1];
            $txnDay = (int) $m[2];
            $description = $this->sanitizeDescription($m[6]);

            $amount = $this->parseNumber($m[7]);
            if ($amount === null) {
                continue;
            }

            // Card convention: positive in PDF = purchase (money out); trailing
            // minus = credit/payment (money in). Flip sign so it matches our DB.
            $signed = -$amount;

            $isoDate = $this->resolveDate($txnMonth, $txnDay, $periodStart, $periodEnd);

            $rows[] = new ParsedTransaction(
                transactionDate: $isoDate.' 00:00:00',
                description: $description,
                amount: $signed,
                balanceAfter: null,
                sourceAccountNumber: null,
                reference: $m[3] ?: null,
            );
        }

        return $rows;
    }

    /**
     * Use the period start/end to pick the correct year for an MMDD pair.
     * If the month falls within the period, use that year; otherwise pick the
     * year that places it inside the period (handles Dec → Jan rollover).
     */
    private function resolveDate(int $month, int $day, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd): string
    {
        $startYear = (int) $periodStart->format('Y');
        $endYear = (int) $periodEnd->format('Y');

        foreach (array_unique([$startYear, $endYear]) as $year) {
            try {
                $dt = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
                if ($dt >= $periodStart->modify('-1 day') && $dt <= $periodEnd->modify('+1 day')) {
                    return $dt->format('Y-m-d');
                }
            } catch (\Exception) {
                continue;
            }
        }

        // fallback: use end year
        try {
            return (new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $endYear, $month, $day)))->format('Y-m-d');
        } catch (\Exception) {
            return $periodEnd->format('Y-m-d');
        }
    }
}
