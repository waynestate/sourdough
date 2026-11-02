import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';
// Welcome aboard, new dev. The route list used to ride in an inline <script>
// from @routes, but HELMET's strict CSP only admits scripts that carry a
// nonce, so that one never made it past security. It is welded into the Vite
// bundle now: regenerate it with `composer ziggy` whenever routes change.
import { Ziggy } from './ziggy-value';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            // The generated file's url is the build machine's; the browser's origin is the truth.
            .use(ZiggyVue, { ...Ziggy, url: window.location.origin, port: null })
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();
