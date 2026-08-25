<script setup>
import { onMounted, onUnmounted, ref } from 'vue';
import api from '../lib/api';

const props = defineProps({ id: { type: String, required: true } });

const post = ref(null);
const targets = ref([]);
const meta = ref(null);
const statusFilter = ref('');
const page = ref(1);
const busy = ref(false);

let poller;

const badgeClass = {
    pending: 'bg-neutral-100 text-neutral-700',
    queued: 'bg-blue-50 text-blue-700',
    publishing: 'bg-amber-50 text-amber-700',
    published: 'bg-green-50 text-green-700',
    failed: 'bg-red-50 text-red-700',
    cancelled: 'bg-neutral-100 text-neutral-500',
    skipped: 'bg-neutral-100 text-neutral-500',
};

async function load() {
    const [postResponse, targetResponse] = await Promise.all([
        api.get(`posts/${props.id}`),
        api.get(`posts/${props.id}/targets`, {
            params: { status: statusFilter.value || undefined, page: page.value },
        }),
    ]);

    post.value = postResponse.data.data;
    targets.value = targetResponse.data.data;
    meta.value = targetResponse.data.meta;
}

onMounted(async () => {
    await load();

    // A blast is not instantaneous; poll while there is still work outstanding.
    poller = setInterval(() => {
        if (post.value && post.value.progress.pending > 0) {
            load();
        }
    }, 5000);
});

onUnmounted(() => clearInterval(poller));

async function cancel() {
    busy.value = true;

    try {
        await api.post(`posts/${props.id}/cancel`);
        await load();
    } finally {
        busy.value = false;
    }
}

async function retryFailed() {
    busy.value = true;

    try {
        await api.post(`posts/${props.id}/retry-failed`);
        await load();
    } finally {
        busy.value = false;
    }
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString('vi-VN') : '—';
}
</script>

<template>
    <div v-if="post" class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">{{ post.name || 'Không tiêu đề' }}</h1>
                <p class="mt-1 max-w-2xl text-[13px] text-neutral-600">{{ post.body }}</p>
            </div>

            <div class="flex gap-2">
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

        <!-- Progress tiles -->
        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div v-for="tile in [
                { label: 'Tổng mục tiêu', value: post.progress.total, tone: 'text-neutral-900' },
                { label: 'Đã đăng', value: post.progress.published, tone: 'text-green-700' },
                { label: 'Đang chờ', value: post.progress.pending, tone: 'text-amber-700' },
                { label: 'Thất bại', value: post.progress.failed, tone: 'text-red-700' },
            ]" :key="tile.label" class="xp-card px-4 py-3">
                <p class="text-xs text-neutral-500">{{ tile.label }}</p>
                <p class="mt-0.5 text-xl font-semibold" :class="tile.tone">
                    {{ tile.value.toLocaleString('vi-VN') }}
                </p>
            </div>
        </div>

        <div class="mb-3 flex items-center gap-2">
            <h2 class="text-[15px] font-semibold">Nhật ký theo tài khoản</h2>
            <select v-model="statusFilter" class="xp-field ml-auto max-w-[180px]" @change="page = 1; load()">
                <option value="">Tất cả trạng thái</option>
                <option value="pending">Chờ đăng</option>
                <option value="published">Đã đăng</option>
                <option value="failed">Thất bại</option>
                <option value="cancelled">Đã huỷ</option>
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
                            <span v-else-if="target.error_message" class="text-red-600" :title="target.error_message">
                                {{ target.error_code }}
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
    </div>
</template>
