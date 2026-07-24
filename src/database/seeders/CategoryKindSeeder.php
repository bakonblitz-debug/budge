<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * One-off backfill of the need/want `kind` axis for categories that predate the
 * column. Maps by (case-insensitive) category name from the project's known
 * defaults, fills ONLY where `kind` is still null (never overwrites a user's
 * choice), and operates across ALL users (global scope bypassed) so it is safe to
 * run once as a maintenance task. Genuinely ambiguous categories (Other, Food,
 * Financial) are intentionally left null for the user to classify.
 */
class CategoryKindSeeder extends Seeder
{
    /**
     * Lowercased category name → kind.
     *
     * @var array<string, string>
     */
    private const KIND_BY_NAME = [
        // need
        'rent' => 'need', 'housing' => 'need', 'utilities' => 'need', 'internet' => 'need',
        'groceries' => 'need', 'gas' => 'need', 'transport' => 'need', 'public transit' => 'need',
        'car maintenance' => 'need', 'car payments' => 'need', 'health' => 'need', 'insurance' => 'need',
        // want
        'restaurants' => 'want', 'shopping' => 'want', 'entertainment' => 'want', 'subscriptions' => 'want',
        // saving / investment
        'savings' => 'saving', 'investments' => 'investment',
        // excluded (money movement) and income (its own kind)
        'transfers' => 'excluded', 'between accounts' => 'excluded', 'income' => 'income',
    ];

    public function run(): void
    {
        Category::withoutGlobalScopes()
            ->whereNull('kind')
            ->get(['id', 'name'])
            ->each(function (Category $category): void {
                $kind = self::KIND_BY_NAME[mb_strtolower($category->name)] ?? null;

                if ($kind !== null) {
                    $category->update(['kind' => $kind]);
                }
            });
    }
}
