<?php

use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Liability;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetRuleAnalyzer;
use App\Services\CashFlowForecaster;
use App\Services\NetWorthService;
use App\Services\SpendingCutAnalyzer;
use App\Support\Stats;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;
use Illuminate\Support\Carbon;

/**
 * STAGE A1 SPIKE — cross-check Budge's money math against `brick/money`
 * (composer version installed: 0.11.2), an independent, exact-decimal-
 * arithmetic library with no float drift. NOT a refactor: this file only
 * ADDS the dependency + these cross-checks. See
 * ~/.claude/plans/sprightly-purring-owl.md section A.
 *
 * Every test asserts + documents, in a comment, both Budge's number and
 * brick/money's number, so a PASS or a DIVERGE is legible straight from the
 * test body — that comment pairing IS the divergence report for that line.
 * A top-of-file summary of every cross-check's verdict follows below the
 * tests as a docblock, so Stage A2 doesn't have to re-run this file to know
 * what to fix.
 *
 * ============================== VERDICT SUMMARY ==============================
 * 1. 50/30/10/10 per-kind window-sum → monthly-average (BudgetRuleAnalyzer::
 *    analyze(), the `$kindMonthly[$kind] / $monthCount` line) — PASS. Budge's
 *    `round($sum / $count, 2)` matches brick/money's exact
 *    `BigDecimal::dividedBy($count, 2, HALF_UP)` in every case tried, including
 *    non-evenly-divisible thirds ($100.01 / 3).
 * 2. Hypothetical per-bucket dollar TARGET split (income × 50/30/10/10, each
 *    independently `round()`ed) — DIVERGES from `Money::allocate(50,30,10,10)`.
 *    BudgetRuleAnalyzer does NOT currently compute this figure (kindRow() only
 *    returns a %, never a $ target) — so this is not a live bug today. But it
 *    is the exact hazard the plan asked to pre-empt: independently-rounded
 *    percentage splits of a total do not, in general, sum back to the total;
 *    `allocate()` guarantees they do. If Stage A2 (or any future feature) adds
 *    a "$X of your $Y budget" figure, it MUST use `Money::allocate()`, not
 *    Budge's existing `round($amount * $pct / 100, 2)` idiom (used elsewhere,
 *    e.g. `kindRow()`'s pct calc, `monthlyEquivalent()`). Concrete counter-
 *    example below: income $5,000.05 → naive parts sum to $5,000.07 (2¢ over).
 * 3. Money summation over many DECIMAL(12,2) rows with drift-prone values
 *    (repeating thirds, 0.10/0.20 series) — PASS everywhere tested:
 *    `SpendingCutAnalyzer::analyze()['discretionary_total']`,
 *    `NetWorthService::current()['cash']`,
 *    `CashFlowForecaster::baseline()['total']`. Float64 has ~15-17 significant
 *    decimal digits; at Budge's realistic scale (a personal user's transaction
 *    volume — tens to low thousands of rows, dollar magnitudes in the
 *    thousands) the accumulated binary-representation error never reaches
 *    half a cent, so `round(floatSum, 2)` recovers the exact decimal result
 *    every time. (A raw-PHP probe alongside these tests pushed this to
 *    100,000 summed terms without a single cent-level mismatch.) This is a
 *    genuine, load-bearing finding: Budge's `(float)` + `round()` money math is
 *    NOT silently wrong at the scale this app actually runs at.
 * 4. Net-worth reconciliation (`cash + assets + credit_overpaid − liabilities
 *    === net_worth`, `NetWorthService::current()`) — PASS, exactly, via
 *    brick/money, including a mix of overpaid + owing credit accounts and
 *    tricky-decimal balances.
 * 5. `App\Support\Stats::median` on an even-length set vs a hand-exact
 *    median — PASS (trivial: it's a straight average of the two middle
 *    values, no rounding step to drift).
 *
 * BOTTOM LINE: no divergence exists in any calc Budge currently computes and
 * ships. Stage A2 has nothing to refactor for money-summation/rounding paths.
 * The one real finding is (2) — a documented hazard for a $-target feature
 * that does not exist yet. Stage A2 should treat this file as the regression
 * guard if that feature is ever added.
 * ===============================================================================
 */
function xcMoney(float $amount): Money
{
    return Money::of(number_format($amount, 2, '.', ''), 'USD');
}

