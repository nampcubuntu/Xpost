<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import api, { validationErrors } from '../lib/api';
import NavIcon from '../components/NavIcon.vue';
import TimezoneSelect from '../components/TimezoneSelect.vue';

const accounts = ref([]);
const meta = ref(null);
const stats = ref(null);
const groups = ref([]);
const apps = ref([]);
const proxies = ref([]);
// How many the pool actually holds, so a capped dropdown can say so rather
// than looking like the whole answer.
const proxyTotal = ref(0);
const loading = ref(true);
const busy = ref(false);
const flash = ref(null);

const search = ref('');
const status = ref('');
// The server has taken a group_id since the beginning; nothing ever sent one.
const groupFilter = ref('');
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
                params: {
                    search: search.value || undefined,
                    status: status.value || undefined,
                    group_id: groupFilter.value || undefined,
                    page: page.value,
                },
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
    /*
     * One wave, not two. Groups and apps do not depend on the account list, so
     * waiting for it first bought nothing and cost a full round trip -- which
     * behind a tunnel is most of a second, more than the four queries put
     * together.
     */
    const [, groupResponse, appResponse, proxyResponse] = await Promise.all([
        load(),
        api.get('account-groups'),
        api.get('apps'),
        // assignable only, and capped: the pool can hold thousands, and a
        // dropdown is not how anyone picks one out of two thousand. Spreading
        // into the pool is the operation that matters at that size, and it
        // needs no list at all.
        api.get('proxies', { params: { assignable: 1, per_page: 200 } }),
    ]);

    groups.value = groupResponse.data.data;
    apps.value = appResponse.data.data;
    proxies.value = proxyResponse.data.data;
    proxyTotal.value = proxyResponse.data.meta?.total ?? proxies.value.length;
});

let timer;
watch([search, status, groupFilter], () => {
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

/*
 * Naming the groups, not just picking one.
 *
 * The bulk bar could put accounts into a group and there was nowhere to make
 * one -- the endpoints existed and nothing on any page reached them, so the
 * dropdown was empty on every install that had not seeded a group by hand.
 *
 * Same shape as the proxy page's, because it is the same job and two pages
 * solving it two ways is how the second one ends up unlearned.
 */
const managing = ref(false);
const newGroup = reactive({ name: '', color: '#1d9bf0' });

async function loadGroups() {
    const { data } = await api.get('account-groups');

    groups.value = data.data;
}

async function createGroup() {
    if (! newGroup.name.trim()) {
        return;
    }

    busy.value = true;

    try {
        await api.post('account-groups', { name: newGroup.name.trim(), color: newGroup.color });
        newGroup.name = '';
        await loadGroups();
    } catch (error) {
        // The one that actually happens: a second person naming a batch that
        // already has a name.
        window.alert(validationErrors(error).name ?? 'Không tạo được nhóm.');
    } finally {
        busy.value = false;
    }
}

async function renameGroup(row) {
    const name = window.prompt('Tên nhóm', row.name)?.trim();

    if (! name || name === row.name) {
        return;
    }

    try {
        await api.put(`account-groups/${row.id}`, { name });
        await loadGroups();
    } catch (error) {
        window.alert(validationErrors(error).name ?? 'Không đổi tên được.');
    }
}

/** The name goes; the accounts stay. Said out loud, because "Xoá" on a row
 *  reading "412 tài khoản" looks like it takes them with it. */
async function deleteGroup(row) {
    if (! window.confirm(`Xoá nhóm "${row.name}"?\n\n${row.accounts_count ?? 0} tài khoản trong nhóm vẫn được giữ nguyên, chỉ mất nhãn nhóm.`)) {
        return;
    }

    await api.delete(`account-groups/${row.id}`);
    await loadGroups();
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

        /*
         * A spread that ran out of proxies has to say so. "Đã áp dụng cho 500"
         * on accounts that still share one IP is the wrong kind of true.
         */
        flash.value = data.unassigned
            ? {
                type: 'error',
                message: `Đã gán cho ${data.affected - data.unassigned} tài khoản. `
                    + `Còn ${data.unassigned} chưa có proxy — pool đã hết chỗ, thêm proxy hoặc nâng "Tài khoản tối đa".`,
            }
            : { type: 'ok', message: `Đã áp dụng cho ${data.affected} tài khoản.` };
        selected.value = new Set();
        await load();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Thao tác thất bại.' };
    } finally {
        busy.value = false;
    }
}

/*
 * A dead proxy is not somewhere to send accounts, so it is not offered.
 * Assigning to one would look like it worked and fail at publish instead.
 */
const assignableProxies = computed(() => proxies.value.filter(
    (proxy) => proxy.is_active && proxy.health_status !== 'dead',
));

function onProxyPick(select) {
    const value = select.value;
    // Reset first: picking the same option twice has to fire twice, and a
    // select that keeps its choice reads as a filter rather than an action.
    select.value = '';

    if (value === 'spread') {
        runBulk('spread_proxy');
    } else if (value === 'clear') {
        runBulk('clear_proxy');
    } else if (value) {
        runBulk('assign_proxy', { proxy_id: Number(value) });
    }
}

/*
 * An account the publisher is currently standing off from.
 *
 * Derived rather than stored: cooldown_until is a moment, and a moment that
 * has passed is simply no longer true. A status column would have needed
 * something to set it back, and nothing would have.
 */
const inCooldown = (account) => account.cooldown_until
    && new Date(account.cooldown_until) > new Date();

const shortTime = (iso) => new Date(iso).toLocaleTimeString('vi-VN', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});
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

