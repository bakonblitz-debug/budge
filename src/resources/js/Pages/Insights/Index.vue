<template>
    <div>
        <!-- Hero row: at-a-glance cards -->
        <v-row class="mb-4">
            <v-col cols="12" sm="6" md="3">
                <MetricCard
                    title="Savings rate (steady)"
                    :value="insights.saving_health.steady.savings_rate"
                    format="percent"
                    icon="mdi-piggy-bank"
                    color="success"
                />
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <MetricCard
                    title="Saved this month"
                    :value="insights.saving_health.actual_month.net"
                    format="currency"
                    icon="mdi-wallet-plus"
                    color="primary"
                    :signed="true"
                />
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <MetricCard
                    title="Cash runway"
                    :value="insights.saving_health.runway_months"
                    format="months"
                    icon="mdi-gas-station"
                    color="warning"
                />
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <MetricCard
                    title="Net worth"
                    :value="insights.net_worth.current.net_worth"
                    format="currency"
                    icon="mdi-scale-balance"
                    color="info"
                />
            </v-col>
        </v-row>

        <!-- Steady-state headline + actual-month comparison -->
        <v-card class="mb-4">
            <v-card-text class="d-flex flex-wrap align-center" style="gap: 24px;">
                <div>
                    <div class="text-caption text-medium-emphasis">Steady state (projection)</div>
                    <div class="text-subtitle-1 font-weight-bold">
                        {{ fmt(insights.saving_health.steady.income) }} in ·
                        {{ fmt(insights.saving_health.steady.spending) }} out ·
                        <span :class="insights.saving_health.steady.net >= 0 ? 'text-success' : 'text-error'">
                            {{ signed(insights.saving_health.steady.net) }} net
                        </span>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                        {{ insights.saving_health.steady.savings_rate }}% savings rate ·
                        {{ fmt(insights.saving_health.steady.projected_year) }}/yr projected
                    </div>
                </div>
                <v-divider vertical class="mx-2" />
                <div>
                    <div class="text-caption text-medium-emphasis">This month (actual)</div>
                    <div class="text-subtitle-1 font-weight-bold">
                        {{ fmt(insights.saving_health.actual_month.income) }} in ·
                        {{ fmt(insights.saving_health.actual_month.spending) }} out ·
                        <span :class="insights.saving_health.actual_month.net >= 0 ? 'text-success' : 'text-error'">
                            {{ signed(insights.saving_health.actual_month.net) }} net
                        </span>
                    </div>
                    <div class="text-caption text-medium-emphasis">
                        {{ insights.saving_health.actual_month.savings_rate }}% savings rate
                    </div>
                </div>
                <v-divider vertical class="mx-2" />
                <div>
                    <div class="text-caption text-medium-emphasis">12-month velocity</div>
                    <div class="text-subtitle-1 font-weight-bold">
                        <span :class="insights.saving_health.velocity >= 0 ? 'text-success' : 'text-error'">
                            {{ signed(insights.saving_health.velocity) }}/mo avg
                        </span>
                    </div>
                    <div class="text-caption text-medium-emphasis">average monthly net</div>
                </div>
            </v-card-text>
        </v-card>

        <!-- Trend chart with toggle -->
        <v-card class="mb-4">
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-chart-bar" class="mr-2" />
                12-month trend
                <v-spacer />
                <v-btn-toggle v-model="trendMode" mandatory density="compact" color="primary">
                    <v-btn value="cashflow" size="small">Cash flow</v-btn>
                    <v-btn value="net" size="small">Net</v-btn>
                    <v-btn value="category" size="small">By category</v-btn>
                </v-btn-toggle>
            </v-card-title>
            <v-card-text>
                <!-- "How to read this" explanation, per mode -->
                <v-alert v-if="trendMode === 'cashflow'" variant="tonal" density="compact" class="mb-3 text-caption">
                    The last 12 months of your cash flow. Each month shows three bars:
                    <span class="d-inline-flex align-center" style="gap: 4px;"><span :style="swatch('#43a047')" /><strong>income</strong> (money in)</span>,
                    <span class="d-inline-flex align-center" style="gap: 4px;"><span :style="swatch('#e53935')" /><strong>spending</strong> (money out)</span>, and
                    <span class="d-inline-flex align-center" style="gap: 4px;"><span :style="swatch('#1e88e5')" /><strong>net</strong> (what's left)</span>.
                    When the green bar is taller than the red, you saved that month and net is positive. Hover any month for exact amounts.
                </v-alert>
                <v-alert v-else-if="trendMode === 'net'" variant="tonal" density="compact" class="mb-3 text-caption">
                    What you <strong>saved</strong> each month — income minus spending. The dashed line is $0: points above it mean you came
                    out ahead that month, below means you spent more than you earned. Hover any point for the exact amount.
                </v-alert>
                <v-alert v-else variant="tonal" density="compact" class="mb-3 text-caption">
                    Where your <strong>spending</strong> went over the last 12 months, grouped by category kind (needs vs wants vs investments).
                    A bigger slice means more spent there. Hover a slice for its share and total.
                </v-alert>

                <!-- Cash flow: grouped income/spending/net bars -->
                <BarChart
                    v-if="trendMode === 'cashflow'"
                    :series="trendSeries"
                    mode="grouped"
                    :keys="[
                        { key: 'income', label: 'Income', color: '#43a047' },
                        { key: 'spending', label: 'Spending', color: '#e53935' },
                        { key: 'net', label: 'Net', color: '#1e88e5' },
                    ]"
                />
                <!-- Net only: line chart -->
                <LineChart
                    v-else-if="trendMode === 'net'"
                    :points="netLinePoints"
                    :y-ticks="netLineTicks"
                    :x-ticks="netLineXTicks"
                    :zero-y="netLineZeroY"
                    stroke="#1e88e5"
                    :hover-format="(p) => fmtSigned(p.value)"
                />
                <!-- By category: donut of spending breakdown -->
                <template v-else>
                    <DonutChart v-if="categoryDonutSlices.length" :slices="categoryDonutSlices" />
                    <div v-else class="text-medium-emphasis text-caption">No categorized spending data yet.</div>
                </template>
            </v-card-text>
        </v-card>

        <!-- Projection card -->
        <v-card class="mb-4">
            <v-card-title>
                <v-icon icon="mdi-trending-up" class="mr-2" />
                Projection
            </v-card-title>
            <v-card-text>
                <v-row>
                    <v-col cols="12" sm="4">
                        <div class="text-caption text-medium-emphasis">Typical monthly income</div>
                        <div class="text-h6 font-weight-bold text-success">{{ fmt(insights.saving_health.steady.income) }}</div>
                        <div class="text-caption text-medium-emphasis">{{ fmt(insights.saving_health.steady.income * 12) }}/yr</div>
                    </v-col>
                    <v-col cols="12" sm="4">
                        <div class="text-caption text-medium-emphasis">Typical monthly spend</div>
                        <div class="text-h6 font-weight-bold text-error">{{ fmt(insights.saving_health.steady.spending) }}</div>
                        <div class="text-caption text-medium-emphasis">{{ fmt(insights.saving_health.steady.spending * 12) }}/yr</div>
                    </v-col>
                    <v-col cols="12" sm="4">
                        <div class="text-caption text-medium-emphasis">Projected net</div>
                        <div class="text-h6 font-weight-bold" :class="insights.saving_health.steady.net >= 0 ? 'text-success' : 'text-error'">
                            {{ signed(insights.saving_health.steady.net) }}/mo
                        </div>
                        <div class="text-caption text-medium-emphasis">
                            {{ signed(insights.saving_health.steady.projected_year) }}/yr
                        </div>
                    </v-col>
                </v-row>

                <!-- 12-month projected savings line -->
                <div v-if="projectedSavingsPoints.length > 1" class="mt-4">
                    <div class="text-caption text-medium-emphasis mb-2">Projected savings accumulation (12 mo)</div>
                    <LineChart
                        :points="projectedSavingsPoints"
                        :y-ticks="projectedSavingsTicks"
                        :x-ticks="projectedSavingsXTicks"
                        :zero-y="projectedSavingsZeroY"
                        stroke="#43a047"
                        :hover-format="(p) => fmt(p.value)"
                    />
                </div>

                <v-alert
                    v-if="insights.windfalls.windfall_total > 0"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mt-3 text-caption"
                >
                    <v-icon icon="mdi-information-outline" size="16" class="mr-1" />
                    Excludes {{ fmt(insights.windfalls.windfall_total) }} in one-time income this period (windfalls).
                </v-alert>
                <v-alert
                    v-if="cutting?.projection?.flags?.length"
                    type="warning"
                    variant="tonal"
                    density="compact"
                    class="mt-3 text-caption"
                >
                    <v-icon icon="mdi-alert-outline" size="16" class="mr-1" />
                    {{ cutting.projection.flags.length }} likely one-time spike{{ cutting.projection.flags.length > 1 ? 's' : '' }} detected
                    ({{ cutting.projection.flags.map((f) => f.category).join(', ') }}).
                    Normalized projection removes {{ fmt(cutting.projection.as_is.monthly - cutting.projection.normalized.monthly) }}/mo.
                </v-alert>
            </v-card-text>
        </v-card>

        <!-- Progressive-disclosure panels -->
        <v-expansion-panels variant="accordion" class="mb-4">
            <!-- Windfalls -->
            <v-expansion-panel>
                <v-expansion-panel-title>
                    <v-icon icon="mdi-cash-fast" class="mr-2" />
                    Money in beyond your pay
                    <v-chip
                        v-if="insights.windfalls.windfall_total > 0"
                        class="ml-3"
                        size="x-small"
                        color="success"
                        variant="flat"
                    >{{ fmt(insights.windfalls.windfall_total) }}</v-chip>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <template v-if="insights.windfalls.windfalls.length">
                        <div class="d-flex flex-wrap mb-3" style="gap: 12px;">
                            <div>
                                <div class="text-caption text-medium-emphasis">Windfall total</div>
                                <div class="text-subtitle-1 font-weight-bold text-success">{{ fmt(insights.windfalls.windfall_total) }}</div>
                            </div>
                            <div>
                                <div class="text-caption text-medium-emphasis">% of income</div>
                                <div class="text-subtitle-1 font-weight-bold">{{ insights.windfalls.windfall_pct }}%</div>
                            </div>
                            <div v-if="insights.windfalls.typical_paycheque > 0">
                                <div class="text-caption text-medium-emphasis">Typical paycheque</div>
                                <div class="text-subtitle-1 font-weight-bold">{{ fmt(insights.windfalls.typical_paycheque) }}</div>
                            </div>
                            <div v-if="insights.windfalls.stability != null">
                                <div class="text-caption text-medium-emphasis">Income stability</div>
                                <div class="text-subtitle-1 font-weight-bold">
                                    {{ stabilityLabel }}
                                    <span class="text-caption text-medium-emphasis ml-1">(CoV {{ insights.windfalls.stability.toFixed(2) }})</span>
                                </div>
                            </div>
                        </div>
                        <v-table density="compact">
                            <thead>
                                <tr>
                                    <th class="text-left">Date</th>
                                    <th class="text-left">Description</th>
                                    <th class="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(w, i) in insights.windfalls.windfalls" :key="i">
                                    <td>{{ w.date }}</td>
                                    <td>{{ w.description }}</td>
                                    <td class="text-right text-success">{{ fmt(w.amount) }}</td>
                                </tr>
                            </tbody>
                        </v-table>
                    </template>
                    <div v-else class="text-medium-emphasis text-caption">
                        Only your regular pay came in this period.
                    </div>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- Where the money goes -->
            <v-expansion-panel>
                <v-expansion-panel-title>
                    <v-icon icon="mdi-chart-pie" class="mr-2" />
                    Where the money goes
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <NeedsWantsMeter v-if="budgetRule" :meter="budgetRule" :compact="false" />
                    <div v-else class="text-medium-emphasis text-caption">No budget-rule data yet.</div>
                    <template v-if="cutting">
                        <v-divider class="my-3" />
                        <div class="d-flex flex-wrap" style="gap: 24px;">
                            <div>
                                <div class="text-caption text-medium-emphasis">Discretionary</div>
                                <div class="text-subtitle-1 font-weight-bold text-warning">{{ fmt(cutting.discretionary_total) }}</div>
                            </div>
                            <div>
                                <div class="text-caption text-medium-emphasis">Essential</div>
                                <div class="text-subtitle-1 font-weight-bold">{{ fmt(cutting.essential_total) }}</div>
                            </div>
                            <div v-if="cutting.subscription_cost > 0">
                                <div class="text-caption text-medium-emphasis">Subscriptions</div>
                                <div class="text-subtitle-1 font-weight-bold">{{ fmt(cutting.subscription_cost) }}/mo</div>
                            </div>
                            <div v-if="cutting.potential_monthly_savings > 0">
                                <div class="text-caption text-medium-emphasis">Potential savings</div>
                                <div class="text-subtitle-1 font-weight-bold text-success">{{ fmt(cutting.potential_monthly_savings) }}/mo</div>
                            </div>
                        </div>
                    </template>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- Cut opportunities -->
            <v-expansion-panel v-if="cutOpportunities.length">
                <v-expansion-panel-title>
                    <v-icon icon="mdi-scissors-cutting" class="mr-2" />
                    Cut opportunities
                    <v-chip class="ml-3" size="x-small" color="warning" variant="flat">
                        {{ cutOpportunities.length }}
                    </v-chip>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <v-table density="compact">
                        <thead>
                            <tr>
                                <th class="text-left">Category</th>
                                <th class="text-right">Monthly spend</th>
                                <th class="text-right">Share</th>
                                <th class="text-right">
                                    <span class="d-inline-flex align-center" style="gap: 2px;">
                                        MoM
                                        <v-icon icon="mdi-help-circle-outline" size="14" />
                                        <v-tooltip activator="parent" location="top" max-width="240">
                                            Month-over-month: how this category's spending changed vs the previous month. Green = down (good), red = up.
                                        </v-tooltip>
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(opp, i) in cutOpportunities" :key="i">
                                <td>{{ opp.category }}</td>
                                <td class="text-right">{{ fmt(opp.monthly_spend) }}</td>
                                <td class="text-right">{{ opp.share_of_discretionary }}%</td>
                                <td class="text-right">
                                    <span v-if="opp.mom_growth_pct != null" :class="opp.mom_growth_pct > 0 ? 'text-error' : 'text-success'">
                                        {{ opp.mom_growth_pct > 0 ? '+' : '' }}{{ opp.mom_growth_pct }}%
                                    </span>
                                    <span v-else class="text-medium-emphasis">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </v-table>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- Savings goals -->
            <v-expansion-panel v-if="insights.goal_progress.length">
                <v-expansion-panel-title>
                    <v-icon icon="mdi-trophy" class="mr-2" />
                    Savings goals
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <div v-for="(goal, i) in insights.goal_progress" :key="i" class="mb-4">
                        <div class="d-flex align-center mb-1">
                            <span class="font-weight-medium">{{ goal.name }}</span>
                            <v-spacer />
                            <span class="text-caption text-medium-emphasis">
                                {{ fmt(goal.current) }} / {{ fmt(goal.target) }}
                            </span>
                            <v-chip class="ml-2" size="x-small" :color="goal.pct >= 100 ? 'success' : 'primary'" variant="flat">
                                {{ goal.pct }}%
                            </v-chip>
                        </div>
                        <v-progress-linear
                            :model-value="goal.pct"
                            :color="goal.pct >= 100 ? 'success' : 'primary'"
                            height="8"
                            rounded
                        />
                        <div class="text-caption text-medium-emphasis mt-1">
                            <template v-if="goal.pct >= 100">Goal reached!</template>
                            <template v-else-if="goal.eta_months != null">
                                ETA: ~{{ goal.eta_months }} months at current velocity
                            </template>
                            <template v-else>Set a savings velocity to see ETA</template>
                        </div>
                    </div>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- Net worth trend -->
            <v-expansion-panel v-if="insights.net_worth.has_history">
                <v-expansion-panel-title>
                    <v-icon icon="mdi-trending-up" class="mr-2" />
                    Net worth trend
                    <span v-if="insights.net_worth.delta_month != null" class="ml-3 text-caption">
                        <span :class="insights.net_worth.delta_month >= 0 ? 'text-success' : 'text-error'">
                            {{ signed(insights.net_worth.delta_month) }} last period
                        </span>
                    </span>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <div class="d-flex flex-wrap mb-3" style="gap: 24px;">
                        <div>
                            <div class="text-caption text-medium-emphasis">Current net worth</div>
                            <div class="text-h6 font-weight-bold">{{ fmt(insights.net_worth.current.net_worth) }}</div>
                        </div>
                        <div v-if="insights.net_worth.delta_month != null">
                            <div class="text-caption text-medium-emphasis">Last period change</div>
                            <div class="text-h6 font-weight-bold" :class="insights.net_worth.delta_month >= 0 ? 'text-success' : 'text-error'">
                                {{ signed(insights.net_worth.delta_month) }}
                            </div>
                        </div>
                        <div v-if="insights.net_worth.delta_ytd != null">
                            <div class="text-caption text-medium-emphasis">Year to date</div>
                            <div class="text-h6 font-weight-bold" :class="insights.net_worth.delta_ytd >= 0 ? 'text-success' : 'text-error'">
                                {{ signed(insights.net_worth.delta_ytd) }}
                            </div>
                        </div>
                    </div>
                    <LineChart
                        v-if="netWorthLinePoints.length > 1"
                        :points="netWorthLinePoints"
                        :y-ticks="netWorthYTicks"
                        :x-ticks="netWorthXTicks"
                        :zero-y="netWorthZeroY"
                        stroke="#43a047"
                        :hover-format="(p) => fmt(p.value)"
                    />
                </v-expansion-panel-text>
            </v-expansion-panel>
            <v-expansion-panel v-else>
                <v-expansion-panel-title>
                    <v-icon icon="mdi-trending-up" class="mr-2" />
                    Net worth trend
                    <span class="ml-3 text-caption text-medium-emphasis">building history</span>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <div class="text-medium-emphasis text-caption">
                        Net worth history will appear here once daily snapshots have been collected.
                        Current: {{ fmt(insights.net_worth.current.net_worth) }}
                    </div>
                </v-expansion-panel-text>
            </v-expansion-panel>

            <!-- AI summary -->
            <v-expansion-panel>
                <v-expansion-panel-title>
                    <v-icon icon="mdi-robot-outline" class="mr-2" />
                    AI summary
                    <span class="ml-2 text-caption text-medium-emphasis">(on demand)</span>
                </v-expansion-panel-title>
                <v-expansion-panel-text>
                    <div v-if="aiSummaryLoading" class="d-flex align-center" style="gap: 8px;">
                        <v-progress-circular size="18" indeterminate color="primary" />
                        <span class="text-caption text-medium-emphasis">Generating summary…</span>
                    </div>
                    <div v-else-if="resolvedAiSummary" class="text-body-2">
                        {{ resolvedAiSummary }}
                    </div>
                    <div v-else class="d-flex flex-column align-start" style="gap: 8px;">
                        <div class="text-caption text-medium-emphasis">Get a plain-language summary of your financial picture.</div>
                        <v-btn size="small" variant="tonal" color="primary" @click="loadAiSummary">
                            <v-icon icon="mdi-robot-outline" class="mr-1" size="16" />
                            Generate summary
                        </v-btn>
                    </div>
                </v-expansion-panel-text>
            </v-expansion-panel>
        </v-expansion-panels>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import MetricCard from '@/Components/MetricCard.vue'
