<?php

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\IncomeEntry;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\BudgetRuleAnalyzer;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
    Carbon::setTestNow('2026-06-15 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow(null);
});

function bra(): array
{
    return app(BudgetRuleAnalyzer::class)->analyze();
}

function braCat(User $user, string $name, ?string $kind): Category
{
    return Category::factory()->create(['user_id' => $user->id, 'name' => $name, 'kind' => $kind]);
}

function braTxn(User $user, ?Category $category, float $signed, string $date): Transaction
{
    static $seq = 0;
    $seq++;

    return Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category?->id,
        'transaction_date' => $date.' 10:00:00',
        'description' => 'BRA'.$seq,
        'amount' => $signed,
        'hash' => 'bra'.$seq,
        'is_excluded' => false,
    ]);
}

/** Put a steady expense in a category across 3 complete months (Jun–Aug 2025). */
function braSteady(User $user, Category $category, float $magnitude): void
{
    foreach (['2025-06-10', '2025-07-10', '2025-08-10'] as $d) {
        braTxn($user, $category, -$magnitude, $d);
    }
}

/** Steady positive income across the same 3 months; last tx sets the (complete) anchor. */
function braIncome(User $user, float $perMonth, array $overrideMonthly = []): void
{
    $income = braCat($user, 'Income', 'income');
    $amounts = array_replace(['2025-06-28' => $perMonth, '2025-07-28' => $perMonth, '2025-08-28' => $perMonth], $overrideMonthly);
    foreach ($amounts as $d => $amt) {
        braTxn($user, $income, $amt, $d);
    }
}

it('buckets actuals by effective kind, drops excluded, surfaces unclassified', function () {
    $need = braCat($this->user, 'Groceries', 'need');
    $want = braCat($this->user, 'Restaurants', 'want');
    $invest = braCat($this->user, 'Investments', 'investment');
    $transfers = braCat($this->user, 'Transfers', 'excluded');
    $other = braCat($this->user, 'Other', null);

    braSteady($this->user, $need, 300);
    braSteady($this->user, $want, 200);
    braSteady($this->user, $invest, 400);
    braSteady($this->user, $transfers, 999);
    braSteady($this->user, $other, 50);
    braIncome($this->user, 5000);

    $r = bra();

    expect($r['window']['months'])->toBe(3)
        ->and($r['kinds']['need']['amount'])->toBe(300.0)
        ->and($r['kinds']['want']['amount'])->toBe(200.0)
        ->and($r['kinds']['investment']['amount'])->toBe(400.0)
        ->and($r['unclassified_monthly'])->toBe(50.0)        // 'Other' shown, not in % math
        ->and($r['income_monthly'])->toBe(5000.0)
        ->and($r['kinds']['saving']['amount'])->toBe(4100.0); // 5000 − 300 − 200 − 400
});

it('folds a null-category expense into the visible unclassified line and count, not silently dropped', function () {
    $need = braCat($this->user, 'Groceries', 'need');
    braSteady($this->user, $need, 300);
    braIncome($this->user, 5000);

    // A null-category_id expense across all 3 window months — previously
    // dropped entirely by `whereNotNull('category_id')`.
    braTxn($this->user, null, -75, '2025-06-05');
    braTxn($this->user, null, -75, '2025-07-05');
    braTxn($this->user, null, -75, '2025-08-05');

    $r = bra();

    expect($r['unclassified_monthly'])->toBe(75.0)
        ->and($r['unclassified_count'])->toBe(3)
        ->and($r['kinds']['need']['amount'])->toBe(300.0); // unaffected
});

it('child category inherits the parent kind when its own kind is null', function () {
    $food = braCat($this->user, 'Food', 'need');
    $child = Category::factory()->childOf($food)->create(['name' => 'Groceries', 'kind' => null]);

    braSteady($this->user, $child, 250);
    braIncome($this->user, 4000);

    $r = bra();

    expect($r['kinds']['need']['amount'])->toBe(250.0)
        ->and($r['unclassified_monthly'])->toBe(0.0);
});

