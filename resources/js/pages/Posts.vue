<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../lib/api';
import NavIcon from '../components/NavIcon.vue';

const posts = ref([]);
const meta = ref(null);
const loading = ref(true);
const status = ref('');
const type = ref('');
const search = ref('');
const page = ref(1);
const copied = ref(null);

const statuses = [
    { value: '', label: 'Tất cả trạng thái' },
    { value: 'draft', label: 'Nháp' },
    { value: 'scheduled', label: 'Đã lên lịch' },
    { value: 'publishing', label: 'Đang đăng' },
    { value: 'published', label: 'Đã đăng' },
    { value: 'partially_failed', label: 'Đăng một phần' },
    { value: 'failed', label: 'Thất bại' },
];

/* Mirrors how Ads Manager splits its Posts list. */
const types = [
    { value: '', label: 'Tất cả' },
    { value: 'organic', label: 'Organic' },
    { value: 'promoted', label: 'Promoted' },
];

const badgeClass = {
    draft: 'bg-neutral-100 text-neutral-700',
    scheduled: 'bg-blue-50 text-blue-700',
    publishing: 'bg-amber-50 text-amber-700',
    published: 'bg-green-50 text-green-700',
    partially_failed: 'bg-orange-50 text-orange-700',
    failed: 'bg-red-50 text-red-700',
    cancelled: 'bg-neutral-100 text-neutral-500',
};

/** How many filters are narrowing the list, for the badge on the button. */
const activeFilters = computed(() => [status.value, type.value, search.value].filter(Boolean).length);

/**
 * The scheduler is a separate process from the queue workers, and when it
 * stops, everything else stays up and healthy while no post is ever
 * dispatched. The only symptom is scheduled posts sitting at 0/N, which says
 * nothing about the cause -- so say it here, where they are looked at.
 */
const schedulerLastRunAt = ref(null);
const schedulerDown = computed(() => schedulerLastRunAt.value === null);

