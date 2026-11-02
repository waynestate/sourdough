<script setup lang="ts">
import type { NavItem, SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

defineProps<{
    items: NavItem[];
}>();

const page = usePage<SharedData>();
</script>

<template>
    <div>
        <p id="nav-main" class="wsu-sidebar__heading">Platform</p>
        <ul class="wsu-sidebar__list" aria-labelledby="nav-main">
            <li v-for="item in items" :key="item.title">
                <Link
                    :href="item.href"
                    class="wsu-sidebar__link"
                    :aria-current="item.href === page.url ? 'page' : undefined"
                >
                    <component v-if="item.icon" :is="item.icon" class="wsu-icon" aria-hidden="true" />
                    <span>{{ item.title }}</span>
                </Link>
            </li>
        </ul>
    </div>
</template>
