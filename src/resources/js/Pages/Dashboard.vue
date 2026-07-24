<template>
    <div>
        <div class="d-flex align-center mb-2">
            <h2 class="text-h6 font-weight-bold">{{ periodLabel }}</h2>
            <v-chip class="ml-3" size="small" variant="tonal">Latest imported month</v-chip>
            <v-chip
                v-if="periodPartial"
                class="ml-2"
                size="small"
                color="warning"
                variant="tonal"
                title="This month is still importing — totals are not a full month and may look low."
            >partial</v-chip>
        </div>

        <v-row>
            <v-col cols="12" sm="6" lg="3">
                <MetricCard :title="incomeFrequency ? 'Income · ' + frequencyLabel(incomeFrequency) : 'Income'" :value="metrics.income" icon="mdi-cash-plus" color="success" />
            </v-col>
            <v-col cols="12" sm="6" lg="3">
                <MetricCard title="Expenses" :value="metrics.expenses" icon="mdi-cash-minus" color="error" />
            </v-col>
            <v-col cols="12" sm="6" lg="3">
                <MetricCard
                    title="Net"
                    :value="metrics.net"
                    icon="mdi-piggy-bank"
                    :color="metrics.net >= 0 ? 'primary' : 'warning'"
                    signed
                />
            </v-col>
            <v-col cols="12" sm="6" lg="3">
                <MetricCard
                    title="Net worth"
                    :value="metrics.total_balance"
                    icon="mdi-scale-balance"
                    color="secondary"
                    signed
                />
            </v-col>
        </v-row>

        <v-row v-if="budgetRule" class="mt-2">
            <v-col cols="12">
                <NeedsWantsMeter :meter="budgetRule" compact />
            </v-col>
        </v-row>

        <v-row class="mt-2">
            <v-col cols="12" md="8">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-swap-horizontal" class="mr-2" />
                        Recent transactions
                        <v-spacer />
                        <v-btn
                            variant="text"
                            size="small"
                            append-icon="mdi-arrow-right"
                            @click="$inertia.visit('/transactions')"
                        >
                            View all
                        </v-btn>
                    </v-card-title>

                    <v-list v-if="recentTransactions.length > 0" lines="two">
                        <v-list-item
                            v-for="t in recentTransactions"
                            :key="t.id"
                        >
                            <template #prepend>
                                <v-avatar :color="t.amount < 0 ? 'error' : 'success'" size="36" variant="tonal">
                                    <v-icon :icon="t.amount < 0 ? 'mdi-arrow-down' : 'mdi-arrow-up'" size="20" />
                                </v-avatar>
                            </template>

                            <v-list-item-title class="font-weight-medium">
                                {{ t.description }}
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                {{ formatDate(t.transaction_date) }} ·
                                {{ t.bank_account?.name }}
                                <span v-if="t.category"> · {{ t.category.name }}</span>
                            </v-list-item-subtitle>

                            <template #append>
                                <span
                                    :class="t.amount < 0 ? 'text-error' : 'text-success'"
                                    class="font-weight-bold"
                                >
                                    {{ fmt(t.amount, true) }}
                                </span>
                            </template>
                        </v-list-item>
                    </v-list>

                    <v-card-text v-else>
                        <v-alert type="info" variant="tonal" class="mb-3">
                            No transactions yet. Import your first statement to get started.
                        </v-alert>
                        <v-btn color="primary" variant="tonal" prepend-icon="mdi-file-upload" @click="$inertia.visit('/transactions/import')">
                            Import statement
                        </v-btn>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" md="4">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-bank" class="mr-2" />
                        Accounts
                    </v-card-title>
                    <v-list v-if="accountSummaries.length > 0" density="compact">
                        <v-list-item
                            v-for="a in accountSummaries"
                            :key="a.id"
                        >
                            <template #prepend>
                                <v-icon :icon="accountIcon(a.type)" size="20" />
                            </template>
                            <v-list-item-title class="font-weight-medium">{{ a.name }}</v-list-item-title>
                            <v-list-item-subtitle class="text-caption">{{ accountLabel(a.type) }}</v-list-item-subtitle>
                            <template #append>
                                <span
                                    :class="balanceClass(a)"
                                    class="font-weight-medium"
                                >
                                    {{ fmt(a.balance) }}
                                </span>
                            </template>
                        </v-list-item>
                    </v-list>
                    <v-card-text v-else>
                        <v-alert type="info" variant="tonal">No accounts configured.</v-alert>
                    </v-card-text>
                </v-card>

                <v-card
                    v-if="forecast"
                    class="mt-3"
                    variant="tonal"
                    :color="forecast.shortfall_date ? 'error' : 'success'"
                    @click="router.visit('/forecast')"
                    style="cursor: pointer"
                >
                    <v-card-text>
                        <div class="d-flex align-center mb-1">
                            <v-icon icon="mdi-chart-bell-curve-cumulative" class="mr-2" />
                            <span class="text-subtitle-2">30-day outlook</span>
                        </div>
                        <div v-if="forecast.shortfall_date" class="text-body-2">
                            Projected to dip below $0 on <strong>{{ formatDate(forecast.shortfall_date) }}</strong>.
                        </div>
                        <div v-else class="text-body-2">
                            Lowest balance <strong>{{ fmt(forecast.lowest_balance) }}</strong> on {{ formatDate(forecast.lowest_date) }}.
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-row class="mt-2">
            <v-col cols="12">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-chart-donut" class="mr-2" />
                        Spending by category
                        <v-spacer />
                        <span class="text-caption text-medium-emphasis">{{ periodLabel }} · transfers excluded</span>
                    </v-card-title>

                    <v-card-text v-if="spendingByCategory.length === 0" class="text-center py-8">
                        <v-icon icon="mdi-chart-pie-outline" size="48" class="mb-2 text-medium-emphasis" />
                        <div class="text-medium-emphasis">No spending this period yet.</div>
                    </v-card-text>

                    <v-list v-else>
                        <v-list-item
                            v-for="row in spendingByCategory"
                            :key="row.id"
                            class="py-3"
                        >
                            <template #prepend>
                                <v-avatar :color="row.color || 'grey'" size="36">
                                    <v-icon :icon="row.icon || 'mdi-tag'" color="white" size="18" />
                                </v-avatar>
                            </template>

                            <div class="flex-grow-1 mx-3">
                                <div class="d-flex align-center mb-1">
                                    <div class="font-weight-medium">{{ row.name }}</div>
                                    <v-spacer />
                                    <span class="font-weight-medium">{{ fmt(row.spent) }}</span>
                                    <span class="text-caption text-medium-emphasis ml-2" style="min-width: 50px; text-align: right;">{{ row.share }}%</span>
                                </div>

                                <v-progress-linear
                                    :model-value="row.share"
                                    :color="row.color || 'primary'"
                                    height="6"
                                    rounded
                                />

                                <div v-if="row.children.length" class="text-caption text-medium-emphasis mt-1">
                                    <span
                                        v-for="(child, idx) in row.children"
                                        :key="child.name"
                                    >{{ idx > 0 ? ' · ' : '' }}{{ child.name }} {{ fmt(child.spent) }}</span>
                                </div>
                            </div>
                        </v-list-item>
                    </v-list>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { router } from '@inertiajs/vue3'
