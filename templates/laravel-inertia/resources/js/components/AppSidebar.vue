<script setup lang="ts">
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import type { NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { BookOpen, Folder, LayoutGrid, X } from 'lucide-vue-next';
import { useEventListener, useMediaQuery } from '@vueuse/core';
import { computed, inject, ref, watch } from 'vue';
import AppLogo from './AppLogo.vue';

interface WsSidebar {
    isDrawerOpen: boolean;
    openDrawer(): void;
    closeDrawer(): void;
    toggleDrawer(): void;
}

const sidebar = inject<WsSidebar | undefined>('wsu-sidebar');

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Github Repo',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits',
        icon: BookOpen,
    },
];

const navRef = ref<HTMLElement | null>(null);

// WayneUI's theme.js strips `hidden` from a docked sidebar, but WELD does not
// run it, and Tailwind's preflight hides [hidden] outright. So `hidden` is only
// ever set below the docked breakpoint, where it is what keeps a closed
// drawer's links out of the tab order. Schrodinger's sidebar, minus the cat.
const isDocked = useMediaQuery('(min-width: 48rem)');
const isHidden = computed(() => !isDocked.value && !sidebar?.isDrawerOpen);

useEventListener(document, 'keydown', (event: KeyboardEvent) => {
    if (event.key === 'Escape' && sidebar?.isDrawerOpen) {
        sidebar.closeDrawer();
    }
});

// Move focus into the drawer when it opens, and hand it back to the toggle
// when it closes. This is not a focus trap.
// TODO(wayneui): WayneUI's drawer script also sets `inert` on the rest of the page while open; adopt it once WayneUI's JS is wired in.
watch(
    () => sidebar?.isDrawerOpen,
    (open) => {
        if (open) {
            navRef.value?.focus();
        } else {
            const active = document.activeElement;
            if (navRef.value && active && navRef.value.contains(active)) {
                document.querySelector<HTMLElement>('[aria-controls="app-sidebar"]')?.focus();
            }
        }
    },
);
</script>

<template>
    <div class="wsu-drawer-backdrop app-sidebar__backdrop" :hidden="isDocked || !sidebar?.isDrawerOpen" @click="sidebar?.closeDrawer()" />
    <nav
        ref="navRef"
        id="app-sidebar"
        class="wsu-sidebar app-sidebar"
        aria-label="Sections"
        tabindex="-1"
        :hidden="isHidden"
    >
        <!-- TODO(wayneui): .wsu-sidebar__inner sticks below var(--wsu-app-bar-height); this layout has no app bar, so expect a top gap once WayneUI's CSS loads. -->
        <div class="wsu-sidebar__inner">
            <button
                type="button"
                class="wsu-btn wsu-btn--icon wsu-btn--ghost wsu-sidebar__close"
                aria-label="Close navigation menu"
                @click="sidebar?.closeDrawer()"
            >
                <X class="wsu-icon" aria-hidden="true" />
            </button>

            <div class="app-sidebar__brand">
                <Link :href="route('dashboard')" class="app-sidebar__brand-link">
                    <AppLogo />
                </Link>
            </div>

            <NavMain :items="mainNavItems" />
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </div>
    </nav>
</template>

<style scoped>
/* Stand-ins for WayneUI's drawer rules until its stylesheet is loaded: a fixed
   overlay below 48rem, an ordinary column above it. */
@media (max-width: 47.999rem) {
    .app-sidebar {
        position: fixed;
        inset-block: 0;
        inset-inline-start: 0;
        z-index: 50;
        inline-size: min(20rem, 85vw);
        overflow-y: auto;
        background-color: hsl(var(--sidebar-background));
    }

    .app-sidebar__backdrop {
        position: fixed;
        inset: 0;
        z-index: 49;
        background-color: rgb(0 0 0 / 0.5);
    }
}

@media (min-width: 48rem) {
    .app-sidebar {
        border-inline-end: 1px solid hsl(var(--border));
        background-color: hsl(var(--sidebar-background));
    }

    .app-sidebar .wsu-sidebar__close {
        display: none;
    }
}

.app-sidebar__brand-link {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    color: inherit;
    text-decoration: none;
}
</style>
