<script setup lang="ts">
import type { NavItem } from '@/types';

interface Props {
    items: NavItem[];
    class?: string;
}

const props = defineProps<Props>();
</script>

<template>
    <div :class="props.class">
        <ul class="wsu-sidebar__list">
            <li v-for="item in items" :key="item.title">
                <a :href="item.href" class="wsu-sidebar__link" target="_blank" rel="noopener noreferrer">
                    <component v-if="item.icon" :is="item.icon" class="wsu-icon" aria-hidden="true" />
                    <span>{{ item.title }}</span>
                </a>
            </li>
        </ul>
    </div>
</template>
