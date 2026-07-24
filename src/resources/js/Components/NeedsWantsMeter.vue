<template>
    <v-card>
        <v-card-title class="d-flex align-center">
            <v-icon icon="mdi-scale-balance" class="mr-2" />
            <span>Needs vs Wants</span>
            <v-spacer />
            <span class="text-caption text-medium-emphasis">
                {{ meter.window.label }} · income {{ money(meter.income_monthly) }}/mo
            </span>
        </v-card-title>

        <v-card-text>
            <!-- Stacked actual bar with target ticks -->
            <div class="meter-bar mb-1">
                <div
                    v-for="seg in segments"
                    :key="seg.key"
                    class="meter-seg"
                    :style="{ width: seg.width + '%', background: seg.color }"
                    :title="`${seg.label}: ${seg.pct}% (target ${seg.target}%)`"
                />
                <div v-if="overspendWidth > 0" class="meter-seg meter-over" :style="{ width: overspendWidth + '%' }" />
                <!-- target boundary ticks at 50 / 80 / 90 -->
                <div v-for="t in [50, 80, 90]" :key="t" class="meter-tick" :style="{ left: t + '%' }" />
            </div>
            <div class="d-flex flex-wrap ga-3 mb-3">
                <div v-for="k in kindOrder" :key="k" class="d-flex align-center text-caption">
                    <span class="legend-dot mr-1" :style="{ background: colors[k] }" />
                    {{ labels[k] }} {{ meter.kinds[k].pct }}%
                    <span class="text-medium-emphasis ml-1">/ {{ meter.kinds[k].target_pct }}%</span>
                </div>
            </div>

            <!-- Uncategorized transactions distort the meter; offer a one-click jump to fix them. -->
            <v-chip
                v-if="meter.unclassified_count > 0"
                color="warning"
                variant="tonal"
                size="small"
                prepend-icon="mdi-tag-off-outline"
                append-icon="mdi-arrow-right"
                role="button"
                class="mb-3"
                @click="goToUncategorized"
            >
                {{ meter.unclassified_count }} transaction{{ meter.unclassified_count === 1 ? '' : 's' }} unclassified — categorize
            </v-chip>

            <!-- Per-kind rows (full mode only) -->
            <template v-if="!compact">
                <div v-for="k in kindOrder" :key="k" class="mb-3">
                    <div class="d-flex align-center mb-1">
                        <span class="legend-dot mr-2" :style="{ background: colors[k] }" />
                        <span class="font-weight-medium">{{ labels[k] }}</span>
                        <v-spacer />
                        <span class="mr-2">{{ money(meter.kinds[k].amount) }}/mo</span>
                        <v-chip :color="statusColor(meter.kinds[k].status)" size="x-small" variant="flat">
                            {{ meter.kinds[k].pct }}% · target {{ meter.kinds[k].target_pct }}% · {{ statusLabel(k, meter.kinds[k].status) }}
                        </v-chip>
                    </div>
                    <div class="kind-track">
                        <div class="kind-actual" :style="{ width: Math.min(100, Math.abs(meter.kinds[k].pct)) + '%', background: colors[k] }" />
                        <div class="kind-target" :style="{ left: meter.kinds[k].target_pct + '%' }" />
                    </div>
                </div>

                <!-- Honesty: saving is steady-state, lumps were real outflows -->
                <v-alert
                    v-if="meter.stripped.lump_total > 0 || meter.added_back.length"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-2 text-caption"
                >
                    <div v-if="meter.stripped.lump_total > 0">
                        Saving shown is <strong>steady-state</strong>. {{ money(meter.stripped.lump_total) }}/mo of one-time lumps
                        (move-in / large contributions) were excluded — real cash out, not money saved.
                    </div>
                    <div v-for="a in meter.added_back" :key="a.label">
                        <strong>{{ a.label }}</strong> added to {{ labels[a.kind] }} at {{ money(a.monthly_equivalent) }}/mo
                        (paid via transfer, absent from categorized spend).
                    </div>
                    <div v-if="meter.unclassified_monthly > 0">
                        {{ money(meter.unclassified_monthly) }}/mo is <strong>unclassified</strong> — set a kind on those
                        categories to fold it into the meter.
                    </div>
                </v-alert>

                <!-- Recurring commitments + by-month, in an expandable detail panel -->
                <v-expansion-panels variant="accordion">
                    <v-expansion-panel title="Recurring commitments (monthly-equivalent)">
                        <v-expansion-panel-text>
                            <v-table density="compact">
                                <thead>
                                    <tr>
                                        <th class="text-left">Commitment</th>
                                        <th class="text-left">Kind</th>
                                        <th class="text-left">Cadence</th>
                                        <th class="text-right">Native</th>
                                        <th class="text-right">≈ /mo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(c, i) in meter.commitments" :key="i">
                                        <td>{{ c.label }}</td>
                                        <td>{{ c.kind ? labels[c.kind] : '—' }}</td>
                                        <td>{{ cadenceLabel(c.cadence) }}</td>
                                        <td class="text-right">{{ money(c.native_amount) }}</td>
                                        <td class="text-right">{{ money(c.monthly_equivalent) }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <div class="text-caption text-medium-emphasis mt-2">
                                Informational — these recurring obligations are not a reconciliation of the headline.
                            </div>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                    <v-expansion-panel title="By month (actual, lumps stripped)">
                        <v-expansion-panel-text>
                            <v-table density="compact">
                                <thead>
                                    <tr>
                                        <th class="text-left">Month</th>
                                        <th class="text-right">Need</th>
                                        <th class="text-right">Want</th>
                                        <th class="text-right">Investment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="m in meter.by_month" :key="m.ym">
                                        <td>{{ m.ym }}</td>
                                        <td class="text-right">{{ money(m.need) }}</td>
                                        <td class="text-right">{{ money(m.want) }}</td>
                                        <td class="text-right">{{ money(m.investment) }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </template>

            <div v-else class="text-caption text-medium-emphasis">
                {{ headline }}
            </div>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { computed } from 'vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    meter: { type: Object, required: true },
    compact: { type: Boolean, default: false },
})

