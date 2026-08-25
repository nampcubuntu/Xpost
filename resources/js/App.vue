<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import AppLayout from './layouts/AppLayout.vue';

const route = useRoute();

// The marketing pages and the login screen own their own full-page chrome;
// everything else renders inside the app shell.
const bare = computed(() => route.meta.guest === true || route.meta.public === true);
</script>

<template>
    <RouterView v-if="bare" />

    <AppLayout v-else>
        <RouterView />
    </AppLayout>
</template>
