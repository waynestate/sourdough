<script setup>
import { ref } from 'vue';

const count = ref(0);
</script>

<template>
    <button type="button" class="wsu-btn wsu-btn--outline" @click="count++">Count is {{ count }}</button>
</template>
