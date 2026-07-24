<?php

namespace App\Services;

use App\Models\MerchantAlias;
use Illuminate\Support\Collection;

/**
 * Turns a raw bank description into a clean merchant name.
 *
 * Deterministic, no AI: first it tries the user's MerchantAlias rules (highest
 * priority first, like CategoryMatcher); if none match it applies a conservative
 * built-in cleanup (drop trailing province codes / store numbers, title-case).
 */
class MerchantNormalizer
{
    private const PROVINCES = ['QC', 'ON', 'BC', 'AB', 'MB', 'SK', 'NS', 'NB', 'NL', 'PE', 'NT', 'YT', 'NU'];

    private Collection $aliases;

    public function __construct()
    {
        $this->aliases = collect();
    }

    /** Load the current user's active aliases, highest priority first. */
    public function loadAliases(): self
    {
        $this->aliases = MerchantAlias::where('is_active', true)
            ->orderByDesc('priority')
            ->get();

        return $this;
    }

    /**
     * Resolve a raw description to a display name. Returns a non-empty string
     * (falls back to built-in cleanup, then to the trimmed raw description).
     */
    public function normalize(string $description): string
    {
        if ($this->aliases->isEmpty()) {
            $this->loadAliases();
        }

        foreach ($this->aliases as $alias) {
            if ($alias->matches($description)) {
                return $alias->display_name;
            }
        }

        return $this->cleanup($description);
    }

    /**
     * Best-effort cleanup when no alias matches: strip a trailing province code,
     * store-number tokens (#1132, trailing digit runs), collapse whitespace, and
     * title-case. Conservative — anything it can't confidently clean is left.
     */
    private function cleanup(string $description): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $description) ?? $description);

        if ($value === '') {
            return '';
        }

        $tokens = explode(' ', $value);

        // Drop a trailing province code (e.g. "... SOMEVILLE QC").
        if (count($tokens) > 1 && in_array(strtoupper(end($tokens)), self::PROVINCES, true)) {
            array_pop($tokens);
        }

        // Drop trailing store-number tokens: "#1132", "516", "STORE#12".
        while (count($tokens) > 1 && preg_match('/^#?\d+$|#\d+$/', end($tokens))) {
            array_pop($tokens);
        }

        $cleaned = trim(implode(' ', $tokens));

        if ($cleaned === '') {
            $cleaned = $value;
        }

        // Title-case ALL-CAPS bank text; leave mixed-case as-is.
        if ($cleaned === mb_strtoupper($cleaned)) {
            $cleaned = mb_convert_case(mb_strtolower($cleaned), MB_CASE_TITLE);
        }

        return $cleaned;
    }
}
