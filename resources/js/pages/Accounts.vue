<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../lib/api';
import TimezoneSelect from '../components/TimezoneSelect.vue';

const accounts = ref([]);
const meta = ref(null);
const stats = ref(null);
const groups = ref([]);
const apps = ref([]);
const loading = ref(true);
const busy = ref(false);
const flash = ref(null);

const search = ref('');
const status = ref('');
const page = ref(1);
const selected = ref(new Set());

const allSelected = computed(() =>
    accounts.value.length > 0 && accounts.value.every((a) => selected.value.has(a.id)),
);

const badgeClass = {
    active: 'bg-green-50 text-green-700',
    needs_reauth: 'bg-amber-50 text-amber-700',
    rate_limited: 'bg-blue-50 text-blue-700',
    suspended: 'bg-red-50 text-red-700',
    disabled: 'bg-neutral-100 text-neutral-500',
    error: 'bg-red-50 text-red-700',
};

async function load() {
    loading.value = true;

    try {
        const [accountResponse, statsResponse] = await Promise.all([
            api.get('accounts', {
                params: { search: search.value || undefined, status: status.value || undefined, page: page.value },
            }),
            api.get('accounts/stats'),
        ]);

        accounts.value = accountResponse.data.data;
        meta.value = accountResponse.data.meta;
        stats.value = statsResponse.data;
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await load();

    const [groupResponse, appResponse] = await Promise.all([api.get('account-groups'), api.get('apps')]);
    groups.value = groupResponse.data.data;
    apps.value = appResponse.data.data;
});

let timer;
watch([search, status], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(page, load);

function toggleAll() {
    if (allSelected.value) {
        accounts.value.forEach((a) => selected.value.delete(a.id));
    } else {
        accounts.value.forEach((a) => selected.value.add(a.id));
    }

    selected.value = new Set(selected.value);
}

function toggle(id) {
    selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id);
    selected.value = new Set(selected.value);
}

async function runBulk(action, extra = {}) {
    if (!selected.value.size) {
        return;
    }

    busy.value = true;
    flash.value = null;

    try {
        const { data } = await api.post('accounts/bulk', {
            action,
            account_ids: [...selected.value],
            ...extra,
        });

        flash.value = { type: 'ok', message: `Đã áp dụng cho ${data.affected} tài khoản.` };
        selected.value = new Set();
        await load();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Thao tác thất bại.' };
    } finally {
        busy.value = false;
    }
}

/** How the timezone on a row got there — a guess is worth flagging as one. */
const sourceLabel = {
    manual: 'đặt tay',
    location: 'từ hồ sơ X',
    country: 'chỉ tới quốc gia — có thể lệch vài giờ',
    proxy: 'từ quốc gia proxy — có thể lệch',
};

// The two country-level sources are flagged: they can be hours out, and a
// silently wrong timezone is worse than an empty one.
const sourceStyle = {
    manual: 'text-neutral-400',
    location: 'text-neutral-400',
    country: 'text-amber-600',
    proxy: 'text-amber-600',
};

/** Correct one row without going through the bulk toolbar. */
async function setTimezone(account, timezone) {
    if (!timezone || timezone === account.timezone) {
        return;
    }

    const previous = account.timezone;
    // Optimistic: the select has already moved, so snapping it back on the
    // response would look like a glitch.
    account.timezone = timezone;
    account.timezone_source = 'manual';

    try {
        await api.put(`accounts/${account.id}`, { timezone });
    } catch (error) {
        account.timezone = previous;
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không lưu được múi giờ.' };
    }
}

async function rebalance() {
    busy.value = true;

    try {
        const { data } = await api.post('accounts/rebalance');
        flash.value = { type: 'ok', message: `Đã gán ${data.assigned} tài khoản vào pool X App.` };
        await load();
    } finally {
        busy.value = false;
    }
}

/**
 * Apps that can actually run an OAuth 2.0 flow, emptiest first.
 *
 * This used to just take apps[0], and the list arrives sorted by name -- so the
 * button reliably picked whichever app happened to sort first rather than the
 * one the account should go to. Connecting through an app whose client_id is
 * wrong fails at X with an error that says nothing about which app was used.
 */
