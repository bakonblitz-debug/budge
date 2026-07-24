<template>
    <v-card class="text-center py-12">
        <v-icon :icon="icon" size="80" color="grey-lighten-1" />
        <v-card-title class="text-h4 mt-4">{{ title }}</v-card-title>
        <v-card-subtitle class="text-body-1 mt-2">
            This feature is coming soon.
        </v-card-subtitle>
        <v-card-actions class="justify-center mt-4">
            <v-btn color="primary" variant="tonal" prepend-icon="mdi-arrow-left" @click="$inertia.visit('/')">
                Back to Dashboard
            </v-btn>
        </v-card-actions>
    </v-card>
</template>

<script setup>
defineProps({
    title: { type: String, default: 'Coming Soon' },
    icon: { type: String, default: 'mdi-hammer-wrench' },
})
</script>
