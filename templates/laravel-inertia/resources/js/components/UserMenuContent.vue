<script setup lang="ts">
import type { User } from '@/types';
import { Link } from '@inertiajs/vue3';
import { Avatar, Divider, Icon } from '@waynestate/wayne-ui-vue';

interface Props {
    user: User;
}

defineProps<Props>();
</script>

<template>
    <!-- WayneUI gap: no menu list classes, so the rows borrow its sidebar link styling. -->
    <div class="user-menu-content">
        <div class="user-menu-content__who">
            <Avatar :name="user.name" :src="user.avatar || ''" aria-hidden="true" />
            <div class="user-menu-content__text">
                <span class="user-menu-content__name">{{ user.name }}</span>
                <span class="user-menu-content__email">{{ user.email }}</span>
            </div>
        </div>
        <Divider />
        <ul class="wsu-sidebar__list user-menu-content__list">
            <li>
                <!-- Sign out is a POST, so it is a button that looks like its neighbor. -->
                <Link :href="route('sesame.logout')" method="post" as="button" class="wsu-sidebar__link user-menu-content__button">
                    <Icon name="log-out" />
                    Sign out
                </Link>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.user-menu-content__who {
    display: flex;
    gap: var(--wsu-space-3);
    align-items: center;
    padding: var(--wsu-space-4);
}

.user-menu-content__text {
    display: grid;
    min-inline-size: 0;
    line-height: 1.3;
}

.user-menu-content__name {
    overflow: hidden;
    font-weight: var(--wsu-font-weight-bold);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.user-menu-content__email {
    overflow: hidden;
    font-size: var(--wsu-font-size-sm);
    color: var(--wsu-text-muted);
    text-overflow: ellipsis;
    white-space: nowrap;
}

.user-menu-content :deep(.wsu-divider) {
    margin: 0;
}

.user-menu-content__list {
    padding: var(--wsu-space-2);
}

.user-menu-content__button {
    inline-size: 100%;
    font: inherit;
    font-size: var(--wsu-font-size-sm);
    font-weight: var(--wsu-font-weight-bold);
    text-align: start;
    cursor: pointer;
    background: none;
    border: none;
}
</style>
