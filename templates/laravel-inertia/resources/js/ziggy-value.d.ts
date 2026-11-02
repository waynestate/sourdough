import type { Config } from 'ziggy-js';

// The generated config carries a plain-string location that predates the typed
// shape, so the declaration stops at Omit<Config, 'location'>. app.ts overwrites
// url and port before handing the config to ZiggyVue anyway.
export const Ziggy: Omit<Config, 'location'>;
