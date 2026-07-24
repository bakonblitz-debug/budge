<template>
    <div>
        <v-row class="mb-2">
            <v-col cols="12" sm="6" md="4">
                <v-card variant="tonal" color="primary">
                    <v-card-text>
                        <div class="text-caption">Monthly budgeted</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(totals.monthly_budgeted) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            Active budgets, normalized to monthly
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="4">
                <v-card variant="tonal" :color="overall.color">
                    <v-card-text>
                        <div class="text-caption">Monthly spent so far</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(totals.monthly_spent) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ overall.label }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="4">
                <v-card variant="tonal" :color="overall.remaining >= 0 ? 'success' : 'error'">
                    <v-card-text>
                        <div class="text-caption">Remaining this month</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(overall.remaining) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            Budgeted − spent
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-target" class="mr-2" />
                Budgets
                <span v-if="latestMonthLabel" class="text-caption text-medium-emphasis ml-3">
                    Latest imported month — {{ latestMonthLabel }}<span
                        v-if="periodPartial"
                        class="text-warning"
                        title="This month is still importing — spent-vs-budget reflects a partial month."
                    > · partial</span>
                </span>
                <v-spacer />
                <v-btn color="primary" prepend-icon="mdi-plus" @click="openDialog()">
                    Add budget
                </v-btn>
            </v-card-title>

            <v-card-text v-if="budgets.length === 0" class="text-center py-12">
                <v-icon icon="mdi-target" size="56" class="mb-2 text-medium-emphasis" />
                <div class="text-medium-emphasis mb-2">No budgets yet.</div>
                <div class="text-body-2 text-medium-emphasis">
                    Set monthly targets for variable categories (Groceries, Restaurants, etc.) and track how you're tracking against them.
                </div>
            </v-card-text>

            <v-list v-else>
                <v-list-item
                    v-for="b in budgets"
                    :key="b.id"
                    class="py-3"
                >
                    <template #prepend>
                        <v-avatar :color="b.category?.color || 'grey'" size="40">
                            <v-icon :icon="b.category?.icon || 'mdi-target'" color="white" size="20" />
                        </v-avatar>
                    </template>

                    <div class="flex-grow-1 mx-3">
                        <div class="d-flex align-center mb-1">
                            <div class="font-weight-medium">
                                {{ b.category?.parent_name ? `${b.category.parent_name} · ` : '' }}{{ b.category?.name }}
                            </div>
                            <v-chip v-if="!b.is_active" size="x-small" color="grey" variant="tonal" class="ml-2">Paused</v-chip>
                            <v-spacer />
                            <span :class="amountClass(b.status)" class="font-weight-medium">
                                {{ fmt(b.spent) }} <span class="text-medium-emphasis">/ {{ fmt(b.amount) }}</span>
                            </span>
                        </div>

                        <v-progress-linear
                            :model-value="b.progress"
                            :color="progressColor(b.status)"
                            height="8"
                            rounded
                        />

                        <div class="d-flex mt-1">
                            <span class="text-caption text-medium-emphasis">
                                {{ b.period_label }} · {{ b.period }}
                            </span>
                            <v-spacer />
                            <span class="text-caption" :class="amountClass(b.status)">
                                <template v-if="b.status === 'over'">
                                    {{ fmt(Math.abs(b.remaining)) }} over
                                </template>
                                <template v-else>
                                    {{ fmt(b.remaining) }} left
                                </template>
                            </span>
                        </div>
                    </div>

                    <template #append>
                        <v-btn icon="mdi-pencil" variant="text" size="small" @click="openDialog(b)" />
                        <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="confirmDelete(b)" />
                    </template>
                </v-list-item>
            </v-list>
        </v-card>

        <v-dialog v-model="dialog.open" max-width="560">
            <v-card>
                <v-card-title>{{ dialog.id ? 'Edit budget' : 'Add budget' }}</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12">
                            <SmartSelect
                                v-model="dialog.category_id"
                                :items="categoryOptions"
                                label="Category"
                                :error-messages="dialog.errors.category_id"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model.number="dialog.amount"
                                type="number"
                                step="0.01"
                                prefix="$"
                                label="Target amount"
                                :error-messages="dialog.errors.amount"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <SmartSelect
                                v-model="dialog.period"
                                :items="periodOptions"
                                label="Period"
                                :error-messages="dialog.errors.period"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model="dialog.start_date"
                                type="date"
                                label="Start date"
                                :error-messages="dialog.errors.start_date"
                            />
                        </v-col>
                        <v-col cols="12" sm="6" class="d-flex align-center">
                            <v-switch
                                v-model="dialog.is_active"
                                label="Active"
                                color="primary"
                                density="compact"
                                hide-details
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
                <v-card-title>Delete budget?</v-card-title>
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
    budgets: Array,
    totals: Object,
    categories: Array,
    periods: Array,
    periodPartial: Boolean,
})

