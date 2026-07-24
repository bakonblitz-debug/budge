<template>
    <div>
        <v-row class="mb-2">
            <v-col cols="12" sm="6" md="4">
                <v-card variant="tonal" color="primary">
                    <v-card-text>
                        <div class="text-caption">Monthly equivalent (active)</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(monthlyTotal) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ activeCount }} of {{ expenses.length }} expenses active
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-home-city" class="mr-2" />
                Fixed expenses
                <v-spacer />
                <v-btn color="primary" prepend-icon="mdi-plus" @click="openDialog()">
                    Add expense
                </v-btn>
            </v-card-title>

            <v-data-table
                :headers="headers"
                :items="expenses"
                :items-per-page="-1"
                hover
                density="comfortable"
            >
                <template #item.amount="{ item }">
                    <span class="font-weight-medium">{{ fmt(item.amount) }}</span>
                    <div class="text-caption text-medium-emphasis">{{ frequencyLabel(item.frequency) }}</div>
                </template>
                <template #item.monthly_amount="{ item }">
                    <span class="font-weight-medium">{{ fmt(item.monthly_amount) }}</span>
                </template>
                <template #item.category="{ item }">
                    <v-chip v-if="item.category" :color="item.category.color || 'default'" size="small" variant="flat" :prepend-icon="item.category.icon || 'mdi-tag'">
                        <span class="text-white">{{ item.category.name }}</span>
                    </v-chip>
                    <span v-else class="text-caption text-medium-emphasis">—</span>
                </template>
                <template #item.due_day="{ item }">
                    <span v-if="item.due_day">{{ ordinal(item.due_day) }}</span>
                    <span v-else class="text-caption text-medium-emphasis">—</span>
                </template>
                <template #item.is_active="{ item }">
                    <v-chip :color="item.is_active ? 'success' : 'grey'" size="x-small" variant="tonal">
                        {{ item.is_active ? 'Active' : 'Paused' }}
                    </v-chip>
                </template>
                <template #item.actions="{ item }">
                    <v-btn icon="mdi-pencil" variant="text" size="small" @click="openDialog(item)" />
                    <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="confirmDelete(item)" />
                </template>
                <template #no-data>
                    <div class="text-center py-8">
                        <v-icon icon="mdi-home-city" size="48" class="mb-2 text-medium-emphasis" />
                        <div class="text-medium-emphasis">No fixed expenses yet — add your first one.</div>
                    </div>
                </template>
            </v-data-table>
        </v-card>

        <v-card v-if="suggestions.length > 0" class="mt-4">
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-lightbulb-on-outline" class="mr-2" />
                Suggested fixed expenses
            </v-card-title>
            <v-card-subtitle>
                Regular outflows we found in your transactions that aren't tracked here yet.
                This catches recurring investments (TFSA/RRSP contributions) that show up as
                transfers and so don't appear as spending.
            </v-card-subtitle>

            <v-list lines="two">
                <v-list-item v-for="s in suggestions" :key="`${s.merchant}-${s.amount}`">
                    <v-list-item-title class="d-flex align-center ga-2">
                        {{ s.merchant }}
                        <v-chip size="x-small" variant="tonal">{{ cadenceLabel(s.suggested_cadence) }}</v-chip>
                        <v-chip v-if="s.category_name" size="x-small" color="secondary" variant="tonal">
                            {{ s.category_name }}
                        </v-chip>
                    </v-list-item-title>
                    <v-list-item-subtitle>
                        {{ fmt(s.amount) }} · {{ s.occurrences }} charges ·
                        <span v-for="(sample, i) in s.sample" :key="i">
                            {{ formatDate(sample.date) }}{{ i < s.sample.length - 1 ? ', ' : '' }}
                        </span>
                    </v-list-item-subtitle>
                    <template #append>
                        <v-btn
                            color="primary"
                            variant="tonal"
                            size="small"
                            prepend-icon="mdi-plus"
                            @click="addSuggestion(s)"
                        >
                            Add
                        </v-btn>
                    </template>
                </v-list-item>
            </v-list>
        </v-card>

        <v-dialog v-model="dialog.open" max-width="600">
            <v-card>
                <v-card-title>{{ dialog.id ? 'Edit expense' : 'Add expense' }}</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="8">
                            <v-text-field
                                v-model="dialog.name"
                                label="Name"
                                placeholder="Rent, Hydro, Internet…"
                                :error-messages="dialog.errors.name"
                                autofocus
                            />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field
                                v-model.number="dialog.amount"
                                label="Amount"
                                type="number"
                                prefix="$"
                                step="0.01"
                                :error-messages="dialog.errors.amount"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-text-field
                                v-model="dialog.match_pattern"
                                label="Match pattern (optional)"
                                :placeholder="dialog.name || 'defaults to the name'"
                                :error-messages="dialog.errors.match_pattern"
                                hint="Bank merchant text to match for ‘spent’ — e.g. GOODLIFE for a ‘Gym Membership’. Leave blank to match the name."
                                persistent-hint
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
                            <SmartSelect
                                v-model="dialog.category_id"
                                :items="categoryOptions"
                                label="Category"
                                :error-messages="dialog.errors.category_id"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model="dialog.start_date"
                                label="Start date"
                                type="date"
                                :error-messages="dialog.errors.start_date"
                            />
                        </v-col>
                        <v-col cols="6" sm="3">
                            <v-text-field
                                v-model.number="dialog.due_day"
                                label="Due day"
                                type="number"
                                min="1"
                                max="31"
                                hint="Day of month (1-31)"
                                persistent-hint
                                :error-messages="dialog.errors.due_day"
                            />
                        </v-col>
                        <v-col cols="6" sm="3" class="d-flex align-center">
                            <v-switch
                                v-model="dialog.is_active"
                                label="Active"
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
                        <v-col cols="12" v-if="monthlyPreview">
                            <v-alert type="info" variant="tonal" density="compact">
                                Monthly equivalent: <strong>{{ fmt(monthlyPreview) }}</strong>
                            </v-alert>
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
                <v-card-title>Delete expense?</v-card-title>
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
import { reactive, computed } from 'vue'
import SmartSelect from '../../Components/SmartSelect.vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    title: String,
    expenses: Array,
    monthlyTotal: Number,
    categories: Array,
    frequencies: Array,
    suggestions: { type: Array, default: () => [] },
})

