<script setup>
import { computed } from 'vue';

const props = defineProps({
    body: { type: String, default: '' },
    headline: { type: String, default: '' },
    websiteUrl: { type: String, default: '' },
    media: { type: Array, default: () => [] },
    account: { type: Object, default: null },
    promotedOnly: { type: Boolean, default: false },
});

const displayName = computed(() => props.account?.name || 'Tài khoản X');
const handle = computed(() => (props.account?.username ? `@${props.account.username}` : '@xpost'));

/** Show the first spintax option — what one recipient actually sees. */
const renderedBody = computed(() =>
    props.body.replace(/\{([^{}]*)\}/g, (_, group) => group.split('|')[0] ?? ''),
);

/** Video needs a <video> element; an <img> pointed at an mp4 renders broken. */
const isVideo = (item) => (item.mime ?? '').startsWith('video/');

/*
 * A frame, not just the metadata.
 *
 * preload="metadata" leaves a browser free to render nothing until play, so a
 * clip that opens on a dark frame previews as a grey rectangle -- which reads
 * as a broken upload rather than as the first frame. The fragment asks for a
 * seek far enough in to decode one, and the request to the server is the same
 * request.
 */
const poster = (item) => `${item.url}#t=0.1`;

/*
 * One item shows its own shape; several share a grid.
 *
 * Every tile was forced to 16:9 and cropped to fit, so a 9:16 clip -- the shape
 * most of this library is in -- previewed as a letterboxed strip of its middle.
 * The preview exists to answer "is this the right framing", and it was
 * answering about a framing X would never show.
 *
 * Clamped at both ends, because X does not hand the whole timeline to a very
 * tall clip either, and a panorama at 3:1 would leave the card a hairline.
 */
const MIN_RATIO = 9 / 16;
const MAX_RATIO = 2;

function mediaStyle(item) {
    // A grid of two or four has to crop: the cells are equal, so the shapes
    // cannot be. That is what X does with them too.
    if (props.media.length !== 1 || !item.width || !item.height) {
        return { aspectRatio: '16 / 9' };
    }

    return { aspectRatio: `${Math.min(Math.max(item.width / item.height, MIN_RATIO), MAX_RATIO)}` };
}

const host = computed(() => {
    if (!props.websiteUrl) {
        return '';
    }

    try {
        const raw = props.websiteUrl.startsWith('http') ? props.websiteUrl : `https://${props.websiteUrl}`;

        return new URL(raw).hostname;
    } catch {
        return props.websiteUrl;
    }
});

// Tweet action bar. Drawn as paths rather than emoji so the weight matches the
// rest of the UI and does not change with the viewer's emoji font.
const actions = [
    'M14 1.5H2A.5.5 0 0 0 1.5 2v8a.5.5 0 0 0 .5.5h3v3l3.5-3H14a.5.5 0 0 0 .5-.5V2a.5.5 0 0 0-.5-.5Z',
    'M4.5 2.5h7l-2-2M11.5 2.5v4M11.5 13.5h-7l2 2M4.5 13.5v-4',
    'M8 13.5S1.5 9.8 1.5 5.6A3.1 3.1 0 0 1 8 4a3.1 3.1 0 0 1 6.5 1.6C14.5 9.8 8 13.5 8 13.5Z',
    'M2 13.5v-4m4 4V6m4 7.5v-3m4 3v-8',
    'M8 10.5v-9M4.5 5 8 1.5 11.5 5M2.5 10v3.5h11V10',
];
</script>

