<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\IncomeEntry;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashFlowForecaster;
use App\Services\NetWorthService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->chequing = BankAccount::factory()->chequing()->create([
        'user_id' => $this->user->id,
        'current_balance' => 1000.00,
    ]);
});

it('starts from current cash and projects a daily timeline', function () {
    $f = app(CashFlowForecaster::class)->forecast(30);

    expect($f['start_balance'])->toBe(1000.0)
        ->and($f['timeline'])->toHaveCount(31) // today .. today+30
        ->and($f['shortfall_date'])->toBeNull();
});

it('never disagrees with NetWorthService on current cash (shared cashBalance())', function () {
    BankAccount::factory()->savings()->create([
        'user_id' => $this->user->id,
        'current_balance' => 250.00,
    ]);

    $forecastCash = app(CashFlowForecaster::class)->forecast(30)['start_balance'];
    $netWorthCash = app(NetWorthService::class)->current()['cash'];

    expect($forecastCash)->toBe($netWorthCash)
        ->and($forecastCash)->toBe(1250.0);
});

it('charges exactly horizon-many days of burn, not one extra', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id]);
    foreach ([5, 15, 25, 35, 45] as $d) {
        Transaction::factory()->expense(80)->create([
            'user_id' => $this->user->id, 'bank_account_id' => $this->chequing->id, 'category_id' => $cat->id,
            'transaction_date' => Carbon::now()->subDays($d), 'balance_after' => null,
        ]);
    }

    $f = app(CashFlowForecaster::class)->forecast(30);
    $daily = $f['baseline']['daily'];
    $end = collect($f['timeline'])->last()['balance'];

    // No events → balance after 30 days = start − 30×burn (the off-by-one charged 31×).
    expect($daily)->toBeGreaterThan(0.0)
        ->and($end)->toBeWithin(round($f['start_balance'] - 30 * $daily, 2), 0.01);
});

it('does not project a null-category recurring series as an event (it stays in burn, no double-count)', function () {
    foreach ([10, 40, 70] as $d) {
        Transaction::factory()->expense(20)->create([
            'user_id' => $this->user->id, 'bank_account_id' => $this->chequing->id, 'category_id' => null,
            'description' => 'NETFLIX', 'transaction_date' => Carbon::now()->subDays($d), 'balance_after' => null,
        ]);
    }
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id, 'category_id' => null, 'merchant_key' => 'netflix',
        'expected_amount' => 20, 'cadence' => 'monthly', 'status' => 'active',
        'next_expected_at' => Carbon::now()->addDays(10)->toDateString(),
    ]);

    $f = app(CashFlowForecaster::class)->forecast(30);

    // Projecting it as an event while its history is also in burn would double-charge it.
    expect(collect($f['events'])->where('kind', 'recurring'))->toHaveCount(0);
});

it('does not project a fixed expense before its future start_date', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id]);
    FixedExpense::create([
        'user_id' => $this->user->id, 'category_id' => $cat->id, 'name' => 'New Apartment',
        'amount' => 2000, 'frequency' => 'monthly', 'due_day' => 1,
        'start_date' => Carbon::now()->startOfDay()->addMonths(3)->startOfMonth()->toDateString(), // future
        'is_active' => true,
    ]);

    $f = app(CashFlowForecaster::class)->forecast(40); // 40 days, well before start_date

    expect(collect($f['events'])->where('kind', 'expense'))->toHaveCount(0);
});

it('applies a fixed expense on its due day', function () {
    $today = Carbon::now()->startOfDay();
    $dueDay = $today->copy()->addDays(10)->day;

    $category = Category::factory()->create(['user_id' => $this->user->id]);

    FixedExpense::create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'name' => 'Rent',
        'amount' => 1675.00,
        'frequency' => 'monthly',
        'due_day' => $dueDay,
        'start_date' => $today->copy()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $f = app(CashFlowForecaster::class)->forecast(40);

    // $1000 cash, $1675 rent → goes negative.
    expect($f['shortfall_date'])->not->toBeNull()
        ->and($f['lowest']['balance'])->toBeLessThan(0.0);
});

