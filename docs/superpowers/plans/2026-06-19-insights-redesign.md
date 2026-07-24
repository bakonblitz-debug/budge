# Insights Page Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace `/statistics` with a clearer, tiered, metrics-rich **Insights** page that shows how money is working and saving, backed by a tested `InsightsService` and reusable charts.

**Architecture:** Thin `InsightsController` → new read-only `InsightsService` that reuses the existing analyzers (`SpendingCutAnalyzer`, `BudgetRuleAnalyzer`, `NetWorthService`, burn baseline) and adds income/net trend + pay-vs-windfall classification. Frontend extracts reusable `LineChart`/`BarChart`/`DonutChart`; the page is tiered (hero cards + progressive-disclosure panels).

**Tech Stack:** PHP 8.3 / Laravel 11, Pest v4 (Feature tests, SQLite in-memory, `RefreshDatabase`, `toBeWithin()` money macro); Vue 3 `<script setup>` + Inertia v3 + Vuetify 3 (hand-rolled SVG charts — no charting dependency).

## Global Constraints

- All paths are relative to the Laravel root **`src/`** (run all commands from there; `make test` / `docker exec budgetapp-app php artisan test --compact` runs Pest).
- **No new dependencies** (charts stay hand-rolled SVG).
- Income resolution is **`kind='income'` (+ children of income-kind parents)** — reuse it everywhere; never resolve income by name.
- Money: `decimal:2` casts return strings; prefer DB `SUM`/single-fetch-then-PHP-fold; round at the final step. Use `toBeWithin()` in tests.
- Run `vendor/bin/pint --format agent <files>` after PHP edits; `npm run build` (in the `budgetapp-node` container) after Vue edits.
- Budge is **not** a git repo — replace each "Commit" step with running the affected tests/build green (no `git commit`).
- Vue: `<script setup>` only, Vuetify 3, MDI icons, theme-aware via CSS vars; reuse `MetricCard`, `NeedsWantsMeter`, `SmartSelect`.

---

## File structure (created / modified)

- Create `src/app/Services/InsightsService.php` — composes all metrics (read-only).
- Create `src/app/Console/Commands/SnapshotNetWorthCommand.php` — daily net-worth snapshot.
- Modify `src/app/Services/CashFlowForecaster.php` — make `baseline()` public (rename concern below).
- Modify `src/app/Http/Controllers/InsightsController.php` — compose `insights` payload, render `Insights/Index`.
- Modify `src/routes/web.php` — add `/insights`, redirect `/statistics` → `/insights`.
- Modify `src/bootstrap/app.php` (or `routes/console.php`) — schedule the snapshot command daily.
- Create `src/resources/js/Components/charts/{LineChart,BarChart,DonutChart}.vue`.
- Modify `src/resources/js/Pages/Forecast/Index.vue`, `src/resources/js/Pages/NetWorth/Index.vue` — use `LineChart`.
- Modify `src/resources/js/Components/MetricCard.vue` — add `format` prop.
- Create `src/resources/js/Pages/Insights/Index.vue`; modify `src/resources/js/Layouts/AppLayout.vue` (nav item).
- Tests under `src/tests/Feature/Services/InsightsServiceTest.php`, `src/tests/Feature/Console/SnapshotNetWorthCommandTest.php`, `src/tests/Feature/Http/Controllers/InsightsControllerTest.php`.

---

## Task 1: Expose the burn baseline for reuse

**Files:**
- Modify: `src/app/Services/CashFlowForecaster.php` (the `baseline()` method, ~line 114)
- Test: `src/tests/Feature/Services/CashFlowForecasterTest.php`

**Interfaces:**
- Produces: `CashFlowForecaster::baseline(?string $baselineStart = null, ?int $baselineMonths = null): array{daily: float, start: string, end: string, window_days: int, total: float, monthly: float}` — now **public**.

- [ ] **Step 1 — Write the failing test** in `CashFlowForecasterTest.php`:

```php
it('exposes the burn baseline publicly for reuse', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id]);
    forecastSpend($this->chequing, '2026-05-02', 30, $cat);
    forecastSpend($this->chequing, '2026-05-16', 30, $cat);
    forecastSpend($this->chequing, '2026-05-30', 30, $cat);

    $b = app(CashFlowForecaster::class)->baseline('2026-05-01');

    expect($b['daily'])->toBeWithin(3.0, 0.01)   // $90 / 30 days
        ->and($b['monthly'])->toBeGreaterThan(0.0);
});
```

