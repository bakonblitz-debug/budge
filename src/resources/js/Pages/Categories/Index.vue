<template>
    <div>
        <div class="d-flex align-center mb-3">
            <h2 class="text-h6 font-weight-bold">{{ categories.length }} categories</h2>
            <v-spacer />
            <v-btn color="primary" prepend-icon="mdi-plus" @click="openCategoryDialog()">
                Add category
            </v-btn>
        </div>

        <v-card v-if="categories.length === 0">
            <v-card-text class="text-center py-12">
                <v-icon icon="mdi-tag-outline" size="56" class="mb-2 text-medium-emphasis" />
                <div class="text-medium-emphasis">No categories yet.</div>
            </v-card-text>
        </v-card>

        <v-expansion-panels v-else multiple variant="accordion">
            <v-expansion-panel
                v-for="parent in categories"
                :key="parent.id"
                :value="parent.id"
            >
                <v-expansion-panel-title>
                    <div class="d-flex align-center" style="width: 100%">
                        <v-avatar :color="parent.color || 'grey'" size="32" class="mr-3">
                            <v-icon :icon="parent.icon || 'mdi-tag'" color="white" size="18" />
                        </v-avatar>
                        <div class="flex-grow-1">
                            <div class="font-weight-medium d-flex align-center">
                                {{ parent.name }}
                                <v-chip
                                    :color="kindChip(parent.kind).color"
                                    size="x-small"
                                    variant="flat"
                                    class="ml-2"
                                >
                                    {{ kindChip(parent.kind).label }}
                                </v-chip>
                            </div>
                            <div class="text-caption text-medium-emphasis">
                                {{ parent.children.length }} subcategor{{ parent.children.length === 1 ? 'y' : 'ies' }}
                                · {{ parent.transactions_count }} transactions
                                · {{ parent.rules_count }} rule{{ parent.rules_count === 1 ? '' : 's' }}
                            </div>
                        </div>
                    </div>
                </v-expansion-panel-title>

                <v-expansion-panel-text>
                    <div class="d-flex mb-3">
                        <v-btn variant="tonal" size="small" prepend-icon="mdi-pencil" @click="openCategoryDialog(parent)" class="mr-2">
                            Edit
                        </v-btn>
                        <v-btn variant="tonal" size="small" color="error" prepend-icon="mdi-delete" @click="confirmDelete(parent)">
                            Delete
                        </v-btn>
                    </div>

                    <!-- Subcategories -->
                    <div class="text-subtitle-2 mb-2">Subcategories</div>
                    <v-list v-if="parent.children.length > 0" density="compact" class="mb-3">
                        <v-list-item v-for="child in parent.children" :key="child.id">
                            <template #prepend>
                                <v-icon :icon="child.icon || 'mdi-tag'" :color="child.color || 'grey'" size="20" />
                            </template>
                            <v-list-item-title>
                                {{ child.name }}
                                <v-chip
                                    :color="kindChip(child.kind).color"
                                    size="x-small"
                                    variant="flat"
                                    class="ml-2"
                                >
                                    {{ kindChip(child.kind).label }}
                                </v-chip>
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption">
                                {{ child.transactions_count }} transactions · {{ child.rules_count }} rule{{ child.rules_count === 1 ? '' : 's' }}
                            </v-list-item-subtitle>
                            <template #append>
                                <v-btn icon="mdi-pencil" variant="text" size="small" @click="openCategoryDialog(child)" />
                                <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="confirmDelete(child)" />
                            </template>
                        </v-list-item>
                    </v-list>
                    <div v-else class="text-caption text-medium-emphasis mb-3">No subcategories.</div>

                    <v-btn variant="tonal" size="small" prepend-icon="mdi-plus" @click="openCategoryDialog(null, parent)" class="mb-4">
                        Add subcategory
                    </v-btn>

                    <v-divider class="my-3" />

                    <!-- Rules -->
                    <div class="d-flex align-center mb-2">
                        <span class="text-subtitle-2">Auto-categorization rules</span>
                        <v-spacer />
                        <v-btn variant="tonal" size="small" prepend-icon="mdi-plus" @click="openRuleDialog(parent)">
                            Add rule
                        </v-btn>
                    </div>

                    <v-list v-if="parent.rules.length > 0" density="compact">
                        <v-list-item v-for="rule in parent.rules" :key="rule.id">
                            <template #prepend>
                                <v-chip size="x-small" variant="outlined" class="mr-2">
                                    {{ matchTypeLabel(rule.match_type) }}
                                </v-chip>
                            </template>
                            <v-list-item-title class="font-family-monospace">
                                {{ rule.match_value }}
                            </v-list-item-title>
                            <v-list-item-subtitle class="text-caption">
                                priority {{ rule.priority }}
                            </v-list-item-subtitle>
                            <template #append>
                                <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="confirmDeleteRule(parent, rule)" />
                            </template>
                        </v-list-item>
                    </v-list>
                    <div v-else class="text-caption text-medium-emphasis">No rules yet. Add one to auto-categorize on import.</div>
                </v-expansion-panel-text>
            </v-expansion-panel>
        </v-expansion-panels>

        <!-- Category add/edit dialog -->
        <v-dialog v-model="categoryDialog.open" max-width="500">
            <v-card>
                <v-card-title>{{ categoryDialog.id ? 'Edit category' : 'Add category' }}</v-card-title>
                <v-card-text>
                    <v-text-field
                        v-model="categoryDialog.name"
                        label="Name"
                        :error-messages="categoryDialog.errors.name"
                        autofocus
                    />
                    <v-text-field
                        v-model="categoryDialog.icon"
                        label="Icon (mdi-...)"
                        placeholder="mdi-tag"
                        :error-messages="categoryDialog.errors.icon"
                        hint="Material Design Icon name"
                    />
                    <v-text-field
                        v-model="categoryDialog.color"
                        label="Color"
                        placeholder="#5E6BC4"
                        :error-messages="categoryDialog.errors.color"
                    >
                        <template #prepend-inner>
                            <v-avatar v-if="categoryDialog.color" :color="categoryDialog.color" size="22" />
                        </template>
                    </v-text-field>
                    <v-select
                        v-model="categoryDialog.kind"
                        :items="kindOptions"
                        label="Kind"
                        :error-messages="categoryDialog.errors.kind"
                        clearable
                        hint="Drives the needs/wants meter and spending insights. Leave blank if unsure."
                        persistent-hint
                    />
                    <div v-if="categoryDialog.parentName" class="text-caption text-medium-emphasis mt-3">
                        Will be added under <strong>{{ categoryDialog.parentName }}</strong>.
                    </div>
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="categoryDialog.open = false">Cancel</v-btn>
                    <v-btn color="primary" :loading="categoryDialog.processing" @click="saveCategory">Save</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Rule add dialog -->
        <v-dialog v-model="ruleDialog.open" max-width="500">
            <v-card>
                <v-card-title>Add rule for {{ ruleDialog.categoryName }}</v-card-title>
                <v-card-text>
                    <SmartSelect
                        v-model="ruleDialog.match_type"
                        :items="matchTypeOptions"
                        label="Match type"
                    />
                    <v-text-field
                        v-model="ruleDialog.match_value"
                        :label="ruleDialog.match_type === 'regex' ? 'Pattern (e.g. /AMZN.*CA/i)' : 'Text to match'"
                        :error-messages="ruleDialog.errors.match_value"
                        autofocus
                    />
                    <v-text-field
                        v-model.number="ruleDialog.priority"
                        type="number"
                        label="Priority"
                        hint="Higher = checked first. Default 100."
                        persistent-hint
                    />
                    <v-checkbox
                        v-model="ruleDialog.apply_to_existing"
                        label="Apply to existing uncategorized transactions"
                        density="compact"
                        hide-details
                        class="mt-3"
                    />
                </v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="ruleDialog.open = false">Cancel</v-btn>
                    <v-btn color="primary" :loading="ruleDialog.processing" @click="saveRule">Save</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Delete confirm -->
        <v-dialog v-model="deleteDialog.open" max-width="400">
            <v-card>
                <v-card-title>Delete?</v-card-title>
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
import SmartSelect from '../../Components/SmartSelect.vue'
import { router } from '@inertiajs/vue3'

