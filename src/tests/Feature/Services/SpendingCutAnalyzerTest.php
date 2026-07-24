<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\IncomeEntry;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\SpendingCutAnalyzer;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/**
 * Create an expense transaction in a given month-offset from now.
 */
function cutExpense(User $user, Category $category, float $magnitude, int $monthsAgo = 0, string $description = 'MERCHANT', array $overrides = []): Transaction
{
    static $seq = 0;
    $seq++;
    $date = Carbon::now()->startOfMonth()->subMonths($monthsAgo)->addDays(2)->format('Y-m-d H:i:s');

    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'transaction_date' => $date,
        'description' => $description,
        'amount' => -abs($magnitude),
        'hash' => 'cut'.$seq,
        'is_excluded' => false,
    ], $overrides));
}

it('excludes one_time income from the monthly run-rate but keeps recurring entries', function () {
    // No UserProfile → income falls back to summing IncomeEntry rows by frequency.
    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'amount' => 2000, 'frequency' => 'monthly', 'pay_date' => now()->toDateString()]);
    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'amount' => 5000, 'frequency' => 'one_time', 'pay_date' => now()->toDateString()]);

    $projection = app(SpendingCutAnalyzer::class)->projection();

    // The $5,000 one-time receipt must not inflate the flat monthly run-rate.
    expect($projection['income_monthly'])->toBeWithin(2000.0, 0.01)
        ->and($projection['as_is']['projected_net_annual'])->toBeWithin(24000.0, 0.01); // 2000×12, no spend
});

it('ranks discretionary categories by spend and computes potential savings', function () {
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    // Restaurants (discretionary): $300 this month, named merchant.
    cutExpense($this->user, $restaurants, 200, 0, 'PIZZA PALACE');
    cutExpense($this->user, $restaurants, 100, 0, 'BURGER BARN');
    // Groceries (essential): $400 this month — bigger but essential, should not be a cut opportunity.
    cutExpense($this->user, $groceries, 400, 0, 'LOBLAWS');

    $result = app(SpendingCutAnalyzer::class)->analyze(1);

    // Discretionary vs essential split (current month).
    expect($result['discretionary_total'])->toBe(300.0)
        ->and($result['essential_total'])->toBe(400.0);

    // Restaurants is the top (only) discretionary cut opportunity.
    $opportunities = collect($result['cut_opportunities']);
    $restaurantOpp = $opportunities->firstWhere('category', 'Restaurants');
    expect($restaurantOpp)->not->toBeNull()
        ->and($restaurantOpp['monthly_spend'])->toBe(300.0);

    // Named merchants are surfaced for the discretionary category.
    $merchantNames = collect($restaurantOpp['top_merchants'])->pluck('description')->all();
    expect($merchantNames)->toContain('PIZZA PALACE');

    // Potential savings is a fraction of discretionary spend and positive.
    expect($result['potential_monthly_savings'])->toBeGreaterThan(0.0)
        ->and($result['potential_monthly_savings'])->toBeLessThanOrEqual(300.0);
});

it('reports a real mom_growth_pct from a day-31 anchor instead of the overflow-masked 0%', function () {
    // Anchor = 2026-05-31 (day-31). A plain subMonth()/subMonths() from here
    // overflows past April (30 days) into May 1, so previousMonth and the
    // lookback window silently collapse onto the current month.
    Carbon::setTestNow('2026-05-31 12:00:00');

    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping', 'kind' => 'want']);

    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $shopping->id,
        'transaction_date' => '2026-04-10 10:00:00', 'description' => 'SHOP', 'amount' => -100,
        'hash' => 'sca-w31-prev', 'is_excluded' => false,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $shopping->id,
        'transaction_date' => '2026-05-31 10:00:00', 'description' => 'SHOP', 'amount' => -200,
        'hash' => 'sca-w31-cur', 'is_excluded' => false,
    ]);

    $result = app(SpendingCutAnalyzer::class)->analyze(3);

    $opp = collect($result['cut_opportunities'])->firstWhere('category', 'Shopping');

    // A real month-over-month doubling ($100 -> $200) must read as ~+100%,
    // not the pre-fix 0% (previousMonth wrongly resolving to the current month).
    expect($opp)->not->toBeNull()
        ->and($opp['mom_growth_pct'])->toBeGreaterThan(50.0)
        ->and($opp['monthly_spend'])->toBe(200.0);

    Carbon::setTestNow(null);
});

