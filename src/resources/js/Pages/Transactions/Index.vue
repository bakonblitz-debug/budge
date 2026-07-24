<template>
    <div>
        <v-row class="mb-2">
            <v-col cols="6" sm="3">
                <v-card variant="tonal" color="success">
                    <v-card-text>
                        <div class="text-caption">Income</div>
                        <div class="text-h6 font-weight-bold">{{ fmt(totals.income) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card variant="tonal" color="error">
                    <v-card-text>
                        <div class="text-caption">Expenses</div>
                        <div class="text-h6 font-weight-bold">{{ fmt(totals.expenses) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card variant="tonal" :color="totals.net >= 0 ? 'primary' : 'warning'">
                    <v-card-text>
                        <div class="text-caption">Net</div>
                        <div class="text-h6 font-weight-bold">{{ fmt(totals.net) }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="6" sm="3">
                <v-card variant="tonal" color="secondary">
                    <v-card-text>
                        <div class="text-caption">Transactions</div>
                        <div class="text-h6 font-weight-bold">{{ totals.count.toLocaleString('en-CA') }}</div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-card-title class="d-flex align-center flex-wrap ga-2">
                <v-icon icon="mdi-swap-horizontal" class="mr-1" />
                <span class="text-subtitle-1">Transactions</span>
                <v-spacer />
                <v-btn
                    v-if="linkableSelection"
                    color="primary"
                    size="small"
                    prepend-icon="mdi-link-variant"
                    :loading="linking"
                    @click="linkSelected"
                >
                    Link as transfer
                </v-btn>
                <v-btn
                    variant="tonal"
                    size="small"
                    prepend-icon="mdi-magnify-scan"
                    :loading="detecting"
                    @click="detectTransfers"
                >
                    Auto-detect transfers
                </v-btn>
            </v-card-title>

            <v-card-subtitle v-if="selected.length === 1" class="pb-2">
                Select one more (an opposing in/out amount) to link them as a transfer.
            </v-card-subtitle>

            <v-card-text>
                <v-row dense>
                    <v-col cols="12" sm="6" md="3">
                        <v-text-field
                            v-model="form.search"
                            label="Search description"
                            prepend-inner-icon="mdi-magnify"
                            clearable
                            density="compact"
                            hide-details
                            @update:modelValue="debouncedApply"
                        />
                    </v-col>
                    <v-col cols="12" sm="6" md="3">
                        <SmartSelect
                            v-model="form.account"
                            :items="accountOptions"
                            label="Account"
                            prepend-inner-icon="mdi-bank"
                            clearable
                            density="compact"
                            hide-details
                            @update:modelValue="apply"
                        />
                    </v-col>
                    <v-col cols="6" sm="3" md="2">
                        <SmartSelect
                            v-model="form.month"
                            :items="monthOptions"
                            label="Month"
                            clearable
                            density="compact"
                            hide-details
                            @update:modelValue="apply"
                        />
                    </v-col>
                    <v-col cols="6" sm="3" md="2">
                        <SmartSelect
                            v-model="form.year"
                            :items="yearOptions"
                            label="Year"
                            clearable
                            density="compact"
                            hide-details
                            @update:modelValue="apply"
                        />
                    </v-col>
                    <v-col cols="12" md="2">
                        <SmartSelect
                            v-model="form.type"
                            :items="typeOptions"
                            label="Type"
                            clearable
                            density="compact"
                            hide-details
                            @update:modelValue="apply"
                        />
                    </v-col>
                </v-row>
            </v-card-text>

            <v-divider />

            <v-data-table-server
                v-model="selected"
                :headers="headers"
                :items="transactions.data"
                :items-length="transactions.total"
                :items-per-page="transactions.per_page"
                :page="transactions.current_page"
                item-value="id"
                show-select
                hover
                density="comfortable"
                @update:page="goToPage"
            >
                <template #item="{ item, internalItem, isSelected, toggleSelect }">
                    <tr :class="{ 'opacity-50': item.is_excluded }">
                        <td>
                            <v-checkbox-btn
                                :model-value="isSelected(internalItem)"
                                density="compact"
                                hide-details
                                @update:model-value="toggleSelect(internalItem)"
                            />
                        </td>
                        <td>{{ formatDate(item.transaction_date) }}</td>
                        <td>
                            <div class="d-flex align-center ga-2">
                                <div class="font-weight-medium">{{ item.merchant_name || item.description }}</div>
                                <v-chip
                                    v-if="item.transfer_id"
                                    size="x-small"
                                    color="info"
                                    variant="tonal"
                                    prepend-icon="mdi-swap-horizontal"
                                >
                                    Transfer
                                    <v-tooltip activator="parent" text="Unlink transfer" />
                                    <template #append>
                                        <v-icon
                                            icon="mdi-close-circle"
                                            size="14"
                                            class="ml-1"
                                            @click.stop="unlinkTransfer(item)"
                                        />
                                    </template>
                                </v-chip>
                                <v-chip
                                    v-if="item.is_excluded"
                                    size="x-small"
                                    color="warning"
                                    variant="tonal"
                                    prepend-icon="mdi-eye-off"
                                >
                                    Excluded
                                </v-chip>
                            </div>
                            <div class="text-caption text-medium-emphasis">
                                <span v-if="item.merchant_name && item.merchant_name !== item.description">{{ item.description }}</span>
                                <span v-if="item.merchant_name && item.merchant_name !== item.description && item.bank_account"> · </span>
                                <span v-if="item.bank_account">{{ item.bank_account.name }}</span>
                                <span v-if="item.notes"> · <v-icon icon="mdi-note-text-outline" size="12" /> {{ item.notes }}</span>
                            </div>
                        </td>
                        <td>
                            <v-chip
                                v-if="item.category"
                                :color="item.category.color || 'default'"
                                size="small"
                                variant="flat"
                                :prepend-icon="item.category.icon || 'mdi-tag'"
                            >
                                <span class="text-white">
                                    {{ item.category.parent?.name ? `${item.category.parent.name} · ` : '' }}{{ item.category.name }}
                                </span>
                            </v-chip>
                            <span v-else class="text-caption text-medium-emphasis">Uncategorized</span>
                        </td>
                        <td class="text-end">
                            <span
                                :class="item.amount < 0 ? 'text-error' : 'text-success'"
                                class="font-weight-medium"
                            >
                                {{ fmt(item.amount, true) }}
                            </span>
                        </td>
                        <td class="text-end">
                            <span v-if="item.balance_after !== null" class="text-medium-emphasis">
                                {{ fmt(item.balance_after) }}
                            </span>
                        </td>
                        <td class="text-end">
                            <v-btn
                                icon="mdi-pencil"
                                size="x-small"
                                variant="text"
                                @click.stop="openEdit(item)"
                            >
                                <v-icon icon="mdi-pencil" />
                                <v-tooltip activator="parent" text="Edit transaction" />
                            </v-btn>
                        </td>
                    </tr>
                </template>

                <template #no-data>
                    <div class="text-center py-8">
                        <v-icon icon="mdi-database-off" size="48" class="mb-2 text-medium-emphasis" />
                        <div class="text-medium-emphasis">
                            {{ hasFilters ? 'No transactions match your filters.' : 'No transactions yet — import a statement to get started.' }}
                        </div>
                    </div>
                </template>
            </v-data-table-server>
        </v-card>

        <v-dialog v-model="editDialog.open" max-width="480">
            <v-card>
                <v-card-title class="d-flex align-center ga-2">
                    <v-icon icon="mdi-pencil" />
                    Edit transaction
                </v-card-title>
                <v-card-subtitle v-if="editDialog.transaction" class="pb-1">
                    {{ editDialog.transaction.merchant_name || editDialog.transaction.description }}
                    · {{ fmt(editDialog.transaction.amount, true) }}
                </v-card-subtitle>
                <v-card-text>
                    <SmartSelect
                        v-model="editForm.category_id"
                        :items="categoryOptions"
                        label="Category"
                        prepend-inner-icon="mdi-tag"
                        clearable
                        density="comfortable"
                    />
                    <v-textarea
                        v-model="editForm.notes"
                        label="Notes"
                        prepend-inner-icon="mdi-note-text-outline"
                        rows="2"
                        auto-grow
                        clearable
                        counter="1000"
                        :rules="[(v) => !v || v.length <= 1000 || 'Max 1000 characters']"
                    />
                    <v-switch
                        v-model="editForm.is_excluded"
                        color="warning"
                        label="Exclude from budgets & analysis"
                        hide-details
                        inset
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="editDialog.open = false">Cancel</v-btn>
                    <v-btn color="primary" variant="flat" :loading="saving" @click="saveEdit">Save</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { reactive, computed, ref } from 'vue'
import SmartSelect from '../../Components/SmartSelect.vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    title: String,
    transactions: Object,
    totals: Object,
    filters: Object,
    bankAccounts: Array,
    categories: Array,
})

const selected = ref([])
const linking = ref(false)
const detecting = ref(false)
const saving = ref(false)

const editDialog = reactive({ open: false, transaction: null })
const editForm = reactive({ category_id: null, notes: null, is_excluded: false })

const categoryOptions = computed(() =>
    props.categories.map((c) => ({
        value: c.id,
        title: c.parent?.name ? `${c.parent.name} · ${c.name}` : c.name,
    })),
)

function openEdit(item) {
    editDialog.transaction = item
    editForm.category_id = item.category?.id ?? null
    editForm.notes = item.notes ?? null
    editForm.is_excluded = !!item.is_excluded
    editDialog.open = true
}

function saveEdit() {
    saving.value = true
    router.patch(`/transactions/${editDialog.transaction.id}`, {
        category_id: editForm.category_id,
        notes: editForm.notes,
        is_excluded: editForm.is_excluded,
    }, {
        preserveScroll: true,
        onSuccess: () => { editDialog.open = false },
        onFinish: () => { saving.value = false },
    })
}

// A valid transfer is exactly two selected rows with opposite signs (one
// outflow, one inflow), neither already part of a transfer.
const linkableSelection = computed(() => {
    if (selected.value.length !== 2) return false
    const rows = props.transactions.data.filter((t) => selected.value.includes(t.id))
    if (rows.length !== 2 || rows.some((r) => r.transfer_id)) return false
    return rows.some((r) => r.amount < 0) && rows.some((r) => r.amount > 0)
})

function linkSelected() {
    linking.value = true
    router.post('/transfers', { transaction_ids: selected.value }, {
        preserveScroll: true,
        onSuccess: () => { selected.value = [] },
        onFinish: () => { linking.value = false },
    })
}

function unlinkTransfer(item) {
    router.delete(`/transfers/${item.transfer_id}`, { preserveScroll: true })
}

function detectTransfers() {
    detecting.value = true
    router.post('/transfers/detect', {}, {
        preserveScroll: true,
        onFinish: () => { detecting.value = false },
    })
}

const form = reactive({
    search: props.filters.search ?? null,
    account: props.filters.account ?? null,
    month: props.filters.month ?? null,
    year: props.filters.year ?? null,
    type: props.filters.type ?? null,
})

const headers = [
    { title: 'Date', key: 'transaction_date', sortable: false, width: 110 },
    { title: 'Description', key: 'description', sortable: false },
    { title: 'Category', key: 'category', sortable: false, width: 200 },
    { title: 'Amount', key: 'amount', sortable: false, align: 'end', width: 130 },
    { title: 'Balance', key: 'balance_after', sortable: false, align: 'end', width: 130 },
    { title: '', key: 'actions', sortable: false, align: 'end', width: 60 },
]

const accountOptions = computed(() =>
    props.bankAccounts.map((a) => ({ value: a.id, title: a.name })),
)

const monthOptions = Array.from({ length: 12 }, (_, i) => ({
    value: i + 1,
    title: new Date(2000, i).toLocaleString('en', { month: 'long' }),
}))

const currentYear = new Date().getFullYear()
const yearOptions = Array.from({ length: 6 }, (_, i) => ({
    value: currentYear - i,
    title: String(currentYear - i),
}))

const typeOptions = [
    { value: 'income', title: 'Income only' },
    { value: 'expense', title: 'Expenses only' },
]

const hasFilters = computed(() =>
    !!(form.search || form.account || form.month || form.year || form.type),
)

let searchTimer = null
function debouncedApply() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(apply, 250)
}

function apply() {
    const payload = {}
    for (const [k, v] of Object.entries(form)) {
        if (v !== null && v !== '' && v !== undefined) payload[k] = v
    }
    router.get('/transactions', payload, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
}

function goToPage(page) {
    const payload = { page }
    for (const [k, v] of Object.entries(form)) {
        if (v !== null && v !== '' && v !== undefined) payload[k] = v
    }
    router.get('/transactions', payload, {
        preserveState: true,
        preserveScroll: true,
    })
}

function fmt(value, signed = false) {
    const v = Number(value || 0)
    const formatted = Math.abs(v).toLocaleString('en-CA', {
        style: 'currency',
        currency: 'CAD',
    })
    if (signed && v < 0) return '-' + formatted
    if (signed && v > 0) return '+' + formatted
    return formatted
}

function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>