it('re-derives a due-day-31 monthly bill each month instead of freezing at the first clamp', function () {
    Carbon::setTestNow('2026-01-15 00:00:00');

    $category = Category::factory()->create(['user_id' => $this->user->id]);
    FixedExpense::create([
        'user_id' => $this->user->id,
        'category_id' => $category->id,
        'name' => 'Rent',
        'amount' => 1000.00,
        'frequency' => 'monthly',
        'due_day' => 31,
        'start_date' => '2025-01-31',
        'is_active' => true,
    ]);

    $f = app(CashFlowForecaster::class)->forecast(120);

    $dates = collect($f['events'])->where('label', 'Rent')->pluck('date')->values()->all();

    // Feb (28 days in 2026) clamps to 28; Mar recovers to 31; Apr clamps to 30.
    // The pre-fix bug chains addMonthNoOverflow() from the clamped day and
    // freezes every month at 28.
    expect($dates)->toContain('2026-02-28')
        ->toContain('2026-03-31')
        ->toContain('2026-04-30')
        ->not->toContain('2026-03-28')
        ->not->toContain('2026-04-28');

    Carbon::setTestNow(null);
});

it('applies income on the pay date', function () {
    IncomeEntry::create([
        'user_id' => $this->user->id,
        'source' => 'Paycheque',
        'amount' => 2200.00,
        'frequency' => 'bi_weekly',
        'pay_date' => Carbon::now()->startOfDay()->addDays(7)->toDateString(),
        'is_net' => true,
    ]);

    $f = app(CashFlowForecaster::class)->forecast(30);

    // Final balance should exceed starting cash thanks to at least one paycheque.
    expect(end($f['timeline'])['balance'])->toBeGreaterThan(1000.0);
});

it('includes detected recurring charges', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id]);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $cat->id,
        'merchant_key' => 'NETFLIX',
        'cadence' => 'monthly',
        'expected_amount' => 16.49,
        'status' => 'active',
        'next_expected_at' => Carbon::now()->startOfDay()->addDays(5)->toDateString(),
    ]);

    $f = app(CashFlowForecaster::class)->forecast(30);

    $netflix = collect($f['events'])->firstWhere('label', 'NETFLIX');
    expect($netflix)->not->toBeNull()
        ->and($netflix['amount'])->toBe(-16.49);
});

it('handles bi_monthly and semi_annual recurring cadences without crashing', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id]);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $cat->id,
        'merchant_key' => 'BIMONTHLY',
        'cadence' => 'bi_monthly',
        'expected_amount' => 40.00,
        'status' => 'active',
        'next_expected_at' => Carbon::now()->startOfDay()->addDays(3)->toDateString(),
    ]);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $cat->id,
        'merchant_key' => 'SEMIANNUAL',
        'cadence' => 'semi_annual',
        'expected_amount' => 120.00,
        'status' => 'active',
        'next_expected_at' => Carbon::now()->startOfDay()->addDays(5)->toDateString(),
    ]);

    $f = app(CashFlowForecaster::class)->forecast(90);

    $labels = collect($f['events'])->pluck('label');
    expect($labels)->toContain('BIMONTHLY')
        ->and($labels)->toContain('SEMIANNUAL');
});

/** Create a variable expense transaction on a specific date in a category. */
function forecastSpend(BankAccount $account, string $date, float $magnitude, ?Category $category = null): void
{
    Transaction::factory()->expense($magnitude)->create([
        'user_id' => $account->user_id,
        'bank_account_id' => $account->id,
        'category_id' => $category?->id,
        'transaction_date' => $date.' 10:00:00',
        'balance_after' => 1000,
    ]);
}