it('treats a named category in neither list as unclassified, not cuttable', function () {
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);
    $pets = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Pets']); // in neither list

    cutExpense($this->user, $restaurants, 200, 0, 'PIZZA PALACE');
    cutExpense($this->user, $pets, 150, 0, 'PETSMART');

    $result = app(SpendingCutAnalyzer::class)->analyze(1);

    // Pets is unclassified: excluded from discretionary, savings, and opportunities;
    // surfaced on its own line instead of silently inflating "cuttable" spend.
    expect($result['discretionary_total'])->toBe(200.0)
        ->and($result['unclassified_total'])->toBe(150.0)
        ->and($result['potential_monthly_savings'])->toBe(60.0) // 200 * 0.30, not 350 * 0.30
        ->and(collect($result['cut_opportunities'])->pluck('category')->all())->not->toContain('Pets');
});

it('still excludes truly uncategorized (null-category) spend entirely', function () {
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);
    cutExpense($this->user, $restaurants, 200, 0, 'PIZZA PALACE');

    // A null-category expense is not "unclassified" — it never enters the analysis.
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => null,
        'transaction_date' => Carbon::now()->startOfMonth()->addDays(2)->format('Y-m-d H:i:s'),
        'amount' => -500,
        'hash' => 'nullcat1',
        'is_excluded' => false,
    ]);

    $result = app(SpendingCutAnalyzer::class)->analyze(1);

    expect($result['discretionary_total'])->toBe(200.0)
        ->and($result['unclassified_total'])->toBe(0.0);
});

it('flags categories that are over budget', function () {
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);
    Budget::factory()->forCategory($restaurants)->create(['amount' => 100]);

    // $250 spent against a $100 budget this month.
    cutExpense($this->user, $restaurants, 250, 0, 'DINER');

    $result = app(SpendingCutAnalyzer::class)->analyze(1);

    $over = collect($result['over_budget'])->firstWhere('category', 'Restaurants');
    expect($over)->not->toBeNull()
        ->and($over['spent'])->toBe(250.0)
        ->and($over['budget'])->toBe(100.0)
        ->and($over['overage'])->toBe(150.0);
});

it('reports total active subscription cost normalized to monthly', function () {
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'spotify',
        'cadence' => 'monthly',
        'expected_amount' => 10.99,
        'status' => 'active',
    ]);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'amazon prime',
        'cadence' => 'yearly',
        'expected_amount' => 120.00,
        'status' => 'active',
    ]);
    // Dismissed series must not count.
    RecurringSeries::factory()->dismissed()->create([
        'user_id' => $this->user->id,
        'cadence' => 'monthly',
        'expected_amount' => 99.99,
    ]);

    $result = app(SpendingCutAnalyzer::class)->analyze(1);

    // 10.99 + (120 / 12) = 10.99 + 10.00 = 20.99
    expect($result['subscription_cost'])->toBe(20.99);
});

it('computes month-over-month growth for discretionary categories', function () {
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);

    cutExpense($this->user, $shopping, 100, 1, 'STORE A'); // last month
    cutExpense($this->user, $shopping, 150, 0, 'STORE A'); // this month

    $result = app(SpendingCutAnalyzer::class)->analyze(2);

    $opp = collect($result['cut_opportunities'])->firstWhere('category', 'Shopping');
    expect($opp)->not->toBeNull()
        // This-month spend is 150; previous month 100 → +50% growth.
        ->and($opp['monthly_spend'])->toBe(150.0)
        ->and($opp['mom_growth_pct'])->toBe(50.0);
});

