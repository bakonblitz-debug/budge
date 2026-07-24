# Plan: Insights page redesign (Budge)

## Status: provisional (same-model verifier — CERTIFIED, no foreign model available)
## Verification: CERTIFIED by an independent, context-isolated same-model reviewer (2026-06-19); marked provisional because no foreign-model CLI was available to cross-check.
## Requirements source: brainstorming spec docs/superpowers/specs/2026-06-19-insights-redesign-design.md

> **Paths:** all `app/...` and `resources/js/...` paths below are relative to the Laravel root
> `src/` (i.e. `src/app/...`, `src/resources/js/...`). Verifier note applied.

## Approach

Replace `/statistics` with a clearer, tiered, metrics-rich **Insights** page. Backend metrics
live in a new read-only `InsightsService`; the controller stays thin. Charts become reusable
Vue components. Every figure reuses the existing income/spend resolution so Insights matches the
dashboard and budget-rule.

### Backend
1. **`InsightsService`** (`app/Services/InsightsService.php`, read-only) composes the payload,
   reusing `SpendingCutAnalyzer`, `BudgetRuleAnalyzer`, `NetWorthService`, and the burn baseline.
   Methods (each independently Pest-tested): `monthlyTrend(12)`, `savingHealth()`,
   `payAndWindfalls()`, `netWorth()`, `goalProgress()`.
2. **Monthly trend** — the aggregator (`App\Services\Ai\SpendingAnalyzer::aggregate`) is
   **spend-only** (`amount<0`) and has no income/net. So `monthlyTrend()` fetches the period's
   transactions once and folds them in PHP into `[{ym, income, spending, net, by_category}]`:
   income via the `kind='income'` resolution, spending via the standard expense filters, net =
   income − spending. 12 complete months.
3. **Pay vs windfall (income stream only)** — operate ONLY on income-category deposits (the
   `kind='income'` set). A deposit is **regular pay** iff it matches the recurring pay cluster:
   description matches the salary pattern (`SALAIRE`/payroll) AND falls on the ~bi-weekly cadence
   AND within an amount band of the median paycheque. Everything else in the income category is a
   **windfall** (tax refunds, bonuses). Suppress deposits `< $1` (tiny interest). **Exclude
   transfer-in legs and all non-income positives entirely** (grounding refuted them: the 30
   transfer-ins are credit-card payments; non-income positives are $12k investment redemptions +
   $9.9k excluded transfers). Output: windfall list (date/description/amount) + total + % of total
   money-in. The same pay-cluster discriminator feeds the **typical paycheque** and **income
   stability** metrics (computed over the pay cluster, not the raw income category).
4. **Saving health** — `savingRate`, `savedThisMonth`, `savingsVelocity` (trailing-12 avg net),
   `runway` (`NetWorthService::current().cash ÷ avg monthly spending`, denominator excludes
   one-time spikes via the burn baseline), `projectedSavings12` (steady-state monthly net × 12).
   Present **two clearly-labeled net bases**: a **steady-state** headline (median income with
   one-time spend stripped — consistent with the projection) AND **actual this month**, each card
   labeled with its window. Savings rate is computed on the steady-state basis (primary), actual
   shown beside it.
5. **Burn baseline** — promote `CashFlowForecaster::baseline()` from private to **public** (or add
   a public `burnRate(): array{daily,monthly}` accessor). Do NOT route through `forecast()`. Both
   `/forecast` and Insights consume the one accessor.
6. **Net worth** — `netWorth()` returns current (cash/assets/liabilities/credit) always; Δ and
   trend only when `net_worth_snapshots` has ≥2 points, else flagged for the `—` empty state.
   Add a **daily scheduled artisan command** (`app/Console/Commands`, e.g. `networth:snapshot`)
   that calls `NetWorthService::snapshot()` for every user, registered in the app schedule, so the
   trend accumulates. (Mirrors the existing on-demand snapshot in `NetWorthController`.)
7. **Goal progress** — `goalProgress()` reads `savings_milestones`, returns current vs target, %,
   and ETA at savings velocity.
8. **Performance** — add a Pest **query-count guard** (`DB::enableQueryLog`) asserting a measured
   ceiling for `GET /insights`. Reuse a single fetched transaction set across metrics where
   practical; accept-and-document the final count after measuring.

### Frontend
9. **Extract chart components** under `resources/js/Components/charts/`:
   `LineChart.vue` (generic props: series, x/y accessors, axes, gridlines, hover, markers — lifted
   from the Forecast inline SVG), `BarChart.vue` (grouped/stacked monthly bars for the trend
   toggle), `DonutChart.vue` (category breakdown). None exist today; line logic is extracted, bar
   and donut built from scratch (hand-rolled SVG — no new dependency).
10. **Refactor** `Forecast/Index.vue` AND `NetWorth/Index.vue` onto `LineChart.vue` (one charting
    implementation; both already render hand-rolled line charts).
11. **Extend `MetricCard.vue`** with a `format`/`unit` prop (currency | percent | months | text)
    so hero cards can render `24%`, `3.0 mo`, and the `—` empty state — today it always
    CAD-formats `value:Number`.
12. **`Insights/Index.vue`** assembles the **tiered** layout: 3–4 hero cards answer "are we
    saving?" at a glance; everything else (net worth, where-money-goes sub-metrics, windfalls,
    goals, AI) lives under expandable `v-expansion-panels`/"details" sections. All metrics kept;
    progressive disclosure provides the clarity. Reuses `MetricCard`, `NeedsWantsMeter`,
    `SmartSelect`. Trend graph has a `Cash flow · Net · By category` toggle (12 mo). Projection =
    typical-month number + 12-mo projected-savings `LineChart`, one-time spend as a callout.
