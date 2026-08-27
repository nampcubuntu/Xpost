<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useComposerStore } from '../stores/composer';
import { useAuthStore } from '../stores/auth';
import { validationErrors } from '../lib/api';
import PhonePreview from '../components/PhonePreview.vue';
import AudiencePicker from '../components/AudiencePicker.vue';
import SchedulePicker from '../components/SchedulePicker.vue';
import NavIcon from '../components/NavIcon.vue';
import MediaPicker from '../components/MediaPicker.vue';

const props = defineProps({ id: { type: String, default: null } });

const composer = useComposerStore();
const auth = useAuthStore();
const router = useRouter();

const fileInput = ref(null);
const postMenuOpen = ref(false);
const replyMenuOpen = ref(false);
const flash = ref(null);
const advancedOpen = ref(false);

// The picker replaces the form rather than opening over it, matching the
// reference's back-arrow flow instead of a modal.
const mediaPickerOpen = ref(false);

const post = computed(() => composer.post);

/**
 * How many accounts this post is heading for.
 *
 * Used to decide whether identical text is worth warning about: two accounts
 * posting the same sentence is normal, two thousand is the signal every
 * platform looks for.
 */
const estimatedAccounts = computed(() => composer.estimate?.accounts ?? 0);

const counterClass = computed(() => {
    if (composer.measure.remaining < 0) return 'text-red-600 font-semibold';
    if (composer.measure.remaining <= 20) return 'text-amber-600';

    return 'text-neutral-500';
});

const replyLabel = computed(() =>
    composer.options?.reply_settings?.find((o) => o.value === post.value.reply_settings)?.label
    ?? 'Mọi người đều có thể trả lời',
);

const hasDestination = computed(() => post.value.destination_type !== 'none');
const canPublish = computed(() => composer.hasContent && !composer.overLimit && !composer.saving);

const destinations = [
    { value: 'website', title: 'Website', hint: 'Include a call to action to your website.' },
    { value: 'app', title: 'App', hint: 'Include a call to action to your app.' },
];

onMounted(async () => {
    await composer.loadOptions();

    if (props.id) {
        await composer.load(props.id);
    } else {
        composer.reset();
    }

    await Promise.all([composer.refreshMeasure(), composer.refreshEstimate()]);
});

// The counter is authoritative on the server (X weights CJK and URLs
// differently), so it is debounced rather than computed locally.
let measureTimer;
watch(
    () => [post.value.body, post.value.website_url, post.value.destination_type],
    () => {
        clearTimeout(measureTimer);
        measureTimer = setTimeout(async () => {
            await composer.refreshMeasure();
            // Samples depend on the permutation count the measure just
            // returned, so they follow it rather than racing it.
            await composer.refreshSamples();
        }, 250);
    },
);

let estimateTimer;
function scheduleEstimate() {
    clearTimeout(estimateTimer);
    estimateTimer = setTimeout(() => composer.refreshEstimate(), 400);
}

/** Clicking the selected card clears it, matching the reference's toggle. */
function pickDestination(value) {
    post.value.destination_type = post.value.destination_type === value ? 'none' : value;
}

async function onFilePicked(event) {
    for (const file of Array.from(event.target.files ?? [])) {
        try {
            await composer.attachMedia(file);
        } catch (error) {
            flash.value = { type: 'error', message: error.response?.data?.message ?? 'Tải tệp thất bại.' };
        }
    }

    event.target.value = '';
}

async function save(status) {
    flash.value = null;

    try {
        await composer.save(status);
        flash.value = { type: 'ok', message: status === 'draft' ? 'Đã lưu nháp.' : 'Đã lên lịch.' };
    } catch (error) {
        composer.errors = validationErrors(error);
        flash.value = { type: 'error', message: 'Vui lòng kiểm tra lại các trường được đánh dấu.' };
    }
}

