<template>
    <div>
        <v-row class="mb-2">
            <v-col cols="12" sm="4">
                <v-card variant="tonal" color="primary">
                    <v-card-text>
                        <div class="text-caption">Cash now</div>
                        <div class="text-h6 font-weight-bold">{{ fmt(forecast.start_balance) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card variant="tonal" :color="forecast.lowest.balance < 0 ? 'error' : 'warning'">
                    <v-card-text>
                        <div class="text-caption">Lowest projected ({{ forecast.horizon_days }}d)</div>
                        <div class="text-h6 font-weight-bold">{{ fmt(forecast.lowest.balance) }}</div>
                        <div class="text-caption">{{ formatDate(forecast.lowest.date) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="4">
                <v-card variant="tonal" :color="forecast.shortfall_date ? 'error' : 'success'">
                    <v-card-text>
                        <div class="text-caption">Shortfall</div>
                        <div class="text-h6 font-weight-bold">
                            {{ forecast.shortfall_date ? formatDate(forecast.shortfall_date) : 'None' }}
                        </div>
                        <div class="text-caption">
                            {{ forecast.shortfall_date ? 'projected to dip below $0' : 'stays positive' }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card class="mb-3">
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-chart-bell-curve-cumulative" class="mr-2" />
                Projected balance
                <v-spacer />
                <v-btn-toggle v-model="days" mandatory density="compact" color="primary" @update:modelValue="changeDays">
                    <v-btn :value="30" size="small">30d</v-btn>
                    <v-btn :value="60" size="small">60d</v-btn>
                    <v-btn :value="90" size="small">90d</v-btn>
                </v-btn-toggle>
            </v-card-title>
            <v-card-text>
                <div class="d-flex flex-wrap align-center mb-3" style="gap: 8px;">
                    <div class="text-caption text-medium-emphasis">
                        Everyday spend:
                        <strong>{{ fmt(forecast.baseline.daily) }}/day</strong>
                        (~{{ fmt(forecast.baseline.monthly) }}/mo) since {{ formatDate(forecast.baseline.start) }}
                    </div>
                    <v-spacer />
                    <v-btn-group density="compact" variant="outlined" divided>
                        <v-btn size="small" @click="setBaselineMonths(1)">1mo</v-btn>
                        <v-btn size="small" @click="setBaselineMonths(2)">2mo</v-btn>
                        <v-btn size="small" @click="setBaselineMonths(3)">3mo</v-btn>
                    </v-btn-group>
                    <v-text-field
                        v-model="baselineStartInput"
                        type="date"
                        density="compact"
                        hide-details
                        label="Since"
                        style="max-width: 160px;"
                        @change="setBaselineStart"
                    />
                </div>

                <LineChart
                    :points="chartPoints"
                    :y-ticks="yTicks"
                    :x-ticks="xTicks"
                    :zero-y="zeroY"
                    :stroke="forecast.shortfall_date ? '#e53935' : '#43a047'"
                    :markers="chartMarkers"
                    :hover-format="(p) => fmt(p.value)"
                />
            </v-card-text>
        </v-card>

        <v-card>
            <v-card-title class="text-subtitle-1">
                <v-icon icon="mdi-calendar-clock" size="18" class="mr-1" />
                Upcoming events
            </v-card-title>
            <v-list v-if="sortedEvents.length > 0" density="compact">
                <v-list-item v-for="(e, i) in sortedEvents" :key="i">
                    <template #prepend>
                        <v-icon
                            :icon="e.kind === 'income' ? 'mdi-cash-plus' : e.kind === 'recurring' ? 'mdi-autorenew' : 'mdi-home-city'"
                            :color="e.amount >= 0 ? 'success' : 'error'"
                            size="18"
                            class="mr-2"
                        />
                    </template>
                    <v-list-item-title>{{ e.label }}</v-list-item-title>
                    <v-list-item-subtitle>{{ formatDate(e.date) }} · {{ e.kind }}</v-list-item-subtitle>
                    <template #append>
                        <span :class="e.amount >= 0 ? 'text-success' : 'text-error'" class="font-weight-medium">
                            {{ signed(e.amount) }}
                        </span>
                    </template>
                </v-list-item>
            </v-list>
            <v-card-text v-else class="text-medium-emphasis">
                No upcoming income or expenses in this window. Add fixed expenses and income to improve the forecast.
            </v-card-text>
        </v-card>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import LineChart from '@/Components/charts/LineChart.vue'

const props = defineProps({
    title: String,
    forecast: Object,
})

const days = ref(props.forecast.horizon_days)
const baselineStartInput = ref(props.forecast.baseline.start)

// Keep the date input in sync after a preset resolves server-side.
watch(() => props.forecast.baseline.start, (value) => {
    baselineStartInput.value = value
})

function reload(params) {
    router.get('/forecast', { days: days.value, ...params }, { preserveState: true, preserveScroll: true, replace: true })
}

function changeDays(value) {
    days.value = value
    // Preserve the current spending window when only the horizon changes.
    reload({ baseline_start: props.forecast.baseline.start })
}

function setBaselineMonths(months) {
    reload({ baseline_months: months })
}

function setBaselineStart() {
    if (baselineStartInput.value) {
        reload({ baseline_start: baselineStartInput.value })
    }
}

// "Nice" rounded tick values spanning [min, max] for a readable dollar axis.
function niceTicks(min, max, count = 4) {
    const range = max - min || 1
    const rawStep = range / count
    const mag = Math.pow(10, Math.floor(Math.log10(rawStep)))
    const norm = rawStep / mag
    const step = (norm < 1.5 ? 1 : norm < 3 ? 2 : norm < 7 ? 5 : 10) * mag
    const start = Math.floor(min / step) * step
    const end = Math.ceil(max / step) * step
    const ticks = []
    for (let v = start; v <= end + step / 1000; v += step) ticks.push(Math.round(v * 100) / 100)
    return ticks
}

// Build the SVG polyline, the dollar (Y) ticks, and the date (X) ticks from the
// daily timeline. The Y domain snaps to the nice ticks so the line, gridlines,
// and labels all share one scale.
const chart = computed(() => {
    const t = props.forecast.timeline
    if (!t.length) return { points: [], yTicks: [], zeroY: 120, xTicks: [] }

    const balances = t.map((p) => p.balance)
    const ticks = niceTicks(Math.min(...balances, 0), Math.max(...balances, 0), 4)
    const domMin = Math.min(...ticks)
    const domMax = Math.max(...ticks)
    const range = domMax - domMin || 1

    const TOP = 8
    const BOTTOM = 232 // leave a little padding top & bottom of the 240-tall viewBox
    const y = (b) => BOTTOM - ((b - domMin) / range) * (BOTTOM - TOP)

    // Per-day plot coordinates.
    // svgX: SVG units (0-1000) for the polyline
    // x: CSS percentage (0-100) for dot/tooltip positioning
    // y: CSS pixels for dot/tooltip positioning
    const coords = t.map((p, i) => ({
        svgX: t.length > 1 ? (i / (t.length - 1)) * 1000 : 0,
        svgY: y(p.balance),
        x: t.length > 1 ? (i / (t.length - 1)) * 100 : 0,
        y: y(p.balance),
        label: formatDate(p.date),
        value: p.balance,
        date: p.date,
    }))

    const yTicks = ticks.map((v) => ({ value: v, y: y(v) }))

    const xCount = Math.min(5, t.length)
    const xTicks = Array.from({ length: xCount }, (_, k) =>
        formatDate(t[xCount > 1 ? Math.round((k * (t.length - 1)) / (xCount - 1)) : 0].date),
    )

    return { coords, yTicks, zeroY: y(0), xTicks }
})

const chartPoints = computed(() => chart.value.coords || [])
const zeroY = computed(() => chart.value.zeroY)
const yTicks = computed(() => chart.value.yTicks)
const xTicks = computed(() => chart.value.xTicks)

// Markers: the lowest projected point and the first day a shortfall occurs.
const lowestMarkerCoord = computed(() =>
    (chart.value.coords || []).find((c) => c.date === props.forecast.lowest?.date) || null,
)
const shortfallMarkerCoord = computed(() =>
    props.forecast.shortfall_date
        ? (chart.value.coords || []).find((c) => c.date === props.forecast.shortfall_date) || null
        : null,
)

const chartMarkers = computed(() => {
    const markers = []
    if (lowestMarkerCoord.value) {
        markers.push({
            coord: lowestMarkerCoord.value,
            color: lowestMarkerCoord.value.value < 0 ? '#e53935' : '#fb8c00',
            title: `Lowest: ${fmt(lowestMarkerCoord.value.value)} on ${formatDate(lowestMarkerCoord.value.date)}`,
        })
    }
    if (shortfallMarkerCoord.value) {
        markers.push({
            coord: shortfallMarkerCoord.value,
            color: '#e53935',
            title: `Dips below $0 on ${formatDate(shortfallMarkerCoord.value.date)}`,
        })
    }
    return markers
})

const sortedEvents = computed(() =>
    [...props.forecast.events].sort((a, b) => a.date.localeCompare(b.date)).slice(0, 25),
)

function fmt(value) {
    return Number(value || 0).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}
function signed(value) {
    const v = Number(value || 0)
    return (v >= 0 ? '+' : '-') + Math.abs(v).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}
function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString('en-CA', { month: 'short', day: 'numeric' })
}
</script>