it('strips a one-time lump month via the in-window median, keeping normal months', function () {
    $want = braCat($this->user, 'Shopping', 'want');
    braTxn($this->user, $want, -200, '2025-06-10');
    braTxn($this->user, $want, -200, '2025-07-10');
    braTxn($this->user, $want, -5000, '2025-08-10'); // furniture lump
    braIncome($this->user, 6000);

    $r = bra();

    // median(200,200,5000)=200; Aug excess 4800 stripped → want = (200+200+200)/3 = 200.
    expect($r['kinds']['want']['amount'])->toBe(200.0)
        ->and($r['stripped']['by_kind']['want'])->toBe(1600.0)   // 4800/3
        ->and($r['stripped']['lump_total'])->toBe(1600.0);
});

it('honors the $1,000 lump floor (a $999 excess stays, a $1,001 excess is stripped)', function () {
    $kept = braCat($this->user, 'Shopping', 'want');
    braTxn($this->user, $kept, -200, '2025-06-10');
    braTxn($this->user, $kept, -200, '2025-07-10');
    braTxn($this->user, $kept, -1199, '2025-08-10'); // excess 999 < floor → kept
    braIncome($this->user, 6000);

    $r = bra();
    // not stripped → want = (200+200+1199)/3 = 533.0
    expect($r['kinds']['want']['amount'])->toBe(533.0)
        ->and($r['stripped']['by_kind']['want'])->toBe(0.0);

    Transaction::query()->where('amount', -1199)->update(['amount' => -1201, 'hash' => 'floorbump']);
    $r2 = bra();
    // excess 1001 ≥ floor → stripped back to median → want = 200
    expect($r2['kinds']['want']['amount'])->toBe(200.0);
});

it('does not strip a recurring large bill active in fewer than half the window months', function () {
    $insurance = braCat($this->user, 'Insurance', 'need');
    // Quarterly $1,200 bill — present in 4 of the 12 window months, $0 the rest.
    foreach (['2024-09-15', '2024-12-15', '2025-03-15', '2025-06-15'] as $d) {
        braTxn($this->user, $insurance, -1200, $d);
    }

    // Steady income across the full 12-month span so the window isn't clamped shorter.
    $income = braCat($this->user, 'Income', 'income');
    foreach (range(0, 11) as $i) {
        braTxn($this->user, $income, 5000, Carbon::parse('2024-09-28')->addMonthsNoOverflow($i)->toDateString());
    }

    $r = bra();

    // The zero months must not drag the median to 0 and make every $1,200 look like a lump.
    expect($r['window']['months'])->toBe(12)
        ->and($r['kinds']['need']['amount'])->toBeWithin(400.00, 0.01)        // 4 × 1,200 / 12
        ->and($r['stripped']['by_kind']['need'])->toBeWithin(0.0, 0.01);      // nothing stripped
});

it('adds back an obligation whose category has ZERO in-window actuals (rent), but not a sparse-but-real bill', function () {
    $rent = braCat($this->user, 'Rent', 'need');          // no transactions at all
    $utilities = braCat($this->user, 'Utilities', 'need'); // one real transaction
    $groceries = braCat($this->user, 'Groceries', 'need');

    braSteady($this->user, $groceries, 300);
    braTxn($this->user, $utilities, -35, '2025-08-10');    // one occurrence

    FixedExpense::factory()->create(['user_id' => $this->user->id, 'category_id' => $rent->id, 'name' => 'Rent', 'amount' => 1675, 'frequency' => 'monthly', 'is_active' => true]);
    FixedExpense::factory()->create(['user_id' => $this->user->id, 'category_id' => $utilities->id, 'name' => 'Hydro', 'amount' => 35, 'frequency' => 'monthly', 'is_active' => true]);

    braIncome($this->user, 5000);

    $r = bra();

    // need = groceries 300 + utilities (35/3≈11.67) + Rent add-back 1675; Hydro NOT added (occ≥1).
    expect(collect($r['added_back'])->pluck('label')->all())->toBe(['Rent'])
        ->and($r['kinds']['need']['amount'])->toBe(round(300 + 35 / 3 + 1675, 2));
});

it('treats a one_time income entry as $0 in the recurring-estimate income fallback', function () {
    $need = braCat($this->user, 'Groceries', 'need');
    braSteady($this->user, $need, 300); // anchors the window; no income transactions exist

    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'amount' => 2000, 'frequency' => 'monthly', 'pay_date' => '2025-08-01']);
    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'amount' => 5000, 'frequency' => 'one_time', 'pay_date' => '2025-08-01']);

    $r = bra();

    expect($r['income_basis'])->toBe('recurring_estimate')
        ->and($r['income_monthly'])->toBeWithin(2000.0, 0.01); // one_time $5,000 contributes nothing
});

