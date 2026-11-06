<script setup lang="ts">
import AppFooter from '@/components/AppFooter.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import { toCrumbs } from '@/navigation';
import type { BreadcrumbItemType } from '@/types';
import { Link } from '@inertiajs/vue3';
import { AppShell, Breadcrumbs, SkipLink } from '@waynestate/wayne-ui-vue';
import { computed } from 'vue';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

const props = withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const crumbs = computed(() => toCrumbs(props.breadcrumbs));
</script>

<template>
    <SkipLink target="main" />
    <!-- WayneUI's shell: app bar on top, the sidebar docked beside <main> from 48rem and a drawer below it. -->
    <AppShell main-id="main">
        <template #bar>
            <AppHeader drawer="app-sidebar" />
        </template>

        <template #sidebar>
            <AppSidebar />
        </template>

        <!-- WayneUI puts the trail at the top of the page, above the page header. A trail of one is just the title, so it waits for two. -->
        <Breadcrumbs v-if="crumbs.length > 1" :items="crumbs" :link-as="Link" />
        <slot />

        <template #footer>
            <AppFooter />
        </template>
    </AppShell>
</template>
