<template>
    <div>
        <v-row class="mb-2">
            <v-col cols="12" md="4">
                <v-card variant="tonal" :color="currentSavings >= 0 ? 'success' : 'error'">
                    <v-card-text>
                        <div class="text-caption">Current savings</div>
                        <div class="text-h4 font-weight-bold">{{ fmt(currentSavings) }}</div>
                        <div class="text-caption text-medium-emphasis">
                            Asset accounts minus debts
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" md="4">
                <v-card variant="tonal" color="primary">
                    <v-card-text>
                        <div class="text-caption">Next milestone</div>
                        <template v-if="nextMilestone">
                            <div class="text-h6 font-weight-bold">{{ nextMilestone.name }}</div>
                            <div class="text-body-2 text-medium-emphasis">
                                {{ fmt(nextMilestone.remaining) }} to go
                            </div>
                        </template>
                        <template v-else>
                            <div class="text-h6 font-weight-bold">All set</div>
                            <div class="text-body-2 text-medium-emphasis">
                                You've cleared every milestone — add a new one?
                            </div>
                        </template>
                    </v-card-text>
                </v-card>
            </v-col>
            <v-col cols="12" md="4">
                <v-card variant="tonal" :color="eta ? 'info' : 'grey'">
                    <v-card-text>
                        <div class="text-caption">Projected ETA</div>
                        <template v-if="eta">
                            <div
                                class="text-h6 font-weight-bold"
                                title="Estimate — straight-line from your last-90-day savings rate; actual timing will vary."
                            >~{{ eta.date }}</div>
                            <div class="text-body-2 text-medium-emphasis">
                                est. {{ eta.months }} months at {{ fmt(eta.monthly_rate) }}/mo
                            </div>
                        </template>
                        <template v-else>
                            <div class="text-h6 font-weight-bold">—</div>
                            <div class="text-body-2 text-medium-emphasis">
                                Need 90 days of activity + a positive savings rate to project
                            </div>
                        </template>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-card>
            <v-card-title class="d-flex align-center">
                <v-icon icon="mdi-trophy" class="mr-2" />
                Milestones
                <v-spacer />
                <v-btn color="primary" prepend-icon="mdi-plus" @click="openDialog()">
                    Add milestone
                </v-btn>
            </v-card-title>

            <v-list>
                <v-list-item
                    v-for="m in milestones"
                    :key="m.id"
                    class="py-3"
                >
                    <template #prepend>
                        <v-avatar :color="m.is_reached ? 'success' : 'grey'" size="40">
                            <v-icon :icon="m.is_reached ? 'mdi-check' : 'mdi-flag-outline'" color="white" />
                        </v-avatar>
                    </template>

                    <div class="flex-grow-1 mx-3">
                        <div class="d-flex align-center mb-1">
                            <div class="font-weight-medium">{{ m.name }}</div>
                            <v-chip
                                v-if="m.is_reached"
                                size="x-small"
                                color="success"
                                variant="tonal"
                                class="ml-2"
                            >Reached {{ formatDate(m.reached_at) }}</v-chip>
                            <v-spacer />
                            <span class="font-weight-medium">{{ fmt(m.target_amount) }}</span>
                        </div>

                        <v-progress-linear
                            :model-value="m.progress"
                            :color="m.is_reached ? 'success' : 'primary'"
                            height="8"
                            rounded
                        />

                        <div class="d-flex mt-1">
                            <span class="text-caption text-medium-emphasis">
                                {{ m.progress }}%
                            </span>
                            <v-spacer />
                            <span v-if="!m.is_reached" class="text-caption text-medium-emphasis">
                                {{ fmt(m.remaining) }} to go
                            </span>
                        </div>
                    </div>

                    <template #append>
                        <v-btn icon="mdi-pencil" variant="text" size="small" @click="openDialog(m)" />
                        <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="confirmDelete(m)" />
                    </template>
                </v-list-item>
            </v-list>
        </v-card>

        <v-dialog v-model="dialog.open" max-width="500">
            <v-card>
                <v-card-title>{{ dialog.id ? 'Edit milestone' : 'Add milestone' }}</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model="dialog.name"
                        label="Name"
                        placeholder="$200K saved, House down payment, ..."
                        :error-messages="dialog.errors.name"
                        autofocus
                    />
                    <v-text-field
                        v-model.number="dialog.target_amount"
                        label="Target amount"
                        type="number"
                        step="100"
                        prefix="$"
                        :error-messages="dialog.errors.target_amount"
                    />
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
                <v-card-title>Delete milestone?</v-card-title>
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
import { reactive } from 'vue'
import { router } from '@inertiajs/vue3'

defineProps({
    title: String,
    currentSavings: Number,
    milestones: Array,
    nextMilestone: Object,
    eta: Object,
})

const dialog = reactive({
    open: false,
    id: null,
    name: '',
    target_amount: 0,
    processing: false,
    errors: {},
})

function openDialog(m = null) {
    dialog.open = true
    dialog.errors = {}
    dialog.processing = false
    if (m) {
        dialog.id = m.id
        dialog.name = m.name
        dialog.target_amount = m.target_amount
    } else {
        dialog.id = null
        dialog.name = ''
        dialog.target_amount = 0
    }
}

function save() {
    dialog.processing = true
    const payload = { name: dialog.name, target_amount: dialog.target_amount }
    const opts = {
        preserveScroll: true,
        onSuccess: () => { dialog.open = false },
        onError: (errs) => { dialog.errors = errs },
        onFinish: () => { dialog.processing = false },
    }
    if (dialog.id) router.patch(`/milestones/${dialog.id}`, payload, opts)
    else router.post('/milestones', payload, opts)
}

const deleteDialog = reactive({ open: false, message: '', confirm: () => {} })

function confirmDelete(m) {
    deleteDialog.open = true
    deleteDialog.message = `Delete the "${m.name}" milestone?`
    deleteDialog.confirm = () => {
        router.delete(`/milestones/${m.id}`, {
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
</script>