it('does not collapse income to $0, nor overstate via the active-only median, when deposits land in fewer than 80% of window months', function () {
    // Window spanned by a tiny steady grocery expense across all 12 months.
    $need = braCat($this->user, 'Groceries', 'need');
    foreach (range(0, 11) as $i) {
        braTxn($this->user, $need, -10, Carbon::parse('2024-09-10')->addMonthsNoOverflow($i)->toDateString());
    }
    // Income deposits in only 5 of the 12 window months (rest misfiled as transfers,
    // i.e. sparse/misfiled pay) — a 41.7% active ratio, well under the 80% gate.
    $income = braCat($this->user, 'Income', 'income');
    foreach (range(0, 4) as $i) {
        braTxn($this->user, $income, 5000, Carbon::parse('2025-04-28')->addMonthsNoOverflow($i)->toDateString());
    }

    $r = bra();

    // The active-only median (5000) would overstate a sparse cadence — this is
    // not a bi-weekly/monthly pay pattern, it's 5 real deposits out of 12
    // months. Correct figure: sum(5 × 5000) ÷ 12 window months = 2083.33 —
    // still nonzero (not the $0 collapse a naive full-window median would give).
    expect($r['window']['months'])->toBe(12)
        ->and($r['income_basis'])->toBe('actual_sum_window_sparse')
        ->and($r['income_warning'])->not->toBeNull()
        ->and($r['income_monthly'])->toBeWithin(round(5 * 5000 / 12, 2), 0.01);
});

it('uses the active-month median (unaffected by the sparse-deposit gate) for a normal every-month cadence', function () {
    // Steady income across all 12 window months — 100% active ratio, well
    // above the 80% gate, so the median basis is unchanged by the ratio gate.
    $need = braCat($this->user, 'Groceries', 'need');
    $income = braCat($this->user, 'Income', 'income');
    foreach (range(0, 11) as $i) {
        $d = Carbon::parse('2024-09-28')->addMonthsNoOverflow($i)->toDateString();
        braTxn($this->user, $need, -10, $d);
        braTxn($this->user, $income, 5000, $d);
    }

    $r = bra();

    expect($r['window']['months'])->toBe(12)
        ->and($r['income_basis'])->toBe('actual_median_window')
        ->and($r['income_warning'])->toBeNull()
        ->and($r['income_monthly'])->toBeWithin(5000.0, 0.01);
});

it('uses the median monthly deposit as income, ignoring a one-off windfall', function () {
    $need = braCat($this->user, 'Groceries', 'need');
    braSteady($this->user, $need, 300);
    // Aug has a tax-refund windfall on top of the steady $5,000.
    braIncome($this->user, 5000, ['2025-08-28' => 13000]);

    $r = bra();

    // median(5000, 5000, 13000) = 5000 (windfall ignored), NOT the mean 7666.67.
    expect($r['income_monthly'])->toBe(5000.0);
});

it('falls to UserProfile.monthly_net instead of zeroing income when only gross IncomeEntry rows exist', function () {
    $need = braCat($this->user, 'Groceries', 'need');
    braSteady($this->user, $need, 300); // anchors the window; no income transactions exist

    IncomeEntry::factory()->gross()->create(['user_id' => $this->user->id, 'amount' => 5000, 'frequency' => 'monthly']);
    UserProfile::factory()->forUser($this->user)->create(['net_pay_per_period' => 2200, 'pay_frequency' => 'bi_weekly']);

    $r = bra();

    $expectedNet = round(2200 * 26 / 12, 2); // 4766.67 — NOT the gross entry's 5000, NOT 0.
    expect($r['income_monthly'])->toBe($expectedNet)
        ->and($r['income_warning'])->toBeNull();
});

it('prefers is_net=true IncomeEntry rows over gross rows in the income fallback', function () {
    $need = braCat($this->user, 'Groceries', 'need');
    braSteady($this->user, $need, 300);

    IncomeEntry::factory()->create(['user_id' => $this->user->id, 'amount' => 4000, 'frequency' => 'monthly', 'is_net' => true]);
    IncomeEntry::factory()->gross()->create(['user_id' => $this->user->id, 'amount' => 9000, 'frequency' => 'monthly']);

    $r = bra();

    expect($r['income_monthly'])->toBe(4000.0)
        ->and($r['income_warning'])->toBeNull();
});

