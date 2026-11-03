import Counter from './islands/Counter.vue';
import { createApp } from 'vue';

// Vue islands: Blade renders the page, Vue takes over each element that
// carries a data-vue-island attribute. Add an island to the registry, then
// drop <div data-vue-island="name"></div> into any Blade view.
const islands = {
    counter: Counter,
};

for (const element of document.querySelectorAll('[data-vue-island]')) {
    const name = element.dataset.vueIsland;
    const component = islands[name];

    if (!component) {
        console.warn(`No Vue island named "${name}". Register it in resources/js/app.js.`);
        continue;
    }

    createApp(component).mount(element);
}