import NeedsWantsMeter from '@/Components/NeedsWantsMeter.vue'
import LineChart from '@/Components/charts/LineChart.vue'
import BarChart from '@/Components/charts/BarChart.vue'
import DonutChart from '@/Components/charts/DonutChart.vue'
import { niceTicks } from '@/Components/charts/ticks.js'

const props = defineProps({
    insights: { type: Object, required: true },
    budgetRule: { type: Object, default: null },
    cutting: { type: Object, default: null },
    aiSummary: { type: String, default: null },
})

// ── Trend toggle ────────────────────────────────────────────────────────────
const trendMode = ref('cashflow')

// Bar chart series (12 months)
const trendSeries = computed(() =>
    (props.insights.trend || []).map((row) => ({
        label: monthAbbr(row.ym), // "May"
        values: { income: row.income, spending: row.spending, net: row.net },
    })),
)

// Net-only line chart
const netLineChart = computed(() => {
    const trend = props.insights.trend || []
    if (!trend.length) return { points: [], yTicks: [], zeroY: 120, xTicks: [] }
    const nets = trend.map((r) => r.net)
    const ticks = niceTicks(Math.min(...nets, 0), Math.max(...nets, 0), 4)
    const domMin = Math.min(...ticks)
    const domMax = Math.max(...ticks)
    const range = domMax - domMin || 1
    const TOP = 8; const BOTTOM = 232
    const y = (v) => BOTTOM - ((v - domMin) / range) * (BOTTOM - TOP)

    const points = trend.map((r, i) => ({
        svgX: trend.length > 1 ? (i / (trend.length - 1)) * 1000 : 0,
        svgY: y(r.net),
        x: trend.length > 1 ? (i / (trend.length - 1)) * 100 : 0,
        y: y(r.net),
        label: monthAbbr(r.ym),
        value: r.net,
    }))
    const yTicks = ticks.map((v) => ({ value: v, y: y(v) }))
    const step = Math.max(1, Math.floor(trend.length / 4))
    const xTicks = trend.filter((_, i) => i % step === 0 || i === trend.length - 1).map((r) => monthAbbr(r.ym))
    return { points, yTicks, zeroY: y(0), xTicks }
})

