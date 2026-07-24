<template>
    <div>
        <v-row class="mb-2">
            <v-col cols="6" sm="4">
                <v-card variant="tonal" color="primary">
                    <v-card-text>
                        <div class="text-caption">Active subscriptions</div>
                        <div class="text-h6 font-weight-bold">{{ totals.active_count }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="4">
                <v-card variant="tonal" color="error">
                    <v-card-text>
                        <div class="text-caption">Est. monthly cost</div>
                        <div class="text-h6 font-weight-bold">{{ fmt(totals.monthly_estimate) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-autorenew" class="mr-2" />
                Subscriptions &amp; recurring bills
                <v-spacer />
                <v-btn
                    color="primary"
                    size="small"
                    prepend-icon="mdi-magnify-scan"
                    :loading="scanning"
                    @click="rescan"
                >
                    Rescan
                </v-btn>
            </v-card-title>
            <v-card-subtitle>
                Detected from your transactions — regular interval, stable amount. No AI.
            </v-card-subtitle>

            <v-list v-if="series.length > 0" lines="two">
                <v-list-item v-for="s in series" :key="s.id">
                    <template #prepend>
                        <v-avatar :color="s.category?.color || 'secondary'" size="36" variant="tonal">
                            <v-icon :icon="s.category?.icon || 'mdi-autorenew'" size="18" />
                        </v-avatar>
                    </template>
                    <v-list-item-title class="d-flex align-center ga-2">
                        {{ s.merchant_key }}
                        <v-chip size="x-small" variant="tonal">{{ cadenceLabel(s.cadence) }}</v-chip>
                        <v-chip v-if="s.is_manual" size="x-small" color="info" variant="tonal">manual</v-chip>
                        <v-chip v-if="s.amount_increased" size="x-small" color="warning" prepend-icon="mdi-trending-up">
                            Price up
                        </v-chip>
                        <v-chip v-if="s.status === 'ended'" size="x-small" color="default">ended</v-chip>
                    </v-list-item-title>
                    <v-list-item-subtitle>
                        {{ fmt(s.expected_amount) }} · {{ s.occurrences }} charges ·
                        {{ s.status === 'active' ? `next ~${formatDate(s.next_expected_at)}` : `last ${formatDate(s.last_seen_at)}` }}
                    </v-list-item-subtitle>
                    <template #append>
                        <div class="text-right mr-2">
                            <div class="font-weight-medium">{{ fmt(s.expected_amount) }}</div>
                            <div v-if="s.amount_increased" class="text-caption text-warning">
                                now {{ fmt(s.last_amount) }}
                            </div>
                        </div>
                        <v-btn
                            v-if="s.status === 'active'"
                            icon="mdi-swap-horizontal-bold"
                            variant="text"
                            size="small"
                            color="primary"
                            title="Convert to a fixed expense"
                            @click="confirmConvert(s)"
                        />
                        <v-btn
                            v-if="s.status === 'active'"
                            icon="mdi-close-circle-outline"
                            variant="text"
                            size="small"
                            color="error"
                            title="Dismiss — not really a subscription"
                            @click="confirmDismiss(s)"
                        />
                    </template>
                </v-list-item>
            </v-list>

            <v-card-text v-else class="text-center py-12">
                <v-icon icon="mdi-autorenew-off" size="56" class="mb-2 text-medium-emphasis" />
                <div class="text-medium-emphasis">
                    No recurring series detected yet. Import a few months of statements, then Rescan.
                </div>
            </v-card-text>
        </v-card>

        <!-- Possible subscriptions to add -->
        <v-card v-if="candidates.length > 0" class="mt-4">
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-plus-circle-outline" class="mr-2" />
                Possible subscriptions to add
            </v-card-title>
            <v-card-subtitle>
                Near-miss groups (stable amount, a few charges) we didn't auto-detect. Add the real ones.
            </v-card-subtitle>

            <v-list lines="two">
                <v-list-item v-for="c in candidates" :key="c.merchant_key">
                    <v-list-item-title class="d-flex align-center ga-2">
                        {{ c.merchant_key }}
                        <v-chip v-if="c.suggested_cadence" size="x-small" variant="tonal">
                            ~{{ cadenceLabel(c.suggested_cadence) }}
                        </v-chip>
                    </v-list-item-title>
                    <v-list-item-subtitle>
                        {{ fmt(c.expected_amount) }} · {{ c.occurrences }} charges ·
                        <span v-for="(sample, i) in c.samples" :key="i">
                            {{ formatDate(sample.date) }}{{ i < c.samples.length - 1 ? ', ' : '' }}
                        </span>
                    </v-list-item-subtitle>
                    <template #append>
                        <v-btn
                            color="primary"
                            variant="tonal"
                            size="small"
                            prepend-icon="mdi-plus"
                            @click="openAdd(c)"
                        >
                            Add
                        </v-btn>
                    </template>
                </v-list-item>
            </v-list>
        </v-card>

        <!-- Dismissed -->
        <v-card v-if="dismissed.length > 0" class="mt-4">
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-eye-off-outline" class="mr-2" />
                Dismissed
            </v-card-title>
            <v-card-subtitle>
                You marked these as not-a-subscription. They won't be re-detected on Rescan.
            </v-card-subtitle>

            <v-list lines="two">
                <v-list-item v-for="s in dismissed" :key="s.id">
                    <v-list-item-title>{{ s.merchant_key }}</v-list-item-title>
                    <v-list-item-subtitle>
                        {{ fmt(s.expected_amount) }} · {{ cadenceLabel(s.cadence) }}
                    </v-list-item-subtitle>
                    <template #append>
                        <v-btn
                            variant="text"
                            size="small"
                            prepend-icon="mdi-restore"
                            @click="restore(s)"
                        >
                            Restore
                        </v-btn>
                    </template>
                </v-list-item>
            </v-list>
        </v-card>

        <!-- Add subscription dialog -->
        <v-dialog v-model="addDialog.open" max-width="480">
            <v-card>
                <v-card-title>Add subscription</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12">
                            <v-text-field
                                v-model="addDialog.merchant_key"
                                label="Name"
                                :error-messages="addDialog.errors.merchant_key"
                                autofocus
                            />
                        </v-col>
                        <v-col cols="12" sm="7">
                            <SmartSelect
                                v-model="addDialog.cadence"
                                :items="cadenceOptions"
                                label="Cadence"
                                :error-messages="addDialog.errors.cadence"
                            />
                        </v-col>
                        <v-col cols="12" sm="5">
                            <v-text-field
                                v-model.number="addDialog.expected_amount"
                                type="number"
                                step="0.01"
                                prefix="$"
                                label="Amount"
                                :error-messages="addDialog.errors.expected_amount"
                            />
                        </v-col>
                    </v-row>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="addDialog.open = false">Cancel</v-btn>
                    <v-btn color="primary" :loading="addDialog.processing" @click="saveAdd">Add</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Dismiss confirm dialog -->
        <v-dialog v-model="dismissDialog.open" max-width="400">
            <v-card>
                <v-card-title>Dismiss subscription?</v-card-title>
                <v-card-text>{{ dismissDialog.message }}</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dismissDialog.open = false">Cancel</v-btn>
                    <v-btn color="error" @click="dismissDialog.confirm">Dismiss</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Convert confirm dialog -->
        <v-dialog v-model="convertDialog.open" max-width="440">
            <v-card>
                <v-card-title>Convert to fixed expense?</v-card-title>
                <v-card-text>
                    {{ convertDialog.message }}
                    <div class="mt-2 text-medium-emphasis text-body-2">
                        It'll move to your
                        <a href="/fixed-expenses" @click.prevent="goToFixedExpenses">Fixed Expenses</a>
                        list and stop showing here.
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="convertDialog.open = false">Cancel</v-btn>
                    <v-btn color="primary" @click="convertDialog.confirm">Convert</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { reactive, ref } from 'vue'
import SmartSelect from '../../Components/SmartSelect.vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    title: String,
    series: Array,
    dismissed: { type: Array, default: () => [] },
    candidates: { type: Array, default: () => [] },
    totals: Object,
})

const scanning = ref(false)

function rescan() {
    scanning.value = true
    router.post('/subscriptions/detect', {}, {
        preserveScroll: true,
        onFinish: () => { scanning.value = false },
    })
}

const CADENCE_LABELS = {
    weekly: 'Weekly',
    bi_weekly: 'Every 2 weeks',
    monthly: 'Monthly',
    bi_monthly: 'Every 2 months',
    quarterly: 'Quarterly',
    semi_annual: 'Every 6 months',
    yearly: 'Yearly',
}
function cadenceLabel(c) {
    return CADENCE_LABELS[c] || c
}

const cadenceOptions = Object.entries(CADENCE_LABELS).map(([value, title]) => ({ value, title }))

const addDialog = reactive({
    open: false,
    merchant_key: '',
    cadence: 'monthly',
    expected_amount: 0,
    processing: false,
    errors: {},
})

function openAdd(candidate) {
    addDialog.open = true
    addDialog.errors = {}
    addDialog.processing = false
    addDialog.merchant_key = candidate?.merchant_key ?? ''
    addDialog.cadence = candidate?.suggested_cadence ?? 'monthly'
    addDialog.expected_amount = candidate?.expected_amount ?? 0
}

function saveAdd() {
    addDialog.processing = true
    router.post('/subscriptions', {
        merchant_key: addDialog.merchant_key,
        cadence: addDialog.cadence,
        expected_amount: addDialog.expected_amount,
    }, {
        preserveScroll: true,
        onSuccess: () => { addDialog.open = false },
        onError: (errs) => { addDialog.errors = errs },
        onFinish: () => { addDialog.processing = false },
    })
}

const dismissDialog = reactive({ open: false, message: '', confirm: () => {} })

function confirmDismiss(s) {
    dismissDialog.open = true
    dismissDialog.message = `Dismiss "${s.merchant_key}"? It won't be re-detected on a rescan. You can restore it later.`
    dismissDialog.confirm = () => {
        router.patch(`/subscriptions/${s.id}/dismiss`, {}, {
            preserveScroll: true,
            onFinish: () => { dismissDialog.open = false },
        })
    }
}

function restore(s) {
    router.patch(`/subscriptions/${s.id}/restore`, {}, { preserveScroll: true })
}

const convertDialog = reactive({ open: false, message: '', confirm: () => {} })

function confirmConvert(s) {
    convertDialog.open = true
    convertDialog.message = `Convert "${s.merchant_key}" into a recurring fixed expense?`
    convertDialog.confirm = () => {
        router.post(`/subscriptions/${s.id}/convert`, {}, {
            preserveScroll: true,
            onFinish: () => { convertDialog.open = false },
        })
    }
}

function goToFixedExpenses() {
    router.visit('/fixed-expenses')
}

function fmt(value) {
    return Number(value || 0).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}

function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>
