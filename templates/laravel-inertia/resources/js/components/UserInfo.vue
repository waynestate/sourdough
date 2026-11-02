<script setup lang="ts">
import { useInitials } from '@/composables/useInitials';
import type { User } from '@/types';
import { computed } from 'vue';

interface Props {
    user: User;
    showEmail?: boolean;
    showName?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showEmail: false,
    showName: true,
});

const { getInitials } = useInitials();

// A photo carries the name in its `alt`; initials carry it in `aria-label` on
// the avatar itself, because two capital letters read aloud are not the name.
const showAvatar = computed(() => props.user.avatar && props.user.avatar !== '');
</script>

<template>
    <span class="wsu-avatar" v-bind="showAvatar ? {} : { role: 'img', 'aria-label': user.name }">
        <img v-if="showAvatar" class="wsu-avatar__image" :src="user.avatar" :alt="user.name" />
        <template v-else>{{ getInitials(user.name) }}</template>
    </span>

    <div v-if="showName" class="user-info__text">
        <span class="user-info__name">{{ user.name }}</span>
        <span v-if="showEmail" class="user-info__email">{{ user.email }}</span>
    </div>
</template>

<style scoped>
.user-info__text {
    display: grid;
    flex: 1;
    min-inline-size: 0;
    text-align: left;
    font-size: 0.875rem;
    line-height: 1.25;
}

.user-info__name {
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.user-info__email {
    font-size: 0.75rem;
    color: hsl(var(--muted-foreground));
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>
