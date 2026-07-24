<template>
    <div>
        <h2 class="text-h6 font-weight-bold mb-3">Profile</h2>

        <v-row>
            <!-- Account identity -->
            <v-col cols="12" md="6">
                <v-card class="mb-4">
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-account-circle" class="mr-2" />
                        Account
                    </v-card-title>
                    <v-card-text>
                        <div class="d-flex align-center mb-4">
                            <v-avatar size="72" color="primary" class="mr-4">
                                <v-img v-if="user.avatar_url" :src="user.avatar_url" alt="Avatar" />
                                <span v-else class="text-h5">{{ initials }}</span>
                            </v-avatar>
                            <div>
                                <v-file-input
                                    v-model="avatarForm.avatar"
                                    accept="image/png,image/jpeg,image/webp"
                                    label="Upload avatar"
                                    density="compact"
                                    prepend-icon="mdi-camera"
                                    hide-details
                                    :error-messages="avatarForm.errors.avatar"
                                    style="max-width: 260px"
                                    @update:modelValue="uploadAvatar"
                                />
                                <v-btn
                                    v-if="user.avatar_url"
                                    variant="text"
                                    size="small"
                                    color="error"
                                    class="mt-1"
                                    :loading="avatarForm.processing"
                                    @click="removeAvatar"
                                >
                                    Remove
                                </v-btn>
                            </div>
                        </div>

                        <v-text-field
                            v-model="profileForm.name"
                            label="Name"
                            prepend-inner-icon="mdi-account"
                            :error-messages="profileForm.errors.name"
                            class="mb-2"
                        />
                        <v-text-field
                            v-model="profileForm.email"
                            label="Email"
                            type="email"
                            prepend-inner-icon="mdi-email"
                            :error-messages="profileForm.errors.email"
                            class="mb-2"
                        />
                        <v-btn color="primary" :loading="profileForm.processing" @click="saveProfile">
                            Save account
                        </v-btn>
                    </v-card-text>
                </v-card>

                <!-- Password -->
                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-lock" class="mr-2" />
                        Password
                    </v-card-title>
                    <v-card-text>
                        <v-text-field
                            v-model="passwordForm.current_password"
                            label="Current password"
                            type="password"
                            :error-messages="passwordForm.errors.current_password"
                            class="mb-2"
                        />
                        <v-text-field
                            v-model="passwordForm.password"
                            label="New password"
                            type="password"
                            hint="At least 8 characters, with letters and numbers"
                            :error-messages="passwordForm.errors.password"
                            class="mb-2"
                        />
                        <v-text-field
                            v-model="passwordForm.password_confirmation"
                            label="Confirm new password"
                            type="password"
                            class="mb-2"
                        />
                        <v-btn color="primary" :loading="passwordForm.processing" @click="savePassword">
                            Change password
                        </v-btn>
                    </v-card-text>
                </v-card>
            </v-col>

            <!-- Appearance + notifications -->
            <v-col cols="12" md="6">
                <v-card class="mb-4">
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-palette" class="mr-2" />
                        Appearance
                    </v-card-title>
                    <v-card-text>
                        <v-switch
                            v-model="darkMode"
                            color="primary"
                            inset
                            :label="darkMode ? 'Dark theme' : 'Light theme'"
                            hide-details
                            @update:modelValue="onThemeToggle"
                        />
                    </v-card-text>
                </v-card>

                <v-card>
                    <v-card-title class="d-flex align-center">
                        <v-icon icon="mdi-bell" class="mr-2" />
                        Notifications
                    </v-card-title>
                    <v-card-text>
                        <div class="text-caption text-medium-emphasis mb-2">
                            Preferences are saved now; delivery isn't wired up yet.
                        </div>
                        <v-switch
                            v-for="opt in notificationOptions"
                            :key="opt.key"
                            v-model="prefs[opt.key]"
                            :label="opt.label"
                            color="primary"
                            density="compact"
                            inset
                            hide-details
                            @update:modelValue="savePreferences"
                        />
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </div>
</template>

<script setup>
import { computed, reactive, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { useTheme } from 'vuetify'

const props = defineProps({
    title: String,
    theme: String,
    notifications: Object,
})

const page = usePage()
const user = computed(() => page.props.auth.user)

const initials = computed(() => {
    const name = user.value?.name || user.value?.email || '?'
    return name.trim().slice(0, 2).toUpperCase()
})

// --- Account ---
const profileForm = useForm({
    name: user.value?.name || '',
    email: user.value?.email || '',
})
function saveProfile() {
    profileForm.post('/profile', { preserveScroll: true })
}

// --- Avatar ---
const avatarForm = useForm({ avatar: null })
function uploadAvatar(file) {
    if (!file) return
    avatarForm.post('/profile/avatar', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => avatarForm.reset('avatar'),
    })
}
function removeAvatar() {
    avatarForm.delete('/profile/avatar', { preserveScroll: true })
}

// --- Password ---
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
})
function savePassword() {
    passwordForm.post('/profile/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    })
}

// --- Appearance ---
const theme = useTheme()
const darkMode = ref(props.theme === 'dark')
function onThemeToggle() {
    theme.global.name.value = darkMode.value ? 'budgetDark' : 'budgetLight'
    savePreferences()
}

// --- Notifications ---
const notificationOptions = [
    { key: 'budget_overspend', label: 'Budget overspend alerts' },
    { key: 'subscription_increase', label: 'Subscription price increases' },
    { key: 'low_balance_forecast', label: 'Low-balance forecast warning' },
    { key: 'weekly_summary', label: 'Weekly summary' },
]
const prefs = reactive({ ...props.notifications })

function savePreferences() {
    router.post('/profile/preferences', {
        theme: darkMode.value ? 'dark' : 'light',
        notifications: { ...prefs },
    }, { preserveScroll: true, preserveState: true })
}
</script>