it('excludes transfers, excluded transactions, and income', function () {
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);

    // Real discretionary expense.
    cutExpense($this->user, $restaurants, 100, 0, 'REAL RESTO');

    // Income (positive) — ignored.
    cutExpense($this->user, $restaurants, -500, 0, 'PAYCHECK', ['amount' => 500.00]);

    // Excluded — ignored.
    cutExpense($this->user, $restaurants, 999, 0, 'EXCLUDED', ['is_excluded' => true]);

    // Transfer-linked — ignored.
    $transfer = Transfer::factory()->create(['user_id' => $this->user->id]);
    cutExpense($this->user, $restaurants, 888, 0, 'TRANSFER', ['transfer_id' => $transfer->id]);

    $result = app(SpendingCutAnalyzer::class)->analyze(1);

    expect($result['discretionary_total'])->toBe(100.0);
});

it('anchors the analysis to the latest data month, not the empty live month', function () {
    // "Today" is June 7 — an empty, in-progress month with no transactions.
    Carbon::setTestNow('2026-06-07 10:00:00');

    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    // May (anchor month): $300 restaurants (discretionary) + $400 groceries (essential).
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $restaurants->id,
        'transaction_date' => '2026-05-04 00:00:00', 'description' => 'PIZZA PALACE',
        'amount' => -300.00, 'hash' => 'anc-may-r', 'is_excluded' => false,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $groceries->id,
        'transaction_date' => '2026-05-04 00:00:00', 'description' => 'LOBLAWS',
        'amount' => -400.00, 'hash' => 'anc-may-g', 'is_excluded' => false,
    ]);
    // April: $200 restaurants — drives MoM growth off the anchored previous month.
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $restaurants->id,
        'transaction_date' => '2026-04-04 00:00:00', 'description' => 'PIZZA PALACE',
        'amount' => -200.00, 'hash' => 'anc-apr-r', 'is_excluded' => false,
    ]);

    $result = app(SpendingCutAnalyzer::class)->analyze(3);

    expect($result['current_month'])->toBe('2026-05')
        ->and($result['discretionary_total'])->toBe(300.0)
        ->and($result['essential_total'])->toBe(400.0);

    $opp = collect($result['cut_opportunities'])->firstWhere('category', 'Restaurants');
    expect($opp)->not->toBeNull()
        ->and($opp['monthly_spend'])->toBe(300.0)
        // May $300 vs April $200 → +50% MoM, proving the anchored previous-month lookup lines up.
        ->and($opp['mom_growth_pct'])->toBe(50.0);
});

it('handles the no-data case gracefully', function () {
    $result = app(SpendingCutAnalyzer::class)->analyze(3);

    expect($result['discretionary_total'])->toBe(0.0)
        ->and($result['essential_total'])->toBe(0.0)
        ->and($result['cut_opportunities'])->toBe([])
        ->and($result['over_budget'])->toBe([])
        ->and($result['subscription_cost'])->toBe(0.0)
        ->and($result['potential_monthly_savings'])->toBe(0.0);
});

/**
 * Place a categorized expense on a specific calendar date (Y-m-d).
 */
function projTxn(User $user, ?Category $category, float $signedAmount, string $date, string $description = 'MERCHANT', array $overrides = []): Transaction
{
    static $seq = 0;
    $seq++;

    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'category_id' => $category?->id,
        'transaction_date' => $date.' 00:00:00',
        'description' => $description,
        'amount' => $signedAmount,
        'hash' => 'proj'.$seq,
        'is_excluded' => false,
    ], $overrides));
}

