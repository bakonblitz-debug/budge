<template>
    <div>
        <v-row class="mb-2">
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="primary">
                    <v-card-text>
                        <div class="text-caption">Monthly obligation</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(totals.monthly) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            {{ totals.expense_count }} active fixed expense{{ totals.expense_count === 1 ? '' : 's' }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="info">
                    <v-card-text>
                        <div class="text-caption">Accrued to date</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(totals.accrued) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            What should have been paid by {{ formatDate(asOf) }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" color="secondary">
                    <v-card-text>
                        <div class="text-caption">Actually spent</div>
                        <div class="text-h5 font-weight-bold">{{ fmt(totals.spent) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            In matching categories since each start date
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" sm="6" md="3">
                <v-card variant="tonal" :color="varianceColor(totals.variance)">
                    <v-card-text>
                        <div class="text-caption">Variance</div>
                        <div class="text-h5 font-weight-bold">{{ fmtSigned(totals.variance) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            <template v-if="totals.variance > 0">Spent more than accrued — ahead of schedule</template>
                            <template v-else-if="totals.variance < 0">Spent less than accrued — possibly behind</template>
                            <template v-else>On the line</template>
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-calendar-clock" class="mr-2" />
                Fixed expense accrual
                <v-spacer />
                <span class="text-caption text-medium-emphasis">As of {{ formatDate(asOf) }}</span>
            </v-card-title>

            <v-card-text v-if="rows.length === 0" class="text-center py-12">
                <v-icon icon="mdi-calendar-blank-outline" size="56" class="mb-2 text-medium-emphasis" />
                <div class="text-medium-emphasis mb-2">No active fixed expenses.</div>
                <div class="text-body-2 text-medium-emphasis">
                    Add some on the Fixed Expenses page to see daily accrual tracking here.
                </div>
            </v-card-text>

            <v-list v-else>
                <v-list-item v-for="row in rows" :key="row.id" class="py-3">
                    <template #prepend>
                        <v-avatar :color="row.category?.color || 'grey'" size="36">
                            <v-icon :icon="row.category?.icon || 'mdi-calendar-clock'" color="white" size="18" />
                        </v-avatar>
                    </template>

                    <div class="flex-grow-1 mx-3">
                        <div class="d-flex align-center mb-1">
                            <div class="font-weight-medium">
                                {{ row.name }}
                                <v-chip
                                    :color="statusColor(row.status)"
                                    size="x-small"
                                    variant="tonal"
                                    class="ml-2"
                                    :title="row.status === 'upcoming' ? 'A lump-sum bill not yet due this month — not a shortfall.' : undefined"
                                >{{ statusLabel(row.status) }}</v-chip>
                                <v-chip
                                    v-if="row.unmatched"
                                    color="warning"
                                    size="x-small"
                                    variant="tonal"
                                    class="ml-2"
                                    prepend-icon="mdi-link-off"
                                    title="Nothing accrued has a matching transaction — check this expense's match pattern. Not necessarily a real shortfall."
                                >no matches</v-chip>
                            </div>
                            <v-spacer />
                            <span class="text-caption text-medium-emphasis">
                                {{ row.days_elapsed }} day{{ row.days_elapsed === 1 ? '' : 's' }} elapsed
                            </span>
                        </div>

                        <div class="d-flex">
                            <span class="text-caption text-medium-emphasis">
                                {{ fmt(row.amount) }} {{ row.frequency.replace('_', '-') }}
                                ({{ fmt(row.monthly) }}/mo · {{ fmt(row.daily_rate) }}/day)
                            </span>
                        </div>

                        <v-row dense class="mt-2">
                            <v-col cols="4">
                                <div class="text-caption text-medium-emphasis">Accrued</div>
                                <div class="font-weight-medium">{{ fmt(row.accrued) }}</div>
                            </v-col>
                            <v-col cols="4">
                                <div class="text-caption text-medium-emphasis">Spent</div>
                                <div class="font-weight-medium">{{ fmt(row.spent) }}</div>
                            </v-col>
                            <v-col cols="4">
                                <div class="text-caption text-medium-emphasis">Variance</div>
                                <div class="font-weight-medium" :class="varianceClass(row.variance)">
                                    {{ fmtSigned(row.variance) }}
                                </div>
                            </v-col>
                        </v-row>

                        <v-progress-linear
                            :model-value="accrualProgress(row)"
                            :color="statusColor(row.status)"
                            height="6"
                            rounded
                            class="mt-2"
                        />
                    </div>
                </v-list-item>
            </v-list>
        </v-card>
    </div>
</template>

<script setup>
defineProps({
    title: String,
    asOf: String,
    rows: Array,
    totals: Object,
})

function fmt(value) {
    return Number(value || 0).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
}

function fmtSigned(value) {
    const v = Number(value || 0)
    const s = Math.abs(v).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
    if (v > 0) return '+' + s
    if (v < 0) return '-' + s
    return s
}

function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' })
}

function statusColor(status) {
    return {
        on_track: 'success',
        ahead: 'info',
        behind: 'warning',
        upcoming: 'blue-grey',
        pending: 'grey',
    }[status] || 'primary'
}

function statusLabel(status) {
    return {
        on_track: 'On track',
        ahead: 'Ahead',
        behind: 'Behind',
        upcoming: 'Upcoming',
        pending: 'Not started',
    }[status] || status
}

function varianceColor(v) {
    if (v > 0) return 'info'
    if (v < 0) return 'warning'
    return 'success'
}

function varianceClass(v) {
    if (v > 0) return 'text-info'
    if (v < 0) return 'text-warning'
    return ''
}

function accrualProgress(row) {
    // Show how much of "monthly" has accrued, capped at 100
    if (!row.monthly) return 0
    return Math.min(100, (row.accrued / row.monthly) * 100)
}
</script>
