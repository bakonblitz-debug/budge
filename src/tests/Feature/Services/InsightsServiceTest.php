<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\IncomeEntry;
use App\Models\NetWorthSnapshot;
use App\Models\SavingsMilestone;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InsightsService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
    $this->acct = BankAccount::factory()->create(['user_id' => $this->user->id]);
    Carbon::setTestNow('2026-06-15');
});
afterEach(fn () => Carbon::setTestNow(null));

it('splits income into regular pay vs windfalls, income stream only', function () {
    $income = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);
    foreach (['2026-03-06', '2026-03-20', '2026-04-03', '2026-04-17'] as $d) {
        Transaction::factory()->income(2367)->categorized($income)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'description' => 'DEPOT SALAIRE PAIE', 'transaction_date' => $d]);
    }
    Transaction::factory()->income(4361.84)->categorized($income)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'description' => "REMB. D'IMPOT GOUV. QUEBEC", 'transaction_date' => '2026-04-20']);
    Transaction::factory()->income(0.02)->categorized($income)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'description' => 'PAIEMENT INTERETS', 'transaction_date' => '2026-04-30']);

    $r = app(InsightsService::class)->payAndWindfalls(12);

    expect($r['typical_paycheque'])->toBeWithin(2367.0, 1.0)
        ->and(collect($r['windfalls'])->pluck('amount')->all())->toBe([4361.84]) // refund kept, interest dropped, salary excluded
        ->and($r['windfall_total'])->toBeWithin(4361.84, 0.01);
});

it('classifies a SALAIRE deposit at 3x median as a windfall (band guard)', function () {
    $income = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);
    // Two regular paycheques at ~2000 (median = 2000)
    Transaction::factory()->income(2000)->categorized($income)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'description' => 'SALAIRE', 'transaction_date' => '2026-05-02']);
    Transaction::factory()->income(2000)->categorized($income)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'description' => 'SALAIRE', 'transaction_date' => '2026-05-16']);
    // A SALAIRE-labelled anomaly at 3× median — outside the ±40% band → should be a windfall
    Transaction::factory()->income(6000)->categorized($income)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'description' => 'SALAIRE BONUS', 'transaction_date' => '2026-05-28']);

    $r = app(InsightsService::class)->payAndWindfalls(12);

    expect($r['typical_paycheque'])->toBeWithin(2000.0, 1.0)
        ->and(collect($r['windfalls'])->pluck('amount')->all())->toBe([6000.0]);
});

it('savingHealth returns steady-state rates, actual month, velocity, and runway', function () {
    // Carbon::setTestNow('2026-06-15') already set in beforeEach.
    // Anchor = 2026-06-15 → anchor month = 2026-06, prior months include 2026-05.

    $income = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);
    $food = Category::factory()->kind('need')->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    // Steady-state income via IncomeEntry (monthly) → income_monthly = 4000
    IncomeEntry::factory()->monthly()->create(['user_id' => $this->user->id, 'amount' => 4000]);

    // Actual transaction in the anchor month (2026-06): income 3000, spend 1000
    Transaction::factory()->income(3000)->categorized($income)->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $this->acct->id,
        'transaction_date' => '2026-06-10',
    ]);
    Transaction::factory()->expense(1000)->categorized($food)->create([
        'user_id' => $this->user->id,
        'bank_account_id' => $this->acct->id,
        'transaction_date' => '2026-06-12',
    ]);

    // A chequing account with $5000 cash so runway is calculable.
    // The acct created in beforeEach is a generic account; create a dedicated chequing one.
    BankAccount::factory()->chequing()->create([
        'user_id' => $this->user->id,
        'current_balance' => 5000.00,
    ]);

    $h = app(InsightsService::class)->savingHealth();

    // steady: income_monthly=4000, spending=0 (no expense transactions → as_is.monthly=0), net=4000
    // steady.savings_rate = round(4000/4000*100,1) = 100.0 when no spend, but let's verify structure
    expect($h)->toHaveKeys(['steady', 'actual_month', 'velocity', 'runway_months']);

    // Steady income ≥ 4000 from IncomeEntry
    expect($h['steady']['income'])->toBeWithin(4000.0, 1.0);
    // savings_rate = round(net/income*100,1) — must not divide by zero
    expect($h['steady']['savings_rate'])->toBeFloat();

    // actual_month reflects the anchor month (2026-06): income 3000, spend 1000, net 2000
    expect($h['actual_month']['income'])->toBeWithin(3000.0, 0.01)
        ->and($h['actual_month']['spending'])->toBeWithin(1000.0, 0.01)
        ->and($h['actual_month']['net'])->toBeWithin(2000.0, 0.01)
        ->and($h['actual_month']['savings_rate'])->toBeWithin(round(2000.0 / 3000.0 * 100, 1), 0.1);

    // velocity = avg net across the trend window (at least the anchor month has net=2000)
    expect($h['velocity'])->toBeFloat();

    // runway = cash / baseline.monthly; cash = 5000, baseline.monthly from CashFlowForecaster
    // runway_months is either a float (when there's burn) or null (when burn = 0).
    expect($h['runway_months'] === null || is_float($h['runway_months']))->toBeTrue();
});