it('learns a daily burn from variable spending inside the baseline window', function () {
    // 3 × $30 spread over May; latest txn (2026-05-30) is the window end/anchor.
    forecastSpend($this->chequing, '2026-05-02', 30);
    forecastSpend($this->chequing, '2026-05-16', 30);
    forecastSpend($this->chequing, '2026-05-30', 30);

    $f = app(CashFlowForecaster::class)->forecast(60, '2026-05-01');

    // window = 2026-05-01 .. 2026-05-30 = 30 days; $90 / 30 = $3.00/day.
    expect($f['baseline']['start'])->toBe('2026-05-01')
        ->and($f['baseline']['window_days'])->toBe(30)
        ->and($f['baseline']['daily'])->toBe(3.0);
});

it('ignores spending before the baseline start date', function () {
    forecastSpend($this->chequing, '2026-03-01', 900); // pre-window: excluded
    forecastSpend($this->chequing, '2026-05-30', 30);  // in-window

    $f = app(CashFlowForecaster::class)->forecast(60, '2026-05-01');

    // Only the $30 counts: $30 / 30 days = $1.00/day.
    expect($f['baseline']['daily'])->toBe(1.0);
});

it('excludes fixed-expense categories from the burn so bills are not double-counted', function () {
    $rent = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Rent']);
    FixedExpense::create([
        'user_id' => $this->user->id,
        'category_id' => $rent->id,
        'name' => 'Rent',
        'amount' => 1675.00,
        'frequency' => 'monthly',
        'start_date' => '2026-05-01',
        'is_active' => true,
    ]);

    forecastSpend($this->chequing, '2026-05-15', 1675, $rent); // already a fixed event
    forecastSpend($this->chequing, '2026-05-30', 30);          // genuine variable spend

    $f = app(CashFlowForecaster::class)->forecast(60, '2026-05-01');

    // Rent excluded → only $30 / 30 days = $1.00/day.
    expect($f['baseline']['daily'])->toBe(1.0);
});

it('drains the projected balance by the daily burn each day', function () {
    forecastSpend($this->chequing, '2026-05-02', 30);
    forecastSpend($this->chequing, '2026-05-30', 30);

    $f = app(CashFlowForecaster::class)->forecast(30, '2026-05-01');

    // Positive burn, no income → end balance below the starting cash.
    expect($f['baseline']['daily'])->toBeGreaterThan(0.0)
        ->and(end($f['timeline'])['balance'])->toBeLessThan($f['start_balance']);
});

/** Investment-category expense on a date (Y-m-d). */
function investSpend(BankAccount $account, Category $investments, float $magnitude, string $date, string $description): void
{
    static $seq = 0;
    $seq++;
    Transaction::factory()->expense($magnitude)->create([
        'user_id' => $account->user_id,
        'bank_account_id' => $account->id,
        'category_id' => $investments->id,
        'transaction_date' => $date.' 10:00:00',
        'description' => $description,
        'balance_after' => 1000,
        'hash' => 'fcinv'.$seq,
    ]);
}

it('excludes a one-time furniture spike from the learned burn', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);

    // Everyday weekly spend ($30) over May.
    foreach (['2026-05-02', '2026-05-09', '2026-05-16', '2026-05-23', '2026-05-30'] as $d) {
        forecastSpend($this->chequing, $d, 30, $shopping);
    }
    // One-time furniture buy ($2,500, single occurrence, ≥ floor & ≥4×median).
    forecastSpend($this->chequing, '2026-05-12', 2500, $shopping);

    $withSpike = app(CashFlowForecaster::class)->forecast(60, '2026-05-01')['baseline']['daily'];

    // Same fixture WITHOUT the furniture row → that is the burn the spike should not inflate.
    Transaction::query()->where('description', 'like', '%')->where('amount', -2500)->delete();
    $withoutSpike = app(CashFlowForecaster::class)->forecast(60, '2026-05-01')['baseline']['daily'];

    expect($withSpike)->toBe($withoutSpike);

    Carbon::setTestNow(null);
});

