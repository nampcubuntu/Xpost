<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
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

const menuFor = ref(null);
const busy = ref(null);
const flash = ref(null);

/*
 * What this team has spent at X today, against its ceiling.
 *
 * Shown here rather than on a settings page, because this is where the money is
 * spent and a settings page is somewhere nobody opens until after the invoice.
 * A null budget means no ceiling, which is the default.
 */
const budget = ref({ spent_usd: 0, budget_usd: null, remaining_usd: null });
const editingBudget = ref(false);
const budgetInput = ref('');

const budgetPercent = computed(() => (budget.value.budget_usd
    ? Math.min(100, Math.round((budget.value.spent_usd / budget.value.budget_usd) * 100))
    : 0));

function startEditBudget() {
    budgetInput.value = budget.value.budget_usd ?? '';
    editingBudget.value = true;
}

async function saveBudget() {
    busy.value = 'budget';

    try {
        const value = String(budgetInput.value).trim();
        const { data } = await api.put('publishing/budget', {
            // Empty clears it: no ceiling has to stay reachable, or the first
            // number typed can never be undone.
            daily_budget_usd: value === '' ? null : Number(value),
        });

        budget.value = data.data;
        editingBudget.value = false;
        flash.value = {
            type: 'ok',
            message: data.data.budget_usd === null
                ? 'Đã bỏ trần chi tiêu.'
                : `Trần chi tiêu: $${data.data.budget_usd}/ngày.`,
        };
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không lưu được.' };
    } finally {
        busy.value = null;
    }
}

/**
 * X refuses every account at once when the balance is spent, so publishing
 * stops as a whole rather than ten thousand posts failing one at a time.
 * Nothing clears this on its own -- Xpost cannot read the balance, and X
 * exposes no endpoint that would.
 */
const halt = ref(null);

async function resumePublishing() {
    busy.value = 'halt';

    try {
        await api.post('publishing/resume');
        flash.value = { type: 'ok', message: 'Đã tiếp tục đăng. Bài đang chờ sẽ chạy ở lượt kế tiếp.' };
        await load();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không tiếp tục được.' };
    } finally {
        busy.value = null;
    }
}

/** Only work that has not gone out yet can be called back. */
const canCancel = (post) => ['scheduled', 'publishing', 'draft'].includes(post.status);

/** Matches PostStatus::isEditable() -- the API refuses anything further along. */
const canEdit = (post) => ['draft', 'scheduled'].includes(post.status);

/** Close the open row menu on any click that is not inside it. */
function closeMenus() {
    menuFor.value = null;
}

onMounted(() => document.addEventListener('click', closeMenus));
onUnmounted(() => document.removeEventListener('click', closeMenus));

async function act(post, request, describe) {
    busy.value = post.id;

    try {
        flash.value = { type: 'ok', message: describe(await request()) };
        await load();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không thực hiện được.' };
    } finally {
        busy.value = null;
        menuFor.value = null;
    }
}

function retryFailed(post) {
    // The targets go back to pending; the scheduler picks them up on its next
    // pass rather than anything being published from here.
    act(post, () => api.post(`posts/${post.id}/retry-failed`),
        ({ data }) => `Đã đưa ${data.requeued} bài lỗi vào hàng đợi.`);
}

function cancelPost(post) {
    act(post, () => api.post(`posts/${post.id}/cancel`),
        ({ data }) => `Đã huỷ ${data.cancelled} bài chưa gửi.`);
}

function destroyPost(post) {
    /*
     * Confirmed because it cannot be undone here and, for anything already
     * published, does not undo what is on X either -- the row goes, the tweets
     * stay. The menu says so; this is the last chance to read it.
     */
    const published = post.progress?.published ?? 0;

    const warning = published
        ? `\n\n${published} bài đã đăng lên X sẽ KHÔNG bị xoá khỏi X.`
        : '';

    if (! window.confirm(`Xoá "${post.name || 'bài này'}" khỏi Xpost?${warning}`)) {
        return;
    }

    act(post, () => api.delete(`posts/${post.id}`), () => 'Đã xoá bài.');
}

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
        halt.value = data.halt ?? null;
        budget.value = data.budget ?? budget.value;
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
 * Thumbnails are a fixed box, deliberately not the media's own shape.
 *
 * They used to take each file's aspect ratio, clamped -- which still let a 9:16
 * clip stand four times taller than a 16:9 one and drag its whole row with it.
 * A list is scanned down the left edge, and rows that change height every time
 * the creative changes orientation are much harder to read than a slightly
 * cropped preview. Clamping only softened that; it did not fix it.
 */