// Jump to the transactions list filtered to uncategorized rows so they can be categorized.
function goToUncategorized() {
    router.visit('/transactions?category=none')
}

const kindOrder = ['need', 'want', 'investment', 'saving']
const labels = { need: 'Needs', want: 'Wants', investment: 'Investment', saving: 'Saving' }
const colors = {
    need: '#1E88E5',        // blue
    want: '#8E24AA',        // deep purple
    investment: '#43A047',  // green
    saving: '#00897B',      // teal
}

function money(v) {
    const n = Number(v) || 0
    return (n < 0 ? '-$' : '$') + Math.abs(n).toLocaleString('en-CA', { minimumFractionDigits: 0, maximumFractionDigits: 0 })
}

function statusColor(status) {
    if (status === 'on') return 'info'
    return status === 'over' ? 'error' : status === 'under' ? 'warning' : 'success'
}

// "over"/"under" mean different things per kind; translate to plain words.
function statusLabel(kind, status) {
    if (status === 'on') return 'on target'
    const overIsBad = kind === 'need' || kind === 'want'
    if (overIsBad) return status === 'over' ? 'over' : 'on track'
    return status === 'under' ? 'below' : 'on track'
}

function cadenceLabel(c) {
    return {
        weekly: 'weekly', bi_weekly: 'bi-weekly', monthly: 'monthly', bi_monthly: 'every 2 mo',
        quarterly: 'quarterly', semi_annual: 'semi-annual', yearly: 'yearly',
    }[c] || c
}

// Stacked bar: positive shares of income, scaled so need+want+investment(+positive saving) read against 100%.
const segments = computed(() => {
    return ['need', 'want', 'investment'].map((k) => ({
        key: k,
        label: labels[k],
        color: colors[k],
        pct: props.meter.kinds[k].pct,
        target: props.meter.kinds[k].target_pct,
        width: Math.max(0, props.meter.kinds[k].pct),
    })).concat(
        props.meter.kinds.saving.amount > 0
            ? [{ key: 'saving', label: 'Saving', color: colors.saving, pct: props.meter.kinds.saving.pct, target: 10, width: Math.max(0, props.meter.kinds.saving.pct) }]
            : [],
    )
})

// When spend exceeds income (negative saving), draw an "over 100%" overspend tail.
const overspendWidth = computed(() => {
    const spend = props.meter.kinds.need.pct + props.meter.kinds.want.pct + props.meter.kinds.investment.pct
    return spend > 100 ? Math.min(20, spend - 100) : 0
})

const headline = computed(() => {
    const n = props.meter.kinds.need
    const w = props.meter.kinds.want
    return `Needs ${n.pct}% (target ${n.target_pct}%) · Wants ${w.pct}% (target ${w.target_pct}%)`
})
</script>

<style scoped>
.meter-bar {
    position: relative;
    display: flex;
    height: 22px;
    border-radius: 6px;
    overflow: hidden;
    background: rgba(128, 128, 128, 0.15);
}
.meter-seg {
    height: 100%;
}
.meter-over {
    background: repeating-linear-gradient(45deg, #e53935, #e53935 4px, #b71c1c 4px, #b71c1c 8px);
}
.meter-tick {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 2px;
    background: rgba(0, 0, 0, 0.45);
}
.legend-dot {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
}
.kind-track {
    position: relative;
    height: 10px;
    border-radius: 5px;
    background: rgba(128, 128, 128, 0.15);
    overflow: visible;
}
.kind-actual {
    height: 100%;
    border-radius: 5px;
}
.kind-target {
    position: absolute;
    top: -2px;
    bottom: -2px;
    width: 2px;
    background: rgba(0, 0, 0, 0.55);
}
</style>
