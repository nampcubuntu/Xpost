<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import api from '../lib/api';
import { listen, socketState } from '../lib/echo';
import { useAuthStore } from '../stores/auth';
import ErrorPopover from '../components/ErrorPopover.vue';

const props = defineProps({ id: { type: String, required: true } });

const auth = useAuthStore();

const post = ref(null);
const targets = ref([]);
const meta = ref(null);
const statusFilter = ref('');
const page = ref(1);
const busy = ref(false);
const loading = ref(true);
const error = ref(null);

const badgeClass = {
    pending: 'bg-neutral-100 text-neutral-700',
    queued: 'bg-blue-50 text-blue-700',
    publishing: 'bg-amber-50 text-amber-700',
    published: 'bg-green-50 text-green-700',
    failed: 'bg-red-50 text-red-700',
    cancelled: 'bg-neutral-100 text-neutral-500',
    skipped: 'bg-neutral-100 text-neutral-500',
};

/** The post's own status, which uses a different set from its targets'. */
const postBadgeClass = {
    draft: 'bg-neutral-100 text-neutral-700',
    scheduled: 'bg-blue-50 text-blue-700',
    publishing: 'bg-amber-50 text-amber-700',
    published: 'bg-green-50 text-green-700',
    partially_failed: 'bg-orange-50 text-orange-700',
    failed: 'bg-red-50 text-red-700',
    cancelled: 'bg-neutral-100 text-neutral-500',
};

/* Every status a target can hold, so the filter cannot hide rows by omission. */
const targetStatuses = [
    { value: '', label: 'Tất cả trạng thái' },
    { value: 'pending', label: 'Chờ đăng' },
    { value: 'queued', label: 'Trong hàng đợi' },
    { value: 'publishing', label: 'Đang đăng' },
    { value: 'published', label: 'Đã đăng' },
    { value: 'failed', label: 'Thất bại' },
    { value: 'skipped', label: 'Bỏ qua' },
    { value: 'cancelled', label: 'Đã huỷ' },
];

async function load() {
    try {
        const [postResponse, targetResponse] = await Promise.all([
            api.get(`posts/${props.id}`),
            api.get(`posts/${props.id}/targets`, {
                params: { status: statusFilter.value || undefined, page: page.value },
            }),
        ]);

        post.value = postResponse.data.data;
        targets.value = targetResponse.data.data;
        meta.value = targetResponse.data.meta;
        error.value = null;
    } catch (e) {
        /*
         * Said rather than left blank. The page used to render nothing at all
         * until both requests resolved, so a deleted post, another team's post
         * or a stopped server were the same white screen.
         */
        error.value = e.response?.status === 404
            ? 'Không tìm thấy bài đăng này. Có thể nó đã bị xoá.'
            : (e.response?.data?.message ?? 'Không tải được bài đăng.');
    } finally {
        loading.value = false;
    }
}

function applyFilter() {
    page.value = 1;
    load();
}

/*
 * Live from Reverb, the same channel the list watches.
 *
 * This page used to hold a five second setInterval of its own -- started on
 * mount and left running whether anything could change or not. The counters
 * come from the same broadcast the list uses; the only difference is that here
 * only one post's messages matter.
 *
 * The per-account table is reloaded on a change rather than patched: the
 * message carries the post's totals, not which account moved, and a row that
 * says "đã đăng" without its permalink would be a worse lie than a row that is
 * a moment late.
 */
let stopListening = () => {};

onMounted(() => {
    load();

    const teamId = auth.user?.current_team_id;

    if (teamId) {
        stopListening = listen(`team.${teamId}`, '.post.progress', (payload) => {
            if (String(payload.id) !== String(props.id)) {
                return;
            }

            post.value = { ...post.value, status: payload.status, progress: payload.progress };
            load();
        });
    }
});

onUnmounted(() => stopListening());

/** Work that has not finished. Nothing else can change on its own. */
const inFlight = computed(() => post.value
    && (['scheduled', 'publishing'].includes(post.value.status) || post.value.progress.pending > 0));

const liveBroken = computed(() => inFlight.value && socketState.value !== 'connected');

/*
 * Why the table is empty, in the caller's terms.
 *
 * Four zeroes and a table with nothing under its headers is what this page
 * showed for a draft, which is technically true and says nothing: a draft that
 * has not fanned out yet looked exactly like a run that reached nobody. The
 * fan-out is dispatched the moment a post is scheduled, so the reasons are
 * distinguishable and worth distinguishing.
 */
