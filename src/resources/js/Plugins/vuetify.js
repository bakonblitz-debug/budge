import 'vuetify/styles'
import '@mdi/font/css/materialdesignicons.css'
import { createVuetify } from 'vuetify'
import * as components from 'vuetify/components'
import * as directives from 'vuetify/directives'

const budgetLight = {
    dark: false,
    colors: {
        background: '#F5F5F5',
        surface: '#FFFFFF',
        primary: '#2E7D32',       // Green — money, growth
        secondary: '#1565C0',     // Blue — trust, stability
        accent: '#FF8F00',        // Amber — attention, warnings
        error: '#C62828',         // Red — overspent, alerts
        warning: '#EF6C00',       // Orange — caution
        info: '#0277BD',          // Light blue — info
        success: '#2E7D32',       // Green — on track
        'on-background': '#212121',
        'on-surface': '#212121',
    },
}

const budgetDark = {
    dark: true,
    colors: {
        background: '#121212',
        surface: '#1E1E1E',
        primary: '#66BB6A',
        secondary: '#42A5F5',
        accent: '#FFB74D',
        error: '#EF5350',
        warning: '#FFA726',
        info: '#29B6F6',
        success: '#66BB6A',
        'on-background': '#E0E0E0',
        'on-surface': '#E0E0E0',
    },
}

export default createVuetify({
    components,
    directives,
    theme: {
        defaultTheme: 'budgetLight',
        themes: {
            budgetLight,
            budgetDark,
        },
    },
    defaults: {
        VCard: { elevation: 1, rounded: 'lg' },
        VBtn: { rounded: 'lg' },
        VTextField: { variant: 'outlined', density: 'comfortable' },
        VSelect: { variant: 'outlined', density: 'comfortable' },
        VDataTable: { density: 'comfortable' },
    },
})
