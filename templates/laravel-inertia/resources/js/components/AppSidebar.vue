<script setup lang="ts">
import { mainNavItems, resourceNavItems, toWayneUi } from '@/navigation';
import { Link, usePage } from '@inertiajs/vue3';
import { Sidebar, SidebarNav } from '@waynestate/wayne-ui-vue';
import { computed } from 'vue';

const page = usePage();

// Grouped items render one heading per group (SidebarNav's grouped mode).
const groups = computed(() => [{ heading: 'Platform', items: toWayneUi(mainNavItems, page.url) }]);

const resources = computed(() => toWayneUi(resourceNavItems, page.url));
</script>

<template>
    <!--
        WayneUI's sidebar: docked from 48rem, a drawer below it. Its runtime (wired
        by AppShell) opens it from the app bar's menu button, makes the rest of the
        page inert, wraps Tab inside, closes on Escape and hands focus back.
    -->
    <Sidebar id="app-sidebar" label="Sections">
        <SidebarNav :items="groups" :link-as="Link" />
        <div class="app-sidebar__resources">
            <SidebarNav heading="Resources" :items="resources" />
        </div>
    </Sidebar>
</template>
