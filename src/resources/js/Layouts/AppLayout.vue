<template>
    <v-app>
        <!-- Navigation Drawer -->
        <v-navigation-drawer v-model="drawer" :rail="rail" permanent>
            <v-list-item
                prepend-icon="mdi-wallet"
                title="BudgetApp"
                class="py-3"
                @click="rail = !rail"
            />

            <v-divider />

            <v-list density="compact" nav>
                <v-list-item
                    v-for="item in navItems"
                    :key="item.route"
                    :prepend-icon="item.icon"
                    :title="item.title"
                    :href="item.route"
                    :active="isActive(item.route)"
                    color="primary"
                    @click.prevent="navigate(item.route)"
                />
            </v-list>

            <template v-slot:append>
                <v-divider />
                <v-list density="compact" nav>
                    <v-list-item
                        prepend-icon="mdi-account-circle"
                        title="Profile"
                        href="/profile"
                        :active="isActive('/profile')"
                        @click.prevent="navigate('/profile')"
                    />
                    <v-list-item
                        prepend-icon="mdi-cog"
                        title="Settings"
                        href="/settings"
                        :active="isActive('/settings')"
                        @click.prevent="navigate('/settings')"
                    />
                    <v-list-item
                        :prepend-icon="darkMode ? 'mdi-weather-sunny' : 'mdi-weather-night'"
                        :title="darkMode ? 'Light Mode' : 'Dark Mode'"
                        @click="toggleTheme"
                    />
                    <v-divider class="my-1" />
                    <v-list-item
                        prepend-icon="mdi-logout"
                        title="Log out"
                        base-color="error"
                        :disabled="loggingOut"
                        @click="logout"
                    />
                </v-list>
            </template>
        </v-navigation-drawer>

        <!-- Top App Bar -->
        <v-app-bar elevation="0" border="b">
            <v-app-bar-title>
                <span class="text-h6 font-weight-medium">{{ pageTitle }}</span>
            </v-app-bar-title>

            <template v-slot:append>
                <v-btn icon="mdi-bell-outline" variant="text" />
                <v-btn icon variant="text" title="Profile" @click="navigate('/profile')">
                    <v-avatar size="32" color="primary">
                        <v-img v-if="avatarUrl" :src="avatarUrl" alt="Avatar" />
                        <v-icon v-else icon="mdi-account" />
                    </v-avatar>
                </v-btn>
            </template>
        </v-app-bar>

        <!-- Main Content -->
        <v-main>
            <v-container fluid class="pa-6">
                <slot />
            </v-container>
        </v-main>

        <!-- Snackbar for notifications -->
        <v-snackbar
            v-model="snackbar.show"
            :color="snackbar.color"
            :timeout="3000"
            location="bottom right"
        >
            {{ snackbar.message }}
            <template v-slot:actions>
                <v-btn variant="text" @click="snackbar.show = false">Close</v-btn>
            </template>
        </v-snackbar>
    </v-app>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useTheme } from 'vuetify'

const theme = useTheme()
const page = usePage()
const drawer = ref(true)
const rail = ref(false)
const loggingOut = ref(false)

// Apply the user's saved theme on load (shared from the server), so it persists
// across refreshes and devices instead of resetting to light every time.
const darkMode = ref(page.props?.theme === 'dark')
theme.global.name.value = darkMode.value ? 'budgetDark' : 'budgetLight'

const avatarUrl = computed(() => page.props?.auth?.user?.avatar_url || null)

const snackbar = ref({
    show: false,
    message: '',
    color: 'success',
})

const pageTitle = computed(() => page.props?.title || 'Dashboard')

const navItems = [
    { title: 'Dashboard', icon: 'mdi-view-dashboard', route: '/' },
    { title: 'Transactions', icon: 'mdi-swap-horizontal', route: '/transactions' },
    { title: 'Import statement', icon: 'mdi-file-upload', route: '/transactions/import' },
    { title: 'Categorize', icon: 'mdi-magnify-scan', route: '/categorize' },
    { title: 'Categories', icon: 'mdi-tag-multiple', route: '/categories' },
    { title: 'Merchant names', icon: 'mdi-store-edit', route: '/merchants' },
    { title: 'Income', icon: 'mdi-cash-plus', route: '/income' },
    { title: 'Fixed Expenses', icon: 'mdi-home-city', route: '/fixed-expenses' },
    { title: 'Budgets', icon: 'mdi-target', route: '/budgets' },
    { title: 'Subscriptions', icon: 'mdi-autorenew', route: '/subscriptions' },
    { title: 'Forecast', icon: 'mdi-chart-bell-curve-cumulative', route: '/forecast' },
    { title: 'Net worth', icon: 'mdi-scale-balance', route: '/net-worth' },
    { title: 'Envelope Pools', icon: 'mdi-email-multiple', route: '/envelope-pools' },
    { title: 'Accrual', icon: 'mdi-calendar-clock', route: '/accrual' },
    { title: 'Insights', icon: 'mdi-chart-box', route: '/insights' },
    { title: 'Milestones', icon: 'mdi-trophy', route: '/milestones' },
    { title: 'Audit Log', icon: 'mdi-history', route: '/audit' },
]

function isActive(route) {
    return page.url.split(/[?#]/)[0] === route
}

function navigate(route) {
    router.visit(route)
}

function logout() {
    loggingOut.value = true
    router.post('/logout', {}, {
        onFinish: () => { loggingOut.value = false },
    })
}

function toggleTheme() {
    darkMode.value = !darkMode.value
    theme.global.name.value = darkMode.value ? 'budgetDark' : 'budgetLight'
    // Persist the choice (theme-only; notification prefs are left untouched).
    router.post('/profile/preferences', { theme: darkMode.value ? 'dark' : 'light' }, {
        preserveState: true,
        preserveScroll: true,
    })
}

// Flash message support from Laravel. Watched (not read once at setup):
// this layout is persistent across Inertia visits, so flashes arriving on
// redirect-backs would otherwise never be shown.
watch(() => page.props?.flash, (flash) => {
    if (flash?.message) {
        snackbar.value = {
            show: true,
            message: flash.message,
            color: flash.type || 'success',
        }
    }
}, { immediate: true, deep: true })
</script>
