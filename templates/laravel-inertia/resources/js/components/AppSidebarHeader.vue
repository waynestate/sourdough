<script setup lang="ts">
import type { BreadcrumbItemType } from '@/types';
import { ChevronRight, Menu } from 'lucide-vue-next';
import { inject } from 'vue';

interface WsSidebar {
    isDrawerOpen: boolean;
    openDrawer(): void;
    closeDrawer(): void;
    toggleDrawer(): void;
}

const sidebar = inject<WsSidebar | undefined>('wsu-sidebar');

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header class="app-sidebar-header">
        <!-- TODO(wayneui): no WayneUI class for a header bar inside the sidebar layout's main column; local styles below until one exists. -->
        <button
            type="button"
            class="wsu-btn wsu-btn--icon app-sidebar-header__toggle"
            aria-label="Open navigation menu"
            aria-controls="app-sidebar"
            :aria-expanded="sidebar?.isDrawerOpen"
            @click="sidebar?.toggleDrawer()"
        >
            <Menu class="wsu-icon" aria-hidden="true" />
        </button>

        <!-- TODO(wayneui): .wsu-breadcrumbs adds space beneath itself for page-header placement; here it sits inline beside the toggle. Confirm placement. -->
        <nav v-if="breadcrumbs.length > 0" class="wsu-breadcrumbs" aria-label="Breadcrumb">
            <ol class="wsu-breadcrumbs__list">
                <li v-for="(item, index) in breadcrumbs" :key="index" class="wsu-breadcrumbs__item">
                    <template v-if="index === breadcrumbs.length - 1">
                        <span aria-current="page">{{ item.title }}</span>
                    </template>
                    <template v-else>
                        <a :href="item.href" class="wsu-breadcrumbs__link">{{ item.title }}</a>
                        <ChevronRight class="wsu-breadcrumbs__separator wsu-icon" aria-hidden="true" />
                    </template>
                </li>
            </ol>
        </nav>
    </header>
</template>

<style scoped>
.app-sidebar-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-height: 4rem;
    padding-inline: 1.5rem;
    border-block-end: 1px solid hsl(var(--border));
}

/* The drawer toggle only matters below the docked breakpoint. */
@media (min-width: 48rem) {
    .app-sidebar-header__toggle {
        display: none;
    }
}
</style>
