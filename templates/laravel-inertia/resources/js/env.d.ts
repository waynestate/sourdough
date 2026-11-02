/// <reference types="vite/client" />

// Extend ImportMeta interface for Vite...
interface ImportMetaEnv {
    readonly VITE_APP_NAME: string;
}
