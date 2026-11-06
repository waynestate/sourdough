import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
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
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
            // WayneUI's built assets (the icon sprite and the university marks).
            // The package's exports map does not list them, so they are reached
            // by path; the version is whatever package-lock.json pins, so the
            // sprite and the stylesheet can never come from two releases.
            '@wayne-ui-assets': path.resolve(__dirname, './node_modules/@waynestate/wayne-ui-css/dist'),
        },
    },
});