const netLinePoints = computed(() => netLineChart.value.points)
const netLineTicks = computed(() => netLineChart.value.yTicks)
const netLineXTicks = computed(() => netLineChart.value.xTicks)
const netLineZeroY = computed(() => netLineChart.value.zeroY)

// By-category donut: aggregate spending per kind from budgetRule
const categoryDonutSlices = computed(() => {
    if (!props.budgetRule?.kinds) return []
    const kindColors = {
        need: '#1E88E5',
        want: '#8E24AA',
        investment: '#43A047',
        saving: '#00897B',
    }
    return ['need', 'want', 'investment', 'saving']
        .filter((k) => (props.budgetRule.kinds[k]?.amount || 0) > 0)
        .map((k) => ({
            label: { need: 'Needs', want: 'Wants', investment: 'Investment', saving: 'Saving' }[k],
            value: Math.abs(props.budgetRule.kinds[k].amount),
            color: kindColors[k],
        }))
})

// ── Projection line: 12-month cumulative savings starting from current cash ──
const projectedSavingsChart = computed(() => {
    const monthlyNet = props.insights.saving_health.steady.net || 0
    const startCash = props.insights.net_worth.current.cash || 0
    const n = 13 // 0..12
    const pts = Array.from({ length: n }, (_, i) => startCash + monthlyNet * i)
    const ticks = niceTicks(Math.min(...pts, 0), Math.max(...pts), 4)
    const domMin = Math.min(...ticks)
    const domMax = Math.max(...ticks)
    const range = domMax - domMin || 1
    const TOP = 8; const BOTTOM = 232
    const y = (v) => BOTTOM - ((v - domMin) / range) * (BOTTOM - TOP)

    const points = pts.map((v, i) => ({
        svgX: (i / (n - 1)) * 1000,
        svgY: y(v),
        x: (i / (n - 1)) * 100,
        y: y(v),
        label: `Month ${i}`,
        value: v,
    }))
    return {
        points,
        yTicks: ticks.map((v) => ({ value: v, y: y(v) })),
        xTicks: ['Now', '3 mo', '6 mo', '9 mo', '12 mo'],
        zeroY: y(0),
    }
})

