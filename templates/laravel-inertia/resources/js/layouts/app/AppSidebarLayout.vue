<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <div class="wsu-shell__body app-sidebar-layout__body">
            <AppSidebar />
            <AppContent>
                <AppSidebarHeader :breadcrumbs="breadcrumbs" />
                <slot />
            </AppContent>
        </div>
    </AppShell>
</template>

<style scoped>
/* WayneUI's stylesheet is not loaded in WELD yet, so .wsu-shell--sidebar's own
   two-column grid does not apply. This keeps the shell usable meanwhile: sidebar
   beside the content from 48rem (WayneUI's breakpoint), stacked below it. */
.app-sidebar-layout__body {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    min-block-size: 100svb;
}

@media (min-width: 48rem) {
    .app-sidebar-layout__body {
        grid-template-columns: 16rem minmax(0, 1fr);
    }
}
</style>
