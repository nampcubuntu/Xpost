<script setup>
import { onMounted, ref, watch } from 'vue';
import api from '../lib/api';

const posts = ref([]);
const meta = ref(null);
const loading = ref(true);
const status = ref('');
const search = ref('');
const page = ref(1);

const statuses = [
    { value: '', label: 'Tất cả' },
    { value: 'draft', label: 'Nháp' },
    { value: 'scheduled', label: 'Đã lên lịch' },
    { value: 'publishing', label: 'Đang đăng' },
    { value: 'published', label: 'Đã đăng' },
    { value: 'partially_failed', label: 'Đăng một phần' },
    { value: 'failed', label: 'Thất bại' },
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

async function load() {
    loading.value = true;

    try {
        const { data } = await api.get('posts', {
            params: { status: status.value || undefined, search: search.value || undefined, page: page.value },
        });

        posts.value = data.data;
        meta.value = data.meta;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

let timer;
watch([status, search], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(page, load);

function formatDate(value) {
    return value ? new Date(value).toLocaleString('vi-VN') : '—';
}
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Bài đăng</h1>
            <RouterLink :to="{ name: 'composer' }" class="xp-btn-primary">Soạn bài mới</RouterLink>
        </div>

        <div class="mb-4 flex gap-2">
            <input v-model="search" type="search" class="xp-field max-w-xs" placeholder="Tìm theo tên hoặc nội dung…">
            <select v-model="status" class="xp-field max-w-[180px]">
                <option v-for="option in statuses" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
        </div>

        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-[13px]">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Bài đăng</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th class="px-4 py-2.5 font-medium">Tiến độ</th>
                        <th class="px-4 py-2.5 font-medium">Lịch đăng</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    <tr v-if="loading">
                        <td colspan="4" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td>
                    </tr>

                    <tr v-else-if="!posts.length">
                        <td colspan="4" class="px-4 py-8 text-center text-neutral-500">Chưa có bài đăng nào.</td>
                    </tr>

                    <tr v-for="post in posts" :key="post.id" class="hover:bg-neutral-50">
                        <td class="px-4 py-3">
                            <RouterLink :to="{ name: 'posts.show', params: { id: post.id } }" class="block max-w-md">
                                <span class="font-medium">{{ post.name || 'Không tiêu đề' }}</span>
                                <span class="mt-0.5 block truncate text-neutral-500">{{ post.body }}</span>
                            </RouterLink>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeClass[post.status]">
                                {{ post.status_label }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-24 overflow-hidden rounded-full bg-neutral-200">
                                    <div class="h-full rounded-full bg-neutral-900" :style="{ width: `${post.progress.percent}%` }"></div>
                                </div>
                                <span class="text-xs text-neutral-500">
                                    {{ post.progress.published }}/{{ post.progress.total }}
                                    <span v-if="post.progress.failed" class="text-red-600">({{ post.progress.failed }} lỗi)</span>
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-neutral-600">{{ formatDate(post.scheduled_at) }}</td>
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