const periodOptions = [
    { value: 'monthly', title: 'Monthly' },
    { value: 'weekly', title: 'Weekly' },
    { value: 'yearly', title: 'Yearly' },
]

const categoryOptions = computed(() =>
    props.categories.map((c) => ({
        value: c.id,
        title: c.parent?.name ? `${c.parent.name} · ${c.name}` : c.name,
    })),
)

// The anchored period label for monthly budgets (e.g. "May 2026"), surfaced so
// it's clear the figures reflect the latest imported month, not the live one.
const latestMonthLabel = computed(() => {
    const monthly = props.budgets.find((b) => b.period === 'monthly')
    return monthly?.period_label ?? null
})

const overall = computed(() => {
    const budgeted = Number(props.totals.monthly_budgeted) || 0
    const spent = Number(props.totals.monthly_spent) || 0
    const remaining = budgeted - spent
    const pct = budgeted > 0 ? spent / budgeted : 0
    if (pct >= 1) return { color: 'error', label: `${(pct * 100).toFixed(0)}% of budget`, remaining }
    if (pct >= 0.85) return { color: 'warning', label: `${(pct * 100).toFixed(0)}% of budget`, remaining }
    return { color: 'success', label: `${(pct * 100).toFixed(0)}% of budget`, remaining }
})

const today = new Date().toISOString().slice(0, 10)

const dialog = reactive({
    open: false,
    id: null,
    category_id: null,
    amount: 0,
    period: 'monthly',
    start_date: today,
    is_active: true,
    processing: false,
    errors: {},
})

function openDialog(budget = null) {
    dialog.open = true
    dialog.errors = {}
    dialog.processing = false
    if (budget) {
        dialog.id = budget.id
        dialog.category_id = budget.category?.id ?? null
        dialog.amount = budget.amount
        dialog.period = budget.period
        dialog.start_date = budget.start_date ?? today
        dialog.is_active = budget.is_active
    } else {
        dialog.id = null
        dialog.category_id = null
        dialog.amount = 0
        dialog.period = 'monthly'
        dialog.start_date = today
        dialog.is_active = true
    }
}

function save() {
    dialog.processing = true
    const payload = {
        category_id: dialog.category_id,
        amount: dialog.amount,
        period: dialog.period,
        start_date: dialog.start_date,
        is_active: dialog.is_active,
    }
    const opts = {
        preserveScroll: true,
        onSuccess: () => { dialog.open = false },
        onError: (errs) => { dialog.errors = errs },
        onFinish: () => { dialog.processing = false },
    }
    if (dialog.id) {
        router.patch(`/budgets/${dialog.id}`, payload, opts)
    } else {
        router.post('/budgets', payload, opts)
    }
}

const deleteDialog = reactive({ open: false, message: '', confirm: () => {} })

function confirmDelete(budget) {
    deleteDialog.open = true
    const name = budget.category?.name || 'this budget'
    deleteDialog.message = `Delete the ${name} budget? Historical spending will remain.`
    deleteDialog.confirm = () => {
        router.delete(`/budgets/${budget.id}`, {
            preserveScroll: true,
            onFinish: () => { deleteDialog.open = false },
        })
    }
}

function progressColor(status) {
    return { ok: 'success', warning: 'warning', over: 'error', unset: 'grey' }[status] || 'primary'
}

function amountClass(status) {
    return { ok: 'text-success', warning: 'text-warning', over: 'text-error', unset: '' }[status] || ''
}

function fmt(value) {
    return Number(value || 0).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}
</script>