it('still counts a sub-threshold infrequent charge in burn', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);

    forecastSpend($this->chequing, '2026-05-30', 150, $shopping); // < $200 floor → stays in burn

    $f = app(CashFlowForecaster::class)->forecast(60, '2026-05-01');

    // $150 / 30 days = $5.00/day; the row was NOT stripped as one-time.
    expect($f['baseline']['daily'])->toBe(5.0);

    Carbon::setTestNow(null);
});

it('keeps elevated everyday rows in burn when investments are present', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    $investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    // Elevated everyday spend ($300 each) — full-population median ~$300 → bar ~$1,200.
    foreach (['2026-05-02', '2026-05-09', '2026-05-16', '2026-05-23'] as $d) {
        forecastSpend($this->chequing, $d, 300, $shopping);
    }
    // Biweekly investments (large debits) — must NOT deflate the one-time median.
    investSpend($this->chequing, $investments, 940, '2026-05-06', 'PLACEMENT NBI');
    investSpend($this->chequing, $investments, 940, '2026-05-20', 'PLACEMENT NBI');
    // A $1,000 everyday row — clears the $200 floor but is < 4×full-median → stays in burn.
    forecastSpend($this->chequing, '2026-05-12', 1000, $shopping);

    $f = app(CashFlowForecaster::class)->forecast(60, '2026-05-01');

    // The $1,000 everyday row must NOT be over-stripped. Compare to a reference run
    // where the investments are gone entirely: the burn total ($2,200: four $300s +
    // the $1,000) is identical, proving the bar wasn't deflated by the investments.
    Transaction::query()->where('description', 'PLACEMENT NBI')->delete();
    $reference = app(CashFlowForecaster::class)->forecast(60, '2026-05-01');

    expect($f['baseline']['total'])->toBe(2200.0)
        ->and($f['baseline']['daily'])->toBe($reference['baseline']['daily']);

    Carbon::setTestNow(null);
});

it('schedules active investment contributions as outflow events on inferred dates', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    foreach (['2026-05-02', '2026-05-16', '2026-05-30'] as $d) {
        investSpend($this->chequing, $investments, 300, $d, 'PLACEMENT NBI');
    }

    $f = app(CashFlowForecaster::class)->forecast(60);

    $investEvents = collect($f['events'])->where('kind', 'investment');
    expect($investEvents)->not->toBeEmpty()
        ->and($investEvents->first()['amount'])->toBe(-300.0)
        // May 30 + 14 = June 13 < today (June 14); next is June 27, within horizon.
        ->and($investEvents->pluck('date'))->toContain('2026-06-27');

    Carbon::setTestNow(null);
});

it('infers a bi_weekly cadence from ~14-day gaps', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    foreach (['2026-05-02', '2026-05-16', '2026-05-30'] as $d) {
        investSpend($this->chequing, $investments, 300, $d, 'PLACEMENT NBI');
    }

    $f = app(CashFlowForecaster::class)->forecast(60);
    $dates = collect($f['events'])->where('kind', 'investment')->pluck('date')->values();

    expect($dates->count())->toBeGreaterThanOrEqual(2);
    $first = Carbon::parse($dates[0]);
    $second = Carbon::parse($dates[1]);
    expect((int) $first->diffInDays($second))->toBe(14);

    Carbon::setTestNow(null);
});

it('excludes active investment-category spend from the daily burn', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    $investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    forecastSpend($this->chequing, '2026-05-30', 30, $shopping); // genuine burn
    foreach (['2026-05-02', '2026-05-16', '2026-05-30'] as $d) {
        investSpend($this->chequing, $investments, 300, $d, 'PLACEMENT NBI'); // scheduled, not burn
    }

    $f = app(CashFlowForecaster::class)->forecast(60, '2026-05-01');

    // Only the $30 shopping row counts: $30 / 30 days = $1.00/day; investments are out.
    expect($f['baseline']['daily'])->toBe(1.0);

    Carbon::setTestNow(null);
});

