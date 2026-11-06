import vue from '@vitejs/plugin-vue';
import { rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';

// The dev server's address. src/Vite.php reads it from public/hot, so PHP
// knows to load the app from here instead of from the build.
const devOrigin = 'http://localhost:5173';
const hotPath = fileURLToPath(new URL('./public/hot', import.meta.url));

// Write public/hot while `npm run dev` runs and take it away when it stops.
// The whole plugin fits on a recipe card, which is why there is no package.
function hotFile() {
    return {
        name: 'hot-file',
        apply: 'serve',
        configureServer(server) {
            server.httpServer?.once('listening', () => writeFileSync(hotPath, devOrigin));
            const clean = () => rmSync(hotPath, { force: true });
            process.on('exit', clean);
            process.on('SIGINT', () => process.exit());
            process.on('SIGTERM', () => process.exit());
        },
    };
}

export default defineConfig(({ command }) => ({
    // Built files live under public/build and are served from /build/.
    base: command === 'build' ? '/build/' : '/',
    // public/ is PHP's document root, not a folder of static assets to copy.
    publicDir: false,
    plugins: [vue(), hotFile()],
    resolve: {
        alias: {
            // WayneUI's built assets (the icon sprite and the university marks).
            // The package's exports map does not list them, so they are reached
            // by path; the version is whatever package-lock.json pins, so the
            // sprite and the stylesheet can never come from two releases.
            '@wayne-ui-assets': fileURLToPath(new URL('./node_modules/@waynestate/wayne-ui-css/dist', import.meta.url)),
        },
    },
    server: {
        origin: devOrigin,
        port: 5173,
        strictPort: true,
    },
    build: {
        outDir: 'public/build',
        emptyOutDir: true,
        // At the top of the build, where src/Vite.php looks for it.
        manifest: 'manifest.json',
        rollupOptions: {
            input: 'resources/js/app.js',
        },
    },
}));