it('anchors the projection to the basis month and sums fixed-expense monthly equivalents', function () {
    // "Today" is an empty June; all data lives in May (the basis month).
    Carbon::setTestNow('2026-06-07 10:00:00');

    $rent = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Rent']);
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);

    // Active fixed expenses: $1,675/mo rent + $120/yr (=$10/mo) → fixed_monthly = 1685.
    FixedExpense::factory()->forCategory($rent)->create(['amount' => 1675, 'frequency' => 'monthly', 'is_active' => true]);
    FixedExpense::factory()->forCategory($rent)->frequency('yearly')->create(['amount' => 120, 'is_active' => true]);
    // Inactive fixed expense must not count.
    FixedExpense::factory()->forCategory($rent)->create(['amount' => 9999, 'frequency' => 'monthly', 'is_active' => false]);

    // Basis-month (May) spend: $300 restaurants (variable) + $1,675 rent (fixed category → excluded from variable).
    projTxn($this->user, $restaurants, -300, '2026-05-04', 'PIZZA');
    projTxn($this->user, $rent, -1675, '2026-05-01', 'LANDLORD');

    $projection = app(SpendingCutAnalyzer::class)->analyze(6)['projection'];

    expect($projection['basis_month'])->toBe('2026-05')
        ->and($projection['fixed_monthly'])->toBe(1685.0)
        // Variable excludes the rent category (a FixedExpense target), keeps restaurants.
        ->and($projection['variable_monthly'])->toBe(300.0);
});

it('drops excluded transactions from variable_monthly', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);

    projTxn($this->user, $restaurants, -300, '2026-05-04', 'KEPT');
    // A move-in purchase the user manually excluded must not count toward run-rate.
    projTxn($this->user, $restaurants, -500, '2026-05-05', 'EXCLUDED MOVE-IN', ['is_excluded' => true]);

    $projection = app(SpendingCutAnalyzer::class)->analyze(6)['projection'];

    expect($projection['variable_monthly'])->toBe(300.0);
});

it('flags a one-time spike, normalizes it to baseline, and as_is keeps it', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    $furniture = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Furniture']);

    // Prior months (baseline): Feb/Mar/Apr each $100 → median 100.
    projTxn($this->user, $furniture, -100, '2026-02-10', 'IKEA');
    projTxn($this->user, $furniture, -100, '2026-03-10', 'IKEA');
    projTxn($this->user, $furniture, -100, '2026-04-10', 'IKEA');
    // Basis month (May): $1,000 — a move-in spike, well above 100 * 1.5.
    projTxn($this->user, $furniture, -1000, '2026-05-10', 'IKEA');

    $projection = app(SpendingCutAnalyzer::class)->analyze(6)['projection'];

    $flag = collect($projection['flags'])->firstWhere('category', 'Furniture');
    expect($flag)->not->toBeNull()
        ->and($flag['baseline'])->toBe(100.0)
        ->and($flag['last_month'])->toBe(1000.0)
        ->and($flag['excess'])->toBe(900.0);

    // as_is keeps the full $1,000; normalized strips the $900 excess down to $100.
    expect($projection['variable_monthly'])->toBe(1000.0)
        ->and($projection['variable_monthly_normalized'])->toBe(100.0)
        ->and($projection['as_is']['monthly'])->toBe(1000.0)
        ->and($projection['normalized']['monthly'])->toBe(100.0);
});

it('does not flag a spike with fewer than two prior months of data', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    $furniture = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Furniture']);

    // Only ONE prior month, then a big basis month — not enough history to flag.
    projTxn($this->user, $furniture, -100, '2026-04-10', 'IKEA');
    projTxn($this->user, $furniture, -1000, '2026-05-10', 'IKEA');

    $projection = app(SpendingCutAnalyzer::class)->analyze(6)['projection'];

    expect($projection['flags'])->toBe([])
        ->and($projection['variable_monthly'])->toBe(1000.0)
        ->and($projection['variable_monthly_normalized'])->toBe(1000.0);
});

