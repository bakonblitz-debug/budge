# Insights page redesign — design spec

**Date:** 2026-06-19
**Status:** draft (pending user review → ouroboros proving)
**Scope:** Replace the current `/statistics` ("Statistics") page with a clearer,
metrics-rich **Insights** page that makes it obvious how money is working and saving.

---

## 1. Context & goals

The current `/statistics` page (`resources/js/Pages/Statistics/Index.vue`, ~500 lines)
is fed by `InsightsController@index` → `SpendingCutAnalyzer::analyze()` +
`BudgetRuleAnalyzer::analyze()` + an optional Claude `aiSummary`. Its forward-projection
card shows two parallel columns — **As-is** vs **Normalized** — each a straight-line ×12.
Users find that confusing, and the page is number-heavy with no trend visuals.

**Goals**
1. **Clarity:** replace the As-is/Normalized two-column projection with one clear view.
2. **Visual:** add forecast-style graphs (axes, gridlines, hover) for trends and projection.
3. **Metric density done right:** surface as many "is our money working / are we saving"
   signals as the data supports, organized into labeled blocks so density reads as clarity.
4. **Windfall visibility:** surface money that came in *beyond* the regular paycheque
   (tax refunds, bonuses, interest, transfers-in).
5. **Rename** the page to **Insights** (`/insights`, keep `/statistics` as a redirect).

**Non-goals**
- No new charting *library* dependency (CLAUDE.md: deps need approval). Build on the
  existing hand-rolled SVG approach used by the Forecast page.
- No historical backfill of net-worth snapshots in this effort (see §6 degradation).
- The Needs/Wants meter math itself is unchanged (reuse `BudgetRuleAnalyzer`).

---

## 2. Page layout (blocks, top → bottom)

1. **Hero — "Are we saving?"** stat cards (savings rate, saved this month, savings
   velocity, runway, investing rate).
2. **Net worth strip** — current net worth + components; Δ/trend shown only once snapshot
   history exists (graceful empty state otherwise).
3. **Cash-flow trend graph** — 12 months, view toggle: `Cash flow` · `Net` · `By category`.
4. **The clear projection** — typical-month number (Income / Spend / **Net** + annualized)
   *and* a 12-month projected-savings line graph; one-time spend shown as a separate callout.
5. **Money in beyond your pay** — non-paycheque inflows (refunds, bonuses, interest,
   transfers-in): list + total + % of total inflow.
6. **Where the money goes** — category breakdown chart (donut + ranked bars), Needs/Wants
   50/30/10/10 meter, discretionary-vs-essential, fixed-vs-variable, subscription burden,
   biggest movers MoM, average daily burn.
7. **Cut opportunities** — ranked categories + potential monthly savings (kept).
8. **Savings goals** — progress to next `savings_milestones` (kept/surfaced).
9. **AI summary** — Claude narrative, on demand (kept).

Period control: **trends/projection always use 12 months**; the cut-analysis stats keep the
existing **1/2/3-month selector**.

---

## 3. Metric catalog

Each metric lists: **formula** · **source** · **availability**. "Available" = computable
now from transaction/profile/milestone data. "Degraded" = needs data we don't yet have;
must show a graceful empty state.

### A. Saving health (hero)
- **Savings rate** = `net ÷ income` for the period; plus trailing-12-mo average and ↑/↓ vs
  an implied ~20% target. · monthly aggregates · **available**.
- **Saved this month** = `income − spending` for the anchor month; + annualized run-rate
  (`× 12`). · aggregates · **available**.
- **Savings velocity** = average monthly net over the trailing 12 months. · aggregates ·
  **available**.
- **Runway (emergency cushion)** = `cash ÷ avg monthly spending` → months covered. ·
  `NetWorthService` cash + avg spend · **available**.
- **Projected savings (12 mo)** = steady-state monthly net × 12 (see §3E). · projection ·
  **available**.

### B. Wealth / net worth
- **Net worth (current)** + breakdown (cash, assets, liabilities, credit owed). ·
  `NetWorthService::current()` · **available**.
