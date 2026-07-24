<template>
    <v-app>
        <v-main>
            <slot />
        </v-main>
        <v-snackbar
            v-model="snackbar.show"
            :color="snackbar.color"
            :timeout="3000"
            location="bottom right"
        >
            {{ snackbar.message }}
        </v-snackbar>
    </v-app>
</template>

<script setup>
import { ref } from 'vue'
import { usePage } from '@inertiajs/vue3'

const page = usePage()
const snackbar = ref({ show: false, message: '', color: 'success' })

if (page.props?.flash?.message) {
    snackbar.value = {
        show: true,
        message: page.props.flash.message,
        color: page.props.flash.type || 'success',
    }
}
</script>