const headers = [
    { title: 'Name', key: 'name' },
    { title: 'Amount', key: 'amount' },
    { title: 'Category', key: 'category' },
    { title: 'Monthly', key: 'monthly_amount', align: 'end' },
    { title: 'Due day', key: 'due_day', align: 'center' },
    { title: 'Status', key: 'is_active', align: 'center' },
    { title: '', key: 'actions', sortable: false, align: 'end', width: 110 },
]

const frequencyOptions = [
    { value: 'weekly', title: 'Weekly' },
    { value: 'bi_weekly', title: 'Bi-weekly' },
    { value: 'monthly', title: 'Monthly' },
    { value: 'quarterly', title: 'Quarterly' },
    { value: 'yearly', title: 'Yearly' },
]

function frequencyLabel(f) {
    return frequencyOptions.find((o) => o.value === f)?.title ?? f
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

/**
 * Map a detected cadence onto a FixedExpense (frequency, amount). Cadences the
 * Fixed Expenses model doesn't support (every 2 / 6 months) collapse to a
 * monthly-equivalent amount — mirrors SubscriptionController::fixedExpenseCadence.
 */
function cadenceToFixedExpense(cadence, amount) {
    if (cadence === 'bi_monthly') return { frequency: 'monthly', amount: round2(amount / 2) }
    if (cadence === 'semi_annual') return { frequency: 'monthly', amount: round2(amount / 6) }
    return { frequency: cadence, amount: round2(amount) }
}

function round2(n) {
    return Math.round((Number(n) || 0) * 100) / 100
}

function addSuggestion(s) {
    const { frequency, amount } = cadenceToFixedExpense(s.suggested_cadence, s.amount)
    const sampleDay = s.sample?.[0]?.date ? new Date(s.sample[0].date).getDate() : null
    const dueDay = sampleDay ? Math.max(1, Math.min(28, sampleDay)) : null

    router.post('/fixed-expenses', {
        name: s.merchant,
        category_id: s.category_id,
        amount,
        frequency,
        due_day: dueDay,
        start_date: s.sample?.[s.sample.length - 1]?.date ?? today,
        is_active: true,
        sort_order: 0,
    }, { preserveScroll: true })
}

const categoryOptions = computed(() =>
    props.categories.map((c) => ({
        value: c.id,
        title: c.parent?.name ? `${c.parent.name} · ${c.name}` : c.name,
    })),
)

const activeCount = computed(() => props.expenses.filter((e) => e.is_active).length)

const today = new Date().toISOString().slice(0, 10)

const dialog = reactive({
    open: false,
    id: null,
    name: '',
    match_pattern: '',
    amount: 0,
    frequency: 'monthly',
    category_id: null,
    start_date: today,
    end_date: null,
    due_day: null,
    is_active: true,
    notes: '',
    processing: false,
    errors: {},
})

const monthlyPreview = computed(() => {
    const a = Number(dialog.amount) || 0
    if (!a) return 0
    return {
        monthly: a,
        bi_weekly: (a * 26) / 12,
        weekly: (a * 52) / 12,
        quarterly: a / 3,
        yearly: a / 12,
    }[dialog.frequency] || a
})

function openDialog(expense = null) {
    dialog.open = true
    dialog.errors = {}
    dialog.processing = false
    if (expense) {
        dialog.id = expense.id
        dialog.name = expense.name
        dialog.match_pattern = expense.match_pattern ?? ''
        dialog.amount = expense.amount
        dialog.frequency = expense.frequency
        dialog.category_id = expense.category?.id ?? null
        dialog.start_date = expense.start_date ?? today
        dialog.end_date = expense.end_date
        dialog.due_day = expense.due_day
        dialog.is_active = expense.is_active
        dialog.notes = expense.notes ?? ''
    } else {
        dialog.id = null
        dialog.name = ''
        dialog.match_pattern = ''
        dialog.amount = 0
        dialog.frequency = 'monthly'
        dialog.category_id = null
        dialog.start_date = today
        dialog.end_date = null
        dialog.due_day = null
        dialog.is_active = true
        dialog.notes = ''
    }
}

function save() {
    dialog.processing = true
    const payload = {
        name: dialog.name,
        match_pattern: dialog.match_pattern || null,
        amount: dialog.amount,
        frequency: dialog.frequency,
        category_id: dialog.category_id,
        start_date: dialog.start_date,
        end_date: dialog.end_date,
        due_day: dialog.due_day,
        is_active: dialog.is_active,
        notes: dialog.notes,
    }
    const opts = {
        preserveScroll: true,
        onSuccess: () => { dialog.open = false },
        onError: (errs) => { dialog.errors = errs },
        onFinish: () => { dialog.processing = false },
    }
    if (dialog.id) {
        router.patch(`/fixed-expenses/${dialog.id}`, payload, opts)
    } else {
        router.post('/fixed-expenses', payload, opts)
    }
}

const deleteDialog = reactive({
    open: false,
    message: '',
    confirm: () => {},
})

function confirmDelete(expense) {
    deleteDialog.open = true
    deleteDialog.message = `Delete "${expense.name}"?`
    deleteDialog.confirm = () => {
        router.delete(`/fixed-expenses/${expense.id}`, {
            preserveScroll: true,
            onFinish: () => { deleteDialog.open = false },
        })
    }
}

function fmt(value) {
    return Number(value || 0).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}

function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' })
}

function ordinal(n) {
    const s = ['th', 'st', 'nd', 'rd']
    const v = n % 100
    return n + (s[(v - 20) % 10] || s[v] || s[0])
}
</script>
