<template>
    <div>
        <!-- Financial Profile -->
        <v-card class="mb-6">
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-account-cash" class="mr-2" />
                Financial Profile
            </v-card-title>

            <v-card-text>
                <v-form @submit.prevent="saveProfile">
                    <v-row>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model.number="profileForm.gross_salary"
                                label="Annual Gross Salary"
                                type="number"
                                prefix="$"
                                :error-messages="profileForm.errors.gross_salary"
                                hint="Before taxes — for statistics and projections"
                                persistent-hint
                            />
                        </v-col>
                        <v-col cols="12" sm="6">
                            <v-text-field
                                v-model.number="profileForm.net_pay_per_period"
                                label="Net Pay Per Period"
                                type="number"
                                prefix="$"
                                :error-messages="profileForm.errors.net_pay_per_period"
                                hint="Your actual take-home per paycheque"
                                persistent-hint
                            />
                        </v-col>
                    </v-row>

                    <v-row>
                        <v-col cols="12" sm="4">
                            <SmartSelect
                                v-model="profileForm.pay_frequency"
                                :items="payFrequencies"
                                label="Pay Frequency"
                                :error-messages="profileForm.errors.pay_frequency"
                            />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field
                                v-model="profileForm.budget_start_date"
                                label="Budget Start Date"
                                type="date"
                                :error-messages="profileForm.errors.budget_start_date"
                                hint="Move-in or tracking start date"
                                persistent-hint
                            />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <SmartSelect
                                v-model="profileForm.province"
                                :items="provinces"
                                label="Province"
                                :error-messages="profileForm.errors.province"
                            />
                        </v-col>
                    </v-row>

                    <!-- Computed Preview -->
                    <v-alert v-if="profileForm.net_pay_per_period" type="info" variant="tonal" class="mt-4">
                        <div class="d-flex justify-space-between flex-wrap ga-4">
                            <div>
                                <div class="text-caption">Monthly Net</div>
                                <div class="text-h6">${{ monthlyNet.toLocaleString('en-CA', { minimumFractionDigits: 2 }) }}</div>
                            </div>
                            <div>
                                <div class="text-caption">Annual Net</div>
                                <div class="text-h6">${{ annualNet.toLocaleString('en-CA', { minimumFractionDigits: 2 }) }}</div>
                            </div>
                            <div>
                                <div class="text-caption">Pays/Year</div>
                                <div class="text-h6">{{ paysPerYear }}</div>
                            </div>
                        </div>
                    </v-alert>

                    <v-btn
                        type="submit"
                        color="primary"
                        class="mt-4"
                        :loading="profileForm.processing"
                        prepend-icon="mdi-content-save"
                    >
                        Save Profile
                    </v-btn>
                </v-form>
            </v-card-text>
        </v-card>

        <!-- Bank Accounts -->
        <v-card class="mb-6">
            <v-card-title class="d-flex align-center justify-space-between">
                <div class="d-flex align-center">
                    <v-icon icon="mdi-bank" class="mr-2" />
                    Bank Accounts
                </div>
                <v-btn color="primary" variant="tonal" size="small" prepend-icon="mdi-plus" @click="openAccountDialog()">
                    Add Account
                </v-btn>
            </v-card-title>

            <v-list v-if="bankAccounts.length > 0">
                <v-list-item
                    v-for="account in bankAccounts"
                    :key="account.id"
                    :subtitle="accountSubtitle(account)"
                >
                    <template #title>
                        <span class="font-weight-medium">{{ account.name }}</span>
                        <v-chip size="x-small" class="ml-2" :color="account.is_active ? 'success' : 'grey'">
                            {{ account.type.replace('_', ' ') }}
                        </v-chip>
                    </template>

                    <template #prepend>
                        <v-icon :icon="accountIcon(account.type)" />
                    </template>

                    <template #append>
                        <v-btn icon="mdi-pencil" variant="text" size="small" @click="openAccountDialog(account)" />
                        <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="confirmDeleteAccount(account)" />
                    </template>
                </v-list-item>
            </v-list>

            <v-card-text v-else>
                <v-alert type="info" variant="tonal">
                    No bank accounts configured. Add one to start importing transactions.
                </v-alert>
            </v-card-text>
        </v-card>

        <!-- Account Dialog -->
        <v-dialog v-model="accountDialog" max-width="500">
            <v-card>
                <v-card-title>{{ accountForm.id ? 'Edit' : 'Add' }} Bank Account</v-card-title>
                <v-card-text>
                    <v-text-field v-model="accountForm.name" label="Account Name" placeholder="e.g. NBC Chequing" class="mb-2" />
                    <SmartSelect v-model="accountForm.type" :items="accountTypes" label="Account Type" class="mb-2" />
                    <v-text-field
                        v-if="['credit_card', 'line_of_credit'].includes(accountForm.type)"
                        v-model.number="accountForm.credit_limit"
                        label="Credit Limit"
                        type="number"
                        prefix="$"
                        class="mb-2"
                    />
                    <v-text-field
                        v-model.number="accountForm.current_balance"
                        label="Current Balance"
                        type="number"
                        prefix="$"
                        hint="Optional — will update on CSV import"
                        persistent-hint
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="accountDialog = false">Cancel</v-btn>
                    <v-btn color="primary" :loading="accountForm.processing" @click="saveAccount">Save</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete Confirmation -->
        <v-dialog v-model="deleteDialog" max-width="400">
            <v-card>
                <v-card-title>Delete Account</v-card-title>
                <v-card-text>
                    Are you sure you want to delete <strong>{{ deleteTarget?.name }}</strong>?
                    This will also delete all associated transactions and import history.
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn @click="deleteDialog = false">Cancel</v-btn>
                    <v-btn color="error" @click="deleteAccount">Delete</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import SmartSelect from '../../Components/SmartSelect.vue'
