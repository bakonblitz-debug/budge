<template>
    <v-card>
        <v-card-text class="d-flex align-center">
            <v-avatar :color="color" size="48" class="mr-4" rounded="lg">
                <v-icon :icon="icon" color="white" />
            </v-avatar>
            <div>
                <div class="text-caption text-medium-emphasis">{{ title }}</div>
                <div class="text-h5 font-weight-bold">{{ formatted }}</div>
            </div>
        </v-card-text>
    </v-card>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    title: { type: String, required: true },
    value: { type: [Number, String], default: 0 },
    icon: { type: String, default: 'mdi-circle' },
    color: { type: String, default: 'primary' },
    signed: { type: Boolean, default: false },
    format: { type: String, default: 'currency' }, // 'currency' | 'percent' | 'months' | 'text'
})

const formatted = computed(() => {
    if (props.value == null) return '—'
    if (props.format === 'text') {
        return String(props.value)
    }
    if (props.format === 'percent') {
        const v = Number(props.value || 0)
        return `${v}%`
    }
    if (props.format === 'months') {
        const v = Number(props.value || 0)
        return `${v} mo`
    }
    // currency (default) — existing behaviour
    const v = Number(props.value || 0)
    if (props.signed) {
        const s = Math.abs(v).toLocaleString('en-CA', {
            style: 'currency',
            currency: 'CAD',
        })
        if (v < 0) return '-' + s
        if (v > 0) return '+' + s
        return s
    }
    // Unsigned: keep the natural sign so a negative value isn't shown as positive.
    return v.toLocaleString('en-CA', { style: 'currency', currency: 'CAD' })
})
</script>