/**
 * What the corner of the thumbnail says: duration, shape, and how many more
 * files there are.
 *
 * The shape used to be visible in the box itself. Now that every box is the
 * same, a vertical clip cropped to 16:9 looks like a landscape one -- so the
 * orientation has to be written down, or the preview quietly misleads.
 */
function mediaBadge(post) {
    const media = post.media?.[0];

    if (! media) {
        return null;
    }

    const parts = [];

    if (isVideo(media) && media.duration_ms) {
        parts.push(duration(media.duration_ms));
    }

    if (media.width && media.height && media.height > media.width) {
        parts.push('dọc');
    }

    if (post.media.length > 1) {
        parts.push(`+${post.media.length - 1}`);
    }

    return parts.length ? parts.join(' · ') : null;
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

            <div class="flex items-center gap-3">
                <!--
                    Today's spend, next to the button that creates more of it.
                    A ceiling nobody chose would be worse than none, so the
                    default reads as an amount rather than a limit.
                -->
                <div class="text-right text-[13px]">
                    <p class="text-neutral-500">
                        Hôm nay
                        <span class="font-medium text-neutral-900">${{ budget.spent_usd.toFixed(2) }}</span>
                        <template v-if="budget.budget_usd !== null">
                            / ${{ budget.budget_usd.toFixed(2) }}
                        </template>
                    </p>

                    <div v-if="budget.budget_usd !== null" class="mt-1 h-1 w-32 overflow-hidden rounded-full bg-neutral-200">
                        <div
                            class="h-full rounded-full"
                            :class="budgetPercent >= 100 ? 'bg-red-600' : budgetPercent >= 80 ? 'bg-amber-500' : 'bg-neutral-900'"
                            :style="{ width: `${budgetPercent}%` }"
                        ></div>
                    </div>

                    <form v-if="editingBudget" class="mt-1 flex items-center gap-1" @submit.prevent="saveBudget">
                        <span class="text-neutral-500">$</span>
                        <input
                            v-model="budgetInput"
                            type="number"
                            step="0.01"
                            min="0"
                            class="xp-field w-24 py-1 text-right text-[12px]"
                            placeholder="không giới hạn"
                        >
                        <button class="text-[12px] text-blue-600 hover:underline" :disabled="busy === 'budget'">Lưu</button>
                        <button type="button" class="text-[12px] text-neutral-500 hover:underline" @click="editingBudget = false">Huỷ</button>
                    </form>
                    <button v-else class="mt-0.5 text-[11px] text-blue-600 hover:underline" @click="startEditBudget">
                        {{ budget.budget_usd === null ? 'Đặt trần chi tiêu' : 'Sửa trần' }}
                    </button>
                </div>

                <RouterLink :to="{ name: 'composer' }" class="xp-btn-primary">
                    <NavIcon name="composer" :size="14" />
                    Soạn bài mới
                </RouterLink>
            </div>
        </div>

        <!--
            Without this the failure is invisible: posts stay at "Đã lên lịch
            0/N" and every other part of the stack reports healthy.
        -->
        <div
            v-if="flash"
            class="mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-[13px]"
            :class="flash.type === 'error'
                ? 'border-red-200 bg-red-50 text-red-800'
                : 'border-green-200 bg-green-50 text-green-800'"
        >
            <span>{{ flash.message }}</span>
            <button class="text-current opacity-60 hover:opacity-100" @click="flash = null">✕</button>
        </div>

        <!--
            Louder than the scheduler warning, because nothing is going out at
            all and no amount of waiting fixes it.
        -->
        <div
            v-if="halt"
            class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-[13px] text-red-900"
        >
            <strong class="font-semibold">Đã dừng đăng — X từ chối vì hết số dư.</strong>
            Bài đang chờ vẫn giữ nguyên, không bài nào bị đánh hỏng. Nạp thêm credit ở
            developer.x.com rồi bấm Tiếp tục.
            <p class="mt-1 text-xs opacity-80">X trả lời: {{ halt.reason }}</p>
            <button
                class="mt-2 rounded-md border border-red-300 bg-white px-3 py-1.5 text-[13px] font-medium hover:bg-red-100 disabled:opacity-50"
                :disabled="busy === 'halt'"
                @click="resumePublishing"
            >
                Tôi đã nạp — tiếp tục đăng
            </button>
        </div>

        <!--
            The ceiling doing its job. Distinct from the out-of-credit halt
            above: that one is X refusing everyone, this one is a limit this
            team chose, and it clears by itself at midnight.
        -->
        <div
            v-if="budget.budget_usd !== null && budget.remaining_usd === 0"
            class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900"
        >
            <strong class="font-semibold">Đã hết trần chi tiêu hôm nay (${{ budget.budget_usd.toFixed(2) }}).</strong>
            Bài còn lại được hoãn, không bài nào bị đánh hỏng — nửa đêm sẽ tự chạy tiếp.
            Cần đăng ngay thì nâng trần ở góc trên.
        </div>

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
                            <th class="w-12 px-4 py-3"><span class="sr-only">Thao tác</span></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-neutral-100">
                        <tr v-if="loading">
                            <td colspan="7" class="px-4 py-10 text-center text-neutral-500">Đang tải…</td>
                        </tr>

                        <tr v-else-if="!posts.length">
                            <td colspan="7" class="px-4 py-10 text-center text-neutral-500">Chưa có bài đăng nào.</td>
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
                                            One fixed box for every row, whatever the creative's
                                            shape. object-cover fills it from the centre, which
                                            is where the subject of a vertical clip almost always
                                            is. A <video> with preload="metadata" paints its
                                            first frame, saving the server generating posters.
                                        -->
                                        <div
                                            v-if="post.media?.[0]"
                                            class="relative mt-2 aspect-video w-32 shrink-0 overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100"
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
                                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-black/55">
                                                    <svg viewBox="0 0 16 16" class="h-2.5 w-2.5 fill-white">
                                                        <path d="M5 3.5v9l7.5-4.5z" />
                                                    </svg>
                                                </span>
                                            </span>

                                            <!--
                                                Orientation is worth stating now that the box no
                                                longer shows it, and the count of extra files
                                                rides along rather than costing another line.
                                            -->
                                            <span
                                                v-if="mediaBadge(post)"
                                                class="absolute bottom-1 left-1 rounded bg-black/65 px-1 text-[10px] font-medium text-white"
                                            >{{ mediaBadge(post) }}</span>
                                        </div>
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

                            <!--
                                Everything here already existed on the API and had
                                no way to be reached: a failed post could not be
                                retried and a finished one could not be cleared.
                            -->
                            <td class="relative px-4 py-4 text-right">
                                <button
                                    class="rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700"
                                    :aria-expanded="menuFor === post.id"
                                    aria-haspopup="menu"
                                    @click.stop="menuFor = menuFor === post.id ? null : post.id"
                                >
                                    <svg viewBox="0 0 16 16" class="h-4 w-4 fill-current">
                                        <circle cx="8" cy="3" r="1.4" />
                                        <circle cx="8" cy="8" r="1.4" />
                                        <circle cx="8" cy="13" r="1.4" />
                                    </svg>
                                </button>

                                <div
                                    v-if="menuFor === post.id"
                                    class="absolute right-4 top-12 z-20 w-56 overflow-hidden rounded-lg border border-neutral-200 bg-white py-1 text-left shadow-lg"
                                    role="menu"
                                    @click.stop
                                >
                                    <button
                                        v-if="post.progress?.failed"
                                        class="block w-full px-3 py-2 text-[13px] hover:bg-neutral-50 disabled:opacity-50"
                                        :disabled="busy === post.id"
                                        role="menuitem"
                                        @click="retryFailed(post)"
                                    >
                                        Đăng lại {{ post.progress.failed }} bài lỗi
                                    </button>

                                    <button
                                        v-if="canCancel(post)"
                                        class="block w-full px-3 py-2 text-[13px] hover:bg-neutral-50 disabled:opacity-50"
                                        :disabled="busy === post.id"
                                        role="menuitem"
                                        @click="cancelPost(post)"
                                    >
                                        Huỷ những bài chưa gửi
                                    </button>

                                    <!--
                                        Only a draft or a scheduled post can be
                                        edited -- once it has started going out,
                                        changing it would mean two versions of the
                                        same campaign on different accounts.
                                    -->
                                    <RouterLink
                                        v-if="canEdit(post)"
                                        :to="{ name: 'composer.edit', params: { id: String(post.id) } }"
                                        class="block w-full px-3 py-2 text-[13px] hover:bg-neutral-50"
                                        role="menuitem"
                                    >
                                        Sửa bài
                                    </RouterLink>

                                    <div class="my-1 border-t border-neutral-100"></div>

                                    <button
                                        class="block w-full px-3 py-2 text-left text-[13px] text-red-600 hover:bg-red-50 disabled:opacity-50"
                                        :disabled="busy === post.id"
                                        role="menuitem"
                                        @click="destroyPost(post)"
                                    >
                                        Xoá khỏi Xpost
                                    </button>

                                    <!--
                                        Deleting here does not reach X. Saying so in
                                        the menu is cheaper than letting someone find
                                        out from their own timeline.
                                    -->
                                    <p
                                        v-if="post.progress?.published"
                                        class="px-3 pb-2 pt-1 text-[11px] leading-snug text-neutral-500"
                                    >
                                        {{ post.progress.published }} bài đã lên X sẽ vẫn còn trên đó.
                                    </p>
                                </div>
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