it('a paused investment stream falls back into burn and is not projected', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    // Stream stopped in early April, but NEWER data exists (groceries through late
    // May → anchor = May 30), so lastSeen(Apr 7)->diffInDays(anchor) = 53 > 28 → stale.
    foreach (['2026-03-10', '2026-03-24', '2026-04-07'] as $d) {
        investSpend($this->chequing, $investments, 300, $d, 'PLACEMENT NBI');
    }
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    forecastSpend($this->chequing, '2026-05-30', 30, $shopping); // newer data → later anchor

    $f = app(CashFlowForecaster::class)->forecast(60, '2026-03-01');

    // (a) Not projected.
    expect(collect($f['events'])->where('kind', 'investment'))->toBeEmpty();

    // (b) Its history stays in burn — it did NOT vanish. A run with the stream
    // removed entirely would give a strictly lower daily.
    $dailyWithStale = $f['baseline']['daily'];
    Transaction::query()->where('description', 'PLACEMENT NBI')->delete();
    $dailyWithout = app(CashFlowForecaster::class)->forecast(60, '2026-03-01')['baseline']['daily'];

    expect($dailyWithStale)->toBeGreaterThan($dailyWithout);

    Carbon::setTestNow(null);
});

it('counts each active investment contribution exactly once — scheduled, not in burn', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    foreach (['2026-05-02', '2026-05-16', '2026-05-30'] as $d) {
        investSpend($this->chequing, $investments, 300, $d, 'PLACEMENT NBI');
    }

    $f = app(CashFlowForecaster::class)->forecast(60, '2026-05-01');

    // Burn excludes them (only investments in window → daily 0).
    expect($f['baseline']['daily'])->toBe(0.0);
    // And every projected investment event is -300.
    collect($f['events'])->where('kind', 'investment')
        ->each(fn ($e) => expect($e['amount'])->toBe(-300.0));

    Carbon::setTestNow(null);
});

it('does not double-count when an ACTIVE recurring series carries the investment category', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    foreach (['2026-05-02', '2026-05-16', '2026-05-30'] as $d) {
        investSpend($this->chequing, $investments, 300, $d, 'PLACEMENT NBI');
    }
    // An ACTIVE recurring series whose merchant_key matches the projector's stream.
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'placement nbi',
        'category_id' => $investments->id,
        'cadence' => 'bi_weekly',
        'expected_amount' => 300,
        'status' => 'active',
        'next_expected_at' => '2026-06-27',
    ]);

    $f = app(CashFlowForecaster::class)->forecast(60);

    // The projector OWNS it; the recurring loop must NOT also emit it on June 27.
    $june27 = collect($f['events'])->where('date', '2026-06-27')
        ->whereIn('kind', ['investment', 'recurring']);
    expect($june27)->toHaveCount(1)
        ->and($june27->first()['kind'])->toBe('investment');

    Carbon::setTestNow(null);
});

it('still emits a DB-active cat-investment series the projector drops as stale', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    // Transactions are STALE relative to the anchor: the stream stopped early April
    // but newer data (groceries through late May → anchor May 30) makes
    // lastSeen(Apr 7)->diffInDays(anchor) = 53 > 28, so the projector drops it...
    foreach (['2026-03-10', '2026-03-24', '2026-04-07'] as $d) {
        investSpend($this->chequing, $investments, 300, $d, 'PLACEMENT NBI');
    }
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    forecastSpend($this->chequing, '2026-05-30', 30, $shopping); // newer data → later anchor
    // ...but a DB-active series with a future next_expected_at still exists.
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'placement nbi',
        'category_id' => $investments->id,
        'cadence' => 'bi_weekly',
        'expected_amount' => 300,
        'status' => 'active',
        'next_expected_at' => '2026-06-20',
    ]);

    $f = app(CashFlowForecaster::class)->forecast(60);

    // No silent drop: the series still emits via the recurring loop (projector didn't claim it).
    $placement = collect($f['events'])
        ->filter(fn ($e) => $e['date'] === '2026-06-20' && in_array($e['kind'], ['investment', 'recurring'], true));
    expect($placement)->toHaveCount(1)
        ->and($placement->first()['kind'])->toBe('recurring');

    Carbon::setTestNow(null);
});

