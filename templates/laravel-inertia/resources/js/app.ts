import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/vue3';
import spriteUrl from '@wayne-ui-assets/icons.svg?url';
import { spriteKey } from '@waynestate/wayne-ui-vue';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h, nextTick } from 'vue';
import { route as ziggyRoute, ZiggyVue } from 'ziggy-js';
import { initializeTheme } from './composables/useAppearance';
// Welcome aboard, new dev. The route list used to ride in an inline <script>
// from @routes, but HELMET's strict CSP only admits scripts that carry a
// nonce, so that one never made it past security. It is welded into the Vite
// bundle now: regenerate it with `composer ziggy` whenever routes change.
import { Ziggy } from './ziggy-value';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// WayneUI's runtime reads the sprite's address from <html>; AppShell's mount
// then fetches it once and wires the drawer, the theme toggle and the rest.
document.documentElement.setAttribute('data-wsu-sprite', spriteUrl);

// HELMET's environment ribbon arrives as a bare <div> outside every landmark,
// so a screen reader skimming by region never meets it (axe: region). WayneUI's
// own EnvRibbon is a polite status, so HELMET's wears the same role until HELMET
// ships it in the markup.
document.querySelector('.helmet-ribbon')?.setAttribute('role', 'status');

// The generated file's url is the build machine's; the browser's origin is the truth.
const ziggy = { ...Ziggy, url: window.location.origin, port: null };

// @routes also defined a global route(), and <script setup> blocks call it
// (the profile form, the delete dialog), as types/ziggy.ts promises they can.
// ZiggyVue only reaches templates, so without this the profile's Save button
// threw "route is not defined" and quietly did nothing.
Object.assign(globalThis, {
    route: (...args: Parameters<typeof ziggyRoute>) => ziggyRoute(args[0], args[1], args[2], ziggy),
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, ziggy)
            // WayneUI's icon sprite, bundled from the pinned package and served from
            // this app's own origin: no CDN, no extra CSP host. This is what
            // app.use(WayneUI, { sprite }) does, minus registering all sixty
            // components globally, which would ship every one of them to every
            // page. Pages import the components they use by name instead.
            .provide(spriteKey, spriteUrl)
            .mount(el);
    },
    progress: {
        // WayneUI's brand green.
        color: '#0c5449',
    },
});

// A link in the open drawer starts a visit that unmounts the drawer mid-open.
// WayneUI's close() is what un-inerts the rest of the page, and nothing calls
// it on an unmount, so the HELMET ribbon outside the app stayed inert for
// good. Close the drawer the proper way as the visit starts.
router.on('start', () => {
    document.querySelectorAll<HTMLElement>('[data-wsu-drawer-toggle][aria-expanded="true"]').forEach((toggle) => toggle.click());
});

// An Inertia visit swaps the page without a load, so focus would stay on the
// link that was clicked, inside chrome that no longer exists. Put it on <main>,
// where a full page load would have left it (WCAG 2.4.3). GET visits only: a
// form post's page knows better where focus belongs.
router.on('finish', (event) => {
    const visit = event.detail.visit;
    if (visit.method !== 'get' || visit.prefetch) return;

    nextTick(() => {
        document.getElementById('main')?.focus({ preventScroll: true });
    });
});

// Light, dark or the system setting, in WayneUI's own theme switch.
initializeTheme();
