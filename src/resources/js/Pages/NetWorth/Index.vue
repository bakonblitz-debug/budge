<template>
    <div>
        <v-row class="mb-1">
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" :color="current.net_worth >= 0 ? 'primary' : 'error'">
                    <v-card-text>
                        <div class="text-caption">Net worth</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(current.net_worth) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="info">
                    <v-card-text>
                        <div class="text-caption">Cash</div>
                        <div class="text-h6 font-weight-bold">{{ fmt(current.cash) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="success">
                    <v-card-text>
                        <div class="text-caption">Assets</div>
                        <div class="text-h6 font-weight-bold">{{ fmt(current.assets) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col v-if="current.credit_overpaid > 0" cols="12" sm="6" md="3">
                <v-card variant="tonal" color="info">
                    <v-card-text>
                        <div class="text-caption">Credit overpaid</div>
                        <div class="text-h6 font-weight-bold">{{ fmt(current.credit_overpaid) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="error">
                    <v-card-text>
                        <div class="text-caption">Liabilities</div>
                        <div class="text-h6 font-weight-bold">{{ fmt(current.liabilities) }}</div>
                        <div v-if="current.credit_owed > 0" class="text-caption">
                            incl. {{ fmt(current.credit_owed) }} card debt
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card class="mb-3">
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-chart-line" class="mr-2" />
                Net worth over time
                <v-spacer />
                <v-btn color="primary" size="small" prepend-icon="mdi-camera-plus" :loading="snapping" @click="saveSnapshot">
                    Save snapshot
                </v-btn>
            </v-card-title>
            <v-card-text v-if="history.length >= 2">
                <LineChart
                    :points="chartPoints"
                    :y-ticks="chartYTicks"
                    :x-ticks="chartXTicks"
                    :zero-y="chartZeroY"
                    stroke="#1e88e5"
                    :hover-format="(p) => fmt(p.value)"
                />
            </v-card-text>
            <v-card-text v-else class="text-medium-emphasis">
                Save a snapshot now and again over time to chart your net-worth trend.
            </v-card-text>
        </v-card>

        <v-row>
            <v-col cols="12" md="6">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-trending-up" class="mr-2" color="success" />
                        Assets
                        <v-spacer />
                        <v-btn color="success" size="small" prepend-icon="mdi-plus" @click="openDialog('asset')">Add</v-btn>
                    </v-card-title>
                    <v-list v-if="assets.length" lines="two">
                        <v-list-item v-for="a in assets" :key="a.id">
                            <v-list-item-title>{{ a.name }}</v-list-item-title>
                            <v-list-item-subtitle>{{ a.type }}<span v-if="a.institution"> · {{ a.institution }}</span></v-list-item-subtitle>
                            <template #append>
                                <span class="font-weight-medium mr-2">{{ fmt(a.current_value) }}</span>
                                <v-btn icon="mdi-pencil" variant="text" size="small" @click="openDialog('asset', a)" />
                                <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="remove('asset', a)" />
                            </template>
                        </v-list-item>
                    </v-list>
                    <v-card-text v-else class="text-medium-emphasis text-center py-6">No assets yet.</v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" md="6">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-trending-down" class="mr-2" color="error" />
                        Liabilities
                        <v-spacer />
                        <v-btn color="error" size="small" prepend-icon="mdi-plus" @click="openDialog('liability')">Add</v-btn>
                    </v-card-title>
                    <v-list v-if="liabilities.length" lines="two">
                        <v-list-item v-for="l in liabilities" :key="l.id">
                            <v-list-item-title>{{ l.name }}</v-list-item-title>
                            <v-list-item-subtitle>
                                {{ l.type }}<span v-if="l.interest_rate"> · {{ l.interest_rate }}%</span>
                            </v-list-item-subtitle>
                            <template #append>
                                <span class="font-weight-medium mr-2">{{ fmt(l.balance) }}</span>
                                <v-btn icon="mdi-pencil" variant="text" size="small" @click="openDialog('liability', l)" />
                                <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="remove('liability', l)" />
                            </template>
                        </v-list-item>
                    </v-list>
                    <v-card-text v-else class="text-medium-emphasis text-center py-6">
                        No manual liabilities. (Credit-card balances come from your accounts.)
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-dialog v-model="dialog.open" max-width="520">
            <v-card>
                <v-card-title>{{ dialog.id ? 'Edit' : 'Add' }} {{ dialog.kind }}</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="7">
                            <v-text-field v-model="dialog.name" label="Name" :error-messages="dialog.errors.name" />
                        </v-col>
                        <v-col cols="12" sm="5">
                            <SmartSelect
                                v-model="dialog.type"
                                :items="dialog.kind === 'asset' ? assetTypes : liabilityTypes"
                                label="Type"
                                :error-messages="dialog.errors.type"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model.number="dialog.value"
                                :label="dialog.kind === 'asset' ? 'Current value' : 'Balance owed'"
                                type="number"
                                prefix="$"
                                :error-messages="dialog.errors.current_value || dialog.errors.balance"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field v-model="dialog.institution" label="Institution (optional)" />
                        </v-col>
                        <v-col v-if="dialog.kind === 'liability'" cols="12" sm="6">
                            <v-text-field v-model.number="dialog.interest_rate" label="Interest rate %" type="number" />
                        </v-col>
                    </v-row>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog.open = false">Cancel</v-btn>
                    <v-btn color="primary" :loading="dialog.processing" @click="save">Save</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import SmartSelect from '../../Components/SmartSelect.vue'
import LineChart from '../../Components/charts/LineChart.vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    title: String,
    current: Object,
    assets: Array,
    liabilities: Array,
    history: Array,
    assetTypes: Array,
    liabilityTypes: Array,
})

const snapping = ref(false)

function saveSnapshot() {
    snapping.value = true
    router.post('/net-worth/snapshot', {}, {
        preserveScroll: true,
        onFinish: () => { snapping.value = false },
    })
}

const chartComputed = computed(() => {
    const h = props.history
    if (h.length < 2) return { points: [], yTicks: [], xTicks: [], zeroY: 120 }

    const values = h.map((p) => Number(p.net_worth))
    const max = Math.max(...values, 0)
    const min = Math.min(...values, 0)
    const range = max - min || 1

    // SVG height is 240 (matching LineChart.vue), with 10px padding top/bottom
    const PAD = 10
    const H = 240
    const svgY = (v) => PAD + (1 - (v - min) / range) * (H - PAD * 2)

    const points = h.map((p, i) => {
        const svgX = (i / (h.length - 1)) * 1000
        const svgYval = svgY(Number(p.net_worth))
        return {
            svgX,
            svgY: svgYval,
            x: (i / (h.length - 1)) * 100,   // percentage for dot positioning
            y: svgYval,                         // pixel position for dot (matches SVG y in LineChart)
            label: formatDate(p.as_of),
            value: Number(p.net_worth),
        }
    })

    // Nice Y ticks: 5 levels
    const tickCount = 5
    const step = range / (tickCount - 1)
    const yTicks = Array.from({ length: tickCount }, (_, i) => {
        const val = min + step * i
        return { value: val, y: svgY(val) }
    })

    // X ticks: first and last date
    const xTicks = [formatDate(h[0].as_of), formatDate(h[h.length - 1].as_of)]

    return { points, yTicks, xTicks, zeroY: svgY(0) }
})

const chartPoints = computed(() => chartComputed.value.points)
const chartYTicks = computed(() => chartComputed.value.yTicks)
const chartXTicks = computed(() => chartComputed.value.xTicks)
const chartZeroY = computed(() => chartComputed.value.zeroY)

const dialog = reactive({
    open: false, kind: 'asset', id: null,
    name: '', type: 'investment', value: 0, institution: '', interest_rate: null,
    processing: false, errors: {},
})

function openDialog(kind, item = null) {
    dialog.open = true
    dialog.kind = kind
    dialog.errors = {}
    if (item) {
        dialog.id = item.id
        dialog.name = item.name
        dialog.type = item.type
        dialog.value = kind === 'asset' ? Number(item.current_value) : Number(item.balance)
        dialog.institution = item.institution || ''
        dialog.interest_rate = item.interest_rate ?? null
    } else {
        dialog.id = null
        dialog.name = ''
        dialog.type = kind === 'asset' ? 'investment' : 'loan'
        dialog.value = 0
        dialog.institution = ''
        dialog.interest_rate = null
    }
}

function save() {
    dialog.processing = true
    const base = `/${dialog.kind === 'asset' ? 'assets' : 'liabilities'}`
    const payload = {
        name: dialog.name,
        type: dialog.type,
        institution: dialog.institution || null,
    }
    if (dialog.kind === 'asset') {
        payload.current_value = dialog.value
    } else {
        payload.balance = dialog.value
        payload.interest_rate = dialog.interest_rate
    }
    const opts = {
        preserveScroll: true,
        onSuccess: () => { dialog.open = false },
        onError: (errs) => { dialog.errors = errs },
        onFinish: () => { dialog.processing = false },
    }
    if (dialog.id) {
        router.patch(`${base}/${dialog.id}`, payload, opts)
    } else {
        router.post(base, payload, opts)
    }
}

function remove(kind, item) {
    const base = `/${kind === 'asset' ? 'assets' : 'liabilities'}`
    router.delete(`${base}/${item.id}`, { preserveScroll: true })
}

function fmt(value) {
    return Number(value || 0).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}
function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>
