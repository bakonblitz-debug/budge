<?php

namespace App\Services\Statements;

use App\Services\Statements\Contracts\StatementParserInterface;
use App\Services\Statements\Exceptions\StatementParseException;

abstract class AbstractStatementParser implements StatementParserInterface
{
    /** Max characters retained for a transaction description after sanitization. */
    protected const DESCRIPTION_MAX_LENGTH = 255;

    /**
     * Parse a number that may use French (`1 234,56`) or English (`1,234.56`) formatting.
     * Returns null for empty input. Strips currency symbols and non-breaking spaces.
     * A trailing minus (`500.00-`) is treated as negative — common in financial exports.
     */
    protected function parseNumber(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $cleaned = trim($raw);
        if ($cleaned === '') {
            return null;
        }

        $negative = false;
        if (str_ends_with($cleaned, '-')) {
            $negative = true;
            $cleaned = rtrim($cleaned, '-');
        }
        if (str_starts_with($cleaned, '-')) {
            $negative = true;
            $cleaned = ltrim($cleaned, '-');
        }

        // strip $, regular space, non-breaking space (U+00A0), narrow nbsp (U+202F)
        $cleaned = str_replace(['$', ' ', "\xC2\xA0", "\xE2\x80\xAF"], '', $cleaned);

        if (str_contains($cleaned, ',') && str_contains($cleaned, '.')) {
            // 1,234.56 → English: comma is thousands sep
            $cleaned = str_replace(',', '', $cleaned);
        } elseif (str_contains($cleaned, ',')) {
            // 1 234,56 → French: comma is decimal
            if (preg_match('/,\d{1,2}$/', $cleaned)) {
                $cleaned = str_replace(',', '.', $cleaned);
            } else {
                $cleaned = str_replace(',', '', $cleaned);
            }
        }

        if (! is_numeric($cleaned)) {
            return null;
        }

        $value = round((float) $cleaned, 2);

        return $negative ? -$value : $value;
    }

    /**
     * Map a 3-letter French month abbreviation (case-insensitive) to a month number.
     * NBC uses these in chequing PDFs (AVR, MAI, JUI, JUL, AOU, etc.).
     */
    protected function parseFrenchMonth(string $abbrev): ?int
    {
        $key = strtoupper(trim($abbrev));

        return match ($key) {
            'JAN' => 1,
            'FEV', 'FÉV' => 2,
            'MAR' => 3,
            'AVR' => 4,
            'MAI', 'MAY' => 5,
            'JUN' => 6,
            'JUL', 'JUI' => 7,
            'AOU', 'AOÛ' => 8,
            'SEP' => 9,
            'OCT' => 10,
            'NOV' => 11,
            'DEC', 'DÉC' => 12,
            default => null,
        };
    }

    /**
     * Sanitize a free-text description from a statement before storage.
     *
     * - Strips control characters and null bytes (defence in depth against bad PDFs)
     * - Collapses runs of whitespace
     * - Normalizes Unicode to NFC where the intl extension is available
     * - Truncates to DESCRIPTION_MAX_LENGTH (matches DB column width)
     *
     * Important: this is for STORAGE sanitization, not for HTML escaping.
     * Templates must still escape on output.
     */
    protected function sanitizeDescription(string $raw): string
    {
        $value = $raw;

        if (\function_exists('normalizer_normalize')) {
            $normalized = @normalizer_normalize($value, \Normalizer::FORM_C);
            if (is_string($normalized)) {
                $value = $normalized;
            }
        }

        // remove C0 + DEL controls and stray nulls
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = trim($value);

        if (mb_strlen($value) > self::DESCRIPTION_MAX_LENGTH) {
            $value = mb_substr($value, 0, self::DESCRIPTION_MAX_LENGTH);
        }

        return $value;
    }

    /**
     * Defensive: confirm a file exists and is non-empty before parsing.
     * The controller is expected to have already validated mime + size + ownership.
     */
    protected function assertReadableFile(string $filePath): void
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            throw new StatementParseException('Statement file is unreadable.');
        }
        if (filesize($filePath) === 0) {
            throw new StatementParseException('Statement file is empty.');
        }
    }

    /**
     * Extract a PDF's text using `pdftotext -layout` (poppler-utils).
     *
     * This preserves column positions as spaces, which matters because most
     * bank PDFs render columns via text positioning rather than actual space
     * characters — text-only extractors (like smalot/pdfparser) end up with
     * concatenated strings ("METROPLUSSOMEVILLE" instead of "METRO PLUS
     * SOMEVILLE"). The -layout flag is the standard fix.
     *
     * @throws StatementParseException if pdftotext is missing or fails
     */
    protected function extractPdfText(string $filePath): string
    {
        $cmd = ['pdftotext', '-layout', '-enc', 'UTF-8', $filePath, '-'];
        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new StatementParseException('pdftotext is not available on this system.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0 || $stdout === false || $stdout === '') {
            throw new StatementParseException('The PDF could not be read — it may be corrupted or password-protected.');
        }

        return $stdout;
    }
}
