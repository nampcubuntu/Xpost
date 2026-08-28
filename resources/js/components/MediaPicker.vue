<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../lib/api';

/**
 * The "Add media" panel.
 *
 * In the reference this is not a modal -- it takes over the composer card, with
 * a back arrow rather than a close button. Keeping that shape matters on a
 * phone, where a dialog this tall would be unusable.
 */
const props = defineProps({
    // Already-attached media, so the picker opens with them selected.
    selected: { type: Array, default: () => [] },
    max: { type: Number, default: 4 },
    destinationType: { type: String, default: 'none' },
});

const emit = defineEmits(['close', 'confirm', 'select']);

const items = ref([]);
const loading = ref(true);
const uploading = ref(false);
const dragging = ref(false);
const error = ref(null);

const search = ref('');
const type = ref('all');
const fileInput = ref(null);

// Declared above load(), which clears it: relying on the call being deferred
// past the end of the script body is a footgun for whoever moves load() next.
const playing = ref(null);
const videoEls = {};

// Keyed by id so re-fetching (search, tab change) never drops a selection that
// is currently filtered out of view.
const chosen = ref(new Map(props.selected.map((m) => [m.id, m])));

/*
 * The live selection, not the confirmed one.
 *
 * The preview beside this panel was reading the media already attached to the
 * post, so ticking a tile changed nothing until Xác nhận -- which is the wrong
 * way round: the preview is how you decide whether to confirm.
 *
 * immediate, so the parent is in sync from the first render rather than from
 * the first change.
 */
watch(chosen, (map) => emit('select', [...map.values()]), { immediate: true });

const tabs = [
    { value: 'all', label: 'All' },
    { value: 'photo', label: 'Photo' },
    { value: 'video', label: 'Video' },
];

const count = computed(() => chosen.value.size);
const atLimit = computed(() => count.value >= props.max);

const destinationLabel = computed(() =>
    ({ website: 'Website', app: 'App' })[props.destinationType] ?? 'No destination',
);

// Video dimensions are not read on upload (that would mean ffprobe on the
// request path), so the element reports them once its metadata loads.
const meta = ref({});