- [ ] **Step 2 — Run it, expect failure** (method is `private`):
`docker exec budgetapp-app php artisan test --compact --filter="exposes the burn baseline publicly"` → Error: call to private method.

- [ ] **Step 3 — Make it public:** change `private function baseline(` to `public function baseline(` in `CashFlowForecaster.php`. Add a docblock line: `/** Public so InsightsService and the forecast share one burn figure. */`.

- [ ] **Step 4 — Run the test + full forecaster suite, expect pass:**
`docker exec budgetapp-app php artisan test --compact --filter=CashFlowForecaster`.

- [ ] **Step 5 — Pint + confirm green** (no git): `docker exec budgetapp-app vendor/bin/pint --format agent app/Services/CashFlowForecaster.php`.

---

## Task 2: `InsightsService::monthlyTrend()` — income / spending / net by month

**Files:**
- Create: `src/app/Services/InsightsService.php`
- Test: `src/tests/Feature/Services/InsightsServiceTest.php`

**Interfaces:**
- Consumes: the `kind='income'` resolution (replicate `BudgetRuleAnalyzer::incomeCategoryIds` logic: income-kind ids + children).
- Produces: `InsightsService::monthlyTrend(int $months = 12): array<int, array{ym: string, income: float, spending: float, net: float}>` — one entry per complete month, oldest→newest, anchored on `ReportingPeriod::anchor()`.

- [ ] **Step 1 — Failing test:**

```php
use App\Models\{BankAccount, Category, Transaction, User};
use App\Services\InsightsService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
    $this->acct = BankAccount::factory()->create(['user_id' => $this->user->id]);
    Carbon::setTestNow('2026-06-15');
});
afterEach(fn () => Carbon::setTestNow(null));

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
```

- [ ] **Step 2 — Run, expect failure** (`InsightsService` does not exist).

- [ ] **Step 3 — Implement** `InsightsService` with `monthlyTrend()`. Fetch the window's transactions once, fold in PHP:

```php
<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonImmutable;

class InsightsService
{
    public function __construct(private readonly ReportingPeriod $reportingPeriod) {}

    /** @return array<int, int> income-kind category ids + their children. */
    private function incomeCategoryIds(): array
    {
        $parents = Category::query()->where('kind', 'income')->pluck('id');

        return Category::query()
            ->where('kind', 'income')
            ->orWhereIn('parent_id', $parents)
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    /** @return array<int, array{ym: string, income: float, spending: float, net: float}> */
    public function monthlyTrend(int $months = 12): array
    {
        $anchor = $this->reportingPeriod->anchor();
        $end = $anchor->startOfMonth()->addMonthNoOverflow();          // exclusive
        $start = $end->subMonthsNoOverflow($months);
        $incomeIds = $this->incomeCategoryIds();

        $rows = Transaction::query()
            ->whereNull('transfer_id')->where('is_excluded', false)
            ->where('transaction_date', '>=', $start)->where('transaction_date', '<', $end)
            ->get(['category_id', 'transaction_date', 'amount']);

        $keys = [];
        for ($c = $start; $c->lessThan($end); $c = $c->addMonthNoOverflow()) {
            $keys[$c->format('Y-m')] = ['income' => 0.0, 'spending' => 0.0];
        }
        foreach ($rows as $t) {
            $ym = CarbonImmutable::parse($t->transaction_date)->format('Y-m');
            if (! isset($keys[$ym])) { continue; }
            $amt = (float) $t->amount;
            if ($amt > 0 && in_array((int) $t->category_id, $incomeIds, true)) {
                $keys[$ym]['income'] += $amt;
            } elseif ($amt < 0) {
                $keys[$ym]['spending'] += -$amt;
            }
        }

        return array_map(fn (string $ym) => [
            'ym' => $ym,
            'income' => round($keys[$ym]['income'], 2),
            'spending' => round($keys[$ym]['spending'], 2),
            'net' => round($keys[$ym]['income'] - $keys[$ym]['spending'], 2),
        ], array_keys($keys));
    }
}
```

- [ ] **Step 4 — Run, expect pass.** `--filter="monthly income/spending/net trend"`.
- [ ] **Step 5 — Pint + green.**

---

## Task 3: Pay-vs-windfall classifier (`payAndWindfalls`, typical paycheque, stability)

**Files:**
- Modify: `src/app/Services/InsightsService.php`
- Test: `src/tests/Feature/Services/InsightsServiceTest.php`