13. **Rename & route** — add `/insights` → `InsightsController@index` rendering `Insights/Index`
    (title "Insights"); permanent redirect `/statistics` → `/insights`; update the `AppLayout.vue`
    nav item. Old bookmarks keep working.

### Graceful degradation
- Net-worth Δ/trend/debt with <2 snapshots → explicit `—` + "building history" note, never `$0`
  or a flat line (mirror `NetWorth/Index.vue`'s `history.length>=2` gate).
- <12 months data → trend shows the complete months that exist.
- No income/profile → saving-health cards show `—` + hint, not NaN/$0.
- No windfalls → "Only your regular pay came in this period."

### Testing
- Pest unit/feature tests for every `InsightsService` method: monthly trend (income+net correct,
  not spend-only), pay/windfall split (SALAIRE pay excluded, refunds included, transfer-ins &
  non-income & sub-$1 excluded), saving-health windows, goal progress, net-worth empty-state, and
  the `GET /insights` query-count ceiling. `toBeWithin()` for money. Charts are visual (no JS
  runner) → covered indirectly by the service tests + `npm run build`. Existing analyzer tests
  stay green.

## Questions
- [resolved/user] Windfall rule? → Smart off-rhythm: income-category deposits not matching the
  pay cluster (SALAIRE + ~14-day cadence + amount band); refunds/bonuses count; tiny interest
  `<$1` suppressed; transfer-ins & non-income positives excluded. (user, 2026-06-19)
- [resolved/user] Hero headline net basis? → Show BOTH steady-state (headline) AND actual-this-
  month, each labeled with its window. (user, 2026-06-19)
- [resolved/user] Metric density? → Tiered: keep all metrics; 3–4 hero cards + progressive
  disclosure for the rest. (user, 2026-06-19)
- [resolved/user] Net-worth history mechanism? → Add a daily scheduled snapshot command. (user, 2026-06-19)
- [resolved/verified] Income resolution? → `kind='income'` (+ children), shared by IncomeController
  & BudgetRuleAnalyzer; Insights reuses it. (BudgetRuleAnalyzer.php:413-426; IncomeController.php:139-151)
- [resolved/verified] Is `baseline()` reusable? → No, it's private; promote to public.
  (CashFlowForecaster.php:117)
- [resolved/verified] Does `aggregate()` give income/net? → No, spend-only at
  `App\Services\Ai\SpendingAnalyzer`; income/net need a new fold. (grounding run)
- [resolved/verified] Snapshot history / job? → 1 snapshot, no scheduled job; only manual POST
  /net-worth/snapshot. (DB run; routes/console.php; NetWorthController.php:33)
- [resolved/verified] Reusable components / charts? → MetricCard, NeedsWantsMeter, SmartSelect
  exist; no donut/bar chart exists (build); Forecast & NetWorth have line charts to share. (grounding)

## Claims
- [verified] Income deposits resolve by `kind='income'` (+children); windfalls live mixed inside
  the income category and must be split by description/cadence/amount, not by category. (grounding)
- [verified] Transfer-in legs and non-income positives are NOT windfalls for this data
  (CC payments / investment redemptions / excluded transfers). (grounding DB run)
- [verified] `CashFlowForecaster::baseline()` is private; `App\Services\Ai\SpendingAnalyzer::aggregate`
  is spend-only. Both require the plan's corrections above. (grounding)
- [verified] Only 1 net-worth snapshot, no scheduler → trend must degrade + a job must be added
  to accumulate. (grounding)
- [verified] MetricCard always CAD-formats a Number → needs a format prop for %/months/—. (MetricCard.vue:38)
- [verified] Reusable: MetricCard, NeedsWantsMeter, SmartSelect; chart line logic extractable;
  donut/bar to be built; thin-controller+service is the convention. (grounding)

## Risks
- Misleading "money in" by counting internal flows — **mitigated**: windfalls restricted to the
  income stream; transfer-ins & non-income positives excluded by design + tests.
- Hero confusion from mixed net bases — **mitigated**: two explicitly-labeled bases; savings rate
  on the steady-state basis.
- Information overload vs the "clearer" goal — **mitigated**: tiered layout / progressive disclosure.
- Net-worth empty-state read as $0/decline — **mitigated**: explicit `—` + note, gated on ≥2 points.
- Page slowness (~20–40 queries composing many services) — **accepted-and-documented**: add a
  query-count guard test with a measured ceiling; optimize the shared transaction fetch if it
  exceeds it. (Exact count not yet measured — the guard test forces the measurement.)
- Income-stability/typical-paycheque skewed by refunds/interest in the income category —
  **mitigated**: computed over the pay cluster via the shared discriminator.

## Rejected alternatives
- Treat transfer-in legs / all positives as windfalls — rejected: grounding proved they're CC
  payments + investment redemptions (would inflate "money in" ~$45k).
- Reuse `aggregate()` for income/net — rejected: spend-only; would zero the income/net trend lines.
- Call `forecast()` to get the burn — rejected: recomputes a full timeline; promote `baseline()` instead.
- Adopt a charting library — rejected: dependency change needs approval; hand-rolled SVG suffices.
- Show all ~25 metrics flat — rejected by user: tiered/progressive disclosure.

## Open questions / deadlocks
None — all preference questions resolved by the user (2026-06-19); all verifiable claims grounded.
