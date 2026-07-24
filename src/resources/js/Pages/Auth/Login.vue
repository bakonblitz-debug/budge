<template>
    <v-container class="fill-height" fluid>
        <v-row justify="center" align="center">
            <v-col cols="12" sm="8" md="5" lg="4">
                <div class="text-center mb-6">
                    <v-icon icon="mdi-wallet" size="48" color="primary" />
                    <h1 class="text-h5 font-weight-medium mt-2">BudgetApp</h1>
                    <p class="text-medium-emphasis">Sign in to your account</p>
                </div>

                <v-card elevation="2" rounded="lg">
                    <v-card-text class="pa-6">
                        <v-form @submit.prevent="submit">
                            <v-text-field
                                v-model="form.email"
                                label="Email"
                                type="email"
                                autocomplete="email"
                                prepend-inner-icon="mdi-email-outline"
                                variant="outlined"
                                density="comfortable"
                                :error-messages="form.errors.email"
                                autofocus
                            />

                            <v-text-field
                                v-model="form.password"
                                label="Password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                prepend-inner-icon="mdi-lock-outline"
                                :append-inner-icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'"
                                variant="outlined"
                                density="comfortable"
                                :error-messages="form.errors.password"
                                @click:append-inner="showPassword = !showPassword"
                            />

                            <v-checkbox
                                v-model="form.remember"
                                label="Remember me"
                                density="compact"
                                hide-details
                                class="mb-2"
                            />

                            <v-btn
                                type="submit"
                                color="primary"
                                size="large"
                                block
                                :loading="form.processing"
                            >
                                Sign in
                            </v-btn>
                        </v-form>

                        <!-- Google SSO: rendered once the OAuth backend is wired
                             (controller flips `googleSsoEnabled`). -->
                        <template v-if="googleSsoEnabled">
                            <div class="d-flex align-center my-4">
                                <v-divider />
                                <span class="px-3 text-medium-emphasis text-caption">or</span>
                                <v-divider />
                            </div>

                            <v-btn
                                variant="outlined"
                                size="large"
                                block
                                prepend-icon="mdi-google"
                                href="/auth/google/redirect"
                            >
                                Continue with Google
                            </v-btn>
                        </template>
                    </v-card-text>
                </v-card>
            </v-col>
        </v-row>
    </v-container>
</template>

<script setup>
import { ref } from 'vue'
import { useForm } from '@inertiajs/vue3'
import BareLayout from '@/Layouts/BareLayout.vue'

defineOptions({ layout: BareLayout })

defineProps({
    googleSsoEnabled: { type: Boolean, default: false },
})

const showPassword = ref(false)

const form = useForm({
    email: '',
    password: '',
    remember: false,
})

function submit() {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    })
}
</script>
