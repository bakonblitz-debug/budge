<template>
    <div>
        <v-row>
            <v-col cols="12" md="7">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-file-upload" class="mr-2" />
                        Upload statement
                    </v-card-title>

                    <v-card-text>
                        <v-form @submit.prevent="submitImport" ref="formRef">
                            <SmartSelect
                                v-model="form.bank_account_id"
                                :items="bankAccounts"
                                item-title="name"
                                item-value="id"
                                label="Bank account"
                                :error-messages="form.errors.bank_account_id"
                                prepend-inner-icon="mdi-bank"
                                class="mb-2"
                            >
                                <template #item="{ item, props }">
                                    <v-list-item v-bind="props">
                                        <template #prepend>
                                            <v-icon :icon="accountIcon(item.raw.type)" />
                                        </template>
                                    </v-list-item>
                                </template>
                            </SmartSelect>

                            <v-row>
                                <v-col cols="6">
                                    <SmartSelect
                                        v-model="form.period_month"
                                        :items="months"
                                        label="Month"
                                        :error-messages="form.errors.period_month"
                                        prepend-inner-icon="mdi-calendar"
                                    />
                                </v-col>
                                <v-col cols="6">
                                    <SmartSelect
                                        v-model="form.period_year"
                                        :items="years"
                                        label="Year"
                                        :error-messages="form.errors.period_year"
                                    />
                                </v-col>
                            </v-row>

                            <SmartSelect
                                v-model="form.bank_format"
                                :items="availableFormats"
                                label="Statement format"
                                :error-messages="form.errors.bank_format"
                                prepend-inner-icon="mdi-file-document-outline"
                                :disabled="!form.bank_account_id || availableFormats.length === 0"
                                :hint="!form.bank_account_id ? 'Pick a bank account first' : null"
                                persistent-hint
                                class="mb-2"
                            />

                            <v-file-input
                                v-model="fileInput"
                                :label="filePickerLabel"
                                :accept="acceptedFileTypes"
                                prepend-icon=""
                                prepend-inner-icon="mdi-paperclip"
                                :error-messages="form.errors.file"
                                show-size
                                class="mb-4"
                                @update:modelValue="onFileChange"
                            />

                            <v-btn
                                type="submit"
                                color="primary"
                                size="large"
                                block
                                :loading="form.processing"
                                :disabled="form.processing"
                                prepend-icon="mdi-upload"
                            >
                                Import transactions
                            </v-btn>
                        </v-form>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" md="5">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-history" class="mr-2" />
                        Recent imports
                    </v-card-title>

                    <v-list v-if="recentImports.length > 0">
                        <v-list-item
                            v-for="batch in recentImports"
                            :key="batch.id"
                            :subtitle="`${batch.imported_count} imported, ${batch.duplicate_count} duplicates`"
                        >
                            <template #title>
                                <span class="font-weight-medium">
                                    {{ batch.bank_account?.name }} — {{ formatPeriod(batch.period_year, batch.period_month) }}
                                </span>
                            </template>
                            <template #prepend>
                                <v-icon
                                    :icon="batch.status === 'completed' ? 'mdi-check-circle' : 'mdi-alert-circle'"
                                    :color="batch.status === 'completed' ? 'success' : 'error'"
                                />
                            </template>
                            <template #append>
                                <span class="text-caption text-medium-emphasis">
                                    {{ formatDate(batch.created_at) }}
                                </span>
                            </template>
                        </v-list-item>
                    </v-list>

                    <v-card-text v-else>
                        <v-alert type="info" variant="tonal">
                            No imports yet. Upload your first statement.
                        </v-alert>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import SmartSelect from '../../Components/SmartSelect.vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps({
    title: String,
    bankAccounts: Array,
    bankFormats: Array,
    recentImports: Array,
})

const now = new Date()
const fileInput = ref(null)
const formRef = ref(null)

const form = useForm({
    file: null,
    bank_account_id: props.bankAccounts.length === 1 ? props.bankAccounts[0].id : null,
    period_year: now.getFullYear(),
    period_month: now.getMonth() + 1,
    bank_format: null,
})

const months = Array.from({ length: 12 }, (_, i) => ({
    value: i + 1,
    title: new Date(2000, i).toLocaleString('en', { month: 'long' }),
}))

const years = Array.from({ length: 5 }, (_, i) => now.getFullYear() - i)

// Filter parsers by the selected account's type so users can't pick a credit
// card parser for a chequing account.
const selectedAccount = computed(() =>
    props.bankAccounts.find((a) => a.id === form.bank_account_id),
)

const availableFormats = computed(() => {
    if (!selectedAccount.value) return []
    return props.bankFormats
        .filter((f) => f.accountTypes.includes(selectedAccount.value.type))
        .map((f) => ({ value: f.value, title: f.title }))
})

const acceptedFileTypes = computed(() => {
    const fmt = props.bankFormats.find((f) => f.value === form.bank_format)
    if (!fmt) return '.pdf,.csv'
    return fmt.extensions.map((e) => '.' + e).join(',')
})

const filePickerLabel = computed(() => {
    if (!form.bank_format) return 'Statement file'
    const fmt = props.bankFormats.find((f) => f.value === form.bank_format)
    return fmt ? `${fmt.extensions.map((e) => e.toUpperCase()).join('/')} file` : 'Statement file'
})

// Auto-clear format when the account changes and current format isn't compatible
watch(() => form.bank_account_id, () => {
    form.clearErrors('bank_account_id')
    if (form.bank_format && !availableFormats.value.find((f) => f.value === form.bank_format)) {
        form.bank_format = null
    }
    // Default to the first compatible format (bank-specific parsers are
    // registered before the generic AI fallback, so it wins).
    if (!form.bank_format && availableFormats.value.length > 0) {
        form.bank_format = availableFormats.value[0].value
    }
})

watch(() => form.bank_format, () => form.clearErrors('bank_format'))

function onFileChange(files) {
    form.file = files?.[0] || files || null
    form.clearErrors('file')
}

function submitImport() {
    if (!form.bank_account_id) form.setError('bank_account_id', 'Pick a bank account.')
    if (!form.bank_format) form.setError('bank_format', 'Pick a statement format.')
    if (!form.file) form.setError('file', 'Choose a statement file.')
    if (form.hasErrors) return

    form.post('/transactions/import', {
        forceFormData: true,
        onSuccess: () => {
            form.reset('file')
            fileInput.value = null
        },
    })
}

function accountIcon(type) {
    return {
        chequing: 'mdi-bank',
        savings: 'mdi-piggy-bank',
        credit_card: 'mdi-credit-card',
        line_of_credit: 'mdi-cash-clock',
        other: 'mdi-wallet',
    }[type] || 'mdi-wallet'
}

function formatPeriod(year, month) {
    return new Date(year, month - 1).toLocaleString('en', { month: 'long', year: 'numeric' })
}

function formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('en-CA')
}
</script>