**Interfaces:**
- Produces: `payAndWindfalls(int $months = 12): array{typical_paycheque: float, stability: float, windfalls: array<int, array{date: string, description: string, amount: float}>, windfall_total: float, windfall_pct: float}`.
- **Rule (user-approved, income stream only):** over income-category deposits in the window, a deposit is **regular pay** iff its description matches the salary pattern (`/SALAIRE|PAIE|PAYROLL/i`) AND its amount is within ±40% of the median salary-pattern deposit. Everything else in the income category with `amount >= 1.0` is a **windfall**. Deposits `< $1` (tiny interest) are dropped from both. Transfer-in legs and non-income positives are never considered (we only query income categories).

- [ ] **Step 1 — Failing test:**

```php
it('splits income into regular pay vs windfalls, income stream only', function () {
    $income = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);
    foreach (['2026-03-06','2026-03-20','2026-04-03','2026-04-17'] as $d) {
        Transaction::factory()->income(2367)->categorized($income)->create(['user_id'=>$this->user->id,'bank_account_id'=>$this->acct->id,'description'=>'DEPOT SALAIRE PAIE','transaction_date'=>$d]);
    }
    Transaction::factory()->income(4361.84)->categorized($income)->create(['user_id'=>$this->user->id,'bank_account_id'=>$this->acct->id,'description'=>"REMB. D'IMPOT GOUV. QUEBEC",'transaction_date'=>'2026-04-20']);
    Transaction::factory()->income(0.02)->categorized($income)->create(['user_id'=>$this->user->id,'bank_account_id'=>$this->acct->id,'description'=>'PAIEMENT INTERETS','transaction_date'=>'2026-04-30']);

    $r = app(InsightsService::class)->payAndWindfalls(12);

    expect($r['typical_paycheque'])->toBeWithin(2367.0, 1.0)
        ->and(collect($r['windfalls'])->pluck('amount')->all())->toBe([4361.84]) // refund kept, interest dropped, salary excluded
        ->and($r['windfall_total'])->toBeWithin(4361.84, 0.01);
});
```

- [ ] **Step 2 — Run, expect failure** (`payAndWindfalls` undefined).
- [ ] **Step 3 — Implement** in `InsightsService` (add a `median()` helper):

```php
public function payAndWindfalls(int $months = 12): array
{
    $anchor = $this->reportingPeriod->anchor();
    $end = $anchor->startOfMonth()->addMonthNoOverflow();
    $start = $end->subMonthsNoOverflow($months);

    $deposits = Transaction::query()
        ->whereNull('transfer_id')->where('is_excluded', false)->where('amount', '>=', 1.0)
        ->whereIn('category_id', $this->incomeCategoryIds())
        ->where('transaction_date', '>=', $start)->where('transaction_date', '<', $end)
        ->orderBy('transaction_date')
        ->get(['transaction_date', 'description', 'amount']);

    $isSalary = fn (string $d): bool => (bool) preg_match('/SALAIRE|PAIE|PAYROLL/i', $d);
    $salaryAmounts = $deposits->filter(fn ($t) => $isSalary((string) $t->description))->map(fn ($t) => (float) $t->amount)->values()->all();
    $median = $salaryAmounts === [] ? 0.0 : $this->median($salaryAmounts);
    $bandLo = $median * 0.60; $bandHi = $median * 1.40;

    $windfalls = [];
    foreach ($deposits as $t) {
        $amt = (float) $t->amount;
        $isPay = $isSalary((string) $t->description) && $median > 0 && $amt >= $bandLo && $amt <= $bandHi;
        if ($isPay) { continue; }
        $windfalls[] = ['date' => CarbonImmutable::parse($t->transaction_date)->toDateString(), 'description' => (string) $t->description, 'amount' => round($amt, 2)];
    }
    $windfallTotal = round(array_sum(array_column($windfalls, 'amount')), 2);
    $totalIn = round((float) $deposits->sum('amount'), 2);

    $payOnly = array_values(array_filter($salaryAmounts, fn ($a) => $a >= $bandLo && $a <= $bandHi));
    $stability = $this->stability($payOnly); // coefficient of variation; 0 if <2

    return [
        'typical_paycheque' => round($median, 2),
        'stability' => $stability,
        'windfalls' => $windfalls,
        'windfall_total' => $windfallTotal,
        'windfall_pct' => $totalIn > 0 ? round($windfallTotal / $totalIn * 100, 1) : 0.0,
    ];
}

/** @param array<int, float> $v */
private function median(array $v): float
{
    if ($v === []) { return 0.0; }
    sort($v); $n = count($v); $m = intdiv($n, 2);
    return $n % 2 ? $v[$m] : ($v[$m - 1] + $v[$m]) / 2;
}

/** @param array<int, float> $v coefficient of variation (stddev/mean), 0 when <2 samples. */
private function stability(array $v): float
{
    $n = count($v);
    if ($n < 2) { return 0.0; }
    $mean = array_sum($v) / $n;
    if ($mean == 0.0) { return 0.0; }
    $var = array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $v)) / $n;
    return round(sqrt($var) / $mean, 4);
}
```

