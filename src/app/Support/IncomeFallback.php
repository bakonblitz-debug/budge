<?php

namespace App\Support;

use App\Models\IncomeEntry;
use App\Models\UserProfile;
use App\Services\BudgetRuleAnalyzer;
use App\Services\SpendingCutAnalyzer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Shared IncomeEntry → UserProfile → gross-with-warning fallback chain, used
 * by both {@see BudgetRuleAnalyzer} and
 * {@see SpendingCutAnalyzer} whenever there's no
 * transaction-derived income to fall back on. Kept as one source of truth so
 * the two analyzers can never silently disagree on the same underlying data.
 *
 * `is_net=false` (gross/pre-tax) rows are never summed alongside net rows —
 * every other consumer of "income" here treats the figure as net, so a gross
 * row would inflate it. But filtering must never collapse income to $0:
 *
 *  1. Prefer `is_net=true` entries (summed by monthly-equivalent).
 *  2. Else the maintained {@see UserProfile}'s net monthly figure.
 *  3. Else — only as a last resort — sum the gross entries anyway, flagging
 *     the result with a warning so the UI can tell the user their figure is
 *     pre-tax.
 *  4. Truly nothing on file → not `found`, so the caller (SpendingCutAnalyzer)
 *     can drop to its own legacy basis-month-deposits fallback.
 *
 * `found` lets a caller with a further fallback of its own (SCA's basis-month
 * deposits) tell "nothing on file" apart from "found $0", in a single query
 * pass — without re-querying to figure that out itself.
 *
 * @return array{monthly: float, warning: ?string, found: bool}
 */
class IncomeFallback
{
    public static function resolve(): array
    {
        $entries = IncomeEntry::all();

        $netEntries = $entries->where('is_net', true);
        if ($netEntries->isNotEmpty()) {
            return ['monthly' => self::sum($netEntries), 'warning' => null, 'found' => true];
        }

        $profile = UserProfile::query()->where('user_id', Auth::id())->first();
        if ($profile !== null) {
            return ['monthly' => (float) $profile->monthly_net, 'warning' => null, 'found' => true];
        }

        if ($entries->isNotEmpty()) {
            return [
                'monthly' => self::sum($entries),
                'warning' => 'Income is based on gross (pre-tax) entries — add a net entry or profile for an accurate figure.',
                'found' => true,
            ];
        }

        return ['monthly' => 0.0, 'warning' => null, 'found' => false];
    }

    /** @param  Collection<int, IncomeEntry>  $entries */
    private static function sum($entries): float
    {
        return (float) $entries->sum(
            fn (IncomeEntry $e): float => Frequency::tryFrom($e->frequency)?->monthlyAmount((float) $e->amount) ?? (float) $e->amount
        );
    }
}