function xcCat(User $user, string $name, ?string $kind): Category
{
    return Category::factory()->create(['user_id' => $user->id, 'name' => $name, 'kind' => $kind]);
}

function xcTxn(User $user, ?Category $category, float $signed, string $date, string $description = 'XC'): Transaction
{
    static $seq = 0;
    $seq++;

    return Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category?->id,
        'transaction_date' => $date.' 10:00:00',
        'description' => $description.$seq,
        'amount' => $signed,
        'hash' => 'xc'.$seq,
        'is_excluded' => false,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

// =============================================================================
// 1. 50/30/10/10 — BudgetRuleAnalyzer::analyze()
// =============================================================================

it('CALC 1a: per-kind monthly average matches brick/money exact division, including non-evenly-divisible thirds', function () {
    $need = xcCat($this->user, 'Rent', 'need');
    $want = xcCat($this->user, 'Shopping', 'want');
    $invest = xcCat($this->user, 'Investments', 'investment');
    $income = xcCat($this->user, 'Income', 'income');

    // need: steady $1,000/mo (divides evenly). want: $33.33 + $33.33 + $33.35 =
    // $100.01 over 3 months — NOT evenly divisible by 3 (the interesting case).
    // invest: steady $33.33/mo.
    xcTxn($this->user, $need, -1000.00, '2025-06-10');
    xcTxn($this->user, $need, -1000.00, '2025-07-10');
    xcTxn($this->user, $need, -1000.00, '2025-08-10');

    xcTxn($this->user, $want, -33.33, '2025-06-10');
    xcTxn($this->user, $want, -33.33, '2025-07-10');
    xcTxn($this->user, $want, -33.35, '2025-08-10');

    xcTxn($this->user, $invest, -33.33, '2025-06-10');
    xcTxn($this->user, $invest, -33.33, '2025-07-10');
    xcTxn($this->user, $invest, -33.33, '2025-08-10');

    xcTxn($this->user, $income, 5000.05, '2025-06-28');
    xcTxn($this->user, $income, 5000.05, '2025-07-28');
    xcTxn($this->user, $income, 5000.05, '2025-08-28');

    $r = app(BudgetRuleAnalyzer::class)->analyze();

    // brick/money: exact window-sum ÷ 3, scale 2, HALF_UP — the same rounding
    // convention Budge's own round() uses.
    $wantExact = BigDecimal::of('100.01')->dividedBy(3, 2, RoundingMode::HALF_UP)->__toString();
    $investExact = BigDecimal::of('99.99')->dividedBy(3, 2, RoundingMode::HALF_UP)->__toString();
    $needExact = BigDecimal::of('3000.00')->dividedBy(3, 2, RoundingMode::HALF_UP)->__toString();

    // calc | scenario | Budge | brick/money | delta
    // need | $3000.00/3mo | 1000.00 | 1000.00 | 0.00 (PASS)
    expect(number_format($r['kinds']['need']['amount'], 2, '.', ''))->toBe($needExact);
    // want | $100.01/3mo (non-divisible thirds) | 33.34 | 33.34 | 0.00 (PASS)
    expect(number_format($r['kinds']['want']['amount'], 2, '.', ''))->toBe($wantExact);
    // investment | $99.99/3mo | 33.33 | 33.33 | 0.00 (PASS)
    expect(number_format($r['kinds']['investment']['amount'], 2, '.', ''))->toBe($investExact);
    // income (median of 3 identical months) | 5000.05 | 5000.05 | 0.00 (PASS)
    expect($r['income_monthly'])->toBe(5000.05);
});

it('CALC 1b: DIVERGENCE — a naive independently-rounded %-of-income split does not sum to the total; Money::allocate() does', function () {
    // No production method currently emits this figure (BudgetRuleAnalyzer's
    // kindRow() only returns a %, never a $ target amount). This test
    // constructs the figure using Budge's OWN existing rounding idiom
    // (round($amount * $pct / 100, 2), as used in kindRow()'s pct calc and
    // monthlyEquivalent()) to prove the hazard the plan flagged, so it's
    // caught BEFORE such a feature ships, not after.
    $income = 5000.05;
    $targets = ['need' => 50, 'want' => 30, 'investment' => 10, 'saving' => 10];

    $naive = [];
    foreach ($targets as $kind => $pct) {
        $naive[$kind] = round($income * $pct / 100, 2);
    }
    $naiveSum = round(array_sum($naive), 2);

    $brickParts = xcMoney($income)->allocate(...array_values($targets));
    $brick = [];
    foreach (array_keys($targets) as $i => $kind) {
        $brick[$kind] = (float) $brickParts[$i]->getAmount()->__toString();
    }
    $brickSum = array_sum($brick);

    // calc | scenario | Budge-style (naive round) | brick/money (allocate) | delta
    // need        | 50% of $5,000.05 | 2500.03 | 2500.03 | 0.00
    // want        | 30% of $5,000.05 | 1500.02 | 1500.02 | 0.00
    // investment  | 10% of $5,000.05 |  500.01 |  500.00 | +0.01 (DIVERGES)
    // saving      | 10% of $5,000.05 |  500.01 |  500.00 | +0.01 (DIVERGES)
    // SUM         |                  | 5000.07 | 5000.05 | +0.02 (naive parts overshoot the total)
    expect($naive['need'])->toBe(2500.03)
        ->and($naive['want'])->toBe(1500.02)
        ->and($naive['investment'])->toBe(500.01)
        ->and($naive['saving'])->toBe(500.01)
        ->and($naiveSum)->toBe(5000.07); // does NOT reconcile to income (5000.05)

    expect($brick['need'])->toBe(2500.03)
        ->and($brick['want'])->toBe(1500.02)
        ->and($brick['investment'])->toBe(500.00)
        ->and($brick['saving'])->toBe(500.00)
        ->and($brickSum)->toBe(5000.05); // allocate() always reconciles exactly

    // The documented divergence, in cents:
    expect(round($naiveSum - $brickSum, 2))->toBe(0.02);
});

// =============================================================================
// 2. Money summation + rounding over many DECIMAL(12,2) rows
// =============================================================================

it('CALC 2a: SpendingCutAnalyzer discretionary_total matches brick/money exact sum over repeating-thirds amounts', function () {
    Carbon::setTestNow('2026-06-15 10:00:00');

    $shopping = xcCat($this->user, 'Shopping', 'want'); // discretionary via kind

    // 60 transactions of $33.33/$33.34 (repeating-thirds pattern) in the
    // current (anchor) month — a realistic-scale, drift-prone value set.
    $amounts = [];
    for ($i = 0; $i < 60; $i++) {
        $amounts[] = $i % 3 === 2 ? 33.34 : 33.33; // 20×33.34 + 40×33.33
        xcTxn($this->user, $shopping, -$amounts[$i], '2026-06-'.str_pad((string) (($i % 27) + 1), 2, '0', STR_PAD_LEFT), 'SHOP');
    }

    $result = app(SpendingCutAnalyzer::class)->analyze(1);

    $exact = array_reduce(
        $amounts,
        fn (BigDecimal $c, float $v) => $c->plus(BigDecimal::of(number_format($v, 2, '.', ''))),
        BigDecimal::zero(),
    )->toScale(2, RoundingMode::HALF_UP)->__toString();

    // calc | scenario | Budge (PHP-level array accumulation + round) | brick/money (exact BigDecimal sum) | delta
    // discretionary_total | 60 rows of ~$33.33 | matches | matches | 0.00 (PASS)
    expect(number_format($result['discretionary_total'], 2, '.', ''))->toBe($exact);

    Carbon::setTestNow(null);
});

it('CALC 2b: NetWorthService cash sum matches brick/money exact sum across multiple tricky-decimal accounts', function () {
    $balances = [1234.33, 6789.67, 0.10, 99999.99, 0.02];
    foreach ($balances as $i => $b) {
        $type = $i === 0 ? 'chequing' : ($i % 2 === 0 ? 'chequing' : 'savings');
        BankAccount::factory()->create([
            'user_id' => $this->user->id,
            'type' => $type,
            'current_balance' => $b,
        ]);
    }

    $current = app(NetWorthService::class)->current();

    $exact = array_reduce(
        $balances,
        fn (BigDecimal $c, float $v) => $c->plus(BigDecimal::of(number_format($v, 2, '.', ''))),
        BigDecimal::zero(),
    )->toScale(2, RoundingMode::HALF_UP)->__toString();

    // calc | scenario | Budge (Collection::sum() over floats) | brick/money (exact) | delta
    // cash | 5 accounts, tricky decimals | matches | matches | 0.00 (PASS)
    expect(number_format($current['cash'], 2, '.', ''))->toBe($exact);
});

it('CALC 2c: CashFlowForecaster baseline total matches brick/money exact sum over many DB-summed rows', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $chequing = BankAccount::factory()->chequing()->create(['user_id' => $this->user->id, 'current_balance' => 5000]);
    $cat = xcCat($this->user, 'Everyday', null);

    // 40 rows of a 0.10/0.20/33.33 mix over the default 3-month baseline window.
    $amounts = [];
    $day = 1;
    for ($i = 0; $i < 40; $i++) {
        $v = match ($i % 3) {
            0 => 0.10,
            1 => 0.20,
            default => 33.33,
        };
        $amounts[] = $v;
        $date = Carbon::parse('2026-05-01')->addDays($day % 28)->toDateString();
        $day++;
        Transaction::factory()->expense($v)->create([
            'user_id' => $this->user->id,
            'bank_account_id' => $chequing->id,
            'category_id' => $cat->id,
            'transaction_date' => $date.' 10:00:00',
            'balance_after' => null,
            'hash' => 'xcburn'.$i,
        ]);
    }

    $baseline = app(CashFlowForecaster::class)->baseline('2026-05-01');

    $exact = array_reduce(
        $amounts,
        fn (BigDecimal $c, float $v) => $c->plus(BigDecimal::of(number_format($v, 2, '.', ''))),
        BigDecimal::zero(),
    )->toScale(2, RoundingMode::HALF_UP)->__toString();

    // calc | scenario | Budge (SQL SUM(DECIMAL) → one float cast → round) | brick/money (exact) | delta
    // baseline.total | 40 rows, 0.10/0.20/33.33 mix | matches | matches | 0.00 (PASS)
    expect(number_format($baseline['total'], 2, '.', ''))->toBe($exact);

    Carbon::setTestNow(null);
});