const projectedSavingsPoints = computed(() => projectedSavingsChart.value.points)
const projectedSavingsTicks = computed(() => projectedSavingsChart.value.yTicks)
const projectedSavingsXTicks = computed(() => projectedSavingsChart.value.xTicks)
const projectedSavingsZeroY = computed(() => projectedSavingsChart.value.zeroY)

// ── Net worth trend line ─────────────────────────────────────────────────────
const netWorthLineChart = computed(() => {
    const series = props.insights.net_worth.series || []
    if (series.length < 2) return { points: [], yTicks: [], zeroY: 120, xTicks: [] }
    const values = series.map((s) => s.net_worth)
    const ticks = niceTicks(Math.min(...values, 0), Math.max(...values), 4)
    const domMin = Math.min(...ticks)
    const domMax = Math.max(...ticks)
    const range = domMax - domMin || 1
    const TOP = 8; const BOTTOM = 232
    const y = (v) => BOTTOM - ((v - domMin) / range) * (BOTTOM - TOP)

    const n = series.length
    const points = series.map((s, i) => ({
        svgX: n > 1 ? (i / (n - 1)) * 1000 : 0,
        svgY: y(s.net_worth),
        x: n > 1 ? (i / (n - 1)) * 100 : 0,
        y: y(s.net_worth),
        label: s.as_of,
        value: s.net_worth,
    }))
    const step = Math.max(1, Math.floor(n / 4))
    const xTicks = series.filter((_, i) => i % step === 0 || i === n - 1).map((s) => s.as_of.slice(5))
    return { points, yTicks: ticks.map((v) => ({ value: v, y: y(v) })), xTicks, zeroY: y(0) }
})

