<script setup>
import { onMounted, ref, watch } from 'vue';
import api from '../lib/api';

const props = defineProps({
    modelValue: { type: Object, required: true },
    estimate: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'change']);

const groups = ref([]);
const apps = ref([]);
const searchResults = ref([]);
const searching = ref(false);
const query = ref('');

const modes = [
    { value: 'all', label: 'Tất cả tài khoản đang hoạt động' },
    { value: 'groups', label: 'Theo nhóm' },
    { value: 'accounts', label: 'Chọn thủ công' },
    { value: 'filter', label: 'Theo bộ lọc' },
];

onMounted(async () => {
    const [groupResponse, appResponse] = await Promise.all([
        api.get('account-groups'),
        api.get('apps'),
    ]);

    groups.value = groupResponse.data.data;
    apps.value = appResponse.data.data;
});

function update(patch) {
    emit('update:modelValue', { ...props.modelValue, ...patch });
    emit('change');
}

function updateFilters(patch) {
    update({ filters: { ...(props.modelValue.filters ?? {}), ...patch } });
}

function toggleGroup(id) {
    const selected = props.modelValue.groups ?? [];

    update({ groups: selected.includes(id) ? selected.filter((g) => g !== id) : [...selected, id] });
}

/** Debounced so typing a handle does not fire a query per keystroke. */
let searchTimer;
watch(query, (term) => {
    clearTimeout(searchTimer);

    if (term.trim().length < 2) {
        searchResults.value = [];

        return;
    }

    searchTimer = setTimeout(async () => {
        searching.value = true;

        try {
            const { data } = await api.get('accounts', { params: { search: term, per_page: 20 } });
            searchResults.value = data.data;
        } finally {
            searching.value = false;
        }
    }, 300);
});

function toggleAccount(account) {
    const selected = props.modelValue.accounts ?? [];

    update({
        accounts: selected.includes(account.id)
            ? selected.filter((a) => a !== account.id)
            : [...selected, account.id],
    });
}
</script>

<template>
    <div class="space-y-3">
        <div class="grid grid-cols-2 gap-2">
            <button
                v-for="mode in modes"
                :key="mode.value"
                type="button"
                class="rounded-md border px-3 py-2 text-left text-[13px] transition"
                :class="modelValue.mode === mode.value
                    ? 'border-neutral-900 ring-1 ring-neutral-900'
                    : 'border-neutral-300 hover:bg-neutral-50'"
                @click="update({ mode: mode.value })"
            >
                {{ mode.label }}
            </button>
        </div>

        <!-- Groups -->
        <div v-if="modelValue.mode === 'groups'" class="flex flex-wrap gap-1.5">
            <button
                v-for="group in groups"
                :key="group.id"
                type="button"
                class="rounded-full border px-3 py-1 text-xs transition"
                :class="(modelValue.groups ?? []).includes(group.id)
                    ? 'border-neutral-900 bg-neutral-900 text-white'
                    : 'border-neutral-300 hover:bg-neutral-50'"
                @click="toggleGroup(group.id)"
            >
                {{ group.name }}
                <span class="opacity-60">{{ group.accounts_count }}</span>
            </button>

            <p v-if="!groups.length" class="xp-hint">Chưa có nhóm nào. Tạo nhóm ở trang Tài khoản X.</p>
        </div>

        <!-- Manual selection -->
        <div v-else-if="modelValue.mode === 'accounts'" class="space-y-2">
            <input v-model="query" type="search" class="xp-field" placeholder="Tìm theo @username hoặc tên…">

            <p v-if="searching" class="xp-hint">Đang tìm…</p>

            <ul v-else-if="searchResults.length" class="max-h-48 divide-y divide-neutral-100 overflow-y-auto rounded-md border border-neutral-200">
                <li v-for="account in searchResults" :key="account.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-[13px] hover:bg-neutral-50"
                        @click="toggleAccount(account)"
                    >
                        <input type="checkbox" :checked="(modelValue.accounts ?? []).includes(account.id)" class="pointer-events-none">
                        <span class="font-medium">{{ account.handle }}</span>
                        <span class="truncate text-neutral-500">{{ account.name }}</span>
                    </button>
                </li>
            </ul>

            <p class="xp-hint">Đã chọn {{ (modelValue.accounts ?? []).length }} tài khoản.</p>
        </div>

        <!-- Filters -->
        <div v-else-if="modelValue.mode === 'filter'" class="grid grid-cols-2 gap-2">
            <label class="space-y-1">
                <span class="xp-hint">Follower tối thiểu</span>
                <input
                    type="number"
                    min="0"
                    class="xp-field"
                    :value="modelValue.filters?.min_followers"
                    @input="updateFilters({ min_followers: $event.target.value ? Number($event.target.value) : undefined })"
                >
            </label>

            <label class="space-y-1">
                <span class="xp-hint">Điểm sức khoẻ tối thiểu</span>
                <input
                    type="number"
                    min="0"
                    max="100"
                    class="xp-field"
                    :value="modelValue.filters?.min_health"
                    @input="updateFilters({ min_health: $event.target.value ? Number($event.target.value) : undefined })"
                >
            </label>

            <label class="col-span-2 space-y-1">
                <span class="xp-hint">Chưa đăng kể từ</span>
                <input
                    type="datetime-local"
                    class="xp-field"
                    :value="modelValue.filters?.not_posted_since"
                    @input="updateFilters({ not_posted_since: $event.target.value || undefined })"
                >
            </label>
        </div>

        <!-- Shared options -->
        <div class="flex flex-wrap items-center gap-4 pt-1">
            <label class="flex items-center gap-2 text-[13px]">
                <input
                    type="checkbox"
                    :checked="modelValue.shuffle"
                    @change="update({ shuffle: $event.target.checked })"
                >
                Xáo trộn thứ tự
            </label>

            <label class="flex items-center gap-2 text-[13px]">
                Giới hạn
                <input
                    type="number"
                    min="1"
                    class="xp-field w-28 py-1"
                    :value="modelValue.limit"
                    @input="update({ limit: $event.target.value ? Number($event.target.value) : undefined })"
                >
                tài khoản
            </label>
        </div>

        <!-- Live estimate from the server, not a client-side guess -->
        <div v-if="estimate" class="rounded-md bg-neutral-50 px-3 py-2 text-[13px]">
            <span class="font-medium">{{ estimate.accounts.toLocaleString('vi-VN') }}</span> tài khoản sẽ nhận bài này.
            <span v-if="estimate.duration_seconds > 0" class="text-neutral-500">
                Rải trong ~{{ Math.round(estimate.duration_seconds / 60) }} phút.
            </span>
            <p v-if="estimate.exceeds_limit" class="mt-1 text-red-600">
                Vượt giới hạn mỗi bài. Hãy đặt "Giới hạn" thấp hơn hoặc chia thành nhiều bài.
            </p>
        </div>
    </div>
</template>
