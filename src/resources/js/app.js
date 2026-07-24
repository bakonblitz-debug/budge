import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import vuetify from './Plugins/vuetify'
import AppLayout from './Layouts/AppLayout.vue'

createInertiaApp({
    title: (title) => title ? `${title} — BudgetApp` : 'BudgetApp',
    resolve: async (name) => {
        const page = await resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue')
        )
        // Auto-apply AppLayout unless the page specifies its own
        page.default.layout = page.default.layout || AppLayout
        return page
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(vuetify)
            .mount(el)
    },
    progress: {
        color: '#4CAF50',
        showSpinner: true,
    },
})
