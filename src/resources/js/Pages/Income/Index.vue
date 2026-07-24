<template>
    <div>
        <!-- Stats row -->
        <v-row class="mb-2">
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="primary">
                    <v-card-text>
                        <div class="text-caption">Typical (median)</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(stats.median) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ stats.window_label }} · {{ stats.count }} pays<span v-if="stats.cadence_days"> · every ~{{ stats.cadence_days }}d</span>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="success">
                    <v-card-text>
                        <div class="text-caption">Average</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(stats.mean) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            <template v-if="stats.p25 !== null && stats.p75 !== null">
                                p25–p75: {{ fmt(stats.p25) }} – {{ fmt(stats.p75) }}
                            </template>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="info">
                    <v-card-text>
                        <div class="text-caption">Last 12 weeks</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(totals.last_12_weeks) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            ≈ {{ fmt(totals.last_12_weeks / 12 * 52 / 12) }}/month
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="secondary">
                    <v-card-text>
                        <div class="text-caption">Last 12 months</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(totals.last_12_months) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ totals.statement_count }} from imports · {{ totals.manual_count }} manual
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-cash-plus" class="mr-2" />
                Income log
                <v-chip class="ml-3" size="x-small" variant="tonal">
                    {{ stats.count }} event{{ stats.count === 1 ? '' : 's' }}
                </v-chip>
                <v-spacer />
                <v-btn-toggle v-model="filter" mandatory variant="outlined" density="compact" color="primary" class="mr-3">
                    <v-btn value="all" size="small">All</v-btn>
                    <v-btn value="statement" size="small">Statement</v-btn>
                    <v-btn value="manual" size="small">Manual</v-btn>
                </v-btn-toggle>
                <v-btn color="primary" prepend-icon="mdi-plus" @click="openDialog()">
                    Add manual entry
                </v-btn>
            </v-card-title>

            <v-list v-if="filteredEvents.length > 0" lines="two">
                <v-list-item
                    v-for="ev in filteredEvents"
                    :key="ev.id"
                    class="py-2"
                >
                    <template #prepend>
                        <v-avatar :color="ev.source_kind === 'statement' ? 'info' : 'success'" size="32" variant="tonal">
                            <v-icon
                                :icon="ev.source_kind === 'statement' ? 'mdi-bank-transfer-in' : 'mdi-cash'"
                                size="18"
                            />
                        </v-avatar>
                    </template>

                    <v-list-item-title class="font-weight-medium">
                        {{ ev.source }}
                        <v-chip
                            v-if="ev.source_kind === 'statement'"
                            size="x-small"
                            variant="tonal"
                            color="info"
                            class="ml-2"
                        >Statement</v-chip>
                    </v-list-item-title>
                    <v-list-item-subtitle class="text-caption">
                        {{ formatDate(ev.date) }}
                        <span v-if="ev.frequency"> · {{ frequencyLabel(ev.frequency) }}</span>
                        <span v-if="ev.notes"> · {{ ev.notes }}</span>
                        <span v-if="ev.is_net"> · Net</span>
                    </v-list-item-subtitle>

                    <template #append>
                        <span class="text-success font-weight-bold mr-4">+{{ fmt(ev.amount) }}</span>
                        <template v-if="ev.source_kind === 'manual'">
                            <v-btn icon="mdi-pencil" variant="text" size="small" @click="openDialog(ev)" />
                            <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="confirmDelete(ev)" />
                        </template>
                        <template v-else>
                            <v-btn icon="mdi-open-in-new" variant="text" size="small" :title="`View transaction`" @click="goToTxn(ev.transaction_id)" />
                        </template>
                    </template>
                </v-list-item>
            </v-list>

            <v-card-text v-else class="text-center py-12">
                <v-icon icon="mdi-cash-plus" size="56" class="mb-2 text-medium-emphasis" />
                <div class="text-medium-emphasis mb-2">No income events yet.</div>
                <div class="text-body-2 text-medium-emphasis">
                    Import a bank statement (paycheques auto-detect via the Income category) or add a manual entry for off-statement income.
                </div>
            </v-card-text>
        </v-card>

        <v-dialog v-model="dialog.open" max-width="560">
            <v-card>
                <v-card-title>{{ dialog.id ? 'Edit entry' : 'Add manual income' }}</v-card-title>
                <v-card-text>
                    <v-alert v-if="!dialog.id" type="info" variant="tonal" density="compact" class="mb-4">
                        Use this for income that doesn't show up on a bank statement (cash, cheques you haven't deposited yet, etc.). Statement-detected income is added automatically.
                    </v-alert>
                    <v-row dense>
                        <v-col cols="12" sm="8">
                            <v-text-field
                                v-model="dialog.source"
                                label="Source"
                                placeholder="Employer salary, freelance, ..."
                                :error-messages="dialog.errors.source"
                                autofocus
                            />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field
                                v-model.number="dialog.amount"
                                type="number"
                                step="0.01"
                                prefix="$"
                                label="Amount"
                                :error-messages="dialog.errors.amount"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <SmartSelect
                                v-model="dialog.frequency"
                                :items="frequencyOptions"
                                label="Frequency"
                                :error-messages="dialog.errors.frequency"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model="dialog.pay_date"
                                type="date"
                                label="Pay date"
                                :error-messages="dialog.errors.pay_date"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-switch
                                v-model="dialog.is_net"
                                :label="dialog.is_net ? 'Net (after-tax)' : 'Gross (before-tax)'"
                                color="primary"
                                density="compact"
                                hide-details
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-textarea
                                v-model="dialog.notes"
                                label="Notes (optional)"
                                rows="2"
                                :error-messages="dialog.errors.notes"
                            />
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

        <v-dialog v-model="deleteDialog.open" max-width="400">
            <v-card>
                <v-card-title>Delete entry?</v-card-title>
                <v-card-text>{{ deleteDialog.message }}</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="deleteDialog.open = false">Cancel</v-btn>
                    <v-btn color="error" @click="deleteDialog.confirm">Delete</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, reactive, computed } from 'vue'