defineProps({
    title: String,
    categories: Array,
})

const matchTypeOptions = [
    { value: 'contains', title: 'Contains' },
    { value: 'starts_with', title: 'Starts with' },
    { value: 'ends_with', title: 'Ends with' },
    { value: 'exact', title: 'Exact match' },
    { value: 'regex', title: 'Regex' },
]

function matchTypeLabel(t) {
    return matchTypeOptions.find((o) => o.value === t)?.title || t
}

const kindOptions = [
    { value: 'need', title: 'Need' },
    { value: 'want', title: 'Want' },
    { value: 'saving', title: 'Saving' },
    { value: 'investment', title: 'Investment' },
    { value: 'income', title: 'Income' },
    { value: 'excluded', title: 'Excluded (transfers)' },
]

const kindMeta = {
    need: { label: 'Need', color: 'blue' },
    want: { label: 'Want', color: 'deep-purple' },
    saving: { label: 'Saving', color: 'teal' },
    investment: { label: 'Investment', color: 'green' },
    income: { label: 'Income', color: 'light-green' },
    excluded: { label: 'Excluded', color: 'blue-grey' },
}

function kindChip(kind) {
    return kindMeta[kind] || { label: 'Unclassified', color: 'grey-lighten-1' }
}

const categoryDialog = reactive({
    open: false,
    id: null,
    parent_id: null,
    parentName: null,
    name: '',
    icon: 'mdi-tag',
    color: '#5E6BC4',
    kind: null,
    processing: false,
    errors: {},
})

