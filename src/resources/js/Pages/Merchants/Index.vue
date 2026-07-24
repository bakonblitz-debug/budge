<template>
    <div>
        <v-row>
            <v-col cols="12" md="8">
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-store-edit" class="mr-2" />
                        Merchant names
                        <v-spacer />
                        <v-btn color="primary" prepend-icon="mdi-plus" @click="openDialog()">
                            Add alias
                        </v-btn>
                    </v-card-title>
                    <v-card-subtitle>
                        Map raw bank text to clean names. Highest priority wins; unmatched
                        descriptions fall back to automatic cleanup.
                    </v-card-subtitle>

                    <v-list v-if="aliases.length > 0" lines="two">
                        <v-list-item v-for="alias in aliases" :key="alias.id">
                            <template #prepend>
                                <v-avatar color="primary" size="32" variant="tonal">
                                    <v-icon icon="mdi-store" size="18" />
                                </v-avatar>
                            </template>
                            <v-list-item-title>{{ alias.display_name }}</v-list-item-title>
                            <v-list-item-subtitle>
                                {{ alias.match_type }} “{{ alias.match_value }}” · priority {{ alias.priority }}
                                <v-chip v-if="!alias.is_active" size="x-small" color="warning" class="ml-1">inactive</v-chip>
                            </v-list-item-subtitle>
                            <template #append>
                                <v-btn icon="mdi-pencil" variant="text" size="small" @click="openDialog(alias)" />
                                <v-btn icon="mdi-delete" variant="text" size="small" color="error" @click="confirmDelete(alias)" />
                            </template>
                        </v-list-item>
                    </v-list>

                    <v-card-text v-else class="text-center py-12">
                        <v-icon icon="mdi-store-off" size="56" class="mb-2 text-medium-emphasis" />
                        <div class="text-medium-emphasis">No aliases yet — raw descriptions are auto-cleaned.</div>
                    </v-card-text>
                </v-card>
            </v-col>

            <v-col cols="12" md="4">
                <v-card variant="tonal">
                    <v-card-title class="text-subtitle-1">
                        <v-icon icon="mdi-text-search" size="18" class="mr-1" />
                        Descriptions in your data
                    </v-card-title>
                    <v-card-text class="text-caption" style="max-height: 420px; overflow-y: auto">
                        <div v-for="(d, i) in sampleDescriptions" :key="i" class="mb-1 text-truncate">
                            {{ d }}
                        </div>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>

        <v-dialog v-model="dialog.open" max-width="560">
            <v-card>
                <v-card-title>{{ dialog.id ? 'Edit alias' : 'Add alias' }}</v-card-title>
                <v-card-text>
                    <v-row dense>
                        <v-col cols="12" sm="5">
                            <SmartSelect
                                v-model="dialog.match_type"
                                :items="matchTypes"
                                label="Match type"
                                :error-messages="dialog.errors.match_type"
                            />
                        </v-col>
                        <v-col cols="12" sm="7">
                            <v-text-field
                                v-model="dialog.match_value"
                                label="Match value (e.g. METRO)"
                                :error-messages="dialog.errors.match_value"
                            />
                        </v-col>
                        <v-col cols="12" sm="8">
                            <v-text-field
                                v-model="dialog.display_name"
                                label="Display name (e.g. Metro)"
                                :error-messages="dialog.errors.display_name"
                            />
                        </v-col>
                        <v-col cols="12" sm="4">
                            <v-text-field
                                v-model.number="dialog.priority"
                                label="Priority"
                                type="number"
                                :error-messages="dialog.errors.priority"
                            />
                        </v-col>
                        <v-col cols="12">
                            <v-switch v-model="dialog.is_active" label="Active" color="primary" hide-details />
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
                <v-card-title>Remove alias?</v-card-title>
                <v-card-text>{{ deleteDialog.message }}</v-card-text>
                <v-card-actions>
                    <v-spacer />
                    <v-btn variant="text" @click="deleteDialog.open = false">Cancel</v-btn>
                    <v-btn color="error" @click="deleteDialog.confirm">Remove</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
</template>

<script setup>
import { reactive } from 'vue'
import SmartSelect from '../../Components/SmartSelect.vue'
import { router } from '@inertiajs/vue3'

const props = defineProps({
    title: String,
    aliases: Array,
    matchTypes: Array,
    sampleDescriptions: Array,
})

const dialog = reactive({
    open: false,
    id: null,
    match_type: 'contains',
    match_value: '',
    display_name: '',
    priority: 0,
    is_active: true,
    processing: false,
    errors: {},
})

function openDialog(alias = null) {
    dialog.open = true
    dialog.errors = {}
    if (alias) {
        dialog.id = alias.id
        dialog.match_type = alias.match_type
        dialog.match_value = alias.match_value
        dialog.display_name = alias.display_name
        dialog.priority = alias.priority
        dialog.is_active = alias.is_active
    } else {
        dialog.id = null
        dialog.match_type = 'contains'
        dialog.match_value = ''
        dialog.display_name = ''
        dialog.priority = 0
        dialog.is_active = true
    }
}

function save() {
    dialog.processing = true
    const payload = {
        match_type: dialog.match_type,
        match_value: dialog.match_value,
        display_name: dialog.display_name,
        priority: dialog.priority,
        is_active: dialog.is_active,
    }
    const opts = {
        preserveScroll: true,
        onSuccess: () => { dialog.open = false },
        onError: (errs) => { dialog.errors = errs },
        onFinish: () => { dialog.processing = false },
    }
    if (dialog.id) {
        router.patch(`/merchants/${dialog.id}`, payload, opts)
    } else {
        router.post('/merchants', payload, opts)
    }
}

const deleteDialog = reactive({ open: false, message: '', confirm: () => {} })

function confirmDelete(alias) {
    deleteDialog.open = true
    deleteDialog.message = `Remove the alias “${alias.display_name}”?`
    deleteDialog.confirm = () => {
        router.delete(`/merchants/${alias.id}`, {
            preserveScroll: true,
            onFinish: () => { deleteDialog.open = false },
        })
    }
}
</script>
