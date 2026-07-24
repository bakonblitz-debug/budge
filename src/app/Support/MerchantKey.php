<?php

namespace App\Support;

use App\Models\RecurringSeries;
use App\Models\Transaction;

/**
 * Stable, noise-stripped merchant grouping key, shared by every service that
 * groups transactions by merchant (RecurringDetector, OneTimePurchaseDetector,
 * InvestmentScheduleProjector). These keys are compared ACROSS services at
 * runtime (e.g. against a persisted {@see RecurringSeries}
 * merchant_key) — drift between copies silently double-counts or misses a
 * stream, so this is the single source of truth.
 *
 * Strips the variable noise banks stamp on each charge: mixed alphanumeric
 * reference tokens (e.g. "P36FB9F285"), long digit/store-number runs, and a
 * trailing geo pair (city + 3-letter country/province like "STOCKHOLM SWE" or
 * "SOMEVILLE QC").
 */
class MerchantKey
{
    /**
     * Canadian province + 3-letter country codes, plus a scoped set of US
     * state codes, seen as trailing geo tokens.
     *
     * The US states are added one at a time, only when confirmed present as
     * a real trailing "CITY ST" pair in actual transaction data (verified via
     * a read-only tinker probe, counts only — see SUB-2/REC-1 plan): CA
     * ("... irvine ca"), WA ("... bellevue wa"), NV ("... vegas nv"), NJ
     * ("... chester nj"), UT ("... sandy ut"), WY ("... sheridan wy").
     * Deliberately excluded: codes that collide with common English/French
     * words (OR, IN, LA, DE, PA, MA, HI, OK, ME, AL) unless positively
     * confirmed as geo — none were. Also excluded: CO (single observed hit,
     * "... mat co", more likely the "Company" abbreviation than Colorado) and
     * NY (single observed hit preceded by a digit run, not a real city).
     */
    private const GEO_TOKENS = [
        'QC', 'ON', 'BC', 'AB', 'MB', 'SK', 'NS', 'NB', 'NL', 'PE', 'NT', 'YT', 'NU',
        'CAN', 'USA', 'SWE', 'GBR', 'IRL', 'NLD', 'DEU', 'FRA', 'LUX',
        'CA', 'WA', 'NV', 'NJ', 'UT', 'WY',
    ];

    /** Merchant key for a transaction (merchant_name, falling back to description). */
    public static function for(Transaction $t): string
    {
        $value = trim((string) ($t->merchant_name ?: $t->description));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        if ($value === '') {
            return '';
        }

        $tokens = explode(' ', $value);

        if (count($tokens) > 1 && self::isGeoToken(end($tokens))) {
            array_pop($tokens);
            if (count($tokens) > 1 && ctype_alpha(str_replace('-', '', end($tokens)))) {
                array_pop($tokens);
            }
        }

        $tokens = array_values(array_filter($tokens, fn (string $tok): bool => ! self::isNoiseToken($tok)));

        $cleaned = trim(implode(' ', $tokens));
        if ($cleaned === '') {
            $cleaned = $value;
        }

        return mb_strtolower($cleaned);
    }

    /**
     * Trivial normalize cousin: lowercase + whitespace-collapse only, no
     * geo/noise-token stripping. Used for best-effort name matching (fixed
     * expense dedupe, accrual merchant matching) where the fuller {@see for()}
     * grouping key would be too aggressive.
     */
    public static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private static function isGeoToken(string $token): bool
    {
        return in_array(strtoupper($token), self::GEO_TOKENS, true);
    }

    private static function isNoiseToken(string $token): bool
    {
        $token = trim($token, '#');
        if ($token === '') {
            return true;
        }

        if (ctype_digit($token)) {
            return true;
        }

        $hasDigit = preg_match('/\d/', $token) === 1;
        $hasAlpha = preg_match('/[a-zA-Z]/', $token) === 1;

        return $hasDigit && $hasAlpha && mb_strlen($token) >= 5;
    }
}