it('uses gross IncomeEntry rows with a data-quality warning when no net rows or profile exist', function () {
    $need = braCat($this->user, 'Groceries', 'need');
    braSteady($this->user, $need, 300);

    IncomeEntry::factory()->gross()->create(['user_id' => $this->user->id, 'amount' => 5000, 'frequency' => 'monthly']);

    $r = bra();

    expect($r['income_monthly'])->toBe(5000.0)
        ->and($r['income_warning'])->not->toBeNull();
});

it('does not double-count a misfiled bill modeled as BOTH a FixedExpense and a RecurringSeries', function () {
    $groceries = braCat($this->user, 'Groceries', 'need');
    braSteady($this->user, $groceries, 300);

    $gym = braCat($this->user, 'Gym', 'need'); // zero actual transactions (paid by transfer)

    FixedExpense::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $gym->id,
        'name' => 'Gym', 'amount' => 25, 'frequency' => 'monthly', 'is_active' => true,
    ]);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $gym->id,
        'merchant_key' => 'gym', 'expected_amount' => 25, 'cadence' => 'monthly', 'status' => 'active',
    ]);

    braIncome($this->user, 5000);

    $r = bra();

    // Gym is added back ONCE ($25), not once per obligation source ($50).
    expect($r['kinds']['need']['amount'])->toBeWithin(325.0, 0.01)
        ->and(collect($r['added_back'])->count())->toBe(1);
});

it('reports negative steady-state saving when spend exceeds income', function () {
    $need = braCat($this->user, 'Rent', 'need');
    braSteady($this->user, $need, 2000);
    braIncome($this->user, 1000);

    $r = bra();

    expect($r['kinds']['saving']['amount'])->toBe(-1000.0)
        ->and($r['kinds']['saving']['status'])->toBe('under'); // below the 10% target → flagged
});

it('excludes the partial anchor month from the window', function () {
    $need = braCat($this->user, 'Groceries', 'need');
    braSteady($this->user, $need, 300); // Jun–Aug complete months
    braIncome($this->user, 5000);
    // A huge spend on the 2nd of a later month → that month is partial (day 2 ≪ end) and excluded.
    braTxn($this->user, $need, -9000, '2025-09-02');

    $r = bra();

    // Window stays Jun–Aug; the $9,000 partial-month spike is not counted.
    expect($r['window']['months'])->toBe(3)
        ->and($r['kinds']['need']['amount'])->toBe(300.0);
});

it('computes per-kind percentage and over/under status vs target', function () {
    $need = braCat($this->user, 'Rent', 'need');
    $want = braCat($this->user, 'Restaurants', 'want');
    braSteady($this->user, $need, 3500); // 70% of 5000 → over the 50 target
    braSteady($this->user, $want, 500);  // 10% → under the 30 target
    braIncome($this->user, 5000);

    $r = bra();

    expect($r['kinds']['need']['pct'])->toBe(70.0)
        ->and($r['kinds']['need']['status'])->toBe('over')
        ->and($r['kinds']['want']['pct'])->toBe(10.0)
        ->and($r['kinds']['want']['status'])->toBe('under')
        ->and($r['targets'])->toBe(['need' => 50, 'want' => 30, 'investment' => 10, 'saving' => 10]);
});

it('reports status "on" when a bucket lands exactly on its target (BRA-2)', function () {
    $need = braCat($this->user, 'Rent', 'need');
    braSteady($this->user, $need, 2500); // exactly 50% of 5000 → the need target
    braIncome($this->user, 5000);

    $r = bra();

    expect($r['kinds']['need']['pct'])->toBe(50.0)
        ->and($r['kinds']['need']['status'])->toBe('on');
});

it('exposes recurring commitments at native cadence and monthly-equivalent', function () {
    $need = braCat($this->user, 'Rent', 'need');
    braSteady($this->user, $need, 100);
    braIncome($this->user, 5000);
    FixedExpense::factory()->create(['user_id' => $this->user->id, 'category_id' => $need->id, 'name' => 'Car Insurance', 'amount' => 1200, 'frequency' => 'yearly', 'is_active' => true]);

    $r = bra();

    $insurance = collect($r['commitments'])->firstWhere('label', 'Car Insurance');
    expect($insurance['cadence'])->toBe('yearly')
        ->and($insurance['native_amount'])->toBe(1200.0)
        ->and($insurance['monthly_equivalent'])->toBe(100.0); // 1200/12
});