import MetricCard from '../Components/MetricCard.vue'
import NeedsWantsMeter from '../Components/NeedsWantsMeter.vue'

defineProps({
    title: String,
    periodLabel: String,
    periodPartial: Boolean,
    metrics: Object,
    accountSummaries: Array,
    recentTransactions: Array,
    spendingByCategory: Array,
    forecast: Object,
    budgetRule: Object,
    incomeFrequency: String,
})

const FREQUENCY_LABELS = {
    weekly: 'Weekly',
    bi_weekly: 'Bi-weekly',
    semi_monthly: 'Semi-monthly',
    monthly: 'Monthly',
}

function frequencyLabel(f) {
    return f ? FREQUENCY_LABELS[f] || f : ''
}

function fmt(value, signed = false) {
    const v = Number(value || 0)
    if (signed) {
        const formatted = Math.abs(v).toLocaleString('en-CA', {
            style: 'currency',
            currency: 'CAD',
        })
        if (v < 0) return '-' + formatted
        if (v > 0) return '+' + formatted
        return formatted
    }
    // Unsigned: let the locale render the natural sign so an overdrawn balance
    // shows '-$500.00', not '$500.00'.
    return v.toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}

function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString('en-CA', { month: 'short', day: 'numeric' })
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

function accountLabel(type) {
    return {
        chequing: 'Chequing',
        savings: 'Savings',
        credit_card: 'Credit card',
        line_of_credit: 'Line of credit',
        other: 'Other',
    }[type] || type
}

function balanceClass(account) {
    if (['credit_card', 'line_of_credit'].includes(account.type)) {
        return account.balance > 0 ? 'text-error' : 'text-medium-emphasis'
    }
    return account.balance < 0 ? 'text-error' : 'text-success'
}
</script>
