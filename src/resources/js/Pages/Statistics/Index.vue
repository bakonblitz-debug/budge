<template>
    <div>
        <div v-if="currentMonthLabel" class="text-caption text-medium-emphasis mb-2">
            Latest imported month — {{ currentMonthLabel }}
        </div>

        <!-- Headline metrics -->
        <v-row class="mb-2">
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="success">
                    <v-card-text>
                        <div class="text-caption">Potential monthly savings</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(analysis.potential_monthly_savings) }}</div>
                        <div
                            class="text-caption text-medium-emphasis"
                            title="A deliberately conservative blanket rule of thumb — not based on your specific habits."
                        >
                            rule-of-thumb ~30% of discretionary
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="warning">
                    <v-card-text>
                        <div class="text-caption">Discretionary this month</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(analysis.discretionary_total) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ discretionaryShare }}% of tracked spend
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="primary">
                    <v-card-text>
                        <div class="text-caption">Essential this month</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(analysis.essential_total) }}</div>
                        <div class="text-caption text-medium-emphasis">Rent, groceries, utilities…</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="info">
                    <v-card-text>
                        <div class="text-caption">Subscriptions / month</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(analysis.subscription_cost) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ analysis.subscriptions.length }} active
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <!-- Honest disclosure: spend we couldn't confidently classify is shown,
             not folded into "cuttable" discretionary. -->
        <!-- 50/30/10/10 needs vs wants meter -->
        <NeedsWantsMeter v-if="budgetRule" :meter="budgetRule" class="mb-4" />

        <div v-if="analysis.unclassified_total > 0" class="text-caption text-medium-emphasis mb-3">
            {{ fmt(analysis.unclassified_total) }} this month is in categories we couldn't classify —
            tag them essential or discretionary to refine these figures.
        </div>

        <!-- Forward projection: "if spending continues as-is" -->
        <v-card v-if="projection" class="mb-4" variant="outlined">
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-chart-line" class="mr-2" />
                If this continues
                <v-spacer />
                <span class="text-caption text-medium-emphasis">
                    Based on {{ basisMonthLabel }} only
                </span>
            </v-card-title>
            <v-card-text>
                <div class="text-body-2 text-medium-emphasis mb-4">
                    Projects your latest complete month forward — go-forward fixed costs plus
                    that month's variable run-rate. The <strong>normalized</strong> view strips
                    out one-time spikes (e.g. move-in purchases) so the picture isn't distorted.
                </div>

                <v-row>
                    <v-col cols="12" md="6">
                        <v-card variant="tonal" color="warning" class="h-100">
                            <v-card-text>
                                <div class="text-overline">As-is</div>
                                <div class="text-caption text-medium-emphasis mb-2">
                                    Keeps recent one-time spend in the run-rate
                                </div>
                                <div class="d-flex align-center mb-1">
                                    <span class="text-body-2">Annualized spend</span>
                                    <v-spacer />
                                    <span class="text-h6 font-weight-bold">~{{ fmt(projection.as_is.annual) }}</span>
                                </div>
                                <div class="d-flex align-center mb-1">
                                    <span class="text-body-2">Next 3 months</span>
                                    <v-spacer />
                                    <span class="font-weight-medium">~{{ fmt(projection.as_is.three_month) }}</span>
                                </div>
                                <div class="d-flex align-center">
                                    <span class="text-body-2" title="Straight-line ×12 from one month — assumes no seasonality or life changes.">Projected net / year</span>
                                    <v-spacer />
                                    <span
                                        class="font-weight-medium"
                                        :class="projection.as_is.projected_net_annual >= 0 ? 'text-success' : 'text-error'"
                                    >~{{ fmt(projection.as_is.projected_net_annual) }}</span>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>

                    <v-col cols="12" md="6">
                        <v-card variant="tonal" color="success" class="h-100">
                            <v-card-text>
                                <div class="text-overline">Normalized</div>
                                <div class="text-caption text-medium-emphasis mb-2">
                                    One-time spikes stripped to their baseline
                                </div>
                                <div class="d-flex align-center mb-1">
                                    <span class="text-body-2">Annualized spend</span>
                                    <v-spacer />
                                    <span class="text-h6 font-weight-bold">~{{ fmt(projection.normalized.annual) }}</span>
                                </div>
                                <div class="d-flex align-center mb-1">
                                    <span class="text-body-2">Next 3 months</span>
                                    <v-spacer />
                                    <span class="font-weight-medium">~{{ fmt(projection.normalized.three_month) }}</span>
                                </div>
                                <div class="d-flex align-center">
                                    <span class="text-body-2" title="Straight-line ×12 from one month — assumes no seasonality or life changes.">Projected net / year</span>
                                    <v-spacer />
                                    <span
                                        class="font-weight-medium"
                                        :class="projection.normalized.projected_net_annual >= 0 ? 'text-success' : 'text-error'"
                                    >~{{ fmt(projection.normalized.projected_net_annual) }}</span>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>

                <!-- Fixed vs variable split -->
                <div class="d-flex align-center mt-4 mb-1">
                    <span class="text-caption text-medium-emphasis">
                        Fixed {{ fmt(projection.fixed_monthly) }}/mo · Variable {{ fmt(projection.variable_monthly) }}/mo
                    </span>
                    <v-spacer />
                    <span
                        class="text-caption text-medium-emphasis"
                        title="Your maintained monthly net pay, not this month's deposits"
                    >
                        Income run-rate {{ fmt(projection.income_monthly) }}/mo
                    </span>
                </div>
                <v-progress-linear
                    :model-value="fixedShare"
                    color="primary"
                    bg-color="warning"
                    bg-opacity="0.6"
                    height="8"
                    rounded
                />

                <!-- One-time spike flags -->
                <template v-if="projection.flags.length">
                    <v-divider class="my-4" />
                    <div class="text-subtitle-2 mb-2 d-flex align-center">
                        <v-icon icon="mdi-flag-variant" size="18" class="mr-1" color="warning" />
                        Likely one-time spikes
                    </div>
                    <v-list density="compact" class="py-0">
                        <v-list-item
                            v-for="f in projection.flags"
                            :key="f.category"
                            class="px-0"
                        >
                            <div class="d-flex align-center w-100">
                                <span class="font-weight-medium">{{ f.category }}</span>
                                <v-chip size="x-small" color="warning" variant="tonal" class="ml-2">
                                    {{ f.note }}
                                </v-chip>
                                <v-spacer />
                                <span class="text-medium-emphasis">
                                    {{ fmt(f.last_month) }} vs usual {{ fmt(f.baseline) }}
                                    <span class="text-warning">(+{{ fmt(f.excess) }})</span>
                                </span>
                            </div>
                        </v-list-item>
                    </v-list>
                    <div class="text-caption text-medium-emphasis mt-2">
                        Refine this: open the Transactions page and toggle the per-row
                        <strong>exclude</strong> switch on specific move-in purchases to drop
                        them from the run-rate.
                    </div>
                </template>
            </v-card-text>
        </v-card>

        <!-- Possible one-time purchases to exclude -->
        <v-card v-if="oneTimeSuggestions.length" class="mb-4" variant="outlined">
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-tag-off-outline" class="mr-2" color="warning" />
                Possible one-time purchases to exclude
            </v-card-title>
            <v-card-text>
                <div class="text-body-2 text-medium-emphasis mb-3">
                    Large, infrequent, non-recurring buys (move-in furniture, hardware, moving)
                    found across the last few months. Excluding one drops it from every total —
                    refining your <strong>spending projection and forecast</strong> in one click.
                </div>
                <v-list density="compact" class="py-0">
                    <v-list-item
                        v-for="s in oneTimeSuggestions"
                        :key="s.id"
                        class="px-0"
                    >
                        <div class="d-flex align-center w-100 flex-wrap">
                            <div class="mr-2">
                                <div class="font-weight-medium">{{ s.merchant }}</div>
                                <div class="text-caption text-medium-emphasis">
                                    {{ formatDate(s.transaction_date) }} · {{ s.category }} · {{ s.reason }}
                                </div>
                            </div>
                            <v-spacer />
                            <span class="font-weight-medium mr-3">{{ fmt(s.amount) }}</span>
                            <v-btn
                                size="small"
                                color="warning"
                                variant="tonal"
                                prepend-icon="mdi-close-circle-outline"
                                :loading="excludingId === s.id"
                                :disabled="excludingId !== null"
                                @click="excludeTransaction(s.id)"
                            >
                                Exclude
                            </v-btn>
                        </div>
                    </v-list-item>
                </v-list>
            </v-card-text>
        </v-card>

        <!-- AI narrative (optional, deferred) -->
        <v-card class="mb-4" variant="tonal" color="purple">
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-creation" class="mr-2" />
                Coach's take
                <v-spacer />
                <v-btn
                    v-if="!aiSummary"
                    size="small"
                    variant="text"
                    prepend-icon="mdi-creation"
                    :loading="ai.loading"
                    @click="generateSummary"
                >
                    Generate
                </v-btn>
            </v-card-title>
            <v-card-text>
                <template v-if="ai.loading">
                    <v-skeleton-loader type="text@3" />
                </template>
                <template v-else-if="aiSummary">
                    <p class="text-body-1">{{ aiSummary }}</p>
                    <div class="text-caption text-medium-emphasis mt-2">
                        AI-written from the figures above — trust the numbers, not the prose.
                    </div>
                </template>
                <template v-else>
                    <div class="text-body-2 text-medium-emphasis">
                        An optional plain-language read of the findings below. Requires an
                        Anthropic API key; the analysis above works without it.
                    </div>
                </template>
            </v-card-text>
        </v-card>

        <v-row>
            <!-- Ranked cut opportunities -->
            <v-col cols="12" md="7">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-content-cut" class="mr-2" />
                        Where to cut
                    </v-card-title>

                    <v-card-text v-if="analysis.cut_opportunities.length === 0" class="text-center py-8 text-medium-emphasis">
                        No discretionary spending found this month.
                    </v-card-text>

                    <v-list v-else>
                        <v-list-item
                            v-for="opp in analysis.cut_opportunities"
                            :key="opp.category"
                            class="py-3"
                        >
                            <div class="flex-grow-1">
                                <div class="d-flex align-center mb-1">
                                    <span class="font-weight-medium">{{ opp.category }}</span>
                                    <v-chip
                                        v-if="opp.mom_growth_pct !== null"
                                        size="x-small"
                                        :color="opp.mom_growth_pct > 0 ? 'error' : 'success'"
                                        variant="tonal"
                                        class="ml-2"
                                    >
                                        <v-icon
                                            :icon="opp.mom_growth_pct > 0 ? 'mdi-trending-up' : 'mdi-trending-down'"
                                            start
                                            size="12"
                                        />
                                        {{ Math.abs(opp.mom_growth_pct) }}% MoM
                                    </v-chip>
                                    <v-spacer />
                                    <span class="font-weight-medium">{{ fmt(opp.monthly_spend) }}</span>
                                </div>
                                <v-progress-linear
                                    :model-value="opp.share_of_discretionary"
                                    color="warning"
                                    height="6"
                                    rounded
                                    class="mb-1"
                                />
                                <div class="text-caption text-medium-emphasis">
                                    {{ opp.share_of_discretionary }}% of discretionary ·
                                    <template v-for="(m, i) in opp.top_merchants.slice(0, 3)" :key="m.description">
                                        {{ i > 0 ? ', ' : '' }}{{ m.description }} ({{ fmt(m.total) }})
                                    </template>
                                </div>
                            </div>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-col>

            <!-- Over budget + subscriptions -->
            <v-col cols="12" md="5">
                <v-card class="mb-4">
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-alert-circle-outline" class="mr-2" color="error" />
                        Over budget
                    </v-card-title>
                    <v-card-text v-if="analysis.over_budget.length === 0" class="text-medium-emphasis">
                        Nothing over budget this period.
                    </v-card-text>
                    <v-list v-else>
                        <v-list-item v-for="b in analysis.over_budget" :key="b.category">
                            <div class="d-flex align-center w-100">
                                <span class="font-weight-medium">{{ b.category }}</span>
                                <v-spacer />
                                <span class="text-error font-weight-medium">{{ fmt(b.overage) }} over</span>
                            </div>
                            <div class="text-caption text-medium-emphasis">
                                {{ fmt(b.spent) }} spent / {{ fmt(b.budget) }} budget
                            </div>
                        </v-list-item>
                    </v-list>
                </v-card>

                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-repeat" class="mr-2" />
                        Subscriptions
                    </v-card-title>
                    <v-card-text v-if="analysis.subscriptions.length === 0" class="text-medium-emphasis">
                        No active subscriptions detected.
                    </v-card-text>
                    <v-list v-else>
                        <v-list-item v-for="s in analysis.subscriptions" :key="s.merchant">
                            <div class="d-flex align-center w-100">
                                <span class="font-weight-medium text-capitalize">{{ s.merchant }}</span>
                                <v-spacer />
                                <span class="font-weight-medium">{{ fmt(s.monthly_cost) }}/mo</span>
                            </div>
                            <div class="text-caption text-medium-emphasis">{{ s.cadence }}</div>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { reactive, ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import NeedsWantsMeter from '../../Components/NeedsWantsMeter.vue'

const props = defineProps({
    title: String,
    months: Number,
    analysis: Object,
    aiSummary: { type: String, default: null },
    budgetRule: { type: Object, default: null },
})

const ai = reactive({ loading: false })

// Large, infrequent, non-recurring purchases the user can one-click exclude.
const oneTimeSuggestions = computed(() => props.analysis?.one_time_suggestions ?? [])

// Tracks which suggestion's Exclude request is in flight (disables the others).
const excludingId = ref(null)

// Exclude a specific purchase. is_excluded is honored across every aggregation,
// so on success a reload recomputes the projection/forecast without this spend.
function excludeTransaction(id) {
    excludingId.value = id
    router.patch(`/transactions/${id}`, { is_excluded: true }, {
        preserveScroll: true,
        onSuccess: () => router.reload({ only: ['analysis'] }),
        onFinish: () => { excludingId.value = null },
    })
}

function formatDate(value) {
    if (!value) return ''
    return new Date(`${value}T00:00:00`).toLocaleDateString('en-CA', {
        year: 'numeric', month: 'short', day: 'numeric',
    })
}

// analysis.current_month is the anchored "Y-m" (e.g. "2026-05"); render it as
// "May 2026" so it's clear figures reflect the latest imported month.
const currentMonthLabel = computed(() => {
    const ym = props.analysis?.current_month
    if (!ym) return null
    const [year, month] = ym.split('-')
    const date = new Date(Number(year), Number(month) - 1, 1)
    return date.toLocaleDateString('en-CA', { month: 'long', year: 'numeric' })
})

const projection = computed(() => props.analysis?.projection ?? null)

// Render the projection's basis month ("2026-05") as "May 2026".
const basisMonthLabel = computed(() => {
    const ym = projection.value?.basis_month
    if (!ym) return ''
    const [year, month] = ym.split('-')
    return new Date(Number(year), Number(month) - 1, 1)
        .toLocaleDateString('en-CA', { month: 'long', year: 'numeric' })
})

// Fixed share of the as-is monthly run-rate, for the split bar.
const fixedShare = computed(() => {
    const p = projection.value
    if (!p) return 0
    const total = (Number(p.fixed_monthly) || 0) + (Number(p.variable_monthly) || 0)
    if (total <= 0) return 0
    return Math.round((Number(p.fixed_monthly) / total) * 100)
})

const discretionaryShare = computed(() => {
    const total = (Number(props.analysis.discretionary_total) || 0) + (Number(props.analysis.essential_total) || 0)
    if (total <= 0) return 0
    return Math.round((Number(props.analysis.discretionary_total) / total) * 100)
})

function generateSummary() {
    ai.loading = true
    router.reload({
        only: ['aiSummary'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { ai.loading = false },
    })
}

function fmt(value) {
    return Number(value || 0).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}
</script>