/** A described expense in a category on a date, with a unique hash. */
function spreeSpend(BankAccount $account, Category $category, float $magnitude, string $date, string $description): void
{
    static $seq = 0;
    $seq++;
    Transaction::factory()->expense($magnitude)->create([
        'user_id' => $account->user_id,
        'bank_account_id' => $account->id,
        'category_id' => $category->id,
        'transaction_date' => $date.' 10:00:00',
        'description' => $description,
        'balance_after' => 1000,
        'hash' => 'spree'.$seq,
    ]);
}

it('strips a frequent-merchant furniture spree the one-time detector keeps', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    $investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    // Prior baseline: two normal months → median $100 (≥ $50 guard).
    spreeSpend($this->chequing, $shopping, 100, '2026-01-15', 'NORMAL JAN');
    spreeSpend($this->chequing, $shopping, 100, '2026-02-15', 'NORMAL FEB');

    // The spree: three RONA buys at the SAME store (key recurs 3× → "frequent" →
    // the per-transaction one-time detector keeps them; only the spike strip catches it).
    spreeSpend($this->chequing, $shopping, 700, '2026-03-05', 'RONA SOMEVILLE');
    spreeSpend($this->chequing, $shopping, 700, '2026-03-12', 'RONA SOMEVILLE');
    spreeSpend($this->chequing, $shopping, 700, '2026-03-19', 'RONA SOMEVILLE');

    // Active biweekly investments → scheduled events, must not vanish.
    foreach (['2026-05-02', '2026-05-16', '2026-05-30'] as $d) {
        investSpend($this->chequing, $investments, 300, $d, 'PLACEMENT NBI');
    }

    $this->chequing->update(['current_balance' => 1500.00]);
    IncomeEntry::create([
        'user_id' => $this->user->id, 'source' => 'Paycheque', 'amount' => 1100.00,
        'frequency' => 'bi_weekly', 'pay_date' => '2026-06-16', 'is_net' => true,
    ]);

    $f = app(CashFlowForecaster::class)->forecast(90, '2026-03-01');

    // Spree excess stripped: only the $100 everyday level of Mar stays in burn
    // ($2,100 − $2,000 excess); investments are scheduled, not in burn.
    expect($f['baseline']['total'])->toBe(100.0)
        ->and(collect($f['events'])->where('kind', 'investment'))->not->toBeEmpty()
        ->and($f['shortfall_date'])->toBeNull();

    Carbon::setTestNow(null);
});

it('removes a one-time row inside a flagged spree month exactly once (no double-count)', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    spreeSpend($this->chequing, $shopping, 100, '2026-01-15', 'NORMAL JAN');
    spreeSpend($this->chequing, $shopping, 100, '2026-02-15', 'NORMAL FEB');

    // Frequent spree (kept by one-time) + a genuine one-time buy (single occurrence).
    spreeSpend($this->chequing, $shopping, 700, '2026-03-05', 'RONA SOMEVILLE');
    spreeSpend($this->chequing, $shopping, 700, '2026-03-12', 'RONA SOMEVILLE');
    spreeSpend($this->chequing, $shopping, 700, '2026-03-19', 'RONA SOMEVILLE');
    spreeSpend($this->chequing, $shopping, 300, '2026-03-18', 'WINNERS ONE-OFF');

    // Small everyday noise (non-discretionary) → drags the one-time MEDIAN down so the
    // $200 floor governs and WINNERS ($300, single) is flagged one-time.
    foreach (['2026-03-02', '2026-03-03', '2026-03-04', '2026-03-06', '2026-03-07', '2026-03-08', '2026-03-09', '2026-03-10'] as $d) {
        spreeSpend($this->chequing, $groceries, 20, $d, 'EPICERIE '.$d);
    }

    $withOneTime = app(CashFlowForecaster::class)->forecast(60, '2026-03-01')['baseline']['total'];

    // Same fixture WITHOUT the one-time row → the burn the strip must match exactly.
    Transaction::query()->where('description', 'WINNERS ONE-OFF')->delete();
    $withoutOneTime = app(CashFlowForecaster::class)->forecast(60, '2026-03-01')['baseline']['total'];

    // If the one-time row were double-counted, $withOneTime would be LOWER by $300.
    expect($withOneTime)->toBe($withoutOneTime);

    Carbon::setTestNow(null);
});

