<template>
    <div>
        <!-- Post-import context banner -->
        <v-alert
            v-if="batchContext"
            :type="counts.unmatched > 0 ? 'info' : 'success'"
            variant="tonal"
            class="mb-3"
            :icon="counts.unmatched > 0 ? 'mdi-school' : 'mdi-check-circle'"
        >
            <div class="d-flex align-center">
                <div class="flex-grow-1">
                    <div class="font-weight-medium">
                        <template v-if="counts.unmatched > 0">
                            {{ counts.unmatched }} new merchant{{ counts.unmatched === 1 ? '' : 's' }} from your
                            {{ batchContext.account_name }} — {{ batchContext.period_label }} import
                        </template>
                        <template v-else>
                            All set — every line from your {{ batchContext.account_name }} — {{ batchContext.period_label }} import is categorized.
                        </template>
                    </div>
                    <div class="text-body-2 mt-1">
                        Categorize each one and we'll save a rule so future imports auto-match.
                    </div>
                </div>
                <v-btn
                    variant="text"
                    size="small"
                    append-icon="mdi-arrow-right"
                    @click="$inertia.visit('/transactions?account=' + (batchContext.id ? '' : ''))"
                >
                    Done
                </v-btn>
            </div>
        </v-alert>

        <v-card>
            <v-tabs v-model="activeView" color="primary" class="px-2">
                <v-tab value="unmatched">
                    {{ batchContext ? 'New to this import' : 'Unmatched' }}
                    <v-chip class="ml-2" size="x-small" :color="counts.unmatched > 0 ? 'warning' : 'success'" variant="tonal">
                        {{ counts.unmatched }}
                    </v-chip>
                </v-tab>
                <v-tab value="matched">
                    {{ batchContext ? 'Already known' : 'Matched' }}
                    <v-chip class="ml-2" size="x-small" color="primary" variant="tonal">
                        {{ counts.matched }}
                    </v-chip>
                </v-tab>
            </v-tabs>

            <v-divider />

            <v-window v-model="activeView">
                <!-- Unmatched view -->
                <v-window-item value="unmatched">
                    <div v-if="unmatchedGroups.length" class="d-flex align-center px-4 pt-3 pb-1">
                        <v-btn
                            variant="tonal"
                            size="small"
                            color="primary"
                            prepend-icon="mdi-robot-outline"
                            :loading="aiState.loading"
                            @click="suggestWithAi"
                        >
                            Suggest with AI
                        </v-btn>
                        <span v-if="aiSuggestedCount" class="text-caption text-medium-emphasis ml-3">
                            {{ aiSuggestedCount }} suggestion{{ aiSuggestedCount === 1 ? '' : 's' }} — review &amp; accept
                        </span>
                    </div>
                    <v-alert
                        v-if="aiState.error"
                        type="warning"
                        variant="tonal"
                        density="compact"
                        class="mx-4 mb-2"
                    >
                        {{ aiState.error }}
                    </v-alert>

                    <v-card-text v-if="unmatchedGroups.length === 0" class="text-center py-12">
                        <v-icon icon="mdi-check-circle" size="56" color="success" class="mb-2" />
                        <div class="text-medium-emphasis">Everything is categorized. Nice.</div>
                    </v-card-text>

                    <v-list v-else lines="two">
                        <v-list-item
                            v-for="group in unmatchedGroups"
                            :key="group.description"
                            @click="openDialog(group, 'assign')"
                        >
                            <template #prepend>
                                <v-avatar color="warning" size="36" variant="tonal">
                                    <v-icon icon="mdi-help-circle-outline" size="20" />
                                </v-avatar>
                            </template>

                            <v-list-item-title class="font-weight-medium">
                                {{ group.description }}
                                <v-chip
                                    v-if="group.occurrences > 1"
                                    size="x-small"
                                    color="primary"
                                    variant="tonal"
                                    class="ml-2"
                                >
                                    × {{ group.occurrences }}
                                </v-chip>
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption">
                                {{ fmt(group.total_amount, true) }} total
                                · {{ formatDate(group.first_date) }}
                                <span v-if="group.first_date !== group.last_date"> → {{ formatDate(group.last_date) }}</span>
                            </v-list-item-subtitle>

                            <template #append>
                                <div class="d-flex align-center ga-2">
                                    <template v-if="suggestionFor(group.description)">
                                        <div class="text-right">
                                            <v-chip size="x-small" color="primary" variant="tonal">
                                                <v-icon start icon="mdi-robot-outline" size="13" />
                                                {{ suggestionFor(group.description).category_name }}
                                            </v-chip>
                                            <div class="text-caption text-medium-emphasis">
                                                {{ Math.round(suggestionFor(group.description).confidence * 100) }}% confident
                                            </div>
                                        </div>
                                        <v-btn variant="flat" size="small" color="primary" @click.stop="acceptSuggestion(group)">
                                            Accept
                                        </v-btn>
                                    </template>
                                    <v-btn v-else variant="tonal" size="small" color="primary" prepend-icon="mdi-tag">
                                        Categorize
                                    </v-btn>
                                </div>
                            </template>
                        </v-list-item>
                    </v-list>
                </v-window-item>

                <!-- Matched view -->
                <v-window-item value="matched">
                    <v-card-text v-if="matchedGroups.length === 0" class="text-center py-12">
                        <v-icon icon="mdi-tag-outline" size="56" class="mb-2 text-medium-emphasis" />
                        <div class="text-medium-emphasis">Nothing categorized yet.</div>
                    </v-card-text>

                    <v-list v-else lines="two">
                        <v-list-item
                            v-for="group in matchedGroups"
                            :key="group.description + '-' + group.category.id"
                            @click="openDialog(group, 'reassign')"
                        >
                            <template #prepend>
                                <v-avatar :color="group.category.color || 'grey'" size="36">
                                    <v-icon :icon="group.category.icon || 'mdi-tag'" color="white" size="18" />
                                </v-avatar>
                            </template>

                            <v-list-item-title class="font-weight-medium">
                                {{ group.description }}
                                <v-chip
                                    v-if="group.occurrences > 1"
                                    size="x-small"
                                    color="primary"
                                    variant="tonal"
                                    class="ml-2"
                                >
                                    × {{ group.occurrences }}
                                </v-chip>
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption">
                                <span class="font-weight-medium">
                                    {{ group.category.parent_name ? `${group.category.parent_name} · ` : '' }}{{ group.category.name }}
                                </span>
                                <span v-if="group.matched_rule" class="text-medium-emphasis">
                                    · via rule:
                                    <code>{{ matchTypeShort(group.matched_rule.match_type) }} "{{ group.matched_rule.match_value }}"</code>
                                </span>
                                <span v-else class="text-medium-emphasis"> · manually set</span>
                                · {{ fmt(group.total_amount, true) }}
                            </v-list-item-subtitle>

                            <template #append>
                                <v-btn variant="text" size="small" icon="mdi-undo" @click.stop="confirmUnset(group)" :title="`Mark uncategorized`" />
                            </template>
                        </v-list-item>
                    </v-list>
                </v-window-item>
            </v-window>
        </v-card>

        <!-- Assignment dialog -->
        <v-dialog v-model="dialog.open" max-width="640">
            <v-card>
                <v-card-title>{{ dialog.mode === 'reassign' ? 'Reassign category' : 'Categorize' }}</v-card-title>
                <v-card-text>
                    <v-alert
                        :type="dialog.occurrences > 1 ? 'info' : 'success'"
                        variant="tonal"
                        density="compact"
                        class="mb-4"
                    >
                        <div class="font-weight-medium">{{ dialog.description }}</div>
                        <div class="text-caption">
                            {{ dialog.occurrences }} transaction{{ dialog.occurrences === 1 ? '' : 's' }}
                            · {{ fmt(dialog.totalAmount, true) }} total
                        </div>
                    </v-alert>

                    <SmartSelect
                        v-model="dialog.category_id"
                        :items="categoryOptions"
                        label="Category"
                        :error-messages="dialog.errors.category_id"
                    />

                    <v-divider class="my-4" />

                    <v-switch
                        v-model="dialog.save_rule"
                        label="Save a rule so future imports auto-categorize this"
                        color="primary"
                        density="compact"
                        hide-details
                    />

                    <div v-if="dialog.save_rule" class="mt-3">
                        <v-row dense>
                            <v-col cols="12" sm="4">
                                <SmartSelect
                                    v-model="dialog.match_type"
                                    :items="matchTypeOptions"
                                    label="Match"
                                    density="compact"
                                />
                            </v-col>
                            <v-col cols="12" sm="8">
                                <v-text-field
                                    v-model="dialog.match_value"
                                    label="Pattern"
                                    density="compact"
                                    :error-messages="dialog.errors.match_value"
                                />
                            </v-col>
                        </v-row>
                        <v-checkbox
                            v-model="dialog.apply_to_existing"
                            label="Also apply this rule to other uncategorized transactions"
                            density="compact"
                            hide-details
                        />
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="dialog.open = false">Cancel</v-btn>
                    <v-btn color="primary" :loading="dialog.processing" @click="apply">Save</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Unset confirm -->
        <v-dialog v-model="unsetDialog.open" max-width="400">
            <v-card>
                <v-card-title>Mark uncategorized?</v-card-title>
                <v-card-text>{{ unsetDialog.message }}</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="unsetDialog.open = false">Cancel</v-btn>
                    <v-btn color="error" @click="unsetDialog.confirm">Confirm</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { ref, reactive, computed, watch } from 'vue'