<template>
    <div class="w-full lg:w-[300px] lg:shrink-0">
        <p class="mb-2 text-[12.5px] font-medium text-neutral-700">Preview</p>

        <!-- Phone shell -->
        <div class="relative w-[300px] max-w-full">
            <!-- Side buttons -->
            <span class="absolute -left-[3px] top-[86px] h-9 w-[3px] rounded-l bg-neutral-800"></span>
            <span class="absolute -left-[3px] top-[132px] h-9 w-[3px] rounded-l bg-neutral-800"></span>
            <span class="absolute -right-[3px] top-[108px] h-16 w-[3px] rounded-r bg-neutral-800"></span>

            <div class="flex h-[580px] flex-col overflow-hidden rounded-[30px] border-[10px] border-neutral-900 bg-white">
                <!-- Status bar -->
                <div class="relative flex shrink-0 items-center justify-between px-3 pb-1 pt-1.5">
                    <span class="text-[9px] font-semibold">9:41</span>
                    <span class="absolute left-1/2 top-1 h-[15px] w-[52px] -translate-x-1/2 rounded-full bg-neutral-900"></span>
                    <span class="flex items-center gap-[3px]">
                        <svg width="11" height="8" viewBox="0 0 11 8" fill="currentColor" aria-hidden="true">
                            <rect x="0" y="5.5" width="1.6" height="2.5" rx=".4" />
                            <rect x="2.6" y="4" width="1.6" height="4" rx=".4" />
                            <rect x="5.2" y="2.2" width="1.6" height="5.8" rx=".4" />
                            <rect x="7.8" y="0.5" width="1.6" height="7.5" rx=".4" />
                        </svg>
                        <svg width="10" height="8" viewBox="0 0 10 8" fill="currentColor" aria-hidden="true">
                            <path d="M5 7.2 6.6 5.4a2.3 2.3 0 0 0-3.2 0L5 7.2ZM5 3.4c1 0 2 .4 2.7 1.1l1-1.1A5.4 5.4 0 0 0 5 1.8C3.5 1.8 2.1 2.3 1.3 3.4l1 1.1A3.8 3.8 0 0 1 5 3.4Z" />
                        </svg>
                        <svg width="15" height="8" viewBox="0 0 15 8" fill="none" aria-hidden="true">
                            <rect x=".5" y=".5" width="11.5" height="7" rx="2" stroke="currentColor" stroke-width="0.8" opacity=".5" />
                            <rect x="1.8" y="1.8" width="8.9" height="4.4" rx="1.1" fill="currentColor" />
                            <path d="M13 3v2c.6-.2 1-.5 1-1s-.4-.8-1-1Z" fill="currentColor" opacity=".5" />
                        </svg>
                    </span>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto px-2.5 pb-3">
                    <!-- Feed above: greyed out so the ad reads as the focus -->
                    <div class="flex gap-2 border-b border-neutral-100 py-3">
                        <div class="h-6 w-6 shrink-0 rounded-full bg-neutral-200"></div>
                        <div class="flex-1 space-y-1.5 pt-1">
                            <div class="h-[6px] w-3/5 rounded bg-neutral-200"></div>
                            <div class="h-[6px] w-4/5 rounded bg-neutral-200"></div>
                        </div>
                    </div>

                    <!-- The post being composed -->
                    <article class="border-b border-neutral-100 py-2.5">
                        <div class="flex items-start gap-2">
                            <img
                                v-if="account?.avatar_url"
                                :src="account.avatar_url"
                                alt=""
                                class="h-[22px] w-[22px] shrink-0 rounded-full object-cover"
                            >
                            <div v-else class="h-[22px] w-[22px] shrink-0 rounded-full bg-neutral-300"></div>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-baseline gap-1 text-[10.5px]">
                                    <span class="truncate font-bold">{{ displayName }}</span>
                                    <svg class="h-[11px] w-[11px] shrink-0 self-center text-[#1d9bf0]" viewBox="0 0 22 22" fill="currentColor" aria-hidden="true">
                                        <path d="M20.4 11a3 3 0 0 0-1.7-2.7 3 3 0 0 0-3.6-3.6A3 3 0 0 0 11 3a3 3 0 0 0-4.1 1.7 3 3 0 0 0-3.6 3.6A3 3 0 0 0 1.6 11a3 3 0 0 0 1.7 4.1 3 3 0 0 0 3.6 3.6A3 3 0 0 0 11 19a3 3 0 0 0 4.1-1.7 3 3 0 0 0 3.6-3.6A3 3 0 0 0 20.4 11Zm-9.9 4.1L6.8 11.4l1.5-1.5 2.2 2.2 4.4-4.4 1.5 1.5Z" />
                                    </svg>
                                    <span class="truncate text-neutral-500">{{ handle }}</span>
                                    <span v-if="promotedOnly" class="ml-auto shrink-0 text-[9.5px] text-neutral-500">Quảng cáo</span>
                                </div>

                                <p v-if="renderedBody" class="mt-1 whitespace-pre-wrap break-words text-[10.5px] leading-snug">
                                    {{ renderedBody }}
                                </p>

                                <!--
                                    Media.

                                    A single attachment carries the destination card:
                                    the headline sits over the bottom-left of the
                                    image and the domain reads underneath, which is
                                    how X renders a website card. Video needs a real
                                    <video> element -- pointing an <img> at an mp4
                                    just renders a broken-image icon.
                                -->
                                <div v-if="media.length" class="mt-1.5">
                                    <div
                                        class="grid gap-px overflow-hidden rounded-xl border border-neutral-200"
                                        :class="media.length === 1 ? 'grid-cols-1' : 'grid-cols-2'"
                                    >
                                        <div
                                            v-for="item in media.slice(0, 4)"
                                            :key="item.id"
                                            class="relative"
                                        >
                                            <video
                                                v-if="isVideo(item)"
                                                :src="poster(item)"
                                                preload="metadata"
                                                muted
                                                playsinline
                                                class="w-full bg-neutral-100 object-cover"
                                                :style="mediaStyle(item)"
                                            ></video>
                                            <img
                                                v-else
                                                :src="item.url"
                                                alt=""
                                                class="w-full bg-neutral-100 object-cover"
                                                :style="mediaStyle(item)"
                                            >

                                            <!-- Play affordance -->
                                            <span
                                                v-if="isVideo(item)"
                                                class="absolute inset-0 grid place-items-center"
                                            >
                                                <span class="grid h-6 w-6 place-items-center rounded-full bg-black/55">
                                                    <svg width="8" height="9" viewBox="0 0 8 9" fill="#fff" aria-hidden="true">
                                                        <path d="M0 0.5 8 4.5 0 8.5Z" />
                                                    </svg>
                                                </span>
                                            </span>

                                            <span
                                                v-if="media.length === 1 && websiteUrl && (headline || host)"
                                                class="absolute bottom-1.5 left-1.5 max-w-[85%] truncate rounded bg-black/75 px-1.5 py-0.5 text-[9px] text-white"
                                            >{{ headline || host }}</span>
                                        </div>
                                    </div>

                                    <p v-if="websiteUrl" class="mt-1 truncate text-[9px] text-neutral-500">
                                        Từ {{ host }}
                                    </p>
                                </div>

                                <!-- Destination card with no media of its own -->
                                <div v-else-if="websiteUrl" class="mt-1.5 overflow-hidden rounded-xl border border-neutral-200">
                                    <div class="aspect-[1.91/1] w-full bg-neutral-100"></div>
                                    <div class="px-2 py-1.5">
                                        <p class="truncate text-[8.5px] text-neutral-500">{{ host }}</p>
                                        <p class="truncate text-[9.5px] font-medium">{{ headline || host }}</p>
                                    </div>
                                </div>

                                <div class="mt-1.5 flex justify-between pr-3 text-neutral-400">
                                    <svg
                                        v-for="(d, i) in actions"
                                        :key="i"
                                        width="11"
                                        height="11"
                                        viewBox="0 0 16 16"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="1.2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        aria-hidden="true"
                                    >
                                        <path :d="d" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </article>

                    <div v-for="n in 6" :key="n" class="flex gap-2 border-b border-neutral-100 py-3">
                        <div class="h-6 w-6 shrink-0 rounded-full bg-neutral-200"></div>
                        <div class="flex-1 space-y-1.5 pt-1">
                            <div class="h-[6px] w-3/5 rounded bg-neutral-200"></div>
                            <div class="h-[6px] w-4/5 rounded bg-neutral-200"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <p class="mt-3 text-center text-[10.5px] leading-relaxed text-neutral-500">
            Bài đã đăng có thể hiển thị khác bản xem trước, tuỳ hệ điều hành và kích thước màn hình.
        </p>
    </div>
</template>
