<template>
    <component
        :is="isTypeahead ? 'v-autocomplete' : 'v-select'"
        v-model="model"
        :items="items"
        v-bind="$attrs"
    >
        <template v-for="(_, name) in $slots" #[name]="slotProps">
            <slot :name="name" v-bind="slotProps ?? {}" />
        </template>
    </component>
</template>

<script setup>
import { computed } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
    items: { type: Array, default: () => [] },
})
const model = defineModel()

// "more than 5" options → typeahead; 5 or fewer stays a plain select.
const isTypeahead = computed(() => props.items.length > 5)
</script>