const netWorthLinePoints = computed(() => netWorthLineChart.value.points)
const netWorthYTicks = computed(() => netWorthLineChart.value.yTicks)
const netWorthXTicks = computed(() => netWorthLineChart.value.xTicks)
const netWorthZeroY = computed(() => netWorthLineChart.value.zeroY)

// ── Income stability label (CoV from windfalls.stability) ────────────────────
const stabilityLabel = computed(() => {
    const cov = props.insights.windfalls.stability
    if (cov == null) return null
    if (cov < 0.1) return 'Very steady'
    if (cov < 0.25) return 'Steady'
    return 'Variable'
})

// ── Cut opportunities from SpendingCutAnalyzer ───────────────────────────────
const cutOpportunities = computed(() => props.cutting?.cut_opportunities || [])

// ── AI summary (on-demand partial reload) ───────────────────────────────────
const aiSummaryLoading = ref(false)
const localAiSummary = ref(props.aiSummary || null)

const resolvedAiSummary = computed(() => localAiSummary.value)

function loadAiSummary() {
    aiSummaryLoading.value = true
    router.reload({
        only: ['aiSummary'],
        onSuccess: (page) => {
            localAiSummary.value = page.props.aiSummary || null
        },
        onFinish: () => {
            aiSummaryLoading.value = false
        },
    })
}

// ── Formatters ───────────────────────────────────────────────────────────────
function fmt(value) {
    return Number(value || 0).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}

// "2026-05" → "May/26"
const MONTH_ABBR = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
function monthAbbr(ym) {
    const m = Number(String(ym).slice(5, 7))
    return `${MONTH_ABBR[m - 1] || '?'}/${String(ym).slice(2, 4)}`
}

// Small colour square for inline legends/captions.
function swatch(color) {
    return { width: '10px', height: '10px', borderRadius: '2px', background: color, display: 'inline-block', flexShrink: 0 }
}

function signed(value) {
    const v = Number(value || 0)
    const s = Math.abs(v).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
    if (v < 0) return '-' + s
    if (v > 0) return '+' + s
    return s
}

function fmtSigned(value) {
    return signed(value)
}
</script>