it('leaves an essentials-category anomaly in burn (only discretionary spikes are stripped)', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    spreeSpend($this->chequing, $groceries, 100, '2026-01-15', 'NORMAL JAN');
    spreeSpend($this->chequing, $groceries, 100, '2026-02-15', 'NORMAL FEB');
    // A big anomalous grocery month — essentials are NEVER stripped.
    spreeSpend($this->chequing, $groceries, 2000, '2026-03-10', 'COSTCO BULK');

    $f = app(CashFlowForecaster::class)->forecast(60, '2026-03-01');

    // Full $2,000 stays in burn (not stripped); the spike detector skips non-discretionary.
    expect($f['baseline']['total'])->toBe(2000.0);

    Carbon::setTestNow(null);
});

it('exposes the burn baseline publicly for reuse', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id]);
    forecastSpend($this->chequing, '2026-05-02', 30, $cat);
    forecastSpend($this->chequing, '2026-05-16', 30, $cat);
    forecastSpend($this->chequing, '2026-05-30', 30, $cat);

    $b = app(CashFlowForecaster::class)->baseline('2026-05-01');

    expect($b['daily'])->toBeWithin(3.0, 0.01)   // $90 / 30 days
        ->and($b['monthly'])->toBeGreaterThan(0.0);
});

it('reproduces no false near-term cliff for a furniture+investment month', function () {
    Carbon::setTestNow('2026-06-14 10:00:00');
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    $investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    // Start with modest cash and a biweekly paycheque that roughly covers everyday burn.
    $this->chequing->update(['current_balance' => 1500.00]);
    IncomeEntry::create([
        'user_id' => $this->user->id, 'source' => 'Paycheque', 'amount' => 1100.00,
        'frequency' => 'bi_weekly', 'pay_date' => '2026-06-16', 'is_net' => true,
    ]);

    // Everyday burn: $30 weekly → $120 over the 30-day window (divides evenly by 30 = $4/day).
    foreach (['2026-05-02', '2026-05-09', '2026-05-16', '2026-05-30'] as $d) {
        forecastSpend($this->chequing, $d, 30, $shopping);
    }
    // The furniture spike that creates the false cliff if learned as burn.
    forecastSpend($this->chequing, '2026-05-12', 2500, $shopping);
    // Biweekly investments — scheduled, not burn.
    foreach (['2026-05-02', '2026-05-16', '2026-05-30'] as $d) {
        investSpend($this->chequing, $investments, 300, $d, 'PLACEMENT NBI');
    }

    $fixed = app(CashFlowForecaster::class)->forecast(90, '2026-05-01');

    // With the fixes: furniture out of burn, investments scheduled → no near-term shortfall.
    expect($fixed['shortfall_date'])->toBeNull()
        ->and($fixed['lowest']['balance'])->toBeGreaterThan(0.0)
        // burn is the everyday $120/30 = $4.00/day, not inflated by the $2,500 spike.
        ->and($fixed['baseline']['daily'])->toBe(4.0);

    Carbon::setTestNow(null);
});