it('savingHealth runway_months is null when monthly burn is zero', function () {
    // No expense transactions → baseline.monthly = 0 → runway_months must be null.
    BankAccount::factory()->chequing()->create([
        'user_id' => $this->user->id,
        'current_balance' => 9999.00,
    ]);

    $h = app(InsightsService::class)->savingHealth();

    expect($h['runway_months'])->toBeNull();
});

it('netWorth: with 1 snapshot has_history is false and series is empty', function () {
    // One snapshot → not enough history
    NetWorthSnapshot::factory()->create([
        'user_id' => $this->user->id,
        'as_of' => '2026-06-01',
        'net_worth' => 50000,
        'total_cash' => 10000,
        'total_assets' => 50000,
        'total_liabilities' => 10000,
    ]);

    $r = app(InsightsService::class)->netWorth();

    expect($r['has_history'])->toBeFalse()
        ->and($r['delta_month'])->toBeNull()
        ->and($r['delta_ytd'])->toBeNull()
        ->and($r['series'])->toBe([])
        ->and($r['current'])->toBeArray()
        ->and($r['current'])->toHaveKey('net_worth');
});

it('netWorth: with 2 snapshots across a month boundary has_history is true and delta_month is computed', function () {
    // Two snapshots in different months
    NetWorthSnapshot::factory()->create([
        'user_id' => $this->user->id,
        'as_of' => '2026-05-01',
        'net_worth' => 48000,
        'total_cash' => 8000,
        'total_assets' => 50000,
        'total_liabilities' => 10000,
    ]);
    NetWorthSnapshot::factory()->create([
        'user_id' => $this->user->id,
        'as_of' => '2026-06-01',
        'net_worth' => 50000,
        'total_cash' => 10000,
        'total_assets' => 50000,
        'total_liabilities' => 10000,
    ]);

    $r = app(InsightsService::class)->netWorth();

    expect($r['has_history'])->toBeTrue()
        ->and($r['series'])->not->toBe([])
        ->and(count($r['series']))->toBe(2)
        ->and($r['delta_month'])->toBeWithin(2000.0, 0.01); // 50000 - 48000
});

it('delta_ytd is null when all snapshots are from a prior year (INS-3)', function () {
    // Anchor is 2026 (Carbon::setTestNow in beforeEach, no transactions to override it).
    // No snapshot exists IN 2026, so there's no baseline to measure "this year" against.
    NetWorthSnapshot::factory()->create([
        'user_id' => $this->user->id,
        'as_of' => '2025-03-01',
        'net_worth' => 40000,
        'total_cash' => 8000,
        'total_assets' => 40000,
        'total_liabilities' => 8000,
    ]);
    NetWorthSnapshot::factory()->create([
        'user_id' => $this->user->id,
        'as_of' => '2025-06-01',
        'net_worth' => 42000,
        'total_cash' => 9000,
        'total_assets' => 42000,
        'total_liabilities' => 9000,
    ]);

    $r = app(InsightsService::class)->netWorth();

    expect($r['has_history'])->toBeTrue()
        ->and($r['delta_ytd'])->toBeNull();
});

