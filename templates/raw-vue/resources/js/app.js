import '../css/app.css';

import spriteUrl from '@wayne-ui-assets/icons.svg?url';
import { spriteKey } from '@waynestate/wayne-ui-vue';
import { createApp } from 'vue';
import App from './App.vue';

// PHP renders one empty <div id="app"> and hands over the facts the page
// needs as data attributes; everything else comes from the JSON API.
const root = document.getElementById('app');

// WayneUI's runtime reads the sprite's address from <html>; AppShell's mount
// then fetches it once and wires the theme toggle and the rest.
document.documentElement.setAttribute('data-wsu-sprite', spriteUrl);

createApp(App, {
    appName: root.dataset.appName,
    environment: root.dataset.env,
})
    // WayneUI's icon sprite, bundled from the pinned package and served from
    // this app's own origin: no CDN, no extra CSP host. This is what
    // app.use(WayneUI, { sprite }) does, minus registering every component
    // globally. Components are imported by name where they are used.
    .provide(spriteKey, spriteUrl)
    .mount(root);
