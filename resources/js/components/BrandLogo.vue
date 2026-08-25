<script setup>
/**
 * Xpost brand mark.
 *
 * Original geometry — deliberately not X's glyph, which is their trademark.
 *
 * The silhouette is one solid beam opening from a point on the left, sliced by
 * two thin negative-space wedges into three bands: one post, many accounts.
 * Everything is a single path, so the mark is a mass with cuts rather than a
 * cluster of separate shapes — that is the difference between a logo and a UI
 * icon.
 *
 * Constraints that keep it legible at 16px, verified by downsampling:
 *   - solid triangular silhouette, no thin floating parts
 *   - band angles 15.5 / 13 / 15.5 degrees, wedges 5 degrees
 *   - sharp corners, flat right edge
 *   - one flat colour, never a gradient
 */
defineProps({
    // 'full' = mark + wordmark, 'mark' = glyph only, 'tile' = glyph on a solid ground
    variant: { type: String, default: 'full' },
    size: { type: Number, default: 28 },
    // 'auto' inherits currentColor; 'light' / 'dark' pin the tone
    tone: { type: String, default: 'auto' },
});

const MARK =
    'M10 50 L88 10.3 L88 34.1 L10 50 L88 41.1 L88 58.9 L10 50 L88 65.9 L88 89.7 Z';
</script>

<template>
    <span class="inline-flex items-center gap-[0.42em] align-middle">
        <!-- Glyph on its own ground: favicon, avatar, app icon -->
        <svg
            v-if="variant === 'tile'"
            :width="size"
            :height="size"
            viewBox="0 0 100 100"
            fill="none"
            aria-hidden="true"
            class="shrink-0"
        >
            <rect width="100" height="100" rx="22" fill="#08080A" />
            <path :d="MARK" fill="#fff" transform="translate(19.6 19) scale(0.62)" />
        </svg>

        <!-- Bare glyph: takes its colour from the surrounding text -->
        <svg
            v-else
            :width="size"
            :height="size"
            viewBox="0 0 100 100"
            fill="none"
            aria-hidden="true"
            class="shrink-0"
        >
            <path
                :d="MARK"
                :fill="tone === 'light' ? '#fff' : tone === 'dark' ? '#08080A' : 'currentColor'"
            />
        </svg>

        <span
            v-if="variant === 'full'"
            class="font-semibold tracking-[-0.045em]"
            :style="{ fontSize: `${Math.round(size * 0.82)}px`, lineHeight: 1 }"
        >Xpost</span>
    </span>
</template>