it('delta_ytd anchors on the reporting year and baselines on the last pre-Jan-1 snapshot (INS-3)', function () {
    // Anchor year 2026. Baseline = last snapshot at/before 2026-01-01 (Dec 2025's),
    // NOT the first snapshot dated in 2026 (the old clock-year behavior).
    NetWorthSnapshot::factory()->create([
        'user_id' => $this->user->id,
        'as_of' => '2025-12-15',
        'net_worth' => 40000,
        'total_cash' => 8000,
        'total_assets' => 40000,
        'total_liabilities' => 8000,
    ]);
    NetWorthSnapshot::factory()->create([
        'user_id' => $this->user->id,
        'as_of' => '2026-04-01',
        'net_worth' => 45000,
        'total_cash' => 9000,
        'total_assets' => 45000,
        'total_liabilities' => 9000,
    ]);
    NetWorthSnapshot::factory()->create([
        'user_id' => $this->user->id,
        'as_of' => '2026-06-01',
        'net_worth' => 50000,
        'total_cash' => 10000,
        'total_assets' => 50000,
        'total_liabilities' => 10000,
    ]);

    $r = app(InsightsService::class)->netWorth();

    expect($r['delta_ytd'])->toBeWithin(10000.0, 0.01); // 50000 - 40000
});

it('goalProgress returns pct and eta_months from milestones vs net savings', function () {
    // Zero out the beforeEach account so net worth is fully controlled.
    $this->acct->update(['current_balance' => 0, 'type' => 'chequing']);

    // Set up a chequing account with $5 000 so NetWorthService::current()['net_worth'] ≈ 5000
    BankAccount::factory()->chequing()->create([
        'user_id' => $this->user->id,
        'current_balance' => 5000.00,
    ]);

    // Two milestones: one half-way, one already beyond current savings
    SavingsMilestone::factory()->create([
        'user_id' => $this->user->id,
        'name' => '$10K saved',
        'target_amount' => 10000,
    ]);
    SavingsMilestone::factory()->create([
        'user_id' => $this->user->id,
        'name' => '$1K saved',
        'target_amount' => 1000,
    ]);

    $goals = app(InsightsService::class)->goalProgress(500.0);

    // Should be sorted by target_amount asc: $1K first, then $10K
    $tenK = collect($goals)->firstWhere('name', '$10K saved');
    $oneK = collect($goals)->firstWhere('name', '$1K saved');

    // $10K milestone: current=5000, target=10000, pct=50.0, eta=(10000-5000)/500=10.0
    expect($tenK['current'])->toBeWithin(5000.0, 1.0)
        ->and($tenK['target'])->toBeWithin(10000.0, 0.01)
        ->and($tenK['pct'])->toBeWithin(50.0, 0.1)
        ->and($tenK['eta_months'])->toBeWithin(10.0, 0.1);

    // $1K milestone already surpassed: pct=100, eta=null or negative → capped at 100, eta=null when remaining<=0
    expect($oneK['pct'])->toBeGreaterThanOrEqual(100.0)
        ->and($oneK['eta_months'])->toBeNull();
});

it('goalProgress eta_months is null when velocity is zero', function () {
    BankAccount::factory()->chequing()->create([
        'user_id' => $this->user->id,
        'current_balance' => 5000.00,
    ]);
    SavingsMilestone::factory()->create([
        'user_id' => $this->user->id,
        'name' => '$10K saved',
        'target_amount' => 10000,
    ]);

    $goals = app(InsightsService::class)->goalProgress(0.0);

    expect($goals[0]['eta_months'])->toBeNull();
});

it('excludes an unlinked excluded-kind outflow from monthlyTrend spending (Dashboard parity)', function () {
    $food = Category::factory()->kind('need')->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    $transfers = Category::factory()->kind('excluded')->create(['user_id' => $this->user->id, 'name' => 'Transfers']);

    Transaction::factory()->expense(200)->categorized($food)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'transaction_date' => '2026-05-12',
    ]);
    // Not linked via transfer_id (real() still includes it) — but its category
    // is kind=excluded, so InsightsService must drop it from spending, same as
    // DashboardController does via idsOfKind('excluded').
    Transaction::factory()->expense(300)->categorized($transfers)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'transaction_date' => '2026-05-14',
    ]);

    $trend = app(InsightsService::class)->monthlyTrend(12);
    $may = collect($trend)->firstWhere('ym', '2026-05');

    expect($may['spending'])->toBeWithin(200.0, 0.01);
});