async function publish() {
    flash.value = null;
    postMenuOpen.value = false;

    try {
        const result = await composer.publish();
        const accounts = result.preview?.accounts ?? 0;

        flash.value = {
            type: 'ok',
            message: `Đã đưa vào hàng đợi cho ${accounts.toLocaleString('vi-VN')} tài khoản.`,
        };

        router.push({ name: 'posts.show', params: { id: composer.savedPostId } });
    } catch (error) {
        composer.errors = validationErrors(error);
        flash.value = {
            type: 'error',
            message: error.response?.data?.message ?? 'Không đăng được. Kiểm tra lại biểu mẫu.',
        };
    }
}

const fieldError = (key) => composer.errors[key];

// ── Media list helpers ──────────────────────────────────────────────────────

const isVideo = (item) => (item.mime ?? '').startsWith('video/');

/*
 * Durations come from the browser rather than the server: extracting them
 * server-side means ffprobe on the upload path, and the <video> element already
 * knows the answer once its metadata loads.
 */
const durations = ref({});

function rememberDuration(id, seconds) {
    if (Number.isFinite(seconds)) {
        durations.value = { ...durations.value, [id]: seconds };
    }
}

function durationLabel(item) {
    const seconds = durations.value[item.id] ?? (item.duration_ms ? item.duration_ms / 1000 : null);

    if (!seconds) {
        return null;
    }

    const total = Math.round(seconds);

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

function uploadedOn(item) {
    if (!item.created_at) {
        return '';
    }

    return new Date(item.created_at).toLocaleDateString('vi-VN', {
        day: 'numeric', month: 'short', year: 'numeric',
    });
}
</script>

<template>
    <div class="px-5 py-5 lg:px-6 lg:py-6">
      <div class="mx-auto w-full max-w-[1280px]">
        <!-- ─── Header ──────────────────────────────────────────────────── -->
        <div class="mb-6 flex items-start justify-between">
            <h1 class="text-[20px] font-semibold tracking-tight">Composer</h1>

            <div class="relative flex">
                <button
                    type="button"
                    class="rounded-l-md bg-[#4b4b4b] px-5 py-1.5 text-[13px] font-medium text-white transition hover:bg-[#3a3a3a] disabled:opacity-50"
                    :disabled="!canPublish"
                    @click="publish"
                >{{ composer.saving ? 'Đang xử lý…' : 'Post' }}</button>

                <button
                    type="button"
                    class="rounded-r-md border-l border-white/20 bg-[#4b4b4b] px-1.5 text-white transition hover:bg-[#3a3a3a]"
                    @click="postMenuOpen = !postMenuOpen"
                >
                    <NavIcon name="chevron" :size="13" />
                </button>

                <div
                    v-if="postMenuOpen"
                    class="absolute right-0 top-full z-30 mt-1 w-44 overflow-hidden rounded-md border border-neutral-200 bg-white py-1 shadow-lg"
                >
                    <button type="button" class="block w-full px-3 py-1.5 text-left text-[13px] hover:bg-neutral-50" @click="save('draft'); postMenuOpen = false">
                        Lưu nháp
                    </button>
                    <button type="button" class="block w-full px-3 py-1.5 text-left text-[13px] hover:bg-neutral-50" @click="save('scheduled'); postMenuOpen = false">
                        Lên lịch
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="flash"
            class="mb-4 rounded-md px-3 py-2 text-[13px]"
            :class="flash.type === 'ok' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'"
        >{{ flash.message }}</div>

        <div class="flex flex-col gap-6 lg:flex-row lg:gap-9">
            <MediaPicker
                v-if="mediaPickerOpen"
                :selected="composer.media"
                :max="composer.options?.limits?.max_media_per_post ?? 4"
                :destination-type="post.destination_type"
                @close="mediaPickerOpen = false"
                @confirm="composer.setMedia($event); mediaPickerOpen = false"
            />

            <!-- ─── Form ────────────────────────────────────────────────── -->
            <div v-else class="min-w-0 flex-1 space-y-4 rounded-lg border border-neutral-200 bg-white p-5">
                <!-- Name -->
                <div>
                    <div class="flex items-baseline justify-between">
                        <label class="block text-[12.5px] font-medium leading-4 text-neutral-800" for="post-name">Name</label>
                        <span class="text-[11.5px] text-neutral-400">{{ (post.name ?? '').length }}/255</span>
                    </div>
                    <input
                        id="post-name"
                        v-model="post.name"
                        type="text"
                        maxlength="255"
                        class="mt-1.5 w-full rounded-md border border-neutral-300 px-3 py-1.5 text-[13px] leading-[17px] outline-none transition placeholder:text-neutral-400 focus:border-neutral-800"
                        placeholder="Untitled ad (optional)"
                    >
                </div>

                <!-- Posting identity. At this scale it is a pool, not one handle,
                     so the row shows the team and how many accounts will carry it. -->
                <div class="flex items-center gap-2 rounded-md border border-neutral-200 px-3 py-2">
                    <span class="grid h-5 w-5 shrink-0 place-items-center rounded-full bg-neutral-800 text-[10px] font-semibold text-white">
                        {{ (auth.currentTeam?.name ?? 'X').slice(0, 1).toUpperCase() }}
                    </span>
                    <span class="text-[13px] font-semibold">{{ auth.currentTeam?.name }}</span>
                    <svg class="h-[13px] w-[13px] text-[#1d9bf0]" viewBox="0 0 22 22" fill="currentColor" aria-hidden="true">
                        <path d="M20.4 11a3 3 0 0 0-1.7-2.7 3 3 0 0 0-3.6-3.6A3 3 0 0 0 11 3a3 3 0 0 0-4.1 1.7 3 3 0 0 0-3.6 3.6A3 3 0 0 0 1.6 11a3 3 0 0 0 1.7 4.1 3 3 0 0 0 3.6 3.6A3 3 0 0 0 11 19a3 3 0 0 0 4.1-1.7 3 3 0 0 0 3.6-3.6A3 3 0 0 0 20.4 11Zm-9.9 4.1L6.8 11.4l1.5-1.5 2.2 2.2 4.4-4.4 1.5 1.5Z" />
                    </svg>
                    <span class="text-[13px] text-neutral-500">
                        {{ (composer.estimate?.accounts ?? 0).toLocaleString('vi-VN') }} tài khoản X
                    </span>
                </div>

                <!-- Body -->
                <div>
                    <label class="block text-[12.5px] font-medium leading-4 text-neutral-800" for="post-body">Đăng văn bản</label>
                    <textarea
                        id="post-body"
                        v-model="post.body"
                        rows="4"
                        class="mt-1.5 min-h-[124px] w-full resize-y rounded-md border px-[9px] py-[9px] text-[13px] leading-[18px] outline-none transition placeholder:text-neutral-400 focus:border-neutral-800"
                        :class="fieldError('body') ? 'border-red-500' : 'border-neutral-300'"
                        placeholder="What's happening?"
                    ></textarea>

                    <div class="mt-1 flex items-center justify-between">
                        <div class="relative">
                            <button
                                type="button"
                                class="flex items-center gap-1 text-[12.5px] text-neutral-600 transition hover:text-neutral-900"
                                @click="replyMenuOpen = !replyMenuOpen"
                            >
                                <NavIcon name="globe" :size="13" class="text-neutral-500" />
                                {{ replyLabel }}
                                <NavIcon name="chevron" :size="12" class="text-neutral-400" />
                            </button>

                            <div
                                v-if="replyMenuOpen"
                                class="absolute bottom-full left-0 z-30 mb-1 w-60 overflow-hidden rounded-md border border-neutral-200 bg-white py-1 shadow-lg"
                            >
                                <button
                                    v-for="option in composer.options?.reply_settings ?? []"
                                    :key="option.value"
                                    type="button"
                                    class="block w-full px-3 py-1.5 text-left text-[12.5px] hover:bg-neutral-50"
                                    :class="post.reply_settings === option.value ? 'font-medium' : ''"
                                    @click="post.reply_settings = option.value; replyMenuOpen = false"
                                >{{ option.label }}</button>
                            </div>
                        </div>

                        <span class="text-[12.5px]" :class="counterClass">{{ composer.measure.remaining }}</span>
                    </div>

                    <p v-if="fieldError('body')" class="mt-1 text-[11.5px] text-red-600">{{ fieldError('body') }}</p>
                    <p v-else-if="composer.usesSpintax" class="mt-1 text-[11.5px] text-neutral-500">
                        Spintax đang bật — {{ composer.measure.permutations.toLocaleString('vi-VN') }} biến thể nội dung.
                    </p>

                    <!--
                        A count is a number, and nobody turns a feature on for a
                        number. Real posts side by side are what show whether the
                        spin reads naturally, and are the only way to catch a
                        bracket in the wrong place before a thousand accounts
                        publish it.
                    -->
                    <div v-if="composer.samples.length > 1" class="mt-2 rounded-md border border-neutral-200 bg-neutral-50 p-2.5">
                        <p class="mb-1.5 text-[11px] font-medium uppercase tracking-wide text-neutral-500">
                            Ví dụ tài khoản sẽ đăng
                        </p>
                        <ul class="space-y-1">
                            <li
                                v-for="(sample, index) in composer.samples"
                                :key="index"
                                class="truncate text-[12px] text-neutral-700"
                                :title="sample"
                            >
                                <span class="mr-1.5 text-neutral-400">{{ index + 1 }}.</span>{{ sample }}
                            </li>
                        </ul>
                    </div>

                    <!--
                        Identical text across a large audience is the strongest
                        signal a platform looks for, and the default is `same`
                        -- so the nudge appears where the text is written, not
                        buried next to the strategy dropdown.
                    -->
                    <p
                        v-else-if="!composer.usesSpintax && post.body && estimatedAccounts > 20"
                        class="mt-2 rounded-md bg-amber-50 px-2.5 py-1.5 text-[11.5px] text-amber-900"
                    >
                        {{ estimatedAccounts.toLocaleString('vi-VN') }} tài khoản sẽ đăng nội dung
                        <strong>giống hệt nhau</strong>. Dùng <code class="font-mono">{'{'}xin chào|chào bạn{'}'}</code>
                        để mỗi tài khoản một biến thể.
                    </p>
                </div>

                <!-- Destination -->
                <div>
                    <p class="mb-1.5 block text-[12.5px] font-medium leading-4 text-neutral-800">Destination</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <button
                            v-for="option in destinations"
                            :key="option.value"
                            type="button"
                            class="relative rounded-md border px-3 py-2 text-left transition"
                            :class="post.destination_type === option.value
                                ? 'border-neutral-900 ring-1 ring-neutral-900'
                                : 'border-neutral-200 hover:border-neutral-300'"
                            @click="pickDestination(option.value)"
                        >
                            <span class="block text-[12.5px] font-medium leading-[17px]">{{ option.title }}</span>
                            <span class="block text-[11.5px] leading-[16px] text-neutral-500">{{ option.hint }}</span>

                            <svg v-if="post.destination_type === option.value" class="absolute right-2.5 top-2.5 h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10 0a10 10 0 100 20 10 10 0 000-20zm4.7 7.7l-5.4 5.4a1 1 0 01-1.4 0L5.3 10.5a1 1 0 011.4-1.4l1.9 1.9 4.7-4.7a1 1 0 011.4 1.4z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Destination fields appear only once a destination is chosen -->
                <template v-if="post.destination_type === 'website'">
                    <div>
                        <label class="block text-[12.5px] font-medium leading-4 text-neutral-800" for="post-url">
                            Website URL <span class="text-red-500">*</span>
                        </label>
                        <div
                            class="mt-1 flex overflow-hidden rounded-md border"
                            :class="fieldError('website_url') ? 'border-red-500' : 'border-neutral-300'"
                        >
                            <span class="border-r border-neutral-200 bg-neutral-50 px-3 py-1.5 text-[13px] leading-[17px] text-neutral-500">https://</span>
                            <input id="post-url" v-model="post.website_url" type="text" class="min-w-0 flex-1 px-3 py-1.5 text-[13px] leading-[17px] outline-none" placeholder="example.com">
                        </div>
                        <p v-if="fieldError('website_url')" class="mt-1 text-[11.5px] text-red-600">{{ fieldError('website_url') }}</p>
                    </div>

                    <div>
                        <label class="block text-[12.5px] font-medium leading-4 text-neutral-800" for="post-headline">
                            Headline <span class="font-normal text-neutral-500">(optional)</span>
                        </label>
                        <input
                            id="post-headline"
                            v-model="post.headline"
                            type="text"
                            maxlength="70"
                            class="mt-1.5 w-full rounded-md border border-neutral-300 px-3 py-1.5 text-[13px] leading-[17px] outline-none transition placeholder:text-neutral-400 focus:border-neutral-800"
                            placeholder="Enter a headline"
                        >
                    </div>
                </template>

                <div v-else-if="post.destination_type === 'app'">
                    <label class="block text-[12.5px] font-medium leading-4 text-neutral-800" for="post-app">
                        App store URL <span class="text-red-500">*</span>
                    </label>
                    <input
                        id="post-app"
                        v-model="post.app_store_url"
                        type="url"
                        class="mt-1.5 w-full rounded-md border border-neutral-300 px-3 py-1.5 text-[13px] leading-[17px] outline-none transition placeholder:text-neutral-400 focus:border-neutral-800"
                        placeholder="https://apps.apple.com/..."
                    >
                </div>

                <!-- Media -->
                <div class="pt-2">
                    <p v-if="hasDestination" class="mb-1.5 block text-[12.5px] font-medium leading-4 text-neutral-800">
                        Phương tiện <span class="text-red-500">*</span>
                        <span class="font-normal text-neutral-500">Required for card ads</span>
                    </p>

                    <!--
                        Attached files list. The reference shows each upload as a
                        row -- thumbnail, name, date, Remove -- above the buttons,
                        not as a grid of bare squares below them.
                    -->
                    <div v-if="composer.media.length" class="mb-3 space-y-2">
                        <div
                            v-for="item in composer.media"
                            :key="item.id"
                            class="flex items-center gap-3 rounded-md border border-neutral-200 px-3 py-2.5"
                        >
                            <div class="relative h-14 w-[90px] shrink-0 overflow-hidden rounded bg-neutral-100">
                                <!--
                                    A <video> renders its first frame as the poster and
                                    reports duration on loadedmetadata, so no server-side
                                    ffprobe is needed just to label the thumbnail.
                                -->
                                <video
                                    v-if="isVideo(item)"
                                    :src="item.url"
                                    preload="metadata"
                                    muted
                                    playsinline
                                    class="h-full w-full object-cover"
                                    @loadedmetadata="rememberDuration(item.id, $event.target.duration)"
                                ></video>
                                <img v-else :src="item.url" alt="" class="h-full w-full object-cover">

                                <span
                                    v-if="durationLabel(item)"
                                    class="absolute bottom-1 left-1 rounded bg-black/75 px-1 py-px text-[10px] font-medium text-white"
                                >{{ durationLabel(item) }}</span>
                            </div>

                            <div class="min-w-0 flex-1">
                                <p class="truncate text-[13px] font-medium">{{ item.original_name }}</p>
                                <p class="text-[12px] text-neutral-500">{{ uploadedOn(item) }}</p>
                            </div>

                            <button
                                type="button"
                                class="shrink-0 text-[13px] text-neutral-500 transition hover:text-neutral-900"
                                @click="composer.detachMedia(item.id)"
                            >Remove</button>
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="rounded-md border border-neutral-300 bg-white px-3 py-1.5 text-[12.5px] font-medium text-neutral-800 transition hover:bg-neutral-50"
                            @click="mediaPickerOpen = true"
                        >+ Add media</button>

                        <button
                            type="button"
                            class="flex items-center gap-1.5 rounded-md bg-neutral-900 px-3 py-1.5 text-[12.5px] font-medium text-white transition hover:bg-neutral-800"
                            @click="flash = { type: 'error', message: 'Sinh nội dung bằng AI chưa bật — cần cấu hình laravel/ai (hiện còn bản 0.x). Xem README.' }"
                        >
                            <NavIcon name="sparkle" :size="12" />
                            Generate with AI
                        </button>

                        <input ref="fileInput" type="file" accept="image/*,video/*" multiple class="hidden" @change="onFilePicked">
                    </div>
                </div>

                <!-- Flags -->
                <div class="space-y-1.5 pt-2">
                    <label class="flex cursor-pointer items-center gap-2 text-[12.5px] leading-[17px]">
                        <input v-model="post.is_ai_generated" type="checkbox" class="h-[13px] w-[13px] rounded-sm accent-neutral-900">
                        Được tạo bằng AI
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-[12.5px] leading-[17px]">
                        <input v-model="post.promoted_only" type="checkbox" class="h-[13px] w-[13px] rounded-sm accent-neutral-900">
                        Promoted only
                    </label>
                </div>

                <p class="pt-1 text-[11.5px] leading-relaxed text-neutral-600">
                    <span class="mr-1.5 rounded bg-neutral-200 px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-neutral-700">NOTE</span>
                    Bài "Promoted only" vẫn là bài thật. Nếu bạn nhắc đến một người dùng X, họ vẫn nhận được thông báo
                    kèm liên kết tới bài đăng. Đối tác dữ liệu của X cũng đọc được ngay.
                    <RouterLink :to="{ name: 'brand' }" class="underline">Tìm hiểu thêm</RouterLink>
                </p>

                <!-- Xpost-specific: audience + schedule, folded away so the top of
                     the form stays identical to the reference. -->
                <div class="border-t border-neutral-200 pt-4">
                    <button type="button" class="flex w-full items-center justify-between text-left" @click="advancedOpen = !advancedOpen">
                        <span class="block text-[12.5px] font-medium leading-4 text-neutral-800">Đối tượng &amp; lịch đăng</span>
                        <span class="text-[11.5px] text-neutral-500">{{ advancedOpen ? 'Thu gọn' : 'Mở rộng' }}</span>
                    </button>

                    <div v-show="advancedOpen" class="mt-4 space-y-5">
                        <div>
                            <p class="mb-2 block text-[12.5px] font-medium leading-4 text-neutral-800">Đăng từ tài khoản nào</p>
                            <AudiencePicker v-model="post.audience" :estimate="composer.estimate" @change="scheduleEstimate" />
                        </div>

                        <div>
                            <p class="mb-2 block text-[12.5px] font-medium leading-4 text-neutral-800">Thời điểm đăng</p>
                            <SchedulePicker v-model="post" @change="scheduleEstimate" />
                        </div>

                        <div>
                            <p class="mb-2 block text-[12.5px] font-medium leading-4 text-neutral-800">Chiến lược nội dung</p>
                            <select v-model="post.variant_strategy" class="w-full rounded-md border border-neutral-300 px-3 py-1.5 text-[13px] leading-[17px] outline-none focus:border-neutral-800">
                                <option value="same">Giống nhau — mọi tài khoản đăng cùng một nội dung</option>
                                <option value="spintax">Spintax — mỗi tài khoản một biến thể từ {a|b|c}</option>
                                <option value="round_robin">Luân phiên — chia đều danh sách biến thể</option>
                                <option value="random">Ngẫu nhiên — chọn biến thể theo trọng số</option>
                                <option value="per_account">Theo tài khoản — ánh xạ 1:1 từ danh sách</option>
                            </select>
                            <p class="mt-1 text-[11.5px] text-neutral-500">
                                Hàng nghìn tài khoản đăng nội dung giống hệt nhau là dấu hiệu spam rõ nhất.
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ─── Preview ─────────────────────────────────────────────── -->
            <PhonePreview
                :body="post.body"
                :headline="post.headline"
                :website-url="post.destination_type === 'website' ? post.website_url : ''"
                :media="composer.media"
                :promoted-only="post.promoted_only"
            />
        </div>
      </div>
    </div>
</template>