- **Investing rate** = investment-kind spend ÷ income; + `$/mo` invested. ·
  `BudgetRuleAnalyzer` investment kind · **available**.
- **Net worth Δ (month / YTD / 12 mo)** and **trend line**. · `net_worth_snapshots` ·
  **DEGRADED** — only 1 snapshot exists; show "building history…" until the daily snapshot
  job accumulates points. (See §6.)
- **Debt paydown trend** (are liabilities shrinking?). · `net_worth_snapshots.total_liabilities`
  history · **DEGRADED** — same reason. Show current total debt now; trend later.

### C. Income & inflows
- **Typical paycheque** = median of regular bi-weekly income deposits (the recurring
  cluster, ~$2,367). · income-category deposits · **available**.
- **Income stability** = consistency of the regular paycheque (e.g. coefficient of
  variation, or min–max band). · income deposits · **available**.
- **Money in beyond your pay (windfalls)** — NEW. All positive inflows in the period that
  are NOT part of the regular paycheque stream: tax refunds, bonuses, interest, transfers-in.
  Detection: take positive inflows (income-category deposits + transfer-in legs + other
  positive txns), subtract the regular-pay stream (deposits within a tolerance band of the
  median paycheque on the pay cadence); the remainder are windfalls, each labeled by
  description, with a total and a "% of total money in" figure. · transactions · **available**
  *(detection heuristic — to be grounded/stress-tested in ouroboros).*

### D. Cash-flow trend (graph)
- **Monthly Income / Spending / Net**, last 12 complete months. · aggregates · **available**.
- Toggle views: cash-flow (3 series), net-only, by-category (stacked). · aggregates · **available**.

### E. The clear projection (replaces As-is/Normalized)
- **Typical month**: steady-state Income / Spending / **Net** + annualized. Uses the
  *normalized* run-rate (one-time spikes stripped) as the single headline. ·
  `SpendingCutAnalyzer::projection()` · **available**.
- **One-time callout**: `ⓘ excludes $X one-time this period` (move-in, big purchases),
  never a competing column. · projection flags / one-time detector · **available**.
- **12-month projected-savings line graph**: cumulative net at the steady-state run-rate. ·
  derived · **available**.

### F. Where the money goes
- **Category breakdown** (donut + ranked bars) for the period. · aggregates · **available**.
- **Needs/Wants 50/30/10/10 meter** (kept). · `BudgetRuleAnalyzer` · **available**.
- **Discretionary vs essential**, **fixed vs variable**, **subscription burden**
  ($/mo + % of income). · `SpendingCutAnalyzer` · **available**.
- **Biggest movers (MoM)** — categories that grew/shrank most vs the prior month. ·
  aggregates · **available**.
- **Average daily burn** + monthly equivalent. · `CashFlowForecaster::baseline()` · **available**.
- **Expense ratio** = spending ÷ income; **MoM spending growth %**. · aggregates · **available**.

### G. Cut opportunities (kept)
- Ranked discretionary categories + potential monthly savings. · `SpendingCutAnalyzer` · **available**.

### H. Savings goals (surfaced)
- Progress to the next `savings_milestones` (current vs target, % and ETA at savings velocity). ·
  `savings_milestones` + savings velocity · **available** (6 milestones exist).

### I. AI summary (kept)
- On-demand Claude narrative. · existing `aiSummary` · **available**.

---

## 4. Backend design

`InsightsController@index` stays the entry point but composes a richer payload. To keep
controllers thin (project convention), introduce a dedicated read-only service:

- **`InsightsService`** (`app/Services/InsightsService.php`) — orchestrates the metric pack:
  saving-health metrics, the 12-month monthly trend series, windfall/non-pay inflows, net
  worth (current + degraded trend), savings-goal progress. It *reuses* `SpendingCutAnalyzer`,
  `BudgetRuleAnalyzer`, `NetWorthService`, the monthly aggregator (`SpendingAnalyzer::aggregate`),
  and `CashFlowForecaster::baseline()` rather than recomputing.
