<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\EnvelopePool;
use App\Models\FixedExpense;
use App\Models\ImportBatch;
use App\Models\IncomeEntry;
use App\Models\SavingsMilestone;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserProfile;
use App\Models\YearlySnapshot;
use App\Services\CategoryMatcher;
use App\Services\MerchantNormalizer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a self-contained demo user with two years of internally-consistent
 * bank + credit-card imports.
 *
 * The category tree and auto-categorization rules mirror a realistic personal
 * setup (structure only — no dollar amounts are copied from real data); every figure
 * below is fabricated so the demo can be shown to other users safely. The
 * transactions flow through the same {@see CategoryMatcher} and
 * {@see MerchantNormalizer} the real PDF importer uses, so the seeded data is a
 * faithful stand-in for real imports and a useful canary for regressions: if a
 * dashboard, budget, envelope, or net-worth number looks wrong for this user,
 * the math is off somewhere.
 *
 * Idempotent: re-running wipes and rebuilds the demo user's data only. Other
 * users (e.g. the dev `dev@budgetapp.local` account) are never touched.
 */
class DemoSeeder extends Seeder
{
    private const EMAIL = 'demo@budgetapp.local';

    private const PASSWORD = 'demo';

    private const MONTHS_OF_HISTORY = 24;

    private const OPENING_BALANCE = 2150.00;

    private const NET_PER_PAYCHEQUE = 1985.00;

    private const RENT = 1725.00;

    private const MONTHLY_INVESTMENT = 300.00;

