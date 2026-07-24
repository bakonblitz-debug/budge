<script>
import BareLayout from '../../Layouts/BareLayout.vue'
export default { layout: BareLayout }
</script>

<template>
    <v-container class="py-12" max-width="900">
        <div class="text-center mb-8">
            <v-icon icon="mdi-wallet" size="64" color="primary" class="mb-3" />
            <h1 class="text-h4 font-weight-bold">Welcome to BudgetApp</h1>
            <p class="text-medium-emphasis mt-2">
                A few quick questions to get your budget set up.
            </p>
        </div>

        <v-card>
            <v-stepper v-model="step" hide-actions flat>
                <v-stepper-header>
                    <v-stepper-item :complete="step > 1" :value="1" title="Income" />
                    <v-divider />
                    <v-stepper-item :complete="step > 2" :value="2" title="Account" />
                    <v-divider />
                    <v-stepper-item :value="3" title="Review" />
                </v-stepper-header>

                <v-stepper-window>
                <v-stepper-window-item :value="1">
                    <v-card-text class="px-md-8 py-6">
                        <h2 class="text-h6 mb-1">Your income</h2>
                        <p class="text-body-2 text-medium-emphasis mb-6">
                            Used to compute monthly net pay, savings rate, and tax context. You can edit this anytime in Settings.
                        </p>

                        <v-row>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.profile.gross_salary"
                                    label="Gross annual salary"
                                    type="number"
                                    prefix="$"
                                    suffix="/ year"
                                    :error-messages="form.errors['profile.gross_salary']"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.profile.net_pay_per_period"
                                    label="Net pay per period"
                                    type="number"
                                    prefix="$"
                                    hint="After-tax take-home per paycheque"
                                    persistent-hint
                                    :error-messages="form.errors['profile.net_pay_per_period']"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <SmartSelect
                                    v-model="form.profile.pay_frequency"
                                    :items="payFrequencies"
                                    label="Pay frequency"
                                    :error-messages="form.errors['profile.pay_frequency']"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <SmartSelect
                                    v-model="form.profile.province"
                                    :items="provinces"
                                    label="Province"
                                    :error-messages="form.errors['profile.province']"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="form.profile.budget_start_date"
                                    label="Budget start date"
                                    type="date"
                                    hint="When your budget tracking begins (move-in date, etc.)"
                                    persistent-hint
                                    :error-messages="form.errors['profile.budget_start_date']"
                                />
                            </v-col>
                        </v-row>

                        <v-alert v-if="monthlyNet > 0" type="info" variant="tonal" class="mt-4" density="compact">
                            That works out to about <strong>${{ monthlyNet.toLocaleString('en-CA', { maximumFractionDigits: 0 }) }}/month</strong> net.
                        </v-alert>
                    </v-card-text>
                </v-stepper-window-item>

                <v-stepper-window-item :value="2">
                    <v-card-text class="px-md-8 py-6">
                        <h2 class="text-h6 mb-1">Your first account</h2>
                        <p class="text-body-2 text-medium-emphasis mb-6">
                            You'll import transactions into this. Add more accounts in Settings later.
                        </p>

                        <v-row>
                            <v-col cols="12" sm="8">
                                <v-text-field
                                    v-model="form.bank_account.name"
                                    label="Account name"
                                    placeholder="e.g. NBC Chequing"
                                    :error-messages="form.errors['bank_account.name']"
                                />
                            </v-col>
                            <v-col cols="12" sm="4">
                                <SmartSelect
                                    v-model="form.bank_account.type"
                                    :items="accountTypes"
                                    label="Account type"
                                    :error-messages="form.errors['bank_account.type']"
                                />
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.bank_account.current_balance"
                                    label="Current balance"
                                    type="number"
                                    prefix="$"
                                    hint="Optional — last known balance"
                                    persistent-hint
                                    :error-messages="form.errors['bank_account.current_balance']"
                                />
                            </v-col>
                            <v-col v-if="isCreditType" cols="12" sm="6">
                                <v-text-field
                                    v-model.number="form.bank_account.credit_limit"
                                    label="Credit limit"
                                    type="number"
                                    prefix="$"
                                    :error-messages="form.errors['bank_account.credit_limit']"
                                />
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-stepper-window-item>

                <v-stepper-window-item :value="3">
                    <v-card-text class="px-md-8 py-6">
                        <h2 class="text-h6 mb-1">All set</h2>
                        <p class="text-body-2 text-medium-emphasis mb-6">
                            We'll create your profile, account, and a starter set of categories you can edit anytime.
                        </p>

                        <v-card variant="outlined" class="mb-4">
                            <v-list density="compact">
                                <v-list-subheader>Profile</v-list-subheader>
                                <v-list-item>
                                    <template #prepend><v-icon icon="mdi-cash" /></template>
                                    <v-list-item-title>
                                        ${{ Number(form.profile.net_pay_per_period || 0).toLocaleString('en-CA') }}
                                        {{ payFrequencyLabel }}
                                    </v-list-item-title>
                                    <v-list-item-subtitle>
                                        ${{ Number(form.profile.gross_salary || 0).toLocaleString('en-CA') }} gross / {{ form.profile.province }}
                                    </v-list-item-subtitle>
                                </v-list-item>

                                <v-list-subheader>Account</v-list-subheader>
                                <v-list-item>
                                    <template #prepend><v-icon :icon="accountIcon(form.bank_account.type)" /></template>
                                    <v-list-item-title>{{ form.bank_account.name }}</v-list-item-title>
                                    <v-list-item-subtitle>
                                        {{ accountTypes.find(t => t.value === form.bank_account.type)?.title }}
                                    </v-list-item-subtitle>
                                </v-list-item>
                            </v-list>
                        </v-card>

                        <v-card variant="outlined">
                            <v-list density="compact">
                                <v-list-subheader>Starter categories ({{ defaultCategories.length }})</v-list-subheader>
                                <v-list-item
                                    v-for="cat in defaultCategories"
                                    :key="cat.name"
                                >
                                    <template #prepend>
                                        <v-icon :icon="cat.icon" :color="cat.color" />
                                    </template>
                                    <v-list-item-title>{{ cat.name }}</v-list-item-title>
                                    <v-list-item-subtitle v-if="cat.children.length">
                                        {{ cat.children.join(' · ') }}
                                    </v-list-item-subtitle>
                                </v-list-item>
                            </v-list>
                        </v-card>
                    </v-card-text>
                </v-stepper-window-item>
                </v-stepper-window>
            </v-stepper>

            <v-divider />

            <v-card-actions class="px-md-8 py-4">
                <v-btn
                    variant="text"
                    :disabled="step === 1 || form.processing"
                    @click="step--"
                >
                    Back
                </v-btn>
                <v-spacer />
                <v-btn
                    v-if="step < 3"
                    color="primary"
                    :disabled="!canAdvance"
                    @click="step++"
                >
                    Continue
                </v-btn>
                <v-btn
                    v-else
                    color="primary"
                    :loading="form.processing"
                    @click="submit"
                >
                    Finish setup
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-container>
</template>

