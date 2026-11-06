<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/vue3';
import { Badge, Card, Icon, PageHeader } from '@waynestate/wayne-ui-vue';
import { computed } from 'vue';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const page = usePage<SharedData>();
const firstName = computed(() => page.props.auth.user?.name.trim().split(/\s+/)[0] ?? '');

// What the stack already did for this page before you wrote a line of it.
const wired = [
    { part: 'SESAME', did: 'signed you in', icon: 'user' },
    { part: 'HELMET', did: 'set the security headers and the CSP nonce', icon: 'circle-check' },
    { part: 'WayneUI', did: 'drew the bar, the sidebar and these cards', icon: 'layout-template' },
];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <PageHeader
            title="Dashboard"
            :subtitle="firstName ? `Welcome back, ${firstName}. This page is yours to replace.` : 'This page is yours to replace.'"
        />

        <section class="app-dashboard" aria-label="Getting started">
            <Card title="Build your first page" :title-level="2">
                <p>
                    Pages live in <code>resources/js/pages</code> and wear WayneUI's Vue components, imported from
                    <code>@waynestate/wayne-ui-vue</code>. Start from this one.
                </p>
                <template #footer>
                    <a class="app-card-link" href="https://wayneui.apps.wayne.edu/components/">
                        Browse the components <Icon name="external-link" />
                    </a>
                </template>
            </Card>

            <Card title="Check before you ship" :title-level="2">
                <p><code>composer ci</code> runs the tests, Pint and PHPStan; <code>npm run lint</code> keeps the front end on WayneUI.</p>
                <template #footer>
                    <a class="app-card-link" href="https://sourdough.apps.wayne.edu/docs/"> Read the docs <Icon name="book-open" /> </a>
                </template>
            </Card>

            <Card title="Already wired in" :title-level="2">
                <ul class="app-wired">
                    <li v-for="item in wired" :key="item.part">
                        <Icon :name="item.icon" />
                        <span>
                            <Badge variant="brand">{{ item.part }}</Badge> {{ item.did }}
                        </span>
                    </li>
                </ul>
            </Card>
        </section>
    </AppLayout>
</template>