async function load() {
    loading.value = true;

    try {
        const { data } = await api.get('posts', {
            params: {
                status: status.value || undefined,
                type: type.value || undefined,
                search: search.value || undefined,
                page: page.value,
            },
        });

        posts.value = data.data;
        meta.value = data.meta;
        // Absent means the scheduler has not run in the last few minutes.
        schedulerLastRunAt.value = data.scheduler_last_run_at ?? null;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

let timer;
watch([status, type, search], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(page, load);

async function copyId(id) {
    try {
        await navigator.clipboard.writeText(id);
        copied.value = id;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        // Clipboard is blocked outside a secure context; the id is on screen anyway.
    }
}

const shortDate = (value) => (value
    ? new Date(value).toLocaleDateString('vi-VN', { day: 'numeric', month: 'short' })
    : '—');

const fullDate = (value) => (value
    ? new Date(value).toLocaleDateString('vi-VN', { day: 'numeric', month: 'short', year: 'numeric' })
    : null);

const time = (value) => (value
    ? new Date(value).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })
    : null);

/** The date a row should show: when it went out, else when it is due. */
const rowDate = (post) => post.published_at ?? post.sample?.published_at ?? post.scheduled_at;

const isVideo = (media) => media.mime?.startsWith('video/');

/**
 * The media's own shape, clamped.
 *
 * Left unclamped, a tall portrait photo would stretch its row twice as high as
 * every other one and the table would look broken; a very wide banner would
 * shrink to a strip. 16:9 down to 4:5 covers what people actually post.
 */
function aspectRatio(media) {
    if (! media.width || ! media.height) {
        return '16 / 9';
    }

    return String(Math.min(1.78, Math.max(0.8, media.width / media.height)));
}

const duration = (ms) => {
    const total = Math.round(ms / 1000);

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
};

const destinationHost = (url) => {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
};
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <!-- Header -->
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Bài đăng</h1>
                <p class="mt-1 text-[13px] text-neutral-500">
                    Soạn, lên lịch và theo dõi các bài đứng sau creative của bạn.
                </p>
            </div>

            <RouterLink :to="{ name: 'composer' }" class="xp-btn-primary">
                <NavIcon name="composer" :size="14" />
                Soạn bài mới
            </RouterLink>
        </div>

        <!--
            Without this the failure is invisible: posts stay at "Đã lên lịch
            0/N" and every other part of the stack reports healthy.
        -->
        <div
            v-if="schedulerDown"
            class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900"
        >
            <strong class="font-semibold">Scheduler không chạy.</strong>
            Bài đã tới giờ sẽ nằm ở “Đã lên lịch” và không bao giờ đăng. Horizon chạy
            job trong hàng đợi, nhưng lệnh đẩy bài vào hàng đợi nằm ở scheduler —
            hai tiến trình khác nhau.
            <span class="mt-1 block">
                Chạy <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">php artisan schedule:work</code>,
                hoặc mở lại bằng <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">dev.bat</code>.
            </span>
        </div>

        <!-- Toolbar -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="relative min-w-0 flex-1">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400">
                    <NavIcon name="search" :size="15" />
                </span>
                <input v-model="search" type="search" class="xp-field pl-9" placeholder="Tìm kiếm">
            </div>

            <span
                class="inline-flex items-center gap-1.5 rounded-md border border-neutral-300 px-3 py-2 text-[13px] text-neutral-600"
            >
                Bộ lọc
                <span
                    v-if="activeFilters"
                    class="rounded bg-neutral-900 px-1.5 text-[11px] font-medium text-white"
                >{{ activeFilters }}</span>
            </span>

            <select v-model="status" class="xp-field max-w-[190px]">
                <option v-for="option in statuses" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>

            <select v-model="type" class="xp-field max-w-[150px]">
                <option v-for="option in types" :key="option.value" :value="option.value">
                    Posts: {{ option.label }}
                </option>
            </select>
        </div>

        <!-- Table -->
        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left text-[13px]">
                    <thead class="border-b border-neutral-200 text-xs text-neutral-500">
                        <tr>
                            <th class="px-4 py-3 font-normal">Tên bài</th>
                            <th class="px-4 py-3 font-normal">Đăng</th>
                            <th class="px-4 py-3 font-normal">Post ID</th>
                            <th class="px-4 py-3 font-normal">Đích đến</th>
                            <th class="px-4 py-3 font-normal">Trạng thái</th>
                            <th class="px-4 py-3 font-normal">Thời điểm</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-neutral-100">
                        <tr v-if="loading">
                            <td colspan="6" class="px-4 py-10 text-center text-neutral-500">Đang tải…</td>
                        </tr>

                        <tr v-else-if="!posts.length">
                            <td colspan="6" class="px-4 py-10 text-center text-neutral-500">Chưa có bài đăng nào.</td>
                        </tr>

                        <tr v-for="post in posts" :key="post.id" class="align-top hover:bg-neutral-50">
                            <!-- Tên bài -->
                            <td class="max-w-[220px] px-4 py-4">
                                <RouterLink
                                    :to="{ name: 'posts.show', params: { id: post.id } }"
                                    class="text-[13px] leading-snug text-neutral-900 hover:underline"
                                >{{ post.name || 'Không tiêu đề' }}</RouterLink>
                            </td>

                            <!-- Đăng: ai, khi nào, nội dung, ảnh -->
                            <td class="px-4 py-4">
                                <div class="flex items-start gap-2.5">
                                    <img
                                        v-if="post.sample?.account?.avatar_url"
                                        :src="post.sample.account.avatar_url"
                                        class="mt-0.5 h-8 w-8 shrink-0 rounded-full object-cover"
                                        alt=""
                                    >
                                    <div v-else class="mt-0.5 h-8 w-8 shrink-0 rounded-full bg-neutral-200"></div>

                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-x-1.5 text-[13px]">
                                            <template v-if="post.sample?.account">
                                                <span class="font-semibold">{{ post.sample.account.name }}</span>
                                                <span class="text-neutral-500">@{{ post.sample.account.username }}</span>
                                            </template>
                                            <span v-else class="text-neutral-500">Chưa fan-out</span>

                                            <span class="text-neutral-400">· {{ shortDate(rowDate(post)) }}</span>

                                            <a
                                                v-if="post.sample?.permalink"
                                                :href="post.sample.permalink"
                                                target="_blank"
                                                rel="noopener"
                                                class="text-neutral-400 hover:text-neutral-900"
                                                title="Mở trên X"
                                            >↗</a>
                                        </p>

                                        <!--
                                            A post here fans out to many accounts, unlike the
                                            reference where one post is one account. Saying so
                                            keeps the row from reading as a single send.
                                        -->
                                        <p v-if="post.progress.total > 1" class="text-xs text-neutral-500">
                                            và {{ (post.progress.total - 1).toLocaleString('vi-VN') }} tài khoản khác
                                        </p>

                                        <p class="mt-1 line-clamp-2 max-w-[320px] text-[13px] text-neutral-800">
                                            {{ post.body }}
                                        </p>

                                        <!--
                                            The box takes the media's own aspect ratio rather
                                            than a fixed crop, so a landscape video and a
                                            squarer photo look like what they are. A <video>
                                            with preload="metadata" paints its first frame,
                                            which saves the server generating poster images.
                                        -->
                                        <div
                                            v-if="post.media?.[0]"
                                            class="relative mt-2 w-40 overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100"
                                            :style="{ aspectRatio: aspectRatio(post.media[0]) }"
                                        >
                                            <video
                                                v-if="isVideo(post.media[0])"
                                                :src="post.media[0].url"
                                                class="h-full w-full bg-black object-cover"
                                                preload="metadata"
                                                muted
                                            ></video>
                                            <img
                                                v-else
                                                :src="post.media[0].url"
                                                class="h-full w-full object-cover"
                                                alt=""
                                            >

                                            <span
                                                v-if="isVideo(post.media[0])"
                                                class="absolute inset-0 flex items-center justify-center"
                                            >
                                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-black/55">
                                                    <svg viewBox="0 0 16 16" class="h-3 w-3 fill-white">
                                                        <path d="M5 3.5v9l7.5-4.5z" />
                                                    </svg>
                                                </span>
                                            </span>

                                            <span
                                                v-if="isVideo(post.media[0]) && post.media[0].duration_ms"
                                                class="absolute bottom-1 left-1 rounded bg-black/65 px-1 text-[10px] font-medium text-white"
                                            >{{ duration(post.media[0].duration_ms) }}</span>
                                        </div>

                                        <p v-if="post.media?.length > 1" class="mt-1 text-xs text-neutral-500">
                                            +{{ post.media.length - 1 }} tệp nữa
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <!-- Post ID -->
                            <td class="px-4 py-4">
                                <button
                                    v-if="post.sample?.x_tweet_id"
                                    class="inline-flex items-center gap-1.5 font-mono text-xs text-neutral-700 hover:text-neutral-900"
                                    :title="copied === post.sample.x_tweet_id ? 'Đã sao chép' : 'Sao chép'"
                                    @click="copyId(post.sample.x_tweet_id)"
                                >
                                    <NavIcon :name="copied === post.sample.x_tweet_id ? 'check' : 'copy'" :size="13" />
                                    {{ post.sample.x_tweet_id }}
                                </button>
                                <span v-else class="text-neutral-400">—</span>
                            </td>

                            <!-- Đích đến -->
                            <td class="px-4 py-4">
                                <template v-if="post.destination_type === 'website' && post.website_url">
                                    <p class="flex items-center gap-1.5 font-medium text-neutral-900">
                                        <NavIcon name="globe" :size="13" class="text-neutral-400" />
                                        Website
                                    </p>
                                    <p v-if="post.headline" class="text-neutral-500">{{ post.headline }}</p>
                                    <a
                                        :href="post.website_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-neutral-500 hover:underline"
                                    >{{ destinationHost(post.website_url) }}</a>
                                </template>
                                <span v-else class="text-neutral-400">—</span>
                            </td>

                            <!-- Trạng thái -->
                            <td class="px-4 py-4">
                                <span
                                    class="inline-block rounded px-2 py-0.5 text-xs font-medium"
                                    :class="badgeClass[post.status]"
                                >{{ post.status_label }}</span>

                                <p class="mt-1 text-xs" :class="post.promoted_only ? 'text-neutral-500' : 'text-neutral-400'">
                                    {{ post.promoted_only ? 'Promoted' : 'Organic' }}
                                </p>

                                <p v-if="post.progress.total" class="mt-1 text-xs text-neutral-500">
                                    {{ post.progress.published }}/{{ post.progress.total }}
                                    <span v-if="post.progress.failed" class="text-red-600">· {{ post.progress.failed }} lỗi</span>
                                </p>
                            </td>

                            <!-- Thời điểm -->
                            <td class="whitespace-nowrap px-4 py-4 text-neutral-600">
                                <template v-if="fullDate(rowDate(post))">
                                    <p>{{ fullDate(rowDate(post)) }}</p>
                                    <p class="text-neutral-400">{{ time(rowDate(post)) }}</p>
                                </template>
                                <span v-else class="text-neutral-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="meta && meta.last_page > 1" class="mt-4 flex items-center gap-2">
            <button class="xp-btn-ghost" :disabled="page <= 1" @click="page--">Trước</button>
            <span class="text-[13px] text-neutral-600">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
            <button class="xp-btn-ghost" :disabled="page >= meta.last_page" @click="page++">Sau</button>
        </div>
    </div>
</template>
