<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../lib/api';

const overview = ref(null);
const loading = ref(true);

const from = ref(new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10));
const to = ref(new Date().toISOString().slice(0, 10));

async function load() {
    loading.value = true;

    try {
        const { data } = await api.get('analytics/overview', { params: { from: from.value, to: to.value } });
        overview.value = data;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

/** Peak of the series, used to scale the bars. Guarded so an all-zero range does not divide by zero. */
const peak = computed(() => {
    const daily = overview.value?.daily ?? [];

    return Math.max(1, ...daily.map((d) => Number(d.published) + Number(d.failed)));
});

function barHeight(row) {
    return `${((Number(row.published) + Number(row.failed)) / peak.value) * 100}%`;
}

function publishedShare(row) {
    const total = Number(row.published) + Number(row.failed);

    return total === 0 ? 0 : (Number(row.published) / total) * 100;
}
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Analytics</h1>
            <div class="flex items-center gap-2">
                <input v-model="from" type="date" class="xp-field w-auto" @change="load">
                <span class="text-neutral-400">→</span>
                <input v-model="to" type="date" class="xp-field w-auto" @change="load">
            </div>
        </div>

        <p v-if="loading" class="text-neutral-500">Đang tải…</p>

        <template v-else-if="overview">
            <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <div v-for="tile in [
                    { label: 'Đã đăng', value: overview.published, tone: 'text-green-700' },
                    { label: 'Thất bại', value: overview.failed, tone: 'text-red-700' },
                    { label: 'Đang chờ', value: overview.pending, tone: 'text-amber-700' },
                    { label: 'Tỉ lệ thành công', value: `${overview.success_rate}%`, tone: 'text-neutral-900' },
                    { label: 'Tài khoản sẵn sàng', value: overview.active_accounts, tone: 'text-neutral-900' },
                ]" :key="tile.label" class="xp-card px-4 py-3">
                    <p class="text-xs text-neutral-500">{{ tile.label }}</p>
                    <p class="mt-0.5 text-xl font-semibold" :class="tile.tone">
                        {{ typeof tile.value === 'number' ? tile.value.toLocaleString('vi-VN') : tile.value }}
                    </p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <!-- Volume -->
                <div class="xp-card p-5 lg:col-span-2">
                    <h2 class="mb-4 text-[15px] font-semibold">Lượng bài đăng theo ngày</h2>

                    <div v-if="overview.daily.length" class="flex h-48 items-end gap-1">
                        <div
                            v-for="row in overview.daily"
                            :key="row.day"
                            class="group relative flex-1"
                            :style="{ height: barHeight(row) }"
                        >
                            <!-- Failed sits behind, published fills from the bottom -->
                            <div class="h-full w-full rounded-t bg-red-200">
                                <div class="w-full rounded-t bg-neutral-900" :style="{ height: `${publishedShare(row)}%` }"></div>
                            </div>

                            <div class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-neutral-900 px-2 py-1 text-[11px] text-white group-hover:block">
                                {{ row.day }}: {{ row.published }} đăng, {{ row.failed }} lỗi
                            </div>
                        </div>
                    </div>

                    <p v-else class="text-[13px] text-neutral-500">Chưa có dữ liệu trong khoảng này.</p>

                    <div class="mt-3 flex gap-4 text-xs text-neutral-500">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-neutral-900"></span> Đã đăng</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-red-200"></span> Thất bại</span>
                    </div>
                </div>

                <!-- Failure breakdown -->
                <div class="xp-card p-5">
                    <h2 class="mb-4 text-[15px] font-semibold">Nguyên nhân lỗi</h2>

                    <ul v-if="overview.failures.length" class="space-y-2.5">
                        <li v-for="row in overview.failures" :key="row.error_code" class="flex items-baseline justify-between gap-2 text-[13px]">
                            <code class="truncate rounded bg-neutral-100 px-1.5 py-0.5 text-xs">{{ row.error_code || '—' }}</code>
                            <span class="shrink-0 font-medium">{{ Number(row.total).toLocaleString('vi-VN') }}</span>
                        </li>
                    </ul>

                    <p v-else class="text-[13px] text-neutral-500">Không có lỗi nào. </p>
                </div>
            </div>
        </template>
    </div>
</template>