function openCategoryDialog(category = null, parent = null) {
    categoryDialog.open = true
    categoryDialog.errors = {}
    categoryDialog.processing = false
    if (category) {
        categoryDialog.id = category.id
        categoryDialog.parent_id = category.parent_id
        categoryDialog.parentName = null
        categoryDialog.name = category.name
        categoryDialog.icon = category.icon ?? 'mdi-tag'
        categoryDialog.color = category.color ?? '#5E6BC4'
        categoryDialog.kind = category.kind ?? null
    } else {
        categoryDialog.id = null
        categoryDialog.parent_id = parent?.id ?? null
        categoryDialog.parentName = parent?.name ?? null
        categoryDialog.name = ''
        categoryDialog.icon = parent?.icon || 'mdi-tag'
        categoryDialog.color = parent?.color || '#5E6BC4'
        categoryDialog.kind = null
    }
}

function saveCategory() {
    categoryDialog.processing = true
    const payload = {
        name: categoryDialog.name,
        icon: categoryDialog.icon,
        color: categoryDialog.color,
        kind: categoryDialog.kind,
    }
    if (!categoryDialog.id) payload.parent_id = categoryDialog.parent_id

    const opts = {
        preserveScroll: true,
        onSuccess: () => { categoryDialog.open = false },
        onError: (errs) => { categoryDialog.errors = errs },
        onFinish: () => { categoryDialog.processing = false },
    }
    if (categoryDialog.id) {
        router.patch(`/categories/${categoryDialog.id}`, payload, opts)
    } else {
        router.post('/categories', payload, opts)
    }
}

const ruleDialog = reactive({
    open: false,
    categoryId: null,
    categoryName: '',
    match_type: 'contains',
    match_value: '',
    priority: 100,
    apply_to_existing: true,
    processing: false,
    errors: {},
})

function openRuleDialog(category) {
    ruleDialog.open = true
    ruleDialog.categoryId = category.id
    ruleDialog.categoryName = category.name
    ruleDialog.match_type = 'contains'
    ruleDialog.match_value = ''
    ruleDialog.priority = 100
    ruleDialog.apply_to_existing = true
    ruleDialog.errors = {}
    ruleDialog.processing = false
}

function saveRule() {
    ruleDialog.processing = true
    router.post(`/categories/${ruleDialog.categoryId}/rules`, {
        match_type: ruleDialog.match_type,
        match_value: ruleDialog.match_value,
        priority: ruleDialog.priority,
        apply_to_existing: ruleDialog.apply_to_existing,
    }, {
        preserveScroll: true,
        onSuccess: () => { ruleDialog.open = false },
        onError: (errs) => { ruleDialog.errors = errs },
        onFinish: () => { ruleDialog.processing = false },
    })
}

const deleteDialog = reactive({
    open: false,
    message: '',
    confirm: () => {},
})

function confirmDelete(category) {
    const hasContent = category.transactions_count > 0 || (category.children?.length || 0) > 0
    deleteDialog.open = true
    deleteDialog.message = hasContent
        ? `Delete "${category.name}"? Its ${category.transactions_count} transactions will become uncategorized${category.children?.length ? ` and ${category.children.length} subcategories will also be deleted` : ''}.`
        : `Delete "${category.name}"?`
    deleteDialog.confirm = () => {
        router.delete(`/categories/${category.id}`, {
            preserveScroll: true,
            onFinish: () => { deleteDialog.open = false },
        })
    }
}

function confirmDeleteRule(category, rule) {
    deleteDialog.open = true
    deleteDialog.message = `Remove this rule? Existing transactions will keep their current category.`
    deleteDialog.confirm = () => {
        router.delete(`/categories/${category.id}/rules/${rule.id}`, {
            preserveScroll: true,
            onFinish: () => { deleteDialog.open = false },
        })
    }
}
</script>