async function load() {
    loading.value = true;
    // The element playing is about to be unmounted; leaving the id set would
    // show a pause badge on whatever tile inherits it.
    playing.value = null;

    try {
        const { data } = await api.get('media', {
            params: {
                search: search.value || undefined,
                type: type.value === 'all' ? undefined : type.value,
                per_page: 60,
            },
        });

        items.value = data.data;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

let timer;
watch([search, type], () => {
    clearTimeout(timer);
    timer = setTimeout(load, 250);
});

const isVideo = (item) => (item.mime ?? '').startsWith('video/');

/*
 * Playing a clip in the grid, before committing it to a post.
 *
 * A still frame says what a video looks like at one instant, which is not the
 * thing being chosen -- and several of these files open on a dark or empty
 * frame, so the tile can be blank for a clip that is fine. Picking the wrong
 * one is otherwise found out at publish, across every account at once.
 */
function setVideoEl(id, el) {
    if (el) {
        videoEls[id] = el;
    } else {
        delete videoEls[id];
    }
}

async function togglePreview(item) {
    const el = videoEls[item.id];

    if (!el) {
        return;
    }

    if (playing.value === item.id) {
        el.pause();
        playing.value = null;

        return;
    }

    /*
     * One at a time. Several tiles playing at once is noise to look at, and
     * every one of them is a video being pulled down a connection that also
     * has to carry the uploads.
     */
    const current = videoEls[playing.value];

    if (current) {
        current.pause();
        current.currentTime = 0;
    }

    playing.value = item.id;

    try {
        await el.play();
    } catch {
        // Refused by the browser, usually for want of a gesture it did not
        // count. The tile stays a still, which is what it already was.
        playing.value = null;
    }
}

/**
 * Ask for a frame rather than only the metadata.
 *
 * With preload="metadata" alone a browser is free to render nothing until
 * play, which is why some tiles are blank rectangles. The fragment asks it to
 * seek a tenth of a second in, which forces a decoded frame and costs the same
 * request -- the URL to the server is unchanged.
 */
const posterSrc = (item) => `${item.url}#t=0.1`;

function rememberMeta(id, el) {
    const width = el.videoWidth || el.naturalWidth;
    const height = el.videoHeight || el.naturalHeight;

    if (!width || !height) {
        return;
    }

    const duration = Number.isFinite(el.duration) ? el.duration : null;

    meta.value = { ...meta.value, [id]: { width, height, duration } };

    persistMeta(id, width, height, duration);
}

/*
 * Write the discovered dimensions back once.
 *
 * Uploads do not measure video server-side -- that would put ffprobe on the
 * request path -- so without this every visit re-learns the shape and the tile
 * reflows from a square to its real ratio as the metadata arrives.
 */
const persisted = new Set();

async function persistMeta(id, width, height, duration) {
    const item = items.value.find((m) => m.id === id);

    if (!item || item.width || persisted.has(id)) {
        return;
    }

    persisted.add(id);

    try {
        await api.put(`media/${id}`, {
            width,
            height,
            duration_ms: duration ? Math.round(duration * 1000) : null,
        });

        Object.assign(item, { width, height });
    } catch {
        // Cosmetic only: the grid already has the numbers it needs in `meta`.
        persisted.delete(id);
    }
}

/** Tiles take the shape of their media, so a 16:9 clip is not cropped square. */
function ratioStyle(item) {
    const width = item.width ?? meta.value[item.id]?.width;
    const height = item.height ?? meta.value[item.id]?.height;

    return { aspectRatio: width && height ? `${width} / ${height}` : '1 / 1' };
}

function durationLabel(item) {
    const seconds = meta.value[item.id]?.duration ?? (item.duration_ms ? item.duration_ms / 1000 : null);

    if (!seconds) {
        return null;
    }

    const total = Math.round(seconds);

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

/** Snap to the nearest ratio X itself labels rather than printing 1600:900. */
const RATIOS = [
    ['16:9', 16 / 9], ['9:16', 9 / 16], ['1:1', 1],
    ['4:5', 4 / 5], ['5:4', 5 / 4], ['4:3', 4 / 3], ['3:4', 3 / 4], ['2:1', 2],
];

function ratioLabel(item) {
    const width = item.width ?? meta.value[item.id]?.width;
    const height = item.height ?? meta.value[item.id]?.height;

    if (!width || !height) {
        return null;
    }

    const actual = width / height;
    const [label] = RATIOS.reduce(
        (best, entry) => (Math.abs(entry[1] - actual) < Math.abs(best[1] - actual) ? entry : best),
    );

    return label;
}

function toggle(item) {
    const next = new Map(chosen.value);

    if (next.has(item.id)) {
        next.delete(item.id);
    } else if (!atLimit.value) {
        next.set(item.id, item);
    }

    chosen.value = next;
}

async function upload(files) {
    error.value = null;
    uploading.value = true;

    try {
        for (const file of Array.from(files ?? [])) {
            const form = new FormData();
            form.append('file', file);

            const { data } = await api.post('media', form, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });

            const media = data.data ?? data;

            // A freshly uploaded file is what the user came here for, so select it.
            items.value = [media, ...items.value.filter((m) => m.id !== media.id)];

            if (!atLimit.value) {
                chosen.value = new Map(chosen.value).set(media.id, media);
            }
        }
    } catch (e) {
        error.value = e.response?.data?.message ?? 'Tải tệp thất bại.';
    } finally {
        uploading.value = false;
    }
}

function onDrop(event) {
    dragging.value = false;
    upload(event.dataTransfer?.files);
}
</script>

<template>
    <div class="min-w-0 flex-1 rounded-lg border border-neutral-200 bg-white">
        <!-- Header -->
        <div class="flex items-center gap-3 border-b border-neutral-200 px-5 py-3.5">
            <button
                type="button"
                class="grid h-6 w-6 place-items-center rounded text-neutral-600 transition hover:bg-neutral-100"
                aria-label="Quay lại"
                @click="emit('close')"
            >
                <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9.5 3.5 5 8l4.5 4.5M5 8h8" />
                </svg>
            </button>

            <span class="text-[14px] font-medium">Add media</span>

            <span class="ml-auto text-[12.5px] text-neutral-500">
                {{ count }} of {{ max }} · {{ destinationLabel }}
            </span>
        </div>

        <div class="space-y-3 p-5">
            <p class="text-[12.5px] font-medium leading-4 text-neutral-800">Phương tiện</p>

            <!--
                What is attached, above the library rather than inside it.

                The count in the header said "1 of 4" and the only way to see
                which one that was, was to scroll the grid hunting for a tick.
                Here it is the first thing under the heading, and the × removes
                it without finding it again.
            -->
            <div v-if="chosen.size" class="flex flex-wrap gap-2">
                <div
                    v-for="item in [...chosen.values()]"
                    :key="`picked-${item.id}`"
                    class="group/pick relative h-[100px] w-[100px] overflow-hidden rounded-lg border border-neutral-200"
                >
                    <video
                        v-if="isVideo(item)"
                        :src="posterSrc(item)"
                        preload="metadata"
                        muted
                        playsinline
                        class="h-full w-full bg-neutral-100 object-cover"
                    ></video>
                    <img v-else :src="item.url" alt="" class="h-full w-full bg-neutral-100 object-cover">

                    <!--
                        Top-right, where the reference puts it. The corner was
                        only ambiguous while the glyph was a cross -- that reads
                        as "close this", which is a different and reversible
                        act. A bin says which of the two it is on its own, so
                        the position is free to go back to the corner and leave
                        the picture unobstructed.
                    -->
                    <button
                        type="button"
                        class="absolute right-1.5 top-1.5 grid h-7 w-7 place-items-center rounded-lg bg-neutral-900/80 text-white opacity-0 transition hover:bg-neutral-900 group-hover/pick:opacity-100"
                        :aria-label="`Bỏ ${item.original_name}`"
                        @click="toggle(item)"
                    >
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M2.6 4.2h10.8M6.2 4.2V2.8h3.6v1.4M4.1 4.2l.6 8.6a1 1 0 0 0 1 .9h4.6a1 1 0 0 0 1-.9l.6-8.6M6.6 6.8v4.3M9.4 6.8v4.3" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Dropzone -->
            <div
                class="grid cursor-pointer place-items-center rounded-md border border-dashed py-9 transition"
                :class="dragging ? 'border-neutral-800 bg-neutral-50' : 'border-neutral-300 hover:bg-neutral-50'"
                @click="fileInput.click()"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" class="text-neutral-500">
                    <path d="M21 13.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h8" />
                    <path d="M3 15.5 8 11l4.5 4M18 15v6M15 18h6" />
                    <circle cx="9" cy="8.5" r="1.4" />
                </svg>
                <p class="mt-2 text-[12.5px] text-neutral-600">
                    {{ uploading ? 'Đang tải lên…' : 'Drag and drop or click to upload' }}
                </p>

                <input
                    ref="fileInput"
                    type="file"
                    accept="image/*,video/*"
                    multiple
                    class="hidden"
                    @click.stop
                    @change="upload($event.target.files); $event.target.value = ''"
                >
            </div>

            <p v-if="error" class="text-[12px] text-red-600">{{ error }}</p>

            <!-- Search -->
            <div class="relative">
                <svg class="absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400" width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3">
                    <circle cx="7" cy="7" r="4.5" />
                    <path d="m10.5 10.5 3 3" stroke-linecap="round" />
                </svg>
                <input
                    v-model="search"
                    type="search"
                    class="w-full rounded-md border border-neutral-300 py-1.5 pl-9 pr-3 text-[13px] leading-[17px] outline-none transition placeholder:text-neutral-400 focus:border-neutral-800"
                    placeholder="Search by name"
                >
            </div>

            <!-- Type tabs -->
            <div class="flex gap-1 rounded-md border border-neutral-200 p-1">
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="rounded px-2.5 py-1 text-[12.5px] transition"
                    :class="type === tab.value
                        ? 'bg-neutral-100 font-medium text-neutral-900'
                        : 'text-neutral-500 hover:text-neutral-900'"
                    @click="type = tab.value"
                >{{ tab.label }}</button>
            </div>

            <!-- Library -->
            <p v-if="loading" class="py-10 text-center text-[13px] text-neutral-500">Đang tải…</p>

            <p v-else-if="!items.length" class="py-10 text-center text-[13px] text-neutral-500">
                Chưa có tệp nào. Kéo thả hoặc bấm vùng phía trên để tải lên.
            </p>

            <!--
                Capped and scrolled, packed into columns rather than rows.

                A grid row is as tall as its tallest tile, and the tiles keep
                the shape of their media on purpose -- so one 9:16 clip made
                every 16:9 beside it sit in half a row of white space, and a
                library of twenty ran off the bottom of the page. Columns pack
                each tile under the one above it, which removes the gaps
                without cropping anything square.

                The order becomes column-major, which is the trade: newest
                still lands top-left, and this is a wall of pictures being
                scanned rather than a list being read.
            -->
            <!--
                Sized against the window rather than fixed, so a tall screen
                shows more rather than scrolling a short box down the middle of
                it. The floor keeps two tiles visible on a laptop, where the
                header, dropzone, search and footer already claim their share.
            -->
            <div v-else class="-mr-2 max-h-[clamp(360px,60vh,820px)] overflow-y-auto pr-2">
              <div class="columns-2 gap-3 lg:columns-3">
                <button
                    v-for="item in items"
                    :key="item.id"
                    type="button"
                    class="group relative mb-3 block w-full break-inside-avoid overflow-hidden rounded-lg border-2 transition"
                    :style="ratioStyle(item)"
                    :class="[
                        chosen.has(item.id) ? 'border-neutral-900' : 'border-transparent',
                        !chosen.has(item.id) && atLimit ? 'cursor-not-allowed opacity-50' : '',
                    ]"
                    @click="toggle(item)"
                >
                    <video
                        v-if="isVideo(item)"
                        :ref="(el) => setVideoEl(item.id, el)"
                        :src="posterSrc(item)"
                        preload="metadata"
                        muted
                        playsinline
                        class="h-full w-full bg-neutral-100 object-cover"
                        @loadedmetadata="rememberMeta(item.id, $event.target)"
                        @ended="playing = null"
                    ></video>
                    <img
                        v-else
                        :src="item.url"
                        alt=""
                        class="h-full w-full bg-neutral-100 object-cover"
                        @load="rememberMeta(item.id, $event.target)"
                    >

                    <span
                        v-if="durationLabel(item)"
                        class="absolute left-2 top-2 rounded bg-black/75 px-1.5 py-0.5 text-[11px] font-medium text-white"
                    >{{ durationLabel(item) }}</span>

                    <!--
                        Play, and only play.

                        Sized to itself rather than covering the tile: an
                        overlay across the whole thing would swallow every
                        click, and selecting is what this grid is for. Clicking
                        the badge previews, clicking anywhere else still picks.

                        A span rather than a button because it sits inside one,
                        and a button inside a button is not valid HTML -- so it
                        carries the role and the key handler by hand.
                    -->
                    <span
                        v-if="isVideo(item)"
                        role="button"
                        tabindex="0"
                        :aria-label="playing === item.id ? 'Tạm dừng' : 'Xem trước'"
                        class="absolute left-1/2 top-1/2 grid h-11 w-11 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full bg-black/55 text-white transition hover:scale-105 hover:bg-black/80"
                        :class="playing === item.id ? 'opacity-0 group-hover:opacity-100' : 'opacity-90'"
                        @click.stop="togglePreview(item)"
                        @keydown.enter.stop.prevent="togglePreview(item)"
                        @keydown.space.stop.prevent="togglePreview(item)"
                    >
                        <svg v-if="playing === item.id" width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                            <rect x="3.5" y="2.5" width="3.5" height="11" rx="1" />
                            <rect x="9" y="2.5" width="3.5" height="11" rx="1" />
                        </svg>
                        <svg v-else width="15" height="15" viewBox="0 0 16 16" fill="currentColor">
                            <path d="M5 3.2 13 8l-8 4.8z" />
                        </svg>
                    </span>

                    <!-- Selection dot -->
                    <span
                        class="absolute right-2 top-2 grid h-5 w-5 place-items-center rounded-full border transition"
                        :class="chosen.has(item.id)
                            ? 'border-neutral-900 bg-neutral-900'
                            : 'border-white/70 bg-black/25'"
                    >
                        <svg v-if="chosen.has(item.id)" width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m3.5 8.5 3 3 6-7" />
                        </svg>
                    </span>

                    <span class="absolute inset-x-0 bottom-0 flex items-end justify-between gap-2 bg-gradient-to-t from-black/70 to-transparent px-2 pb-2 pt-6">
                        <span class="min-w-0 truncate text-left text-[11px] text-white">{{ item.original_name }}</span>
                        <span
                            v-if="ratioLabel(item)"
                            class="shrink-0 rounded bg-black/70 px-1.5 py-0.5 text-[10px] text-white"
                        >{{ ratioLabel(item) }}</span>
                    </span>
                </button>
              </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="flex justify-end gap-2 border-t border-neutral-200 px-5 py-3.5">
            <button
                type="button"
                class="rounded-md border border-neutral-300 bg-white px-4 py-1.5 text-[12.5px] font-medium text-neutral-800 transition hover:bg-neutral-50"
                @click="emit('close')"
            >Hủy</button>

            <button
                type="button"
                class="rounded-md bg-neutral-900 px-4 py-1.5 text-[12.5px] font-medium text-white transition hover:bg-neutral-800"
                @click="emit('confirm', [...chosen.values()])"
            >Xác nhận</button>
        </div>
    </div>
</template>