import SmartSelect from '../../Components/SmartSelect.vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    title: String,
    view: String,
    batchContext: Object,
    unmatchedGroups: Array,
    matchedGroups: Array,
    categories: Array,
    counts: Object,
    suggestions: Object,
})

const activeView = ref(props.view || 'unmatched')

// Preserve batch scope across tab toggles so the post-import view doesn't
// silently widen to all transactions when the user flips to "Already known".
watch(activeView, (v) => {
    const payload = { view: v }
    if (props.batchContext?.id) payload.batch = props.batchContext.id
    router.get('/categorize', payload, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    })
})

const categoryOptions = computed(() =>
    props.categories.map((c) => ({
        value: c.id,
        title: c.parent_name ? `${c.parent_name} · ${c.name}` : c.name,
    })),
)

const matchTypeOptions = [
    { value: 'contains', title: 'contains' },
    { value: 'starts_with', title: 'starts with' },
    { value: 'ends_with', title: 'ends with' },
    { value: 'exact', title: 'exact match' },
]

function matchTypeShort(t) {
    return { contains: '⊃', starts_with: '▶', ends_with: '◀', exact: '=', regex: '/…/' }[t] || t
}

const dialog = reactive({
    open: false,
    mode: 'assign',
    description: '',
    occurrences: 0,
    totalAmount: 0,
    category_id: null,
    save_rule: true,
    match_type: 'contains',
    match_value: '',
    apply_to_existing: true,
    processing: false,
    errors: {},
})