/*
 * Ads accounts, per row, fetched on demand.
 *
 * One Ads API call each, so a page of fifty rows cannot ask for all of them and
 * a pool of ten thousand certainly cannot. Kept out of the accounts payload for
 * that reason and loaded only when someone opens a picker.
 */
const adsOptions = reactive({});
const adsError = reactive({});
const adsManual = reactive({});
const adsLoading = ref(null);

async function loadAdsAccounts(account, adsAccountId = null) {
    adsLoading.value = account.id;
    delete adsError[account.id];

    try {
        const { data } = await api.get(`accounts/${account.id}/ads-accounts`, {
            params: { ads_account_id: adsAccountId || undefined },
        });

        if (! data.data.length) {
            /*
             * Not proof there is none. X's list leaves out ads accounts that
             * work -- one was missing from it, answered NOT_FOUND when asked
             * for by id, and minted cards without complaint. So offer the way
             * round it rather than declaring the account has nothing.
             */
            adsError[account.id] = 'X không liệt kê ads account nào. Nếu bạn biết ID (ví dụ 18ce55ocexb), nhập vào ô bên dưới.';
            adsManual[account.id] = adsManual[account.id] ?? '';

            return;
        }

        adsOptions[account.id] = data.data;
    } catch (error) {
        adsError[account.id] = error.response?.data?.message ?? 'Không đọc được danh sách.';
        adsManual[account.id] = adsManual[account.id] ?? '';
    } finally {
        adsLoading.value = null;
    }
}

/** Check an id typed by hand, then offer it like any other. */
async function verifyAdsAccount(account) {
    const id = (adsManual[account.id] ?? '').trim();

    if (! id) {
        return;
    }

    await loadAdsAccounts(account, id);

    if (adsOptions[account.id]) {
        delete adsError[account.id];
        delete adsManual[account.id];
        await setAdsAccount(account, id);
    }
}

async function setAdsAccount(account, adsAccountId) {
    const previous = account.ads_account_id;
    account.ads_account_id = adsAccountId || null;

    try {
        await api.put(`accounts/${account.id}`, { ads_account_id: adsAccountId || null });
        flash.value = {
            type: 'ok',
            message: adsAccountId
                ? `${account.handle} sẽ đúc card qua ${adsAccountId}.`
                : `${account.handle} sẽ không đúc card nữa.`,
        };
    } catch (error) {
        account.ads_account_id = previous;
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không lưu được.' };
    }
}

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
 * Apps an account can be connected through, emptiest first.
 *
 * This used to just take apps[0], and the list arrives sorted by name -- so the
 * button reliably picked whichever app happened to sort first rather than the
 * one the account should go to. Connecting through an app whose client_id is
 * wrong fails at X with an error that says nothing about which app was used.
 *
 * Either set of keys qualifies: the two flows need different ones, and an app
 * carrying only consumer keys is perfectly usable -- for OAuth 1.0a.
 */