- [ ] **Step 4 — Run, expect pass.** Add a second test: a deposit named SALAIRE but at 3× median is NOT pay (gets classified windfall) — confirms the band guard.
- [ ] **Step 5 — Pint + green.**

---

## Task 4: `savingHealth()` — rate, saved, velocity, runway, projected (two net bases)

**Files:** Modify `InsightsService.php`; Test `InsightsServiceTest.php`.

**Interfaces:**
- Consumes: `SpendingCutAnalyzer::projection()` (steady-state income/spend), `monthlyTrend()` (actual + velocity), `NetWorthService::current()['cash']`, `CashFlowForecaster::baseline()['monthly']` (runway denominator).
- Produces: `savingHealth(): array{steady: array{income: float, spending: float, net: float, savings_rate: float, projected_year: float}, actual_month: array{income: float, spending: float, net: float, savings_rate: float}, velocity: float, runway_months: ?float}`.

- [ ] **Step 1 — Failing test** asserting: with a profile/income set, `steady.savings_rate` = round(steady.net/steady.income*100,1); `actual_month` reflects the anchor month; `velocity` = avg net over trend; `runway_months` = round(cash / baseline.monthly, 1) and **null** when monthly burn is 0 (no divide-by-zero). (Write explicit numbers with a small fixture.)
- [ ] **Step 2 — Run, expect failure.**
- [ ] **Step 3 — Implement** (inject the services via constructor; reuse, don't recompute). Guard every divide: `income > 0 ? round(net/income*100,1) : 0.0`; `monthly > 0 ? round(cash/monthly,1) : null`.
- [ ] **Step 4 — Run, expect pass.**
- [ ] **Step 5 — Pint + green.**

---

## Task 5: `netWorth()` — current always, trend only with ≥2 snapshots

**Files:** Modify `InsightsService.php`; Test `InsightsServiceTest.php`.

**Interfaces:**
- Produces: `netWorth(): array{current: array, has_history: bool, series: array<int, array{as_of: string, net_worth: float}>, delta_month: ?float, delta_ytd: ?float}` — `has_history=false`, `delta_*=null`, `series=[]` when `<2` snapshots.

- [ ] **Step 1 — Failing test:** with 1 snapshot → `has_history` false, `delta_month` null, `series` empty, `current` populated from `NetWorthService::current()`. With 2 snapshots across a month boundary → `has_history` true and `delta_month` = newest − older.
- [ ] **Step 2 — Run, expect failure.**
- [ ] **Step 3 — Implement:** query `NetWorthSnapshot` ordered by `as_of`; gate on `count >= 2`; never emit `0`/flat when empty.
- [ ] **Step 4 — Run, expect pass.**
- [ ] **Step 5 — Pint + green.**

---

## Task 6: `goalProgress()` — savings milestones

**Files:** Modify `InsightsService.php`; Test `InsightsServiceTest.php`.

**Interfaces:**
- Produces: `goalProgress(float $monthlyVelocity): array<int, array{name: string, current: float, target: float, pct: float, eta_months: ?float}>`.

- [ ] **Step 1 — Failing test:** a `SavingsMilestone` with current 5000 / target 10000 → `pct` 50.0; `eta_months` = round((target−current)/velocity,1) when velocity>0 else null.
- [ ] **Step 2 — Run, expect failure.** (Check the real `SavingsMilestone` column names via `database-schema`/factory before writing.)
- [ ] **Step 3 — Implement** with divide-by-zero guards on target and velocity.
- [ ] **Step 4 — Run, expect pass.**
- [ ] **Step 5 — Pint + green.**

---

## Task 7: Daily net-worth snapshot command + schedule

**Files:**
- Create: `src/app/Console/Commands/SnapshotNetWorthCommand.php`
- Modify: `src/bootstrap/app.php` (or `routes/console.php`) to schedule it daily
- Test: `src/tests/Feature/Console/SnapshotNetWorthCommandTest.php`

**Interfaces:**
- Produces: artisan command `networth:snapshot` that calls `NetWorthService::snapshot()` once per user (acting as each user so `BelongsToUser`-scoped reads resolve correctly); idempotent per day (`updateOrCreate` on `as_of` already in `NetWorthService::snapshot`).

- [ ] **Step 1 — Failing test:** create 2 users with cash/assets; run `$this->artisan('networth:snapshot')->assertOk()`; assert each user has exactly 1 snapshot for today with the right `net_worth`. Running twice keeps it at 1 (idempotent).
- [ ] **Step 2 — Run, expect failure** (command missing).
- [ ] **Step 3 — Implement** the command: `User::query()->each(fn ($u) => auth()->login($u) ... app(NetWorthService::class)->snapshot())` then `auth()->logout()` in a finally (mirror `DemoSeeder`'s login/logout pattern). Register `Schedule::command('networth:snapshot')->dailyAt('23:30')` in the schedule.
- [ ] **Step 4 — Run, expect pass.** Also assert `php artisan schedule:list` includes it (optional).
- [ ] **Step 5 — Pint + green.**

---

## Task 8: Controller payload, route rename + redirect, query-count guard

**Files:**
- Modify: `src/app/Http/Controllers/InsightsController.php`
- Modify: `src/routes/web.php`
- Test: `src/tests/Feature/Http/Controllers/InsightsControllerTest.php`

**Interfaces:**
- Produces: `GET /insights` renders Inertia `Insights/Index` with `{ insights: {...}, budgetRule, aiSummary? }`. `GET /statistics` → 301/302 redirect to `/insights`.

- [ ] **Step 1 — Failing tests:**
  - `get('/insights')->assertOk()->assertInertia(fn ($p) => $p->component('Insights/Index')->has('insights.saving_health')->has('insights.trend')->has('insights.windfalls'))`.
  - `get('/statistics')->assertRedirect('/insights')`.
  - **Query guard:** `DB::enableQueryLog(); $this->get('/insights'); expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(45);` (ceiling — adjust to the measured value + headroom, and document it inline).
- [ ] **Step 2 — Run, expect failure.**
- [ ] **Step 3 — Implement:** inject `InsightsService` into `InsightsController`; build one `insights` array from its methods (compute `savingHealth` once, pass its `velocity` into `goalProgress`); render `Insights/Index`. Add the `/insights` route; change the existing `/statistics` route to `Route::redirect('/statistics', '/insights')`. Measure the query count from the test output and set the ceiling.
- [ ] **Step 4 — Run, expect pass.** Then run the FULL suite to confirm no regressions: `docker exec budgetapp-app php artisan test`.
- [ ] **Step 5 — Pint + green.**

---

## Task 9: Extract `LineChart.vue`; refactor Forecast onto it

**Files:**
- Create: `src/resources/js/Components/charts/LineChart.vue`
- Modify: `src/resources/js/Pages/Forecast/Index.vue`
- (No JS test runner — verified by `npm run build` + the page still rendering.)

**Interfaces:**
- Produces: `<LineChart :points="[{x,y,label,value}]" :y-ticks :x-ticks :zero-y :stroke :markers :hover-format />` — the axis/gridline/hover/marker SVG currently inline in `Forecast/Index.vue`, parameterized. Forecast computes the data and passes it in.

- [ ] **Step 1 — Create `LineChart.vue`** lifting the SVG + Y/X tick rendering + hover overlay + markers from `Forecast/Index.vue` (lines that build the `<div class="d-flex">…<svg>…` block and the hover/marker logic). Props replace the hard-coded `forecast` references.
- [ ] **Step 2 — Refactor `Forecast/Index.vue`** to compute `points/yTicks/xTicks/zeroY/markers` (it already does) and render `<LineChart … />` instead of the inline SVG.
- [ ] **Step 3 — Build:** `docker exec budgetapp-node npm run build` → success.
- [ ] **Step 4 — Verify** `/forecast` still shows axes, hover, and lowest/shortfall markers (manual or screenshot).
- [ ] **Step 5 — Confirm** no Pest regressions (backend untouched): `php artisan test --compact --filter=CashFlow`.

---

## Task 10: Refactor `NetWorth/Index.vue` onto `LineChart`

**Files:** Modify `src/resources/js/Pages/NetWorth/Index.vue`.

- [ ] **Step 1 — Replace** NetWorth's inline line chart with `<LineChart … />`, mapping its snapshot series to `points`.
- [ ] **Step 2 — Keep** its existing `history.length >= 2` empty-state gate (this is the pattern Insights mirrors).
- [ ] **Step 3 — Build + verify** `/net-worth` renders identically.

---

## Task 11: `BarChart.vue` + `DonutChart.vue`

**Files:** Create `src/resources/js/Components/charts/BarChart.vue`, `DonutChart.vue`.

**Interfaces:**
- `<BarChart :series="[{label, values:{income,spending,net}}]" mode="grouped|stacked" />` — monthly bars for the trend toggle.
- `<DonutChart :slices="[{label, value, color}]" />` — category breakdown (hand-rolled SVG arcs).

- [ ] **Step 1 — Build `BarChart.vue`** (hand-rolled SVG rects; reuse the nice-tick helper from `LineChart` for the Y axis — extract it to a shared `charts/ticks.js` if duplicated).
- [ ] **Step 2 — Build `DonutChart.vue`** (SVG path arcs + a legend with values).
- [ ] **Step 3 — Build** → success.

---

## Task 12: `MetricCard` format prop

**Files:** Modify `src/resources/js/Components/MetricCard.vue`.

**Interfaces:**
- Adds `format: 'currency' | 'percent' | 'months' | 'text'` (default `'currency'`) and accepts a string `value` for `'text'` (e.g. `'—'`). Currency keeps current behaviour.

- [ ] **Step 1 — Add the prop** and branch `formatted`: percent → `${value}%`, months → `${value} mo`, text → raw string, currency → existing logic. Guard non-numeric for text/`—`.
- [ ] **Step 2 — Build** → success. Confirm dashboard/forecast cards (currency) unchanged.

---

## Task 13: Assemble `Insights/Index.vue` (tiered) + nav

**Files:**
- Create: `src/resources/js/Pages/Insights/Index.vue`
- Modify: `src/resources/js/Layouts/AppLayout.vue` (nav item label/route → "Insights" / `/insights`).

**Layout (tiered):**
- **Hero row** (`MetricCard`s): savings rate (`percent`), saved this month (currency), runway (`months`, `—` when null), investing rate (`percent`). Steady-state headline + an "actual this month" line beneath, each labeled with its window.
- **Trend** card: `BarChart`/`LineChart` with a `SmartSelect`/`v-btn-toggle` `Cash flow · Net · By category` (12 mo).
- **Projection** card: typical-month Income/Spend/Net + annualized, a 12-mo projected-savings `LineChart`, and a `ⓘ excludes $X one-time this period` callout.
- **`v-expansion-panels`** (progressive disclosure): "Money in beyond your pay" (windfalls list + total + %), "Where the money goes" (`DonutChart` + `NeedsWantsMeter` + discretionary/fixed/subscription/biggest-movers/daily-burn), "Cut opportunities", "Savings goals", "AI summary" (on-demand).
- Empty states: net-worth Δ/trend → `—` "building history"; no windfalls → "Only your regular pay came in this period."

- [ ] **Step 1 — Build the page** consuming the `insights` prop; reuse `MetricCard`, `NeedsWantsMeter`, the three charts, `SmartSelect`.
- [ ] **Step 2 — Update nav** in `AppLayout.vue`: the Statistics item → `{ title: 'Insights', icon: 'mdi-chart-box', route: '/insights' }`.
- [ ] **Step 3 — Build** → success.
- [ ] **Step 4 — Manual verify** `/insights`: hero reads at a glance, trend toggles, projection clear, windfalls correct (tax refunds only), panels expand, empty states show `—` not `$0`.
- [ ] **Step 5 — Full suite green:** `docker exec budgetapp-app php artisan test` and `npm run build`.

---

## Self-review (coverage)

- Spec §3A saving health → Task 4; §3B net worth + degradation → Tasks 5, 7; §3C windfalls + typical pay/stability → Task 3; §3D trend → Tasks 2, 11, 13; §3E projection → Tasks 4, 13; §3F where-money-goes → Task 13 (reuses analyzers); §3H goals → Task 6; §3I AI → Task 13 (kept). §4 service/perf → Tasks 2-8 (+ query guard Task 8); §5 charts/MetricCard → Tasks 9-12; §6 degradation → Tasks 5, 13; §7 rename/redirect/nav → Tasks 8, 13. baseline()/aggregate() corrections → Tasks 1, 2. All resolved questions are baked into the relevant task interfaces.
- Risk dispositions map to tests: windfall exclusions (Task 3), mixed-net labeling (Tasks 4/13), net-worth empty state (Tasks 5/13), perf (Task 8 query guard), stability over pay-cluster (Task 3).
