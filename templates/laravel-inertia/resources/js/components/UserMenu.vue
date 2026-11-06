<script setup lang="ts">
import UserMenuContent from '@/components/UserMenuContent.vue';
import type { SharedData, User } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { Avatar, Icon } from '@waynestate/wayne-ui-vue';
import { computed, onBeforeUnmount, onMounted, ref, useId } from 'vue';

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user as User);

const menuId = `user-menu-${useId()}`;
const open = ref(false);
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);

const close = (returnFocus = false) => {
    if (!open.value) return;
    open.value = false;
    if (returnFocus) trigger.value?.focus();
};

const onDocumentClick = (event: MouseEvent) => {
    if (root.value && !root.value.contains(event.target as Node)) close();
};

// Escape closes from anywhere inside, and hands focus back to the button so a
// keyboard user lands where they started.
const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && open.value) {
        event.preventDefault();
        close(true);
    }
};

// Tabbing out of the panel closes it, so it never hangs open behind the page.
const onFocusOut = (event: FocusEvent) => {
    const next = event.relatedTarget as Node | null;
    if (next && root.value && !root.value.contains(next)) close();
};

let stopNavigate: (() => void) | undefined;

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    stopNavigate = router.on('start', () => close());
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    stopNavigate?.();
});
</script>

<template>
    <!--
        WayneUI gap: it has no menu or dropdown component (AppBar's user slot is a
        static label), so this is a small local disclosure built on its tokens and
        its bar button. A disclosure, not role="menu": no arrow-key contract to break.
    -->
    <div ref="root" class="user-menu" @keydown="onKeydown" @focusout="onFocusOut">
        <button
            ref="trigger"
            type="button"
            class="wsu-app-bar__toggle user-menu__trigger"
            :aria-expanded="open"
            :aria-controls="menuId"
            @click="open = !open"
        >
            <!-- The button's own text carries the name, so the avatar is not read twice. -->
            <Avatar :name="user.name" :src="user.avatar || ''" size="sm" aria-hidden="true" />
            <span class="user-menu__name">{{ user.name }}</span>
            <span class="wsu-sr-only">, account menu</span>
            <Icon name="chevron-down" class="user-menu__chevron" />
        </button>
        <div v-show="open" :id="menuId" class="user-menu__panel">
            <UserMenuContent :user="user" />
        </div>
    </div>
</template>

<style scoped>
.user-menu {
    position: relative;
}

.user-menu__trigger {
    padding-inline: var(--wsu-space-2);
}

/* The avatar keeps the name on narrow screens; the button still says it to a screen reader. */
@media (max-width: 39.999rem) {
    .user-menu__name {
        position: absolute;
        inline-size: 1px;
        block-size: 1px;
        overflow: hidden;
        clip-path: inset(50%);
        white-space: nowrap;
    }
}

/* WayneUI's avatar is brand green, the same green as the light bar, so in the
   bar it wears the bar's colors reversed and stays a visible chip in both themes. */
.user-menu__trigger .wsu-avatar {
    color: var(--wsu-chrome-bar);
    background-color: var(--wsu-chrome-bar-text);
}

.user-menu__chevron {
    transition: rotate var(--wsu-duration-fast) ease-out;
}

.user-menu__trigger[aria-expanded='true'] .user-menu__chevron {
    rotate: 180deg;
}

.user-menu__panel {
    position: absolute;
    inset-block-start: calc(100% + var(--wsu-space-2));
    inset-inline-end: 0;
    z-index: var(--wsu-z-overlay);
    min-inline-size: 16rem;
    color: var(--wsu-text);
    background-color: var(--wsu-surface-raised);
    border: var(--wsu-size-border-width) solid var(--wsu-border);
    border-radius: var(--wsu-radius-box);
    box-shadow: var(--wsu-shadow-lg);
    animation: user-menu-in var(--wsu-duration-fast) ease-out;
}

@keyframes user-menu-in {
    from {
        opacity: 0;
        translate: 0 -0.25rem;
    }
}
</style>