    /** Monotonic counter used to give every transaction a unique intra-day time (keeps hashes distinct). */
    private int $sequence = 0;

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Demo User',
                'password' => bcrypt(self::PASSWORD),
                'email_verified_at' => now(),
                'onboarding_completed_at' => now(),
            ],
        );

        Auth::login($user);

        try {
            // Deterministic output so the demo looks the same on every rebuild.
            fake()->seed(20260605);
            $this->sequence = 0;

            DB::transaction(function () use ($user): void {
                $this->wipeExistingDemoData($user);
                $this->seedProfile();

                $categories = $this->seedCategories();
                $this->seedCategoryRules($categories);
                $this->seedFixedExpenses($categories);
                $this->seedIncome();
                $this->seedBudgets($categories);
                $this->seedEnvelopePools($categories);

                [$chequing, $card] = $this->seedAccounts();
                $finalSavings = $this->seedImports($chequing, $card, $categories);

                $this->seedSavingsMilestones($finalSavings);
                $this->seedYearlySnapshots();
            });
        } finally {
            Auth::logout();
        }

        $this->command?->info('Demo user seeded: '.self::EMAIL.' / '.self::PASSWORD);
    }

    private function wipeExistingDemoData(User $user): void
    {
        // FK-safe order; all queries are auto-scoped to the logged-in demo user.
        Transaction::query()->delete();
        ImportBatch::query()->delete();
        Budget::query()->delete();
        EnvelopePool::query()->delete();
        FixedExpense::query()->delete();
        CategoryRule::query()->delete();
        Category::whereNotNull('parent_id')->delete();
        Category::query()->delete();
        BankAccount::query()->delete();
        IncomeEntry::query()->delete();
        SavingsMilestone::query()->delete();
        YearlySnapshot::query()->delete();
        UserProfile::where('user_id', $user->id)->delete();
    }

    private function seedProfile(): void
    {
        UserProfile::create([
            'user_id' => Auth::id(),
            'gross_salary' => 78000.00,
            'net_pay_per_period' => self::NET_PER_PAYCHEQUE,
            'pay_frequency' => 'bi_weekly',
            'budget_start_date' => now()->startOfMonth()->subMonths(self::MONTHS_OF_HISTORY - 1)->toDateString(),
            'currency' => 'CAD',
            'province' => 'QC',
        ]);
    }

    /**
     * Build the category hierarchy (mirrors the real tree's names/icons/colors).
     *
     * @return array<string, Category> map of category name => model
     */
    private function seedCategories(): array
    {
        // `kind` is set up-front so the demo's 50/30/10/10 meter + insights work
        // out of the box (no out-of-whack unclassified categories). Mixed parents
        // (Financial, Food) and the genuine catch-all (Other) stay null.
        $tree = [
            ['name' => 'Housing', 'icon' => 'mdi-home', 'color' => '#5E6BC4', 'sort' => 0, 'kind' => 'need', 'children' => [
                ['name' => 'Rent', 'sort' => 0, 'kind' => 'need'],
                ['name' => 'Utilities', 'sort' => 1, 'kind' => 'need'],
                ['name' => 'Internet', 'sort' => 2, 'kind' => 'need'],
            ]],
            ['name' => 'Financial', 'icon' => 'mdi-finance_mode', 'color' => '#fcba03', 'sort' => 0, 'kind' => null, 'children' => [
                ['name' => 'Insurance', 'sort' => 0, 'kind' => 'need'],
                ['name' => 'Investments', 'sort' => 0, 'kind' => 'investment'],
                ['name' => 'Between Accounts', 'sort' => 0, 'kind' => 'excluded'],
                ['name' => 'Car Payments', 'sort' => 0, 'kind' => 'need'],
            ]],
            ['name' => 'Food', 'icon' => 'mdi-food', 'color' => '#FB8C00', 'sort' => 10, 'kind' => null, 'children' => [
                ['name' => 'Groceries', 'sort' => 0, 'kind' => 'need'],
                ['name' => 'Restaurants', 'sort' => 1, 'kind' => 'want'],
            ]],
            ['name' => 'Transport', 'icon' => 'mdi-car', 'color' => '#43A047', 'sort' => 20, 'kind' => 'need', 'children' => [
                ['name' => 'Gas', 'sort' => 0, 'kind' => 'need'],
                ['name' => 'Car Maintenance', 'sort' => 0, 'kind' => 'need'],
                ['name' => 'Public Transit', 'sort' => 1, 'kind' => 'need'],
            ]],
            ['name' => 'Subscriptions', 'icon' => 'mdi-credit-card-outline', 'color' => '#8E24AA', 'sort' => 30, 'kind' => 'want', 'children' => []],
            ['name' => 'Health', 'icon' => 'mdi-medical-bag', 'color' => '#E53935', 'sort' => 40, 'kind' => 'need', 'children' => []],
            ['name' => 'Entertainment', 'icon' => 'mdi-controller', 'color' => '#00ACC1', 'sort' => 50, 'kind' => 'want', 'children' => []],
            ['name' => 'Shopping', 'icon' => 'mdi-cart', 'color' => '#FDD835', 'sort' => 60, 'kind' => 'want', 'children' => []],
            ['name' => 'Income', 'icon' => 'mdi-cash-plus', 'color' => '#2E7D32', 'sort' => 70, 'kind' => 'income', 'children' => []],
            ['name' => 'Transfers', 'icon' => 'mdi-bank-transfer', 'color' => '#546E7A', 'sort' => 80, 'kind' => 'excluded', 'children' => []],
            ['name' => 'Other', 'icon' => 'mdi-dots-horizontal', 'color' => '#757575', 'sort' => 90, 'kind' => null, 'children' => []],
        ];

        $map = [];

        foreach ($tree as $parent) {
            $parentModel = Category::create([
                'parent_id' => null,
                'name' => $parent['name'],
                'icon' => $parent['icon'],
                'color' => $parent['color'],
                'sort_order' => $parent['sort'],
                'is_active' => true,
                'kind' => $parent['kind'] ?? null,
            ]);
            $map[$parent['name']] = $parentModel;

            foreach ($parent['children'] as $child) {
                $map[$child['name']] = Category::create([
                    'parent_id' => $parentModel->id,
                    'name' => $child['name'],
                    'icon' => $parent['icon'],
                    'color' => $parent['color'],
                    'sort_order' => $child['sort'],
                    'is_active' => true,
                    'kind' => $child['kind'] ?? null,
                ]);
            }
        }

        return $map;
    }

    /**
     * @param  array<string, Category>  $categories
     */
    private function seedCategoryRules(array $categories): void
    {
        // [category name, match type, match value, priority] — a realistic rule set.
        $rules = [
            ['Housing', 'contains', 'ASSURANCE BIENS', 150],
            ['Insurance', 'contains', 'ASSURANCE HABITATION', 200],
            ['Rent', 'contains', 'CHEQUE CLIENT RENTCO', 250],
            ['Utilities', 'contains', 'HYDRO-QUEBEC', 200],
            ['Internet', 'contains', 'BELL CANADA', 150],
            ['Groceries', 'contains', 'WMT SUPRCTR', 200],
            ['Groceries', 'contains', 'WMTSUPRCTR', 200],
            ['Groceries', 'contains', 'METRO', 200],
            ['Restaurants', 'contains', 'BISTROCENTRAL', 150],
            ['Restaurants', 'contains', 'GRILL MAISON', 150],
            ['Restaurants', 'contains', 'GRILLMAISON', 150],
            ['Restaurants', 'contains', 'RESTO BURGER PLUS', 150],
            ['Restaurants', 'contains', 'RESTOBURGERPLUS', 150],
            ['Restaurants', 'contains', 'DOORDASH', 150],
            ['Restaurants', 'contains', 'A&W', 150],
            ['Restaurants', 'contains', 'WENDYS', 150],
            ['Restaurants', 'contains', 'MCDONALD', 150],
            ['Restaurants', 'contains', 'BISTRO CENTRAL', 150],
            ['Restaurants', 'contains', 'DEJEUNER', 150],
            ['Restaurants', 'contains', 'CAFE DU COIN', 100],
            ['Transport', 'contains', 'ASSURANCE AUTO', 200],
            ['Transport', 'contains', 'PNEUSEXPRESS', 150],
            ['Transport', 'contains', 'PNEUS EXPRESS', 150],
            ['Transport', 'contains', '9000000CANADA', 100],
            ['Transport', 'contains', '9000000 CANADA INC', 100],
            ['Gas', 'contains', 'SHELL', 150],
            ['Gas', 'contains', 'COUCHETARD', 100],
            ['Subscriptions', 'contains', 'FIDO MOBILE', 200],
            ['Subscriptions', 'contains', 'INTERNET PAIEM', 200],
            ['Subscriptions', 'contains', 'SPOTIFY', 150],
            ['Subscriptions', 'contains', 'YOUTUBEPREMIUM', 150],
            ['Subscriptions', 'contains', 'GITHUB', 150],
            ['Subscriptions', 'contains', 'BETTERME', 150],
            ['Entertainment', 'contains', 'BLIZZARD', 150],
            ['Entertainment', 'contains', 'SQUAREENIX', 150],
            ['Entertainment', 'contains', 'SQUARE ENIX', 150],
            ['Shopping', 'contains', 'AMZN', 150],
            ['Shopping', 'contains', 'AMAZON', 150],
            ['Shopping', 'contains', 'CANADIAN TIRE', 150],
            ['Shopping', 'contains', 'DOLLARAMA', 150],
            ['Shopping', 'contains', 'WINNERS', 150],
            ['Shopping', 'contains', 'RONA', 150],
            ['Shopping', 'contains', 'STOKES', 150],
            ['Shopping', 'contains', 'PAYPAL', 100],
            ['Income', 'contains', 'REMB. IMPOT', 200],
            ['Income', 'contains', 'SALAIRE', 200],
            ['Income', 'contains', "REMB. D'IMPOT", 200],
            ['Income', 'contains', 'PAIEMENT INTERETS', 150],
            ['Transfers', 'starts_with', 'COMPTE A PAYER', 300],
            ['Transfers', 'contains', 'MOBILE VIR', 300],
            ['Transfers', 'contains', 'VIREMENT INTERAC', 300],
            ['Transfers', 'contains', 'PAIEMENT RECU', 300],
            ['Transfers', 'contains', 'PAIEMENTRECU', 300],
            ['Transfers', 'contains', 'AVEC POINTS', 300],
            ['Transfers', 'contains', 'AVECPOINTS', 300],
            ['Transfers', 'contains', 'PRET PMT', 300],
            ['Transfers', 'contains', 'PLACEMENT', 300],
            ['Transfers', 'starts_with', 'MOBILE PAIEM', 300],
            ['Transfers', 'contains', 'FRAIS FIXES', 200],
            ['Other', 'contains', 'SQDC', 100],
            ['Other', 'contains', 'SAQ', 100],
            ['Other', 'contains', 'NATURE SHOP', 100],
            ['Other', 'contains', 'WELLNESS SHOP', 100],
            ['Other', 'contains', 'WL *BLIZZARD', 100],
            ['Other', 'contains', 'ASSURANCE', 50],
        ];

        foreach ($rules as [$categoryName, $matchType, $matchValue, $priority]) {
            CategoryRule::create([
                'category_id' => $categories[$categoryName]->id,
                'match_type' => $matchType,
                'match_value' => $matchValue,
                'priority' => $priority,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  array<string, Category>  $categories
     */
    private function seedFixedExpenses(array $categories): void
    {
        $start = now()->startOfMonth()->subMonths(self::MONTHS_OF_HISTORY - 1)->toDateString();

        // `match_pattern` ties each bill to its bank-merchant string (which differs
        // from the human name) so the accrual "spent" matcher finds the payment —
        // no false "behind"/unmatched in the demo. Home Insurance lives in the real
        // Insurance category (a need), not the catch-all "Other".
        $fixed = [
            ['name' => 'Rent', 'category' => 'Rent', 'amount' => self::RENT, 'due_day' => 1, 'match_pattern' => 'LOYER'],
            ['name' => 'Hydro', 'category' => 'Utilities', 'amount' => 110.00, 'due_day' => 19, 'match_pattern' => 'HYDRO-QUEBEC'],
            ['name' => 'Home Insurance', 'category' => 'Insurance', 'amount' => 42.00, 'due_day' => 30, 'match_pattern' => 'ASSURANCE HABITATION'],
        ];

        foreach ($fixed as $sort => $expense) {
            FixedExpense::create([
                'category_id' => $categories[$expense['category']]->id,
                'name' => $expense['name'],
                'match_pattern' => $expense['match_pattern'],
                'amount' => $expense['amount'],
                'frequency' => 'monthly',
                'due_day' => $expense['due_day'],
                'start_date' => $start,
                'end_date' => null,
                'is_active' => true,
                'sort_order' => $sort,
            ]);
        }
    }

    private function seedIncome(): void
    {
        IncomeEntry::create([
            'source' => 'Acme Corp',
            'amount' => self::NET_PER_PAYCHEQUE,
            'frequency' => 'bi_weekly',
            'pay_date' => now()->startOfMonth()->addDays(3)->toDateString(),
            'is_net' => true,
        ]);
    }

    /**
     * @param  array<string, Category>  $categories
     */
    private function seedBudgets(array $categories): void
    {
        $start = now()->startOfMonth()->subMonths(self::MONTHS_OF_HISTORY - 1)->toDateString();

        $budgets = [
            ['category' => 'Groceries', 'amount' => 520.00],
            ['category' => 'Restaurants', 'amount' => 280.00],
            ['category' => 'Shopping', 'amount' => 220.00],
        ];

        foreach ($budgets as $budget) {
            Budget::create([
                'category_id' => $categories[$budget['category']]->id,
                'amount' => $budget['amount'],
                'period' => 'monthly',
                'start_date' => $start,
                'end_date' => null,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  array<string, Category>  $categories
     */
    private function seedEnvelopePools(array $categories): void
    {
        $pool = EnvelopePool::create([
            'category_id' => $categories['Groceries']->id,
            'name' => 'Groceries pool',
            'monthly_accrual' => 500.00,
            'current_balance' => 0,
            'start_date' => now()->startOfMonth()->subMonths(11)->toDateString(),
            'is_active' => true,
        ]);

        // Sync the cache to the live calculated value (depends on seeded transactions).
        $pool->update(['current_balance' => $pool->calculated_balance]);
    }

    /**
     * @return array{0: BankAccount, 1: BankAccount}
     */
    private function seedAccounts(): array
    {
        $chequing = BankAccount::create([
            'name' => 'NBC Chequing',
            'type' => 'chequing',
            'current_balance' => self::OPENING_BALANCE,
            'currency' => 'CAD',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $card = BankAccount::create([
            'name' => 'NBC Mastercard',
            'type' => 'credit_card',
            'credit_limit' => 8000.00,
            'current_balance' => 0,
            'currency' => 'CAD',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        return [$chequing, $card];
    }

    /**
     * Generate 24 months of chequing + credit-card statements.
     *
     * Transactions are generated as specs, the chequing running balance is
     * accumulated chronologically (so balance_after is correct), then every row
     * is categorized + normalized exactly like the real importer before persist.
     *
     * @param  array<string, Category>  $categories
     * @return float final liquid + invested savings (used to mark milestones)
     */
    private function seedImports(BankAccount $chequing, BankAccount $card, array $categories): float
    {
        $matcher = (new CategoryMatcher)->loadRules();
        $normalizer = (new MerchantNormalizer)->loadAliases();

        $firstMonth = now()->startOfMonth()->subMonths(self::MONTHS_OF_HISTORY - 1);
        $now = now();

        /** @var array<int, array<string, mixed>> $chequingSpecs */
        $chequingSpecs = [];
        /** @var array<int, array<string, mixed>> $cardSpecs */
        $cardSpecs = [];

        $previousCardTotal = 0.0;

        for ($i = 0; $i < self::MONTHS_OF_HISTORY; $i++) {
            $month = $firstMonth->copy()->addMonths($i);

            $cardMonth = $this->generateCardSpecs($month, $now);
            $cardSpecs = array_merge($cardSpecs, $cardMonth['specs']);

            $chequingSpecs = array_merge(
                $chequingSpecs,
                $this->generateChequingSpecs($month, $now, $previousCardTotal),
            );

            // This month's purchases are paid off from chequing next month.
            $cardMonthTotal = $cardMonth['total'];
            if ($previousCardTotal > 0) {
                $cardSpecs[] = $this->spec(
                    $month->copy()->addDays(22),
                    $now,
                    'PAIEMENT RECU - MERCI / PAYMENT RECEIVED - THANK YOU',
                    $previousCardTotal,
                    isExcluded: true,
                );
            }
            $previousCardTotal = $cardMonthTotal;
        }

        $chequingSpecs = array_values(array_filter($chequingSpecs));
        $cardSpecs = array_values(array_filter($cardSpecs));

        // Chequing running balance, in chronological order.
        usort($chequingSpecs, fn ($a, $b) => $a['date'] <=> $b['date']);
        $balance = self::OPENING_BALANCE;
        $invested = 0.0;
        foreach ($chequingSpecs as &$spec) {
            $balance = round($balance + $spec['amount'], 2);
            $spec['balance_after'] = $balance;
            if (str_contains($spec['description'], 'PLACEMENT')) {
                $invested = round($invested - $spec['amount'], 2);
            }
        }
        unset($spec);

        $batches = $this->createBatches($chequing, $card, $firstMonth);

        $this->persistSpecs($chequingSpecs, $chequing, $batches['chequing'], $matcher, $normalizer, $categories);
        $this->persistSpecs($cardSpecs, $card, $batches['card'], $matcher, $normalizer, $categories);

        $this->finalizeBatches($batches);

        $chequing->update(['current_balance' => $balance]);
        $card->update(['current_balance' => round((float) Transaction::where('bank_account_id', $card->id)->sum('amount'), 2)]);

        return round($balance + $invested, 2);
    }

    /**
     * Chequing rows for one month: salary in, fixed bills out, card payment, investment.
     *
     * @return array<int, array<string, mixed>|null>
     */
    private function generateChequingSpecs(Carbon $month, Carbon $now, float $previousCardTotal): array
    {
        $specs = [];

        // Bi-weekly salary (two per month, an occasional third "3-paycheque" month).
        $specs[] = $this->spec($month->copy()->addDays(3), $now, 'DEPOT SALAIRE ACME CORP', self::NET_PER_PAYCHEQUE);
        $specs[] = $this->spec($month->copy()->addDays(17), $now, 'DEPOT SALAIRE ACME CORP', self::NET_PER_PAYCHEQUE);
        if ($month->month % 6 === 0) {
            $specs[] = $this->spec($month->copy()->addDays(27), $now, 'DEPOT SALAIRE ACME CORP', self::NET_PER_PAYCHEQUE);
        }

        // Fixed bills (rent has no clean rule in the real set, so we categorize it explicitly).
        $specs[] = $this->spec($month->copy()->startOfMonth(), $now, 'PAIEMENT PREAUTORISE LOYER', -self::RENT, categoryName: 'Rent');
        $specs[] = $this->spec($month->copy()->addDays(18), $now, 'HYDRO-QUEBEC PAIEMENT PREAUTORISE', -$this->hydroAmount($month));
        $specs[] = $this->spec($month->copy()->addDays(14), $now, 'BELL CANADA PAIEMENT PREAUTORISE', -89.99);
        $specs[] = $this->spec($month->copy()->addDays(9), $now, 'FIDO MOBILE PAIEMENT', -55.00);
        $specs[] = $this->spec($month->copy()->addDays(27), $now, 'ASSURANCE HABITATION PREAUTORISE', -42.00);

        // Streaming / tooling subscriptions.
        $specs[] = $this->spec($month->copy()->addDays(5), $now, 'SPOTIFY P0ABC123', -11.99);
        $specs[] = $this->spec($month->copy()->addDays(7), $now, 'YOUTUBEPREMIUM', -13.99);
        $specs[] = $this->spec($month->copy()->addDays(11), $now, 'GITHUB INC', -5.40);
        $specs[] = $this->spec($month->copy()->addDays(19), $now, 'BETTERME LIMITED', -6.99);

        // Monthly investment contribution → the Investments category (kind=investment)
        // so the demo's needs/wants meter shows a real ~10% investment slice rather
        // than an out-of-whack $0 (the contribution is a genuine outflow, not excluded).
        $specs[] = $this->spec($month->copy()->addDays(23), $now, 'VIREMENT PLACEMENT CELI', -self::MONTHLY_INVESTMENT, categoryName: 'Investments');

        // Pay off last month's card statement in full.
        if ($previousCardTotal > 0) {
            $specs[] = $this->spec($month->copy()->addDays(21), $now, 'MOBILE PAIEM. MASTERCARD', -$previousCardTotal, isExcluded: true);
        }

        // Occasional outgoing e-transfer.
        if ($month->month % 3 === 1) {
            $specs[] = $this->spec($month->copy()->addDays(15), $now, 'VIREMENT INTERAC ENVOI', -fake()->randomFloat(2, 25, 120), isExcluded: true);
        }

        return $specs;
    }

    /**
     * Credit-card purchases for one month.
     *
     * @return array{specs: array<int, array<string, mixed>>, total: float}
     */
    private function generateCardSpecs(Carbon $month, Carbon $now): array
    {
        $specs = [];

        $groceries = ['WMT SUPRCTR #3987 QC', 'METRO 4521 QC'];
        $restaurants = [
            'MCDONALDS #4123 QC', 'WENDYS 5567 QC', 'A&W #221 QC', 'DOORDASH*ORDER',
            'RESTO BURGER PLUS QC', 'GRILL MAISON QC', 'BISTRO CENTRAL RESTO QC', 'DEJEUNER SOLEIL QC',
        ];
        $gas = ['SHELL C12345 QC', 'COUCHETARD #6677 QC'];
        $shopping = [
            'AMZN MKTP CA*1A2B3', 'AMAZON.CA*4C5D6', 'DOLLARAMA #455 QC',
            'CANADIAN TIRE #321 QC', 'WINNERS #210 QC', 'RONA #89 QC',
        ];
        $entertainment = ['BLIZZARD ENTERTAINMENT', 'SQUARE ENIX'];
        $other = ['SQDC QC', 'SAQ #232 QC'];
        $health = ['PHARMAPRIX #123 QC', 'JEAN COUTU #45 QC'];

        // Weekly groceries.
        foreach ([3, 10, 17, 24] as $offset) {
            $specs[] = $this->spec($month->copy()->addDays($offset), $now, fake()->randomElement($groceries), -fake()->randomFloat(2, 70, 165));
        }

        // Restaurants, gas, shopping, plus the lighter categories.
        $specs = array_merge(
            $specs,
            $this->scatter($month, $now, 6, $restaurants, 12, 68),
            $this->scatter($month, $now, fake()->numberBetween(2, 3), $gas, 45, 82),
            $this->scatter($month, $now, fake()->numberBetween(2, 3), $shopping, 15, 140),
            $this->scatter($month, $now, fake()->numberBetween(0, 2), $entertainment, 15, 75),
            $this->scatter($month, $now, fake()->numberBetween(1, 2), $other, 18, 85),
            $this->scatter($month, $now, fake()->numberBetween(0, 1), $health, 10, 55, categoryName: 'Health'),
        );

        $specs = array_values(array_filter($specs));
        $total = round(abs(array_sum(array_column($specs, 'amount'))), 2);

        return ['specs' => $specs, 'total' => $total];
    }

    /**
     * Scatter N purchases across a month from a merchant pool.
     *
     * @param  array<int, string>  $merchants
     * @return array<int, array<string, mixed>|null>
     */
    private function scatter(Carbon $month, Carbon $now, int $count, array $merchants, float $min, float $max, ?string $categoryName = null): array
    {
        $specs = [];
        for ($n = 0; $n < $count; $n++) {
            $day = fake()->numberBetween(1, min(28, $month->daysInMonth));
            $specs[] = $this->spec(
                $month->copy()->startOfMonth()->addDays($day - 1),
                $now,
                fake()->randomElement($merchants),
                -fake()->randomFloat(2, $min, $max),
                categoryName: $categoryName,
            );
        }

        return $specs;
    }

    /**
     * Build a single transaction spec, or null when the date is in the future
     * (keeps the current partial month realistic). Each spec gets a unique time.
     *
     * @return array<string, mixed>|null
     */
    private function spec(Carbon $day, Carbon $now, string $description, float $amount, ?string $categoryName = null, bool $isExcluded = false): ?array
    {
        $secondOfDay = ($this->sequence++ * 37) % 86400;
        $date = $day->copy()->startOfDay()->addSeconds($secondOfDay);

        // Drop only days strictly after today — compared day-granular, NOT by the
        // intra-day time-of-day. A datetime-level comparison against now() made the
        // seeded count depend on the wall-clock SECOND of the seed (a current-day
        // spec flips kept↔dropped as now() advances), so two seeds milliseconds
        // apart produced different counts — a flaky non-idempotency.
        if ($day->copy()->startOfDay()->greaterThan($now->copy()->startOfDay())) {
            return null;
        }

        return [
            'date' => $date,
            'description' => $description,
            'amount' => round($amount, 2),
            'category_name' => $categoryName,
            'is_excluded' => $isExcluded,
            'balance_after' => null,
        ];
    }

    private function hydroAmount(Carbon $month): float
    {
        return match (true) {
            in_array($month->month, [12, 1, 2, 3], true) => fake()->randomFloat(2, 120, 165),
            in_array($month->month, [6, 7, 8], true) => fake()->randomFloat(2, 55, 85),
            default => fake()->randomFloat(2, 85, 115),
        };
    }

    /**
     * @return array{chequing: array<string, ImportBatch>, card: array<string, ImportBatch>}
     */
    private function createBatches(BankAccount $chequing, BankAccount $card, Carbon $firstMonth): array
    {
        $batches = ['chequing' => [], 'card' => []];

        for ($i = 0; $i < self::MONTHS_OF_HISTORY; $i++) {
            $month = $firstMonth->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $stamp = $month->format('Y-m');

            $batches['chequing'][$key] = ImportBatch::create([
                'bank_account_id' => $chequing->id,
                'period_year' => $month->year,
                'period_month' => $month->month,
                'filename' => "nbc-chequing-{$stamp}.pdf",
                'bank_format' => 'nbc_bank_pdf',
                'status' => 'processing',
            ]);

            $batches['card'][$key] = ImportBatch::create([
                'bank_account_id' => $card->id,
                'period_year' => $month->year,
                'period_month' => $month->month,
                'filename' => "nbc-mastercard-{$stamp}.pdf",
                'bank_format' => 'nbc_credit_card_pdf',
                'status' => 'processing',
            ]);
        }

        return $batches;
    }

    /**
     * @param  array<int, array<string, mixed>>  $specs
     * @param  array<string, ImportBatch>  $batches
     * @param  array<string, Category>  $categories
     */
    private function persistSpecs(array $specs, BankAccount $account, array $batches, CategoryMatcher $matcher, MerchantNormalizer $normalizer, array $categories): void
    {
        foreach ($specs as $spec) {
            /** @var Carbon $date */
            $date = $spec['date'];
            $dateString = $date->format('Y-m-d H:i:s');
            $batch = $batches[$date->format('Y-m')];

            $categoryId = $spec['category_name'] !== null
                ? $categories[$spec['category_name']]->id
                : $matcher->match($spec['description']);

            Transaction::create([
                'bank_account_id' => $account->id,
                'import_batch_id' => $batch->id,
                'category_id' => $categoryId,
                'transaction_date' => $dateString,
                'description' => $spec['description'],
                'merchant_name' => $normalizer->normalize($spec['description']),
                'amount' => $spec['amount'],
                'balance_after' => $spec['balance_after'],
                'is_excluded' => $spec['is_excluded'],
                'hash' => Transaction::generateHash($dateString, $spec['description'], $spec['amount']),
            ]);
        }
    }

    /**
     * @param  array{chequing: array<string, ImportBatch>, card: array<string, ImportBatch>}  $batches
     */
    private function finalizeBatches(array $batches): void
    {
        foreach (['chequing', 'card'] as $kind) {
            foreach ($batches[$kind] as $batch) {
                $count = Transaction::where('import_batch_id', $batch->id)->count();
                $batch->update([
                    'total_rows' => $count,
                    'imported_count' => $count,
                    'duplicate_count' => 0,
                    'skipped_count' => 0,
                    'status' => 'completed',
                    'imported_at' => Carbon::create($batch->period_year, $batch->period_month, 1)->endOfMonth(),
                ]);
            }
        }
    }

    private function seedSavingsMilestones(float $finalSavings): void
    {
        $targets = [1000, 5000, 10000, 25000, 50000, 100000];
        $firstMonth = now()->startOfMonth()->subMonths(self::MONTHS_OF_HISTORY - 1);

        foreach ($targets as $sort => $target) {
            $reached = $finalSavings >= $target;
            // Spread reached dates across the history proportional to the target.
            $reachedAt = $reached
                ? $firstMonth->copy()->addMonths((int) min(self::MONTHS_OF_HISTORY - 1, round($target / max($finalSavings, 1) * (self::MONTHS_OF_HISTORY - 1))))
                : null;

            SavingsMilestone::create([
                'name' => '$'.number_format($target / 1000).'K saved',
                'target_amount' => $target,
                'reached_at' => $reachedAt,
                'is_reached' => $reached,
                'sort_order' => $sort,
            ]);
        }
    }

    private function seedYearlySnapshots(): void
    {
        // Aggregate in PHP so this works on both MySQL (dev) and SQLite (tests).
        $byYear = [];
        Transaction::where('is_excluded', false)
            ->get(['transaction_date', 'amount'])
            ->each(function (Transaction $transaction) use (&$byYear): void {
                $year = $transaction->transaction_date->year;
                $amount = (float) $transaction->amount;
                $byYear[$year]['income'] = ($byYear[$year]['income'] ?? 0.0) + max($amount, 0);
                $byYear[$year]['expenses'] = ($byYear[$year]['expenses'] ?? 0.0) + max(-$amount, 0);
            });
        ksort($byYear);

        $monthlyFixed = (float) FixedExpense::sum('amount');
        $previousRates = null;

        foreach ($byYear as $year => $totals) {
            $income = round($totals['income'], 2);
            $expenses = round($totals['expenses'], 2);
            $netSavings = round($income - $expenses, 2);
            $savingsRate = $income > 0 ? round($netSavings / $income * 100, 2) : 0.0;
            $expenseRatio = $income > 0 ? round($expenses / $income * 100, 2) : 0.0;
            $fixed = round(min($expenses, $monthlyFixed * 12), 2);

            $direction = 'stable';
            if ($previousRates !== null) {
                $savingsDelta = $savingsRate - $previousRates['savings_rate'];
                $expenseDelta = $expenseRatio - $previousRates['expense_ratio'];
                if (abs($savingsDelta) <= 2 && abs($expenseDelta) <= 2) {
                    $direction = 'stable';
                } elseif ($savingsDelta > 0 || $expenseDelta < 0) {
                    $direction = 'improving';
                } elseif ($savingsDelta < 0 && $expenseDelta > 0) {
                    $direction = 'declining';
                }
            }

            YearlySnapshot::create([
                'year' => (int) $year,
                'total_income' => $income,
                'total_expenses' => $expenses,
                'total_fixed' => $fixed,
                'total_variable' => round($expenses - $fixed, 2),
                'net_savings' => $netSavings,
                'savings_rate' => $savingsRate,
                'expense_ratio' => $expenseRatio,
                'life_direction' => $direction,
                'metadata' => [],
                'calculated_at' => now(),
            ]);

            $previousRates = ['savings_rate' => $savingsRate, 'expense_ratio' => $expenseRatio];
        }
    }
}