import SmartSelect from '../../Components/SmartSelect.vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    title: String,
    events: Array,
    totals: Object,
    stats: Object,
    frequencies: Array,
})

const filter = ref('all')

const filteredEvents = computed(() => {
    if (filter.value === 'all') return props.events
    return props.events.filter((e) => e.source_kind === filter.value)
})

const frequencyOptions = [
    { value: 'weekly', title: 'Weekly' },
    { value: 'bi_weekly', title: 'Bi-weekly' },
    { value: 'monthly', title: 'Monthly' },
    { value: 'one_time', title: 'One-time' },
]

function frequencyLabel(f) {
    return frequencyOptions.find((o) => o.value === f)?.title || f
}

const today = new Date().toISOString().slice(0, 10)

const dialog = reactive({
    open: false,
    id: null,
    source: '',
    amount: 0,
    frequency: 'bi_weekly',
    pay_date: today,
    is_net: true,
    notes: '',
    processing: false,
    errors: {},
})

function openDialog(entry = null) {
    dialog.open = true
    dialog.errors = {}
    dialog.processing = false
    if (entry) {
        dialog.id = entry.id
        dialog.source = entry.source
        dialog.amount = entry.amount
        dialog.frequency = entry.frequency
        dialog.pay_date = entry.date
        dialog.is_net = entry.is_net
        dialog.notes = entry.notes ?? ''
    } else {
        dialog.id = null
        dialog.source = ''
        dialog.amount = 0
        dialog.frequency = 'bi_weekly'
        dialog.pay_date = today
        dialog.is_net = true
        dialog.notes = ''
    }
}

function save() {
    dialog.processing = true
    const payload = {
        source: dialog.source,
        amount: dialog.amount,
        frequency: dialog.frequency,
        pay_date: dialog.pay_date,
        is_net: dialog.is_net,
        notes: dialog.notes,
    }
    const opts = {
        preserveScroll: true,
        onSuccess: () => { dialog.open = false },
        onError: (errs) => { dialog.errors = errs },
        onFinish: () => { dialog.processing = false },
    }
    if (dialog.id) {
        router.patch(`/income/${dialog.id}`, payload, opts)
    } else {
        router.post('/income', payload, opts)
    }
}

const deleteDialog = reactive({ open: false, message: '', confirm: () => {} })

function confirmDelete(entry) {
    deleteDialog.open = true
    deleteDialog.message = `Delete this ${fmt(entry.amount)} entry from ${formatDate(entry.date)}?`
    deleteDialog.confirm = () => {
        router.delete(`/income/${entry.id}`, {
            preserveScroll: true,
            onFinish: () => { deleteDialog.open = false },
        })
    }
}

function goToTxn(id) {
    router.visit(`/transactions?search=` + encodeURIComponent(props.events.find(e => e.transaction_id === id)?.source || ''))
}

function fmt(value) {
    if (value === null || value === undefined) return '—'
    return Number(value || 0).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}

function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>