it('projects monthly forward using the maintained net pay from UserProfile, not basis-month deposits', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Rent']);

    FixedExpense::factory()->forCategory($rent)->create(['amount' => 1000, 'frequency' => 'monthly', 'is_active' => true]);

    // Maintained net pay: $2,000 bi-weekly → 2000 * 26 / 12 = 4333.33/mo.
    UserProfile::factory()->forUser($this->user)->create([
        'net_pay_per_period' => 2000,
        'pay_frequency' => 'bi_weekly',
    ]);

    // Variable run-rate: $500 restaurants in May.
    projTxn($this->user, $restaurants, -500, '2026-05-04', 'DINER');
    // A noisy basis month with only TWO paycheques — must NOT drive income now.
    projTxn($this->user, null, 4000, '2026-05-15', 'PAYCHECK');

    $projection = app(SpendingCutAnalyzer::class)->analyze(6)['projection'];

    $monthlyNet = 2000 * 26 / 12;          // 4333.333… (unrounded run-rate)
    $expectedIncome = round($monthlyNet, 2); // 4333.33 (displayed figure)

    // monthly = fixed 1000 + variable 500 = 1500.
    expect($projection['as_is']['monthly'])->toBe(1500.0)
        ->and($projection['as_is']['three_month'])->toBe(4500.0)
        ->and($projection['as_is']['annual'])->toBe(18000.0)
        // Income now comes from the maintained net pay, not the $4,000 of May deposits.
        ->and($projection['income_monthly'])->toBe($expectedIncome)
        ->and($projection['as_is']['projected_net_annual'])->toBe(round($monthlyNet * 12 - 18000, 2));
});

it('surfaces a large infrequent purchase as a one-time suggestion to exclude', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    $furniture = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Furniture']);
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    // A single $2,000 furniture charge two months ago — a move-in spike that
    // landed OUTSIDE the basis month, so the per-category spike detector misses it.
    projTxn($this->user, $furniture, -2000, '2026-04-12', 'NUASIS FURNITURE');

    // Lots of small, frequent grocery purchases — the "usual" noise the
    // median-times-k floor must keep out of the suggestions.
    foreach (['2026-04-03', '2026-04-10', '2026-04-17', '2026-04-24', '2026-05-01', '2026-05-08'] as $i => $date) {
        projTxn($this->user, $groceries, -80 - $i, $date, 'LOBLAWS');
    }

    $suggestions = app(SpendingCutAnalyzer::class)->analyze(6)['one_time_suggestions'];

    expect($suggestions)->toBeArray()->not->toBeEmpty();

    $top = collect($suggestions)->firstWhere('merchant', 'NUASIS FURNITURE');
    expect($top)->not->toBeNull()
        ->and($top['amount'])->toBe(2000.0)
        ->and($top['category'])->toBe('Furniture')
        ->and($top['transaction_date'])->toBe('2026-04-12')
        ->and($top)->toHaveKeys(['id', 'transaction_date', 'merchant', 'category', 'amount', 'reason'])
        ->and($top['reason'])->toBeString()->not->toBe('');

    // The frequent grocery merchant is never suggested, regardless of total.
    expect(collect($suggestions)->pluck('merchant'))->not->toContain('LOBLAWS');
});

it('ignores recurring series, already-excluded rows, income, and transfers as one-time suggestions', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);

    // A large charge whose merchant is an ACTIVE recurring series — must be skipped
    // even though it is large and infrequent (it's a known subscription/bill).
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'acme insurance',
        'cadence' => 'monthly',
        'expected_amount' => 900,
        'status' => 'active',
    ]);
    projTxn($this->user, $shopping, -900, '2026-04-15', 'ACME INSURANCE');

    // Already excluded — gone from every aggregation, must not be re-suggested.
    projTxn($this->user, $shopping, -1500, '2026-04-16', 'JYSK', ['is_excluded' => true]);

    // Income (positive) — never a spend suggestion.
    projTxn($this->user, null, 5000, '2026-04-17', 'PAYCHECK');

    // Transfer-linked — internal movement, not a real expense.
    $transfer = Transfer::factory()->create(['user_id' => $this->user->id]);
    projTxn($this->user, $shopping, -3000, '2026-04-18', 'SAVINGS TRANSFER', ['transfer_id' => $transfer->id]);

    $suggestions = app(SpendingCutAnalyzer::class)->analyze(6)['one_time_suggestions'];

    $merchants = collect($suggestions)->pluck('merchant');
    expect($merchants)->not->toContain('ACME INSURANCE')
        ->and($merchants)->not->toContain('JYSK')
        ->and($merchants)->not->toContain('PAYCHECK')
        ->and($merchants)->not->toContain('SAVINGS TRANSFER');
});

