<script setup lang="ts">
import UserInfo from '@/components/UserInfo.vue';
import type { SharedData, User } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { ChevronsUpDown } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import UserMenuContent from './UserMenuContent.vue';

const page = usePage<SharedData>();
const user = page.props.auth.user as User;

const open = ref(false);
const root = ref<HTMLElement | null>(null);

const toggle = () => {
    open.value = !open.value;
};

const close = () => {
    open.value = false;
};

const onDocumentClick = (event: MouseEvent) => {
    if (root.value && !root.value.contains(event.target as Node)) {
        close();
    }
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        close();
    }
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div ref="root" class="nav-user">
        <!-- TODO(wayneui): no WayneUI menu/dropdown component; local disclosure menu until one exists. -->
        <button
            type="button"
            class="nav-user__trigger"
            aria-controls="nav-user-menu"
            :aria-expanded="open"
            @click="toggle"
        >
            <UserInfo :user="user" />
            <ChevronsUpDown class="wsu-icon nav-user__chevron" aria-hidden="true" />
        </button>
        <!-- A disclosure, not role="menu": no aria-haspopup, so screen readers do not expect arrow-key menu behaviour. -->
        <div v-if="open" id="nav-user-menu" class="nav-user__menu">
            <UserMenuContent :user="user" />
        </div>
    </div>
</template>

<style scoped>
.nav-user {
    position: relative;
}

.nav-user__trigger {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    padding: 0.5rem;
    border: none;
    border-radius: 0.375rem;
    background: none;
    cursor: pointer;
    text-align: left;
    color: inherit;
}

.nav-user__trigger:hover {
    background-color: hsl(var(--accent));
}

.nav-user__chevron {
    margin-inline-start: auto;
    inline-size: 1rem;
    block-size: 1rem;
}

/* The user sits at the bottom of the sidebar, so the menu opens upward. */
.nav-user__menu {
    position: absolute;
    bottom: calc(100% + 0.25rem);
    inset-inline: 0;
    z-index: 50;
    background-color: hsl(var(--popover));
    border: 1px solid hsl(var(--border));
    border-radius: 0.5rem;
    box-shadow:
        0 10px 15px -3px rgb(0 0 0 / 0.1),
        0 4px 6px -4px rgb(0 0 0 / 0.1);
}
</style>
