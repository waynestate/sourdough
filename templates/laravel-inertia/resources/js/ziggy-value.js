// The generated ziggy.d.ts declares the route names and takes the module name
// with them, so TypeScript never sees the Ziggy value sitting in ziggy.js.
// This one-line re-export is what app.ts imports; the .d.ts beside it types
// the value. `composer ziggy` regenerates ziggy.js and ziggy.d.ts together,
// so this hand-written pair stays put between runs.
export { Ziggy } from './ziggy.js';