it('does not suggest large money-movement debits (transfers, investments) as one-time purchases', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    $transfers = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transfers']);
    $furniture = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Furniture']);

    // Everyday small spend keeps the window median low (so the $200 floor governs).
    $coffee = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Coffee']);
    foreach (['2026-03-01', '2026-03-08', '2026-03-15', '2026-03-22', '2026-04-01', '2026-04-08'] as $i => $date) {
        projTxn($this->user, $coffee, -5 - $i, $date, 'CAFE'.$i);
    }

    // A big transfer-category debit that is NOT transfer-linked (so it passes the
    // transfer_id filter) — it's money movement, not a purchase. Must be skipped.
    projTxn($this->user, $transfers, -8000, '2026-03-10', 'PLACEMENT');
    // A genuine large one-time purchase that SHOULD surface, to prove the filter
    // is category-specific and not just dropping everything.
    projTxn($this->user, $furniture, -2000, '2026-03-12', 'NUASIS');

    $suggestions = app(SpendingCutAnalyzer::class)->analyze(6)['one_time_suggestions'];
    $merchants = collect($suggestions)->pluck('merchant');

    expect($merchants)->not->toContain('PLACEMENT')
        ->and($merchants)->toContain('NUASIS');
});

it('respects the dollar floor / multiplier — a small infrequent charge is not suggested', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);

    // A handful of normal-sized purchases; none clears the $200 dollar floor, so
    // even a one-off $150 buy stays out of the suggestions.
    projTxn($this->user, $shopping, -50, '2026-04-03', 'STORE A');
    projTxn($this->user, $shopping, -60, '2026-04-10', 'STORE B');
    projTxn($this->user, $shopping, -150, '2026-04-17', 'STORE C ONE OFF');

    $suggestions = app(SpendingCutAnalyzer::class)->analyze(6)['one_time_suggestions'];

    expect($suggestions)->toBe([]);
});

it('sources income_monthly from the fallback chain: UserProfile, then IncomeEntry, then basis-month deposits', function () {
    Carbon::setTestNow('2026-06-07 10:00:00');

    // Basis-month deposits (the legacy source) — $4,000 in May.
    projTxn($this->user, null, 4000, '2026-05-15', 'PAYCHECK');

    // 1. With a UserProfile → uses its monthly_net accessor.
    $profile = UserProfile::factory()->forUser($this->user)->create([
        'net_pay_per_period' => 2200,
        'pay_frequency' => 'bi_weekly',
    ]);
    $expectedProfileNet = round(2200 * 26 / 12, 2); // 4766.67
    expect(app(SpendingCutAnalyzer::class)->analyze(6)['projection']['income_monthly'])
        ->toBe($expectedProfileNet);

    // 2. No profile, but IncomeEntry rows → sum of their monthly-equivalents.
    $profile->delete();
    IncomeEntry::factory()->biWeekly()->create(['user_id' => $this->user->id, 'amount' => 1500]); // 1500 * 26/12 = 3250
    IncomeEntry::factory()->monthly()->create(['user_id' => $this->user->id, 'amount' => 500]);   // 500
    $expectedEntries = round(1500 * 26 / 12 + 500, 2); // 3750
    expect(app(SpendingCutAnalyzer::class)->analyze(6)['projection']['income_monthly'])
        ->toBe($expectedEntries);

    // 3. Neither profile nor income entries → fall back to basis-month deposits.
    IncomeEntry::query()->delete();
    expect(app(SpendingCutAnalyzer::class)->analyze(6)['projection']['income_monthly'])
        ->toBe(4000.0);
});