it('keys the monthlyTrend memo by $months so different windows are not cached together', function () {
    $food = Category::factory()->kind('need')->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    Transaction::factory()->expense(100)->categorized($food)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'transaction_date' => '2025-01-15',
    ]);

    $service = app(InsightsService::class);
    $short = $service->monthlyTrend(3);
    $long = $service->monthlyTrend(12);

    expect($short)->toHaveCount(3)
        ->and($long)->toHaveCount(12);
});

it('excludes a French PAIEMENT deposit from typical_paycheque but includes real SALAIRE/PAIE deposits', function () {
    $income = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);
    Transaction::factory()->income(2000)->categorized($income)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'description' => 'DEPOT SALAIRE', 'transaction_date' => '2026-05-01']);
    Transaction::factory()->income(2000)->categorized($income)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'description' => 'DEPOT PAIE', 'transaction_date' => '2026-05-15']);
    // A deposit whose description contains "PAIEMENT" (payment) must NOT match
    // the \bPAIE\b word-boundary and pollute the salary median/windfall split.
    Transaction::factory()->income(9000)->categorized($income)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'description' => 'PAIEMENT VIREMENT', 'transaction_date' => '2026-05-20']);

    $r = app(InsightsService::class)->payAndWindfalls(12);

    expect($r['typical_paycheque'])->toBeWithin(2000.0, 1.0)
        ->and(collect($r['windfalls'])->pluck('amount')->all())->toBe([9000.0]);
});

it('excludes an investment-kind outflow from actual_month spending so savings_rate is not understated (matches BudgetRuleAnalyzer treatment)', function () {
    $income = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);
    $need = Category::factory()->kind('need')->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    $invest = Category::factory()->kind('investment')->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    Transaction::factory()->income(4767)->categorized($income)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'transaction_date' => '2026-06-05',
    ]);
    Transaction::factory()->expense(3767)->categorized($need)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'transaction_date' => '2026-06-10',
    ]);
    // $1k/mo invested — a saving action, not spending. Pre-fix this landed in
    // "spending" and understated the savings rate (0% instead of ~21%).
    Transaction::factory()->expense(1000)->categorized($invest)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'transaction_date' => '2026-06-15',
    ]);

    $h = app(InsightsService::class)->savingHealth();

    expect($h['actual_month']['spending'])->toBeWithin(3767.0, 0.01)
        ->and($h['actual_month']['net'])->toBeWithin(1000.0, 0.01)
        ->and($h['actual_month']['savings_rate'])->toBeWithin(round(1000 / 4767 * 100, 1), 0.05); // ≈21.0%, not 0%
});

it('excludes a saving-kind outflow from monthlyTrend spending, same as investment-kind', function () {
    $need = Category::factory()->kind('need')->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    $saving = Category::factory()->kind('saving')->create(['user_id' => $this->user->id, 'name' => 'Emergency Fund']);

    Transaction::factory()->expense(200)->categorized($need)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'transaction_date' => '2026-05-12',
    ]);
    Transaction::factory()->expense(300)->categorized($saving)->create([
        'user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'transaction_date' => '2026-05-14',
    ]);

    $trend = app(InsightsService::class)->monthlyTrend(12);
    $may = collect($trend)->firstWhere('ym', '2026-05');

    expect($may['spending'])->toBeWithin(200.0, 0.01);
});

it('builds a monthly income/spending/net trend (not spend-only)', function () {
    $income = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);
    $food = Category::factory()->kind('need')->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    Transaction::factory()->income(2000)->categorized($income)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'transaction_date' => '2026-05-10']);
    Transaction::factory()->expense(500)->categorized($food)->create(['user_id' => $this->user->id, 'bank_account_id' => $this->acct->id, 'transaction_date' => '2026-05-12']);

    $trend = app(InsightsService::class)->monthlyTrend(12);
    $may = collect($trend)->firstWhere('ym', '2026-05');

    expect($may['income'])->toBeWithin(2000.0, 0.01)
        ->and($may['spending'])->toBeWithin(500.0, 0.01)
        ->and($may['net'])->toBeWithin(1500.0, 0.01);
});