- **New small pieces inside `InsightsService`** (each independently testable):
  - `monthlyTrend(int $months = 12)` → `[{ym, income, spending, net, by_category}]`.
  - `savingHealth()` → savings rate, saved/mo, velocity, runway, projected-12.
  - `nonPayInflows()` → windfalls list + totals (the NEW detection).
  - `netWorthTrend()` → current + snapshot series (empty-safe).
  - `goalProgress()` → milestone progress.

The controller returns one `insights` object (+ existing `budgetRule`, optional `aiSummary`).
All queries honor the standard filters (`transfer_id` null, `is_excluded` false) and the
`BelongsToUser` scope — except inflows, which intentionally *include* transfer-in legs and
non-income positives (that's the point of the windfall block; handled explicitly).

## 5. Frontend design

- **Extract reusable chart components** under `resources/js/Components/charts/`:
  - `LineChart.vue` — the axis + gridline + hover + marker logic currently inline in
    `Forecast/Index.vue` (refactor Forecast to use it — DRY; keeps one charting implementation).
  - `BarChart.vue` — grouped/stacked monthly bars for the trend toggle.
  - `DonutChart.vue` — category breakdown.
- **`Insights/Index.vue`** composes the blocks from §2 using those components + small stat-card
  components. Reuse `MetricCard.vue` and `NeedsWantsMeter.vue`.
- Vuetify 3, `<script setup>`, MDI icons, theme-aware (CSS vars), per existing conventions.

## 6. Graceful degradation (must-haves)
- **Net-worth trend / debt paydown**: with <2 snapshots, render the card with current values
  and a muted "Trend builds as daily snapshots accumulate" note — never a broken/empty chart.
- **<12 months of data**: trend graph shows whatever complete months exist; labels adapt.
- **No income / no profile**: saving-health cards show "—" with a hint, not NaN/$0 noise.
- **Windfalls none**: hide the block or show "Only your regular pay came in this period."

## 7. Rename & routing
- Add `/insights` → `InsightsController@index`; rename rendered component to `Insights/Index`.
- Keep `/statistics` as a permanent redirect to `/insights` (no broken bookmarks).
- Update the nav item (label "Insights", keep/choose icon) in `AppLayout.vue`.

## 8. Testing
- **Pest feature/unit tests** for every `InsightsService` metric: savings rate, velocity,
  runway, monthly trend, windfall detection (regular pay excluded, refund/bonus/interest/
  transfer-in included), goal progress, net-worth-trend empty-state. Use factories;
  `toBeWithin()` for money.
- Chart components are visual (no JS test runner) — verify via `npm run build` + manual; the
  *data* they render is covered by the service tests.
- No regression: existing `SpendingCutAnalyzer`/`BudgetRuleAnalyzer` tests stay green.

## 9. Reused existing code
`SpendingCutAnalyzer`, `BudgetRuleAnalyzer`, `NetWorthService`, `SpendingAnalyzer::aggregate`,
`CashFlowForecaster::baseline`, `MetricCard.vue`, `NeedsWantsMeter.vue`, the Forecast chart
logic (extracted to `LineChart.vue`), `savings_milestones`.

## 10. Open assumptions / risks (for ouroboros to ground)
1. **Windfall detection heuristic** — what exactly counts as "not regular pay"? Tolerance band
   around the median paycheque, cadence matching, and whether to include transfer-in legs and
   tiny interest ($0.02) need a precise, defensible rule. Highest-risk item.
2. **Net-worth trend data gap** — confirmed only 1 snapshot; the trend/debt metrics must
   degrade, not fabricate. Decide whether to (a) just accumulate going forward, or (b) also
   reconstruct *cash* history from `transactions.balance_after` (assets/liabilities can't be
   backfilled) — leaning (a) for honesty.
3. **Savings rate / velocity windows** — anchor month vs trailing-12; bi-weekly 3-paycheque
   months must not distort (use the same median/steady-state discipline as elsewhere).
4. **Runway** denominator — avg monthly spending over which window; exclude one-time spikes.
5. **Performance** — 12-month aggregates + several services per request; confirm query count
   is reasonable (reuse one aggregate call, don't re-query per metric).
6. **Income basis consistency** — reuse the same income resolution (kind='income') fixed
   earlier so Insights matches the dashboard/budget-rule.
