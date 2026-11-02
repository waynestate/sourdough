<script setup lang="ts">
interface Props {
    class?: string;
}

const props = defineProps<Props>();
</script>

<template>
    <main id="main" tabindex="-1" class="wsu-shell__main" :class="props.class">
        <slot />
    </main>
</template>