const connectableApps = computed(() => apps.value
    .filter((app) => app.has_oauth2)
    .sort((a, b) => (a.assigned_count / a.max_accounts) - (b.assigned_count / b.max_accounts)));

const connectVia = ref(null);

watch(connectableApps, (list) => {
    connectVia.value ??= list[0]?.id ?? null;
}, { immediate: true });

async function connectAccount() {
    if (!connectVia.value) {
        flash.value = {
            type: 'error',
            message: apps.value.length
                ? 'Không có X App nào đủ khoá OAuth 2.0. Điền Client ID và Client Secret ở trang X Apps.'
                : 'Hãy tạo ít nhất một X App trước.',
        };

        return;
    }

    const app = apps.value.find((a) => a.id === connectVia.value);

    try {
        const { data } = await api.get(`apps/${connectVia.value}/authorize-url`);
        window.location.href = data.url;
    } catch (error) {
        flash.value = {
            type: 'error',
            message: `Không mở được xác thực qua "${app?.name}": `
                + (error.response?.data?.message ?? 'lỗi không rõ'),
        };
    }
}
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Tài khoản X</h1>
            <div class="flex flex-wrap items-center gap-2">
                <button class="xp-btn-ghost" :disabled="busy" @click="rebalance">Cân bằng pool</button>

                <!--
                    Which app the account is authorised through decides which
                    credentials X sees and which app's quota it spends. Worth
                    being explicit about rather than picking one silently.
                -->
                <select v-if="connectableApps.length > 1" v-model="connectVia" class="xp-field max-w-[200px] py-2">
                    <option v-for="app in connectableApps" :key="app.id" :value="app.id">
                        qua {{ app.name }} ({{ app.assigned_count }}/{{ app.max_accounts }})
                    </option>
                </select>

                <button class="xp-btn-primary" @click="connectAccount">+ Kết nối tài khoản</button>
            </div>
        </div>

        <div
            v-if="flash"
            class="mb-4 rounded-md px-3 py-2 text-sm"
            :class="flash.type === 'ok' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'"
        >
            {{ flash.message }}
        </div>

        <!-- Stat tiles -->
        <div v-if="stats" class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
            <div class="xp-card px-4 py-3">
                <p class="text-xs text-neutral-500">Tổng</p>
                <p class="mt-0.5 text-xl font-semibold">{{ stats.total.toLocaleString('vi-VN') }}</p>
            </div>
            <div v-for="key in ['active', 'needs_reauth', 'disabled', 'error']" :key="key" class="xp-card px-4 py-3">
                <p class="text-xs capitalize text-neutral-500">{{ key.replace('_', ' ') }}</p>
                <p class="mt-0.5 text-xl font-semibold">{{ (stats.by_status[key] ?? 0).toLocaleString('vi-VN') }}</p>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <input v-model="search" type="search" class="xp-field max-w-xs" placeholder="Tìm @username…">
            <select v-model="status" class="xp-field max-w-[190px]">
                <option value="">Tất cả trạng thái</option>
                <option value="active">Hoạt động</option>
                <option value="needs_reauth">Cần xác thực lại</option>
                <option value="rate_limited">Bị giới hạn</option>
                <option value="disabled">Đã tắt</option>
                <option value="error">Lỗi</option>
            </select>

            <template v-if="selected.size">
                <span class="ml-2 text-[13px] text-neutral-600">Đã chọn {{ selected.size }}</span>
                <button class="xp-btn-ghost" :disabled="busy" @click="runBulk('enable')">Bật</button>
                <button class="xp-btn-ghost" :disabled="busy" @click="runBulk('disable')">Tắt</button>
                <button class="xp-btn-ghost" :disabled="busy" @click="runBulk('refresh_token')">Làm mới token</button>
                <select
                    class="xp-field max-w-[170px]"
                    @change="$event.target.value && runBulk('assign_group', { group_id: Number($event.target.value) })"
                >
                    <option value="">Thêm vào nhóm…</option>
                    <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.name }}</option>
                </select>

                <!--
                    Where these accounts actually are. It is what "đăng lúc 09:00
                    giờ địa phương" resolves against, and the pool is global, so
                    it has to be settable in bulk rather than one row at a time.
                -->
                <TimezoneSelect
                    class="max-w-[220px]"
                    :model-value="null"
                    placeholder="Đặt múi giờ…"
                    @update:model-value="$event && runBulk('set_timezone', { timezone: $event })"
                />

                <button
                    class="xp-btn-ghost"
                    :disabled="busy"
                    title="Suy ra từ mục Location trên hồ sơ X, sau đó tới quốc gia của proxy. Không ghi đè múi giờ đã đặt tay."
                    @click="runBulk('guess_timezone')"
                >Tự đoán múi giờ</button>
            </template>
        </div>

        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-[13px]">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-2.5"><input type="checkbox" :checked="allSelected" @change="toggleAll"></th>
                        <th class="px-4 py-2.5 font-medium">Tài khoản</th>
                        <th class="px-4 py-2.5 font-medium">Trạng thái</th>
                        <th class="px-4 py-2.5 font-medium">X App</th>
                        <th class="px-4 py-2.5 font-medium">Sức khoẻ</th>
                        <th class="px-4 py-2.5 font-medium">Hạn mức/ngày</th>
                        <th class="px-4 py-2.5 font-medium">Múi giờ</th>
                        <th class="px-4 py-2.5 font-medium">Đăng gần nhất</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    <tr v-if="loading"><td colspan="8" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td></tr>
                    <tr v-else-if="!accounts.length">
                        <td colspan="8" class="px-4 py-8 text-center text-neutral-500">
                            Chưa có tài khoản nào. Kết nối qua OAuth, hoặc import hàng loạt bằng
                            <code class="rounded bg-neutral-100 px-1">php artisan xpost:import-accounts</code>.
                        </td>
                    </tr>

                    <tr v-for="account in accounts" :key="account.id" class="hover:bg-neutral-50">
                        <td class="px-4 py-2.5">
                            <input type="checkbox" :checked="selected.has(account.id)" @change="toggle(account.id)">
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <img v-if="account.avatar_url" :src="account.avatar_url" alt="" class="h-6 w-6 rounded-full object-cover">
                                <div v-else class="h-6 w-6 rounded-full bg-neutral-200"></div>
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ account.handle }}</p>
                                    <p class="truncate text-xs text-neutral-500">{{ account.name }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-2.5">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="badgeClass[account.status]">
                                {{ account.status_label }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-neutral-600">{{ account.app?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-1.5">
                                <div class="h-1.5 w-12 overflow-hidden rounded-full bg-neutral-200">
                                    <div
                                        class="h-full rounded-full"
                                        :class="account.health_score > 70 ? 'bg-green-600' : account.health_score > 40 ? 'bg-amber-500' : 'bg-red-600'"
                                        :style="{ width: `${account.health_score}%` }"
                                    ></div>
                                </div>
                                <span class="text-xs text-neutral-500">{{ account.health_score }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-2.5 text-neutral-600">{{ account.daily_post_cap }}</td>
                        <!--
                            Editable in place: a guess from a free-text profile
                            gets some accounts wrong, and correcting one should
                            not mean selecting it and driving the bulk toolbar.
                        -->
                        <td class="px-4 py-2.5">
                            <TimezoneSelect
                                class="min-w-[180px] py-1 text-[12.5px]"
                                :model-value="account.timezone"
                                :placeholder="account.timezone ? null : 'chưa đặt'"
                                @update:model-value="setTimezone(account, $event)"
                            />
                            <p v-if="account.timezone" class="mt-0.5 text-[11px]" :class="sourceStyle[account.timezone_source ?? 'manual']">
                                {{ sourceLabel[account.timezone_source ?? 'manual'] }}
                            </p>
                            <p v-else-if="account.location" class="mt-0.5 truncate text-[11px] text-neutral-400" :title="account.location">
                                hồ sơ: {{ account.location }}
                            </p>
                        </td>
                        <td class="px-4 py-2.5 text-neutral-600">
                            {{ account.last_posted_at ? new Date(account.last_posted_at).toLocaleString('vi-VN') : '—' }}
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