const connectableApps = computed(() => apps.value
    .filter((app) => app.has_oauth1 || app.has_oauth2)
    .sort((a, b) => (a.assigned_count / a.max_accounts) - (b.assigned_count / b.max_accounts)));

const connectVia = ref(null);

watch(connectableApps, (list) => {
    connectVia.value ??= list[0]?.id ?? null;
}, { immediate: true });

const selectedApp = computed(() => apps.value.find((app) => app.id === connectVia.value) ?? null);

/**
 * Which flows the selected app has the keys for.
 *
 * Shown as a disabled button with the reason rather than a hidden one: a
 * button that vanishes leaves nothing to explain why, and the fix -- filling
 * in keys on the X Apps page -- is not guessable from an empty toolbar.
 */
const canOAuth1 = computed(() => selectedApp.value?.has_oauth1 === true);
const canOAuth2 = computed(() => selectedApp.value?.has_oauth2 === true);

async function connectAccount(flow) {
    if (!connectVia.value) {
        flash.value = {
            type: 'error',
            message: apps.value.length
                ? 'X App đang chọn chưa có khoá nào. Điền ở trang X Apps.'
                : 'Hãy tạo ít nhất một X App trước.',
        };

        return;
    }

    const endpoint = flow === 'oauth1' ? 'authorize-url-oauth1' : 'authorize-url';

    try {
        const { data } = await api.get(`apps/${connectVia.value}/${endpoint}`);
        window.location.href = data.url;
    } catch (error) {
        flash.value = {
            type: 'error',
            message: `Không mở được xác thực qua "${selectedApp.value?.name}": `
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

                <!--
                    OAuth 1.0a leads because it is the flow this pool is built
                    on: only an account connected this way can mint its own
                    card, and that is what puts the "From <domain>" row under a
                    video. It is also the button that gets pressed thousands of
                    times, against a handful for the other.

                    Two buttons rather than a checkbox: picking the wrong flow
                    is only discovered much later, when a publish fails.
                -->
                <button
                    class="xp-btn-primary"
                    :disabled="!canOAuth1"
                    :title="canOAuth1
                        ? 'OAuth 1.0a — đăng bài và tự tạo card. Token không hết hạn.'
                        : `X App &quot;${selectedApp?.name ?? ''}&quot; thiếu Consumer Key/Secret. Điền ở trang X Apps.`"
                    @click="connectAccount('oauth1')"
                >
                    + Kết nối tài khoản
                </button>

                <button
                    class="xp-btn-ghost"
                    :disabled="!canOAuth2"
                    :title="canOAuth2
                        ? 'OAuth 2.0 — đăng được nhưng không tự tạo card. Dùng cho tài khoản không có Ads account.'
                        : `X App &quot;${selectedApp?.name ?? ''}&quot; thiếu Client ID/Secret. Điền ở trang X Apps.`"
                    @click="connectAccount('oauth2')"
                >
                    + Kết nối (không Ads)
                </button>
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

            <!--
                Filtered on the server, like search and status: a group can hold
                more accounts than one page shows, so narrowing the fifty rows
                already fetched would find only the members that landed on this
                page.
            -->
            <select v-model="groupFilter" class="xp-field max-w-[190px]">
                <option value="">Mọi nhóm</option>
                <option v-for="row in groups" :key="row.id" :value="row.id">
                    {{ row.name }} ({{ row.accounts_count ?? 0 }})
                </option>
            </select>

            <!--
                A button, at the height of the fields beside it, and the only
                way to make a group at all: the endpoints existed and no page
                reached them, so the dropdown below was empty on every install
                that had not seeded one by hand.
            -->
            <button
                class="inline-flex items-center gap-1.5 rounded-md border border-neutral-300 px-3 py-1.5 text-[13px] text-neutral-600 transition hover:border-neutral-400 hover:bg-neutral-50"
                @click="managing = true"
            >
                <NavIcon name="apps" :size="13" class="text-neutral-400" />
                Quản lý nhóm
            </button>
        </div>

        <!--
            The actions, on their own bar.

            Seven controls used to be appended to the filter row the moment
            something was ticked, so "which accounts am I looking at" and "what
            am I about to do to fifty of them" shared one line of flat ghost
            buttons. Only present when there is a selection, the same way the
            proxy page does it.
        -->
        <div
            v-if="selected.size"
            class="mb-3 flex flex-wrap items-center gap-3 rounded-lg bg-neutral-900 px-4 py-2.5 text-[13px] text-white"
        >
            <span class="font-medium">Đã chọn {{ selected.size }}</span>

            <div class="flex flex-wrap items-center gap-1.5">
                <button
                    class="rounded-md bg-white/10 px-2.5 py-1 transition hover:bg-white/20 disabled:opacity-40"
                    :disabled="busy"
                    @click="runBulk('enable')"
                >Bật</button>
                <button
                    class="rounded-md bg-white/10 px-2.5 py-1 transition hover:bg-white/20 disabled:opacity-40"
                    :disabled="busy"
                    @click="runBulk('disable')"
                >Tắt</button>
                <button
                    class="rounded-md bg-white/10 px-2.5 py-1 transition hover:bg-white/20 disabled:opacity-40"
                    :disabled="busy"
                    @click="runBulk('refresh_token')"
                >Làm mới token</button>

                <select
                    class="xp-dark-select"
                    :disabled="busy || !groups.length"
                    :value="''"
                    @change="$event.target.value && runBulk('assign_group', { group_id: Number($event.target.value) }); $event.target.value = ''"
                >
                    <option value="">
                        {{ groups.length ? 'Thêm vào nhóm…' : 'Chưa có nhóm nào' }}
                    </option>
                    <option v-for="group in groups" :key="group.id" :value="group.id">
                        {{ group.name }}
                    </option>
                </select>

                <!--
                    Which IP these accounts walk out of.

                    "Chia đều" is the one that matters: the whole reason for
                    holding proxies is that a thousand accounts do not leave
                    from one address, and doing that by hand is a thousand
                    dropdowns. Picking a single proxy is for the accounts that
                    have to sit on a particular exit.
                -->
                <select
                    class="xp-dark-select max-w-[240px]"
                    :disabled="busy"
                    @change="onProxyPick($event.target)"
                >
                    <option value="">Gán proxy…</option>
                    <option value="spread">Chia đều vào pool</option>
                    <option value="clear">— Bỏ proxy —</option>
                    <option v-if="proxyTotal > proxies.length" disabled>
                        ── {{ proxies.length }} / {{ proxyTotal }} proxy — dùng "Chia đều" cho cả pool ──
                    </option>
                    <!-- Named the same way the column names it, so the string you pick is the string you see. -->
                    <option v-for="proxy in assignableProxies" :key="proxy.id" :value="proxy.id">
                        {{ proxy.host }}:{{ proxy.port }}
                        — {{ proxy.country || '?' }} · {{ proxy.assigned_count }}/{{ proxy.max_accounts }}
                    </option>
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
                    class="rounded-md bg-white/10 px-2.5 py-1 transition hover:bg-white/20 disabled:opacity-40"
                    :disabled="busy"
                    title="Suy ra từ mục Location trên hồ sơ X, sau đó tới quốc gia của proxy. Không ghi đè múi giờ đã đặt tay."
                    @click="runBulk('guess_timezone')"
                >Tự đoán múi giờ</button>
            </div>

            <button class="ml-auto text-white/60 transition hover:text-white" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <!--
            Creating the names themselves.

            A dialog rather than a page: a group is a name and a colour, and a
            whole route for two fields would be heavier than the thing it
            manages.
        -->
        <div v-if="managing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="managing = false">
            <div class="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-[15px] font-semibold">Nhóm tài khoản</h2>
                    <p class="mt-0.5 text-[12.5px] text-neutral-500">
                        Đặt tên cho từng tệp tài khoản — theo thị trường, theo chiến dịch, hay theo lô mua.
                        Một tài khoản có thể thuộc nhiều nhóm.
                    </p>
                </div>

                <form class="flex items-center gap-2 border-b border-neutral-200 px-5 py-3" @submit.prevent="createGroup">
                    <input
                        v-model="newGroup.name"
                        class="xp-field flex-1 py-1.5 text-[13px]"
                        placeholder="Tên nhóm mới…"
                        maxlength="255"
                    >
                    <input v-model="newGroup.color" type="color" class="h-8 w-10 cursor-pointer rounded border border-neutral-200">
                    <button type="submit" class="xp-btn-primary py-1.5 text-[13px]" :disabled="busy || !newGroup.name.trim()">
                        Tạo
                    </button>
                </form>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <p v-if="!groups.length" class="px-5 py-8 text-center text-[13px] text-neutral-500">
                        Chưa có nhóm nào.
                    </p>

                    <div
                        v-for="row in groups"
                        :key="row.id"
                        class="flex items-center gap-3 border-b border-neutral-100 px-5 py-2.5 text-[13px] last:border-b-0"
                    >
                        <span class="h-3 w-3 shrink-0 rounded-full" :style="{ backgroundColor: row.color }"></span>
                        <span class="min-w-0 flex-1 truncate font-medium">{{ row.name }}</span>
                        <span class="shrink-0 text-[12.5px] text-neutral-500">{{ row.accounts_count ?? 0 }} tài khoản</span>
                        <button class="shrink-0 rounded-md px-2 py-1 text-[12.5px] text-neutral-500 transition hover:bg-neutral-100 hover:text-neutral-900" @click="renameGroup(row)">Đổi tên</button>
                        <button class="shrink-0 rounded-md px-2 py-1 text-[12.5px] text-neutral-500 transition hover:bg-red-50 hover:text-red-600" @click="deleteGroup(row)">Xoá</button>
                    </div>
                </div>

                <div class="border-t border-neutral-200 px-5 py-3 text-right">
                    <button class="xp-btn-ghost" @click="managing = false">Đóng</button>
                </div>
            </div>
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
                        <th class="px-4 py-2.5 font-medium">Proxy</th>
                        <th class="px-4 py-2.5 font-medium">Ads account</th>
                        <th class="px-4 py-2.5 font-medium">Sức khoẻ</th>
                        <th class="px-4 py-2.5 font-medium">Hạn mức/ngày</th>
                        <th class="px-4 py-2.5 font-medium">Múi giờ</th>
                        <th class="px-4 py-2.5 font-medium">Đăng gần nhất</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    <tr v-if="loading"><td colspan="10" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td></tr>
                    <tr v-else-if="!accounts.length">
                        <td colspan="10" class="px-4 py-8 text-center text-neutral-500">
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

                            <!--
                                Backoff, which the status column could not say.

                                Three failures in a row pulls an account out of
                                rotation for thirty minutes and does not change
                                its status -- deliberately, because it heals
                                itself and a status that needs something to set
                                it back would stick. But "Hoạt động" on an
                                account that will not be published to for the
                                next hour is the wrong half of the truth, and
                                this is derived, so it clears on its own.
                            -->
                            <p
                                v-if="inCooldown(account)"
                                class="mt-0.5 text-[11px] text-amber-700"
                                :title="account.last_error ?? ''"
                            >tạm nghỉ tới {{ shortTime(account.cooldown_until) }}</p>

                            <!-- The reason it needs attention, where the reason
                                 is known and the state is not self-healing. -->
                            <p
                                v-else-if="account.last_error && account.status !== 'active'"
                                class="mt-0.5 max-w-[200px] truncate text-[11px] text-neutral-500"
                                :title="account.last_error"
                            >{{ account.last_error }}</p>
                        </td>
                        <td class="px-4 py-2.5 text-neutral-600">{{ account.app?.name ?? '—' }}</td>

                        <!--
                            An account with no proxy leaves from the server's own
                            IP, alongside every other account with no proxy. That
                            is the state worth seeing at a glance, so it is
                            flagged rather than left as a dash.
                        -->
                        <td class="px-4 py-2.5">
                            <template v-if="account.proxy">
                                <span
                                    class="mr-1.5 inline-block h-1.5 w-1.5 rounded-full align-middle"
                                    :class="account.proxy.health_status === 'healthy' ? 'bg-green-500'
                                        : account.proxy.health_status === 'dead' ? 'bg-red-500' : 'bg-neutral-300'"
                                    :title="account.proxy.health_status"
                                ></span>
                                <!--
                                    The address, not the label. A pasted list
                                    arrives without labels, and the question
                                    this column answers is which IP the account
                                    leaves from -- "#12" answers a different one.
                                -->
                                <span class="font-mono text-[12px] text-neutral-700">{{ account.proxy.host }}:{{ account.proxy.port }}</span>
                                <span class="ml-1 text-xs text-neutral-500">{{ account.proxy.country || '' }}</span>
                            </template>
                            <span v-else class="text-xs text-amber-600" title="Đi ra bằng IP của server">IP server</span>
                        </td>

                        <!--
                            Which ads account mints this account's cards.
                            Connecting picks one automatically, which is right
                            when there is exactly one and a guess when there is
                            more -- and the wrong guess is only found when a
                            card fails at publish.

                            The list is fetched on click, never with the page:
                            it is one Ads API call per account.
                        -->
                        <td class="px-4 py-2.5">
                            <select
                                v-if="adsOptions[account.id]"
                                class="xp-field min-w-[190px] py-1 text-[12.5px]"
                                :value="account.ads_account_id ?? ''"
                                @change="setAdsAccount(account, $event.target.value)"
                            >
                                <option value="">không dùng card</option>
                                <option v-for="ads in adsOptions[account.id]" :key="ads.id" :value="ads.id">
                                    {{ ads.name }} — {{ ads.id }}{{ ads.approval_status === 'ACCEPTED' ? '' : ' (chưa duyệt)' }}
                                </option>
                            </select>

                            <button
                                v-else
                                class="text-left text-[12.5px] text-blue-600 hover:underline disabled:text-neutral-400 disabled:no-underline"
                                :disabled="adsLoading === account.id"
                                @click="loadAdsAccounts(account)"
                            >
                                <span v-if="adsLoading === account.id">Đang hỏi X…</span>
                                <span v-else-if="account.ads_account_id" class="font-mono text-neutral-700">
                                    {{ account.ads_account_id }}
                                </span>
                                <span v-else>chọn…</span>
                            </button>

                            <p v-if="adsError[account.id]" class="mt-0.5 max-w-[230px] text-[11px] text-red-600">
                                {{ adsError[account.id] }}
                            </p>

                            <!--
                                A way round X's own list, which leaves out ads
                                accounts that work. Checked before it is saved,
                                so a typo cannot be stored as a real id.
                            -->
                            <form
                                v-if="adsManual[account.id] !== undefined"
                                class="mt-1 flex gap-1"
                                @submit.prevent="verifyAdsAccount(account)"
                            >
                                <input
                                    v-model="adsManual[account.id]"
                                    type="text"
                                    class="xp-field w-[130px] py-1 font-mono text-[12px]"
                                    placeholder="18ce55ocexb"
                                >
                                <button
                                    class="rounded-md border border-neutral-300 px-2 text-[12px] hover:bg-neutral-50 disabled:opacity-50"
                                    :disabled="adsLoading === account.id"
                                >
                                    Kiểm tra
                                </button>
                            </form>
                            <p v-else-if="!account.ads_account_id && !adsOptions[account.id]" class="mt-0.5 text-[11px] text-neutral-400">
                                chưa có — không đúc được card
                            </p>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-1.5">
                                <!--
                                    A full green bar on an account that has
                                    never posted is the column default drawn as
                                    a measurement. It moves by +1 per published
                                    target and -5 per failure, so before the
                                    first one there is nothing to report -- and
                                    1,019 of these accounts were reading 100/100
                                    having never sent anything.
                                -->
                                <template v-if="account.last_posted_at">
                                    <div class="h-1.5 w-12 overflow-hidden rounded-full bg-neutral-200">
                                        <div
                                            class="h-full rounded-full"
                                            :class="account.health_score > 70 ? 'bg-green-600' : account.health_score > 40 ? 'bg-amber-500' : 'bg-red-600'"
                                            :style="{ width: `${account.health_score}%` }"
                                        ></div>
                                    </div>
                                    <span class="text-xs text-neutral-500">{{ account.health_score }}</span>
                                </template>

                                <span v-else class="text-xs text-neutral-400" title="Điểm sức khoẻ chỉ thay đổi sau mỗi lần đăng: +1 khi thành công, -5 khi lỗi.">
                                    chưa đăng lần nào
                                </span>
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
