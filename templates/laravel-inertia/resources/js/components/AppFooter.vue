<script setup lang="ts">
import { Footer } from '@waynestate/wayne-ui-vue';

// The university's standard footer links for an internal app.
const links = [
    { label: 'wayne.edu', href: 'https://wayne.edu/' },
    { label: 'Help Desk', href: 'https://tech.wayne.edu/' },
    { label: 'Accessibility', href: 'https://wayne.edu/accessibility/' },
    { label: 'Privacy and University Policies', href: 'https://wayne.edu/policies' },
];
</script>

<template>
    <Footer :links="links" />
</template>
