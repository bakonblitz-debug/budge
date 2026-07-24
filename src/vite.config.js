import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import vuetify from 'vite-plugin-vuetify'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.js'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        vuetify({ autoImport: true }),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        cors: true,
        origin: `http://localhost:${process.env.VITE_HMR_PORT || 5174}`,
        hmr: {
            host: 'localhost',
            clientPort: parseInt(process.env.VITE_HMR_PORT || '5174', 10),
        },
        watch: {
            usePolling: true,
        },
    },
})