function openDialog(group, mode) {
    dialog.open = true
    dialog.mode = mode
    dialog.description = group.description
    dialog.occurrences = group.occurrences
    dialog.totalAmount = group.total_amount
    dialog.category_id = group.category?.id ?? null
    dialog.save_rule = mode === 'assign'
    dialog.match_type = 'contains'
    dialog.match_value = group.suggested_pattern || ''
    dialog.apply_to_existing = true
    dialog.processing = false
    dialog.errors = {}
}

function apply() {
    dialog.processing = true
    router.post('/categorize/apply', {
        description: dialog.description,
        category_id: dialog.category_id,
        save_rule: dialog.save_rule,
        match_type: dialog.match_type,
        match_value: dialog.match_value,
        apply_to_existing: dialog.apply_to_existing,
    }, {
        preserveScroll: true,
        onSuccess: () => { dialog.open = false },
        onError: (errs) => { dialog.errors = errs },
        onFinish: () => { dialog.processing = false },
    })
}

// ── AI suggestions ──
const aiState = reactive({ loading: false, error: null, map: {} })

watch(() => props.suggestions, (s) => {
    if (!s) return
    aiState.error = s.error || null
    const map = {}
    for (const item of s.items || []) map[item.description] = item
    aiState.map = map
}, { immediate: true })

function suggestWithAi() {
    aiState.loading = true
    aiState.error = null
    router.reload({
        only: ['suggestions'],
        preserveScroll: true,
        preserveState: true,
        onFinish: () => { aiState.loading = false },
    })
}

function suggestionFor(description) {
    return aiState.map[description] || null
}

const aiSuggestedCount = computed(() => Object.keys(aiState.map).length)

function acceptSuggestion(group) {
    const s = suggestionFor(group.description)
    if (!s) return
    openDialog(group, 'assign')
    dialog.category_id = s.category_id
    dialog.save_rule = !!s.match_value
    dialog.match_type = s.match_type || 'contains'
    dialog.match_value = s.match_value || group.suggested_pattern || ''
}

const unsetDialog = reactive({ open: false, message: '', confirm: () => {} })

function confirmUnset(group) {
    unsetDialog.open = true
    unsetDialog.message = `Mark ${group.occurrences} "${group.description}" transaction${group.occurrences === 1 ? '' : 's'} as uncategorized?`
    unsetDialog.confirm = () => {
        router.post('/categorize/unset', { description: group.description }, {
            preserveScroll: true,
            onFinish: () => { unsetDialog.open = false },
        })
    }
}

function fmt(value, signed = false) {
    const v = Number(value || 0)
    const s = Math.abs(v).toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
    if (signed && v < 0) return '-' + s
    if (signed && v > 0) return '+' + s
    return s
}

function formatDate(d) {
    if (!d) return ''
    return new Date(d).toLocaleDateString('en-CA', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>
