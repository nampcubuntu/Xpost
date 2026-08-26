<script setup>
/**
 * Sidebar icon set.
 *
 * Thin single-stroke line icons on a 16px grid, matching the weight X Ads
 * Manager uses. Kept as one component with a path map rather than a file per
 * icon so the stroke width and cap style can never drift between them.
 */
defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 15 },
});

const paths = {
    // Campaigns
    analytics: 'M2.5 13.5v-4m4 4V6m4 7.5V9.5m4 4v-9',
    posts: 'M3 2.5h10a.5.5 0 0 1 .5.5v10a.5.5 0 0 1-.5.5H3a.5.5 0 0 1-.5-.5V3a.5.5 0 0 1 .5-.5ZM5 5.5h6M5 8h6M5 10.5h3.5',

    // Creatives
    composer: 'M11.5 2.5 13.5 4.5 5.5 12.5 2.5 13.5 3.5 10.5 11.5 2.5ZM10 4l2 2',
    media: 'M2.5 3.5h11v9h-11v-9ZM2.5 10l3-3 2.5 2.5 2-2 3.5 3.5M6 6.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z',
    schedules: 'M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12ZM8 4.8V8l2.3 1.6',

    // Tools
    accounts: 'M6 7.5a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5ZM1.5 13c0-2 2-3.5 4.5-3.5s4.5 1.5 4.5 3.5M11 3.2a2.25 2.25 0 0 1 0 4.3M12 9.7c1.5.5 2.5 1.7 2.5 3.3',
    apps: 'M9.5 2.5a4 4 0 1 0-3.4 6.1L5 9.7v1.6H3.4L2 12.7v1.8h2.5l5.1-5.1A4 4 0 0 0 9.5 2.5ZM10.7 5.3h.01',
    proxy: 'M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12ZM2.2 6.5h11.6M2.2 9.5h11.6M8 2c1.6 1.7 2.4 3.8 2.4 6S9.6 12.3 8 14M8 2C6.4 3.7 5.6 5.8 5.6 8S6.4 12.3 8 14',
    queue: 'M8 1.8 14.5 5 8 8.2 1.5 5 8 1.8ZM1.5 8l6.5 3.2L14.5 8M1.5 11l6.5 3.2L14.5 11',

    // Chrome
    moon: 'M13.5 9.6A5.7 5.7 0 0 1 6.4 2.5a5.8 5.8 0 1 0 7.1 7.1Z',
    logout: 'M6 13.5H3.5a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1H6M10 11l3-3-3-3M13 8H6',
    selector: 'M5.5 6.5 8 4l2.5 2.5M5.5 9.5 8 12l2.5-2.5',
    chevron: 'M4.5 6.5 8 10l3.5-3.5',
    check: 'M3.5 8.5 6.5 11.5 12.5 5',
    search: 'M7.2 12.4a5.2 5.2 0 1 0 0-10.4 5.2 5.2 0 0 0 0 10.4ZM11 11l3.2 3.2',
    copy: 'M5.5 5.5h7a1 1 0 0 1 1 1v7a1 1 0 0 1-1 1h-7a1 1 0 0 1-1-1v-7a1 1 0 0 1 1-1ZM10.5 5.5v-2a1 1 0 0 0-1-1h-6a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2',
    plus: 'M8 3.5v9M3.5 8h9',
    sparkle: 'M8 2.2 9.3 6 13 7.3 9.3 8.6 8 12.4 6.7 8.6 3 7.3 6.7 6 8 2.2Z',
    globe: 'M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12ZM2.4 8h11.2M8 2c1.5 1.7 2.3 3.8 2.3 6S9.5 12.3 8 14M8 2C6.5 3.7 5.7 5.8 5.7 8S6.5 12.3 8 14',
};
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 16 16"
        fill="none"
        stroke="currentColor"
        stroke-width="1.3"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        class="shrink-0"
    >
        <path :d="paths[name] ?? paths.posts" />
    </svg>
</template>