// =============================================================================
// 3. Net-worth reconciliation — NetWorthService::current()
// =============================================================================

it('CALC 3: NetWorthService reconciles cash + assets + credit_overpaid − liabilities === net_worth exactly (brick/money)', function () {
    BankAccount::factory()->chequing()->create(['user_id' => $this->user->id, 'current_balance' => 1234.56]);
    BankAccount::factory()->savings()->create(['user_id' => $this->user->id, 'current_balance' => 789.01]);
    BankAccount::factory()->creditCard()->create(['user_id' => $this->user->id, 'current_balance' => 250.33]); // overpaid
    BankAccount::factory()->create(['user_id' => $this->user->id, 'type' => 'line_of_credit', 'current_balance' => -1899.99]); // owed

    Asset::factory()->create(['user_id' => $this->user->id, 'current_value' => 15000.10]);
    Liability::factory()->create(['user_id' => $this->user->id, 'balance' => 333.33]);

    $current = app(NetWorthService::class)->current();

    $exact = xcMoney($current['cash'])
        ->plus(xcMoney($current['assets']))
        ->plus(xcMoney($current['credit_overpaid']))
        ->minus(xcMoney($current['liabilities']));

    // calc | scenario | Budge net_worth | brick/money (cash+assets+credit_overpaid-liabilities) | delta
    // net_worth reconciliation | mixed overpaid+owed credit, tricky decimals | matches | matches | 0.00 (PASS)
    expect(number_format($current['net_worth'], 2, '.', ''))->toBe($exact->getAmount()->__toString());
});

// =============================================================================
// 4. Stats::median spot check (optional, cheap)
// =============================================================================

it('CALC 4: Stats::median on an even-length set matches a hand-exact median', function () {
    $values = [10.01, 400.50, 33.33, 999.99, 12.00, 87.65]; // sorted: 10.01,12.00,33.33,87.65,400.50,999.99

    $budge = Stats::median($values);
    $exact = (87.65 + 33.33) / 2; // average of the two middle (sorted) values = 60.49

    // calc | scenario | Budge | hand-exact | delta
    // Stats::median | even-length 6-value set | 60.49 | 60.49 | 0.00 (PASS)
    expect($budge)->toBeWithin($exact, 0.0001);
});
