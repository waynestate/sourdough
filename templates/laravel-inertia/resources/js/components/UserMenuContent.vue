<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import type { User } from '@/types';
import { Link } from '@inertiajs/vue3';
import { LogOut } from 'lucide-vue-next';

interface Props {
    user: User;
}

defineProps<Props>();
</script>

<template>
    <div class="user-menu">
        <!-- TODO(wayneui): no WayneUI menu list classes; local styles until a menu component exists. -->
        <div class="user-menu__header">
            <UserInfo :user="user" :show-email="true" />
        </div>
        <div class="wsu-divider" role="separator" />
        <ul class="user-menu__list">
            <li>
                <Link :href="route('sesame.logout')" method="post" class="user-menu__item">
                    <LogOut class="wsu-icon" aria-hidden="true" />
                    Sign out
                </Link>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.user-menu__header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
}

.user-menu__list {
    list-style: none;
    margin: 0;
    padding: 0.25rem;
}

.user-menu__item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.5rem;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    color: inherit;
    text-decoration: none;
}

.user-menu__item:hover {
    background-color: hsl(var(--accent));
}

.user-menu__item .wsu-icon {
    inline-size: 1rem;
    block-size: 1rem;
}
</style>
