<script setup>
/**
 * Sidebar icon set.
 *
 * Thin single-stroke line icons on a 16px grid, matching the weight X Ads
 * Manager uses. Kept as one component with a path map rather than a file per
 * icon so the stroke width and cap style can never drift between them.
 */
import { computed } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    size: { type: Number, default: 15 },
    /** The row you are on. X Ads Manager fills its icon rather than tinting it. */
    active: { type: Boolean, default: false },
});

const paths = {
    // Campaigns
    analytics: 'M2.5 13.5v-4m4 4V6m4 7.5V9.5m4 4v-9',
    // An open book, which is what X Ads Manager itself puts next to Bài đăng.
    posts: 'M8 4.7c-1.1-.9-2.6-1.3-4.4-1.3H2.2v8.2h1.4c1.8 0 3.3.4 4.4 1.3M8 4.7c1.1-.9 2.6-1.3 4.4-1.3h1.4v8.2h-1.4c-1.8 0-3.3.4-4.4 1.3M8 4.7v8.2',

    // Creatives
    composer: 'M11.5 2.5 13.5 4.5 5.5 12.5 2.5 13.5 3.5 10.5 11.5 2.5ZM10 4l2 2',
    media: 'M2.5 3.5h11v9h-11v-9ZM2.5 10l3-3 2.5 2.5 2-2 3.5 3.5M6 6.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z',
    schedules: 'M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12ZM8 4.8V8l2.3 1.6',

    /*
     * Tools.
     *
     * One silhouette per destination. Tài khoản X and Thành viên both drew the
     * group of people, and Proxy and Short link both drew the globe, so half
     * this section was two pairs of identical marks -- at 15px a duplicated
     * icon is worse than no icon, because it reads as "you are already here".
     *
     * Split by what the page actually holds: one person for a handle, a group
     * for the team, a chain for the links themselves. Proxy keeps the globe --
     * with Short link moved to the chain there is nothing left for it to
     * collide with, and an exit IP in another country is what the page is for.
     */
    accounts: 'M8 14A6 6 0 1 0 8 2a6 6 0 0 0 0 12ZM8 8.1a1.9 1.9 0 1 0 0-3.8 1.9 1.9 0 0 0 0 3.8ZM4.3 12.7a3.9 3.9 0 0 1 7.4 0',
    members: 'M6 7.5a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5ZM1.5 13c0-2 2-3.5 4.5-3.5s4.5 1.5 4.5 3.5M11 3.2a2.25 2.25 0 0 1 0 4.3M12 9.7c1.5.5 2.5 1.7 2.5 3.3',
    /*
     * The grid of tiles every launcher uses for "apps". A key was accurate --
     * an app row holds a consumer key and secret -- but accuracy is not the
     * job here: a key reads as credentials, security, login, and the reader
     * has to already know what the page holds before the icon means anything.
     */
    apps: 'M2.4 2.4h4.4v4.4H2.4ZM9.2 2.4h4.4v4.4H9.2ZM2.4 9.2h4.4v4.4H2.4ZM9.2 9.2h4.4v4.4H9.2Z',
    link: 'M6.7 8.7a3.3 3.3 0 0 0 5 .4l2-2a3.3 3.3 0 0 0-4.7-4.7l-1.1 1.1M9.3 7.3a3.3 3.3 0 0 0-5-.4l-2 2a3.3 3.3 0 0 0 4.7 4.7l1.1-1.1',
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
    // "Only people you mention" -- the one reply setting whose meaning is a
    // handle, so the icon is the character a handle starts with.
    at: 'M8 5.4a2.6 2.6 0 1 0 0 5.2 2.6 2.6 0 0 0 0-5.2M10.6 5.4v3.9a1.7 1.7 0 0 0 3.4 0V8a6 6 0 1 0-4.3 5.8',
};

/**
 * Solid glyphs for the row you are on.
 *
 * X Ads Manager marks the current page by filling its icon, not by tinting it:
 * the selected book is a solid black shape among outlines. That reads from the
 * corner of the eye, where a colour change on a 1.3px stroke does not.
 *
 * Only icons with a natural solid form are here. A chain and a globe are line
 * drawings -- filling a line yields a blob, not a bolder version of itself --
 * so those fall back to the outline at a heavier stroke. The two styles never
 * have to agree with each other: exactly one row is selected at a time, so no
 * two active icons are ever on screen together.
 *
 * Holes are cut with fill-rule evenodd, which is why the clock and the avatar
 * can be a single disc each.
 */
const solid = {
    analytics: 'M1.6 9.5h1.8v4.6H1.6zM5.6 6h1.8v8.1H5.6zM9.6 9.5h1.8v4.6H9.6zM13.6 4.5h1.8v9.6h-1.8z',
    posts: 'M7.4 4.6c-1.1-.9-2.6-1.3-4.4-1.3H2.2v8.2h1.4c1.6 0 3 .4 3.8 1.3zM8.6 4.6c1.1-.9 2.6-1.3 4.4-1.3h.8v8.2h-1.4c-1.6 0-3 .4-3.8 1.3z',
    composer: 'M11.7 2.1 13.9 4.3 5.9 12.3 2.6 13.4 3.7 10.1z',
    schedules: 'M8 1.6a6.4 6.4 0 1 0 0 12.8A6.4 6.4 0 0 0 8 1.6zM7.4 4.2h1.2v3.5l2.6 1.8-.7 1.1-3.1-2.2z',
    accounts: 'M8 1.6a6.4 6.4 0 1 0 0 12.8A6.4 6.4 0 0 0 8 1.6zM8 4.4a2 2 0 1 1 0 4 2 2 0 0 1 0-4zM4.2 12.4c.5-1.7 2-2.9 3.8-2.9s3.3 1.2 3.8 2.9a5.6 5.6 0 0 1-7.6 0z',
    members: 'M5.6 8.1a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM11.2 8.1a2.2 2.2 0 1 0 0-4.4 2.2 2.2 0 0 0 0 4.4zM5.6 9.3c-2.5 0-4.5 1.6-4.5 3.5 0 .4.3.7.7.7h7.6c.4 0 .7-.3.7-.7 0-1.9-2-3.5-4.5-3.5zM11.2 9.3c-.4 0-.9 0-1.3.1 1.2.9 1.9 2.1 1.9 3.4 0 .3-.1.5-.2.7h2.4c.4 0 .7-.3.7-.7 0-1.9-1.6-3.5-3.5-3.5z',
    apps: 'M2.4 2.4h4.4v4.4H2.4zM9.2 2.4h4.4v4.4H9.2zM2.4 9.2h4.4v4.4H2.4zM9.2 9.2h4.4v4.4H9.2z',
};

const glyph = computed(() => (props.active ? solid[props.name] : undefined));
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 16 16"
        :fill="glyph ? 'currentColor' : 'none'"
        fill-rule="evenodd"
        :stroke="glyph ? 'none' : 'currentColor'"
        :stroke-width="active ? 1.8 : 1.3"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        class="shrink-0"
    >
        <path :d="glyph ?? paths[name] ?? paths.posts" />
    </svg>
</template>