const emptyReason = computed(() => {
    if (! post.value || targets.value.length) {
        return null;
    }

    if (statusFilter.value) {
        return { title: 'Không có tài khoản nào ở trạng thái này.', hint: null };
    }

    if (post.value.status === 'draft') {
        return {
            title: 'Bài này còn là nháp, chưa có mục tiêu nào.',
            hint: 'Danh sách tài khoản được tạo khi bạn lên lịch hoặc đăng. Bấm "Sửa" để hoàn tất và lên lịch.',
        };
    }

    if (post.value.status === 'scheduled' && post.value.progress.total === 0) {
        return {
            title: 'Đang tạo danh sách tài khoản…',
            // Queued rather than inline, so 10,000 accounts answers as fast as
            // 10 -- but that also means a stopped queue looks like this.
            hint: 'Việc này chạy trong hàng đợi và thường xong sau vài giây. Nếu đứng mãi ở đây thì Horizon chưa chạy.',
        };
    }

    if (post.value.status === 'failed' && post.value.progress.total === 0) {
        return {
            title: 'Không tìm thấy tài khoản nào khớp với đối tượng đã chọn.',
            hint: 'Bài không được gửi đi đâu cả. Sửa lại đối tượng ở Composer rồi lên lịch lại.',
        };
    }

    return { title: 'Chưa có tài khoản nào.', hint: null };
});

function formatDate(value) {
    return value ? new Date(value).toLocaleString('vi-VN') : '—';
}

async function act(request) {
    busy.value = true;

    try {
        await request();
        await load();
    } finally {
        busy.value = false;
    }
}

