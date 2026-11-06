<script setup lang="ts">
import UserMenu from '@/components/UserMenu.vue';
import { toWayneUi } from '@/navigation';
import type { NavItem, SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import shieldUrl from '@wayne-ui-assets/marks/wsu-shield.svg?url';
import { AppBar } from '@waynestate/wayne-ui-vue';
import { computed } from 'vue';

interface Props {
    /** Links shown in the bar itself (hidden below 48rem, where the drawer repeats them). */
    nav?: NavItem[];
    /** The id of the drawer the bar's menu button opens; empty for no drawer. */
    drawer?: string;
}

const props = withDefaults(defineProps<Props>(), {
    nav: () => [],
    drawer: '',
});

const page = usePage<SharedData>();
const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const barNav = computed(() => toWayneUi(props.nav, page.url));
const signedIn = computed(() => Boolean(page.props.auth?.user));
</script>

<template>
    <!-- WayneUI's own app bar: the shield, the theme toggle and the drawer button come with it. -->
    <AppBar variant="compact" :mark="shieldUrl" :nav="barNav" :link-as="Link" :drawer="drawer">
        <template #brand>
            <Link class="wsu-app-bar__brand" :href="signedIn ? route('dashboard') : route('home')">{{ appName }}</Link>
        </template>
        <template #user>
            <UserMenu v-if="signedIn" />
            <!-- A plain link, not an Inertia visit: CAS and the gateway answer /login with a redirect to another host. -->
            <a v-else class="wsu-app-bar__toggle" :href="route('sesame.login')">Sign in</a>
        </template>
    </AppBar>
</template>