<script setup>
import { ref, computed } from 'vue'
import SmartSelect from '../../Components/SmartSelect.vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    title: String,
    defaultCategories: Array,
    provinces: Array,
})

const step = ref(1)

const today = new Date().toISOString().slice(0, 10)

const form = useForm({
    profile: {
        gross_salary: null,
        net_pay_per_period: null,
        pay_frequency: 'bi_weekly',
        budget_start_date: today,
        province: 'QC',
    },
    bank_account: {
        name: '',
        type: 'chequing',
        current_balance: 0,
        credit_limit: null,
    },
})

const payFrequencies = [
    { value: 'weekly', title: 'Weekly' },
    { value: 'bi_weekly', title: 'Bi-weekly' },
    { value: 'semi_monthly', title: 'Semi-monthly (15th/30th)' },
    { value: 'monthly', title: 'Monthly' },
]

const accountTypes = [
    { value: 'chequing', title: 'Chequing' },
    { value: 'savings', title: 'Savings' },
    { value: 'credit_card', title: 'Credit card' },
    { value: 'line_of_credit', title: 'Line of credit' },
    { value: 'other', title: 'Other' },
]

const isCreditType = computed(() =>
    ['credit_card', 'line_of_credit'].includes(form.bank_account.type),
)

const payFrequencyLabel = computed(() => {
    const map = { weekly: '/ week', bi_weekly: 'bi-weekly', semi_monthly: 'twice a month', monthly: '/ month' }
    return map[form.profile.pay_frequency] || ''
})

const monthlyNet = computed(() => {
    const n = Number(form.profile.net_pay_per_period) || 0
    return {
        weekly: n * 52 / 12,
        bi_weekly: n * 26 / 12,
        semi_monthly: n * 2,
        monthly: n,
    }[form.profile.pay_frequency] || 0
})

const canAdvance = computed(() => {
    if (step.value === 1) {
        return form.profile.gross_salary > 0
            && form.profile.net_pay_per_period > 0
            && form.profile.budget_start_date
    }
    if (step.value === 2) {
        return form.bank_account.name.trim().length > 0
    }
    return true
})

function accountIcon(type) {
    return {
        chequing: 'mdi-bank',
        savings: 'mdi-piggy-bank',
        credit_card: 'mdi-credit-card',
        line_of_credit: 'mdi-cash-clock',
        other: 'mdi-wallet',
    }[type] || 'mdi-wallet'
}

function submit() {
    form.post('/onboarding')
}
</script>