it('falls to UserProfile.monthly_net instead of zeroing income when only gross IncomeEntry rows exist', function () {
    IncomeEntry::factory()->gross()->create(['user_id' => $this->user->id, 'amount' => 5000, 'frequency' => 'monthly']);
    UserProfile::factory()->forUser($this->user)->create(['net_pay_per_period' => 2200, 'pay_frequency' => 'bi_weekly']);

    $projection = app(SpendingCutAnalyzer::class)->projection();

    $expectedNet = round(2200 * 26 / 12, 2); // 4766.67 — NOT the gross entry's 5000, NOT 0.
    expect($projection['income_monthly'])->toBe($expectedNet)
        ->and($projection['income_warning'])->toBeNull();
});

it('prefers is_net=true IncomeEntry rows over gross rows in the income fallback', function () {
    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'amount' => 4000, 'frequency' => 'monthly', 'is_net' => true]);
    IncomeEntry::factory()->gross()->create(['user_id' => $this->user->id, 'amount' => 9000, 'frequency' => 'monthly']);

    $projection = app(SpendingCutAnalyzer::class)->projection();

    expect($projection['income_monthly'])->toBe(4000.0)
        ->and($projection['income_warning'])->toBeNull();
});

it('uses gross IncomeEntry rows with a data-quality warning when no net rows or profile exist', function () {
    IncomeEntry::factory()->gross()->create(['user_id' => $this->user->id, 'amount' => 5000, 'frequency' => 'monthly']);

    $projection = app(SpendingCutAnalyzer::class)->projection();

    expect($projection['income_monthly'])->toBe(5000.0)
        ->and($projection['income_warning'])->not->toBeNull();
});

it('classifies by the category kind when set, overriding the name-based lists', function () {
    // "Pets" is in NEITHER name list (normally unclassified) but kind=want → discretionary.
    $pets = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Pets', 'kind' => 'want']);
    // "Restaurants" is discretionary BY NAME, but kind=need flips it to essential.
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants', 'kind' => 'need']);

    cutExpense($this->user, $pets, 200, 0, 'PETSMART');
    cutExpense($this->user, $restaurants, 300, 0, 'PIZZA PALACE');

    $result = app(SpendingCutAnalyzer::class)->analyze(1);

    expect($result['discretionary_total'])->toBe(200.0)   // Pets, via kind
        ->and($result['essential_total'])->toBe(300.0)    // Restaurants, via kind
        ->and($result['unclassified_total'])->toBe(0.0);
});

it('falls back to the name lists when kind is null', function () {
    // No kind set → name-based classification still applies.
    $restaurants = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants', 'kind' => null]);
    $pets = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Pets', 'kind' => null]);

    cutExpense($this->user, $restaurants, 250, 0, 'PIZZA PALACE');
    cutExpense($this->user, $pets, 150, 0, 'PETSMART');

    $result = app(SpendingCutAnalyzer::class)->analyze(1);

    expect($result['discretionary_total'])->toBe(250.0)   // Restaurants by name
        ->and($result['unclassified_total'])->toBe(150.0); // Pets unrecognised
});

it('inherits a parent category kind when a child kind is null', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'FoodTopic', 'kind' => 'want']);
    // Child has no kind and a name in neither list → would be unclassified, but inherits parent want.
    $child = Category::factory()->childOf($food)->create(['name' => 'SnackRun', 'kind' => null]);

    cutExpense($this->user, $child, 120, 0, 'VENDING');

    $result = app(SpendingCutAnalyzer::class)->analyze(1);

    expect($result['discretionary_total'])->toBe(120.0)
        ->and($result['unclassified_total'])->toBe(0.0);
});