import { useForm, router } from '@inertiajs/vue3'

const props = defineProps({
    title: String,
    profile: Object,
    bankAccounts: Array,
    settings: Object,
})

// ── Profile Form ──
const profileForm = useForm({
    gross_salary: props.profile?.gross_salary || null,
    net_pay_per_period: props.profile?.net_pay_per_period || null,
    pay_frequency: props.profile?.pay_frequency || 'bi_weekly',
    budget_start_date: props.profile?.budget_start_date || '',
    province: props.profile?.province || 'QC',
})

const payFrequencies = [
    { value: 'bi_weekly', title: 'Bi-weekly (every 2 weeks)' },
    { value: 'weekly', title: 'Weekly' },
    { value: 'semi_monthly', title: 'Semi-monthly (1st & 15th)' },
    { value: 'monthly', title: 'Monthly' },
]

const provinces = [
    { value: 'QC', title: 'Quebec' },
    { value: 'ON', title: 'Ontario' },
    { value: 'BC', title: 'British Columbia' },
    { value: 'AB', title: 'Alberta' },
    { value: 'MB', title: 'Manitoba' },
    { value: 'SK', title: 'Saskatchewan' },
    { value: 'NS', title: 'Nova Scotia' },
    { value: 'NB', title: 'New Brunswick' },
    { value: 'NL', title: 'Newfoundland & Labrador' },
    { value: 'PE', title: 'Prince Edward Island' },
]

const paysPerYear = computed(() => {
    const map = { bi_weekly: 26, weekly: 52, semi_monthly: 24, monthly: 12 }
    return map[profileForm.pay_frequency] || 0
})

const monthlyNet = computed(() => {
    const p = profileForm.net_pay_per_period || 0
    return (p * paysPerYear.value) / 12
})

const annualNet = computed(() => {
    return (profileForm.net_pay_per_period || 0) * paysPerYear.value
})

function saveProfile() {
    profileForm.post('/settings/profile')
}

// ── Bank Accounts ──
const accountDialog = ref(false)
const deleteDialog = ref(false)
const deleteTarget = ref(null)

const accountForm = useForm({
    id: null,
    name: '',
    type: 'chequing',
    credit_limit: null,
    current_balance: 0,
    is_active: true,
})

const accountTypes = [
    { value: 'chequing', title: 'Chequing' },
    { value: 'savings', title: 'Savings' },
    { value: 'credit_card', title: 'Credit Card' },
    { value: 'line_of_credit', title: 'Line of Credit' },
    { value: 'other', title: 'Other' },
]

function openAccountDialog(account = null) {
    if (account) {
        accountForm.id = account.id
        accountForm.name = account.name
        accountForm.type = account.type
        accountForm.credit_limit = account.credit_limit
        accountForm.current_balance = account.current_balance
        accountForm.is_active = account.is_active
    } else {
        accountForm.reset()
    }
    accountDialog.value = true
}

function saveAccount() {
    accountForm.post('/settings/bank-accounts', {
        onSuccess: () => {
            accountDialog.value = false
            accountForm.reset()
        },
    })
}

function confirmDeleteAccount(account) {
    deleteTarget.value = account
    deleteDialog.value = true
}

function deleteAccount() {
    router.delete(`/settings/bank-accounts/${deleteTarget.value.id}`, {
        onSuccess: () => {
            deleteDialog.value = false
            deleteTarget.value = null
        },
    })
}

function accountIcon(type) {
    const icons = {
        chequing: 'mdi-bank',
        savings: 'mdi-piggy-bank',
        credit_card: 'mdi-credit-card',
        line_of_credit: 'mdi-cash-clock',
        other: 'mdi-wallet',
    }
    return icons[type] || 'mdi-wallet'
}

function accountSubtitle(account) {
    const parts = []
    if (account.current_balance) parts.push(`Balance: $${Number(account.current_balance).toLocaleString('en-CA', { minimumFractionDigits: 2 })}`)
    if (account.credit_limit) parts.push(`Limit: $${Number(account.credit_limit).toLocaleString('en-CA', { minimumFractionDigits: 2 })}`)
    return parts.join(' · ') || 'No balance set'
}
</script>
