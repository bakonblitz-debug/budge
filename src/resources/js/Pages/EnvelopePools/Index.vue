<template>
    <div>
        <v-row class="mb-2">
            <v-col cols="12" sm="6" md="4">
                <v-card variant="tonal" color="primary">
                    <v-card-text>
                        <div class="text-caption">Monthly accrual (active)</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(totals.monthly_accrual) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            What you're setting aside each month
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="4">
                <v-card variant="tonal" :color="totals.balance >= 0 ? 'success' : 'error'">
                    <v-card-text>
                        <div class="text-caption">Total balance</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(totals.balance) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            Sum of accrued minus spending
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-email-multiple" class="mr-2" />
                Envelope pools
                <v-spacer />
                <v-btn color="primary" prepend-icon="mdi-plus" @click="openDialog()">
                    Add pool
                </v-btn>
            </v-card-title>

            <v-list v-if="pools.length > 0">
                <v-list-item v-for="pool in pools" :key="pool.id" class="py-3">
                    <template #prepend>
                        <v-avatar :color="pool.category?.color || 'grey'" size="40">
                            <v-icon :icon="pool.category?.icon || 'mdi-email'" color="white" size="20" />
                        </v-avatar>
                    </template>

                    <v-list-item-title class="font-weight-medium">
                        {{ pool.name }}
                        <v-chip v-if="!pool.is_active" size="x-small" color="grey" variant="tonal" class="ml-2">Paused</v-chip>
                    </v-list-item-title>
                    <v-list-item-subtitle class="text-caption">
                        {{ pool.category?.parent?.name ? `${pool.category.parent.name} · ` : '' }}{{ pool.category?.name }}
                        · {{ fmt(pool.monthly_accrual) }}/mo
                        · since {{ formatDate(pool.start_date) }}
                    </v-list-item-subtitle>

                    <template #append>
                        <div class="text-end mr-4">
                            <div :class="balanceClass(pool.calculated_balance)" class="text-h6 font-weight-bold">
                                {{ fmt(pool.calculated_balance) }}
                            </div>
                            <div class="text-caption text-medium-emphasis">balance</div>
                        </div>
                        <v-btn icon="mdi-pencil" variant="text" size="small" @click="openDialog(pool)" />
                        <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="confirmDelete(pool)" />
                    </template>
                </v-list-item>
            </v-list>

            <v-card-text v-else class="text-center py-12">
                <v-icon icon="mdi-email-outline" size="56" class="mb-2 text-medium-emphasis" />
                <div class="text-medium-emphasis mb-3">No envelope pools yet.</div>
                <div class="text-body-2 text-medium-emphasis mb-4">
                    Set a monthly accrual for variable categories (Groceries, Restaurants, etc.) to track
                    how much you've saved up vs. spent.
                </div>
            </v-card-text>
        </v-card>

        <v-dialog v-model="dialog.open" max-width="560">
            <v-card>
                <v-card-title>{{ dialog.id ? 'Edit pool' : 'Add pool' }}</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12">
                            <v-text-field
                                v-model="dialog.name"
                                label="Name"
                                placeholder="Groceries pool, Dining out, Gas…"
                                :error-messages="dialog.errors.name"
                                autofocus
                            />
                        </v-col>
                        <v-col cols="12" sm="8">
                            <SmartSelect
                                v-model="dialog.category_id"
                                :items="categoryOptions"
                                label="Category"
                                :hint="dialog.id ? 'Category cannot be changed' : 'Each category can have one pool'"
                                persistent-hint
                                :disabled="!!dialog.id"
                                :error-messages="dialog.errors.category_id"
                            />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field
                                v-model.number="dialog.monthly_accrual"
                                type="number"
                                step="0.01"
                                prefix="$"
                                suffix="/mo"
                                label="Monthly accrual"
                                :error-messages="dialog.errors.monthly_accrual"
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model="dialog.start_date"
                                type="date"
                                label="Start date"
                                hint="Spending before this date is ignored"
                                persistent-hint
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
                <v-card-title>Delete pool?</v-card-title>
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
    pools: Array,
    totals: Object,
    categories: Array,
})

const today = new Date().toISOString().slice(0, 10)

const categoryOptions = computed(() =>
    props.categories.map((c) => ({
        value: c.id,
        title: c.parent?.name ? `${c.parent.name} · ${c.name}` : c.name,
    })),
)

const dialog = reactive({
    open: false,
    id: null,
    name: '',
    category_id: null,
    monthly_accrual: 0,
    start_date: today,
    is_active: true,
    processing: false,
    errors: {},
})

function openDialog(pool = null) {
    dialog.open = true
    dialog.errors = {}
    dialog.processing = false
    if (pool) {
        dialog.id = pool.id
        dialog.name = pool.name
        dialog.category_id = pool.category?.id ?? null
        dialog.monthly_accrual = pool.monthly_accrual
        dialog.start_date = pool.start_date ?? today
        dialog.is_active = pool.is_active
    } else {
        dialog.id = null
        dialog.name = ''
        dialog.category_id = null
        dialog.monthly_accrual = 0
        dialog.start_date = today
        dialog.is_active = true
    }
}

function save() {
    dialog.processing = true
    const payload = {
        name: dialog.name,
        category_id: dialog.category_id,
        monthly_accrual: dialog.monthly_accrual,
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
        router.patch(`/envelope-pools/${dialog.id}`, payload, opts)
    } else {
        router.post('/envelope-pools', payload, opts)
    }
}

const deleteDialog = reactive({ open: false, message: '', confirm: () => {} })

function confirmDelete(pool) {
    deleteDialog.open = true
    deleteDialog.message = `Delete "${pool.name}"? Historical spending will remain but the pool calculation will be gone.`
    deleteDialog.confirm = () => {
        router.delete(`/envelope-pools/${pool.id}`, {
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

function balanceClass(balance) {
    if (balance >= 0) return 'text-success'
    return 'text-error'
}
</script>