const cancel = () => act(() => api.post(`posts/${props.id}/cancel`));
const retryFailed = () => act(() => api.post(`posts/${props.id}/retry-failed`));
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <p v-if="loading" class="py-16 text-center text-[13px] text-neutral-500">Đang tải…</p>

        <div v-else-if="error" class="xp-card px-5 py-8 text-center">
            <p class="text-[13px] text-neutral-700">{{ error }}</p>
            <RouterLink :to="{ name: 'posts' }" class="xp-btn-ghost mt-3 inline-block">Về danh sách bài đăng</RouterLink>
        </div>

        <template v-else-if="post">
            <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-semibold">{{ post.name || 'Không tiêu đề' }}</h1>

                        <!--
                            The one thing this page never said. A draft and a
                            run that reached nobody both show four zeroes, and
                            without this there is nothing to tell them apart.
                        -->
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="postBadgeClass[post.status]"
                        >{{ post.status_label }}</span>

                        <span
                            v-if="inFlight && !liveBroken"
                            class="inline-flex items-center gap-1.5 text-[12px] text-green-700"
                            :title="`Reverb (${socketState}) — trạng thái được đẩy tới ngay khi đổi.`"
                        >
                            <span class="relative flex h-1.5 w-1.5">
                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-500 opacity-75"></span>
                                <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-green-600"></span>
                            </span>
                            Reverb
                        </span>
                    </div>

                    <p class="mt-1 max-w-2xl whitespace-pre-wrap text-[13px] text-neutral-600">{{ post.body }}</p>

                    <!-- When it goes out and where it points: both were on the
                         resource already and neither was ever shown. -->
                    <p class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12.5px] text-neutral-500">
                        <span v-if="post.scheduled_at">
                            {{ post.published_at ? 'Đã đăng' : 'Lên lịch' }}
                            {{ formatDate(post.published_at || post.scheduled_at) }}
                            <span v-if="post.timezone" class="text-neutral-400">({{ post.timezone }})</span>
                        </span>
                        <a
                            v-if="post.website_url"
                            :href="post.website_url"
                            target="_blank"
                            rel="noopener"
                            class="truncate text-blue-600 hover:underline"
                        >{{ post.website_url }}</a>
                        <span v-if="post.reply_settings_label">Trả lời: {{ post.reply_settings_label }}</span>
                    </p>
                </div>

                <div class="flex shrink-0 gap-2">
                    <button v-if="post.progress.failed > 0" class="xp-btn-ghost" :disabled="busy" @click="retryFailed">
                        Thử lại {{ post.progress.failed }} lỗi
                    </button>
                    <button v-if="post.progress.pending > 0" class="xp-btn-ghost" :disabled="busy" @click="cancel">
                        Huỷ phần còn lại
                    </button>
                    <RouterLink v-if="post.is_editable" :to="{ name: 'composer.edit', params: { id: post.id } }" class="xp-btn-primary">
                        Sửa
                    </RouterLink>
                </div>
            </div>

            <div
                v-if="liveBroken"
                class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900"
            >
                <strong class="font-semibold">Cập nhật trực tiếp đang không hoạt động.</strong>
                Số liệu bên dưới là của lần tải gần nhất và sẽ không tự đổi nữa.
                Reverb chưa kết nối được ({{ socketState }}).
                <button class="ml-1 underline underline-offset-2" @click="load">Tải lại</button>
            </div>

            <!-- Progress tiles -->
            <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div v-for="tile in [
                    { label: 'Tổng mục tiêu', value: post.progress.total, tone: 'text-neutral-900' },
                    { label: 'Đã đăng', value: post.progress.published, tone: 'text-green-700' },
                    { label: 'Đang chờ', value: post.progress.pending, tone: 'text-amber-700' },
                    { label: 'Thất bại', value: post.progress.failed, tone: 'text-red-700' },
                ]" :key="tile.label" class="xp-card px-4 py-3">
                    <p class="text-xs text-neutral-500">{{ tile.label }}</p>
                    <!-- Greyed at zero: four coloured zeroes read as four
                         results, when they are the absence of any. -->
                    <p
                        class="mt-0.5 text-xl font-semibold"
                        :class="tile.value ? tile.tone : 'text-neutral-300'"
                    >{{ tile.value.toLocaleString('vi-VN') }}</p>
                </div>
            </div>

            <div class="mb-3 flex items-center gap-2">
                <h2 class="text-[15px] font-semibold">Nhật ký theo tài khoản</h2>
                <select v-model="statusFilter" class="xp-field ml-auto max-w-[180px]" @change="applyFilter">
                    <option v-for="option in targetStatuses" :key="option.value" :value="option.value">
                        {{ option.label }}
                    </option>
                </select>
            </div>

            <div class="xp-card overflow-hidden">
                <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-[13px]">
                    <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Tài khoản</th>
                            <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                            <th class="px-4 py-2.5 font-medium">Nội dung đã gửi</th>
                            <th class="px-4 py-2.5 font-medium">Thời điểm</th>
                            <th class="px-4 py-2.5 font-medium">Kết quả</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <!--
                            Headers over nothing is what this table did before,
                            on the page someone lands on straight after saving a
                            draft. Every other table in the app says why.
                        -->
                        <tr v-if="emptyReason">
                            <td colspan="5" class="px-4 py-10 text-center">
                                <p class="text-[13px] text-neutral-600">{{ emptyReason.title }}</p>
                                <p v-if="emptyReason.hint" class="mx-auto mt-1 max-w-md text-[12.5px] text-neutral-500">
                                    {{ emptyReason.hint }}
                                </p>
                            </td>
                        </tr>

                        <tr v-for="target in targets" :key="target.id" class="hover:bg-neutral-50">
                            <td class="px-4 py-2.5 font-medium">@{{ target.account?.username }}</td>
                            <td class="px-4 py-2.5">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeClass[target.status]">
                                    {{ target.status_label }}
                                </span>
                            </td>
                            <td class="max-w-sm truncate px-4 py-2.5 text-neutral-600">{{ target.rendered_body }}</td>
                            <td class="px-4 py-2.5 text-neutral-600">
                                {{ formatDate(target.published_at || target.scheduled_at) }}
                            </td>
                            <td class="px-4 py-2.5">
                                <a v-if="target.permalink" :href="target.permalink" target="_blank" rel="noopener" class="text-blue-600 hover:underline">
                                    Xem trên X
                                </a>

                                <!--
                                    The same mark the list uses, so the two
                                    pages do not explain a failure two different
                                    ways. The code stays visible here because
                                    this page is already the detail -- what it
                                    should not do is print a paragraph into a
                                    table cell.
                                -->
                                <span v-else-if="target.error_message" class="inline-flex items-center gap-1.5">
                                    <ErrorPopover :failure="target.failure" />
                                    <span class="text-[12px] text-red-600">{{ target.error_code }}</span>
                                </span>

                                <span v-else class="text-neutral-400">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>

            <div v-if="meta && meta.last_page > 1" class="mt-4 flex items-center gap-2">
                <button class="xp-btn-ghost" :disabled="page <= 1" @click="page--; load()">Trước</button>
                <span class="text-[13px] text-neutral-600">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
                <button class="xp-btn-ghost" :disabled="page >= meta.last_page" @click="page++; load()">Sau</button>
            </div>
        </template>
    </div>
</template>
