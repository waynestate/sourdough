<script setup lang="ts">
import { provide, reactive } from 'vue';

interface Props {
    variant?: 'header' | 'sidebar';
    class?: string;
}

const props = defineProps<Props>();

// The drawer state lives here so the sidebar (and whatever opens it) can share
// it without a prop-drilling chain. A reactive object, not a ref: nested refs
// do not auto-unwrap in templates.
const sidebar = reactive({
    isDrawerOpen: false,
    openDrawer() {
        this.isDrawerOpen = true;
    },
    closeDrawer() {
        this.isDrawerOpen = false;
    },
    toggleDrawer() {
        this.isDrawerOpen = !this.isDrawerOpen;
    },
});

provide('wsu-sidebar', sidebar);
</script>

<template>
    <div class="wsu-shell" :class="[variant === 'sidebar' && 'wsu-shell--sidebar', props.class]">
        <!-- sr-only/focus:not-sr-only are Tailwind's, standing in until WayneUI's stylesheet (which styles .wsu-skip-link) is loaded. -->
        <a href="#main" class="wsu-skip-link sr-only focus:not-sr-only">Skip to main content</a>
        <slot />
    </div>
</template>
