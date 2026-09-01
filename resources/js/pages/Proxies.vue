<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import api, { validationErrors } from '../lib/api';
import { cached, swr } from '../lib/cache';

const proxies = ref([]);
const meta = ref(null);
const page = ref(1);
const search = ref('');
const health = ref('');
const group = ref('');

/*
 * Names for batches of proxies, loaded once beside the list.
 *
 * A supplier's delivery is hundreds of lines whose only distinguishing feature
 * is the host, which is exactly what nobody remembers afterwards.
 */
const groups = ref([]);
const managing = ref(false);
const newGroup = reactive({ name: '', color: '#1d9bf0' });
const showBulk = ref(false);
const bulkText = ref('');
const bulkScheme = ref('http');
const bulkResult = ref(null);
const loading = ref(true);
const busy = ref(false);
const editing = ref(null);
const errors = ref({});
const checking = ref(new Set());
const selected = ref(new Set());

/*
 * The rows the page is still waiting on a verdict for.
 *
 * A checked selection runs on the queue, so the answers arrive after the
 * response does. Something has to say "asked, not answered yet" or a click on
 * fifty rows looks like a click that did nothing.
 */
const pending = computed(() => proxies.value.filter((proxy) => proxy.check_queued_at));

/*
 * A verdict is only as good as the day it was reached.
 *
 * "healthy" was shown as a plain fact whatever its age, and last_checked_at was
 * already in the payload and never rendered. A proxy checked five days ago read
 * exactly like one checked a minute ago -- which is how a supplier's line can
 * start answering 407, keep its green badge, and take every post assigned to it
 * down with it.
 */
const STALE_AFTER_HOURS = 24;

function verdictAgeHours(proxy) {
    return proxy.last_checked_at
        ? (Date.now() - new Date(proxy.last_checked_at).getTime()) / 3600000
        : null;
}

const isStale = (proxy) => {
    const hours = verdictAgeHours(proxy);

    return hours === null || hours > STALE_AFTER_HOURS;
};

/** Short enough to sit under a badge without pushing the row taller. */
function verdictAge(proxy) {
    const hours = verdictAgeHours(proxy);

    if (hours === null) {
        return 'chưa kiểm tra';
    }

    if (hours < 1) {
        return `${Math.max(1, Math.round(hours * 60))} phút trước`;
    }

    return hours < 48 ? `${Math.round(hours)} giờ trước` : `${Math.round(hours / 24)} ngày trước`;
}

/** How many rows on this page are showing a verdict nobody should trust. */
const staleCount = computed(() => proxies.value.filter(
    (proxy) => ! proxy.check_queued_at && proxy.health_status === 'healthy' && isStale(proxy)
).length);
const allSelected = computed(() => proxies.value.length > 0 && selected.value.size === proxies.value.length);

const blank = () => ({
    label: '',
    scheme: 'http',
    host: '',
    port: 8080,
    username: '',
    password: '',
    country: '',
    max_accounts: 50,
    is_active: true,
});

const form = reactive(blank());

const healthClass = {
    healthy: 'bg-green-50 text-green-700',
    degraded: 'bg-amber-50 text-amber-700',
    dead: 'bg-red-50 text-red-700',
    unknown: 'bg-neutral-100 text-neutral-500',
};

async function load() {
    /*
     * A page at a time, filtered on the server.
     *
     * A supplier delivers two thousand proxies in one paste, and this used to
     * fetch all of them -- 0.6MB down the tunnel and two thousand rows of
     * table. Searching a pool you have not finished downloading is not
     * searching it either, so the filter goes with the request.
     */
    const key = `proxies:${search.value}:${health.value}:${group.value}:${page.value}`;

    // Last answer first, then the true one: arriving at a page that has not
    // changed should not mean staring at an empty table while it is proved.
    loading.value = cached(key) === undefined;

    try {
        await swr(key, async () => (await api.get('proxies', {
            params: {
                search: search.value || undefined,
                health: health.value || undefined,
                group: group.value || undefined,
                page: page.value,
            },
        })).data, (data) => {
            proxies.value = data.data;
            meta.value = data.meta;

            // Drop anything no longer on this page, or the count in the action
            // bar promises rows the next action cannot find.
            const live = new Set(proxies.value.map((proxy) => proxy.id));
            selected.value = new Set([...selected.value].filter((id) => live.has(id)));
        });
    } finally {
        loading.value = false;
    }
}

let searchTimer;
watch([search, health, group], () => {
    page.value = 1;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});
watch(page, load);

onMounted(() => {
    load();
    loadGroups();
});

function startCreate() {
    Object.assign(form, blank());
    editing.value = 'new';
    errors.value = {};
}

function startEdit(proxy) {
    Object.assign(form, { ...blank(), ...proxy, password: '' });
    editing.value = proxy.id;
    errors.value = {};
}

async function save() {
    busy.value = true;
    errors.value = {};

    try {
        if (editing.value === 'new') {
            await api.post('proxies', form);
        } else {
            await api.put(`proxies/${editing.value}`, form);
        }

        editing.value = null;
        await load();
    } catch (error) {
        errors.value = validationErrors(error);
    } finally {
        busy.value = false;
    }
}

/** Only to size the button — the server decides what is actually a proxy. */
const bulkCount = computed(() => bulkText.value.split('\n').filter((line) => line.trim()).length);

async function addBulk() {
    busy.value = true;
    bulkResult.value = null;

    try {
        const { data } = await api.post('proxies/bulk', { text: bulkText.value, scheme: bulkScheme.value });
        bulkResult.value = data;

        /*
         * Lines that could not be read stay in the box, alone, so they can be
         * fixed and sent again. Clearing the whole paste would leave someone
         * holding a count of failures with no way to find them.
         */
        if (data.skipped.length) {
            bulkText.value = data.skipped.map((row) => row.line).join('\n');
        } else {
            bulkText.value = '';
            showBulk.value = false;
        }

        await load();
    } catch (error) {
        bulkResult.value = { created: 0, updated: 0, skipped: [], message: error.response?.data?.message ?? 'Không thêm được.' };
    } finally {
        busy.value = false;
    }
}

function toggle(id) {
    selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id);
    selected.value = new Set(selected.value);
}

function toggleAll() {
    selected.value = allSelected.value ? new Set() : new Set(proxies.value.map((proxy) => proxy.id));
}

/**
 * Poll while anything is still queued, and stop the moment nothing is.
 *
 * Every request costs a full round trip behind a tunnel, so this exists only
 * for as long as there is an answer outstanding -- never as a background timer
 * ticking over an idle page.
 */
let pollTimer;

function pollWhilePending() {
    clearTimeout(pollTimer);

    if (!pending.value.length) {
        return;
    }

    pollTimer = setTimeout(async () => {
        await load();
        pollWhilePending();
    }, 4000);
}

onUnmounted(() => clearTimeout(pollTimer));

/*
 * Picking the accounts that go behind the selected proxies.
 *
 * The other direction already exists on the accounts page, where a set of
 * accounts is handed to the whole pool. This is the same operation entered from
 * the end you are standing at: proxies in hand, choose who uses them.
 */
const picker = ref(false);
const pickerAccounts = ref([]);
const pickerSelected = ref(new Set());
const pickerSearch = ref('');
const pickerUnassignedOnly = ref(true);
const pickerMeta = ref(null);
const pickerPage = ref(1);
const pickerLoading = ref(false);

const pickerAllSelected = computed(() => pickerAccounts.value.length > 0
    && pickerAccounts.value.every((account) => pickerSelected.value.has(account.id)));

async function loadPicker() {
    pickerLoading.value = true;

    try {
        const { data } = await api.get('accounts', {
            params: {
                search: pickerSearch.value || undefined,
                without_proxy: pickerUnassignedOnly.value ? 1 : undefined,
                page: pickerPage.value,
                per_page: 200,
            },
        });

        pickerAccounts.value = data.data;
        pickerMeta.value = data.meta;
    } finally {
        pickerLoading.value = false;
    }
}

function openPicker() {
    picker.value = true;
    pickerSelected.value = new Set();
    pickerPage.value = 1;
    loadPicker();
}

let pickerTimer;
watch([pickerSearch, pickerUnassignedOnly], () => {
    pickerPage.value = 1;
    clearTimeout(pickerTimer);
    pickerTimer = setTimeout(loadPicker, 300);
});
watch(pickerPage, loadPicker);

function togglePicked(id) {
    pickerSelected.value.has(id) ? pickerSelected.value.delete(id) : pickerSelected.value.add(id);
    pickerSelected.value = new Set(pickerSelected.value);
}

function togglePickedAll() {
    // Read once, before the loop: mutating the Set is reactive, so the computed
    // would flip half way through and the loop would undo its own work.
    const clearing = pickerAllSelected.value;

    // This page only. The selection survives paging, so several pages can be
    // gathered up before assigning.
    pickerAccounts.value.forEach((account) => {
        clearing ? pickerSelected.value.delete(account.id) : pickerSelected.value.add(account.id);
    });

    pickerSelected.value = new Set(pickerSelected.value);
}

async function assignPicked() {
    busy.value = true;

    try {
        const { data } = await api.post('proxies/assign', {
            ids: [...selected.value],
            account_ids: [...pickerSelected.value],
        });

        /*
         * A selection that ran out of headroom has to say so. "Đã gán 500" over
         * accounts that still share one IP is the wrong kind of true, and it is
         * the exact state proxies exist to prevent.
         */
        window.alert(data.unassigned
            ? `Đã gán ${data.assigned} tài khoản. Còn ${data.unassigned} chưa có proxy — `
                + 'các proxy đã chọn hết chỗ. Chọn thêm proxy hoặc nâng "Tài khoản tối đa".'
            : `Đã gán ${data.assigned} tài khoản vào ${selected.value.size} proxy.`);

        picker.value = false;
        selected.value = new Set();
        await load();
    } catch (error) {
        window.alert(error.response?.data?.message ?? 'Không gán được.');
    } finally {
        busy.value = false;
    }
}

/*
 * Re-check everything on this page.
 *
 * The per-row button exists, but the state worth acting on is "these nine
 * verdicts are five days old", and clicking nine times is how that gets left
 * alone until a campaign fails.
 */
async function checkStale() {
    busy.value = true;

    try {
        await api.post('proxies/check', { ids: proxies.value.map((proxy) => proxy.id) });
        await load();
        pollWhilePending();
    } finally {
        busy.value = false;
    }
}
async function checkSelected() {
    busy.value = true;

    try {
        await api.post('proxies/check', { ids: [...selected.value] });
        await load();
        pollWhilePending();
    } finally {
        busy.value = false;
    }
}

/*
 * How many accounts a delete would set loose.
 *
 * Exact rather than an estimate: load() prunes the selection to the rows it
 * just fetched, so a tick can never survive a page change and everything
 * selected is on screen with its counter in hand.
 */
const selectedAccounts = computed(() => proxies.value
    .filter((proxy) => selected.value.has(proxy.id))
    .reduce((total, proxy) => total + (proxy.assigned_count ?? 0), 0));

/*
 * Said out loud before it happens, not refused.
 *
 * Deleting releases whatever accounts were behind these, and those accounts
 * then go out from the server's own IP until they are given another proxy --
 * which is the one thing this page exists to prevent. That is a consequence to
 * put in front of someone, not a reason to make a dead proxy undeletable.
 */
function confirmDelete(count, accounts) {
    const subject = `Xoá ${count} proxy`;

    if (! accounts) {
        return window.confirm(`${subject}?`);
    }

    return window.confirm(
        `${subject}?\n\n${accounts} tài khoản đang dùng sẽ được gỡ gán và tạm đi ra bằng IP máy chủ `
        + 'cho tới khi bạn gán proxy khác.'
    );
}

async function loadGroups() {
    const { data } = await api.get('proxy-groups');

    groups.value = data.data;
}

/*
 * The selection, put in a group.
 *
 * One request for the whole selection rather than one per proxy: this is how a
 * supplier's delivery of two thousand gets named, and two thousand requests is
 * not a way to name anything.
 *
 * Already-members are sent along with the rest -- "chọn tất cả" on a page where
 * half are in the group is the normal way this is used, and the server attaches
 * without detaching so the repeat is a no-op rather than an error.
 */
async function assignGroup(id) {
    if (! id) {
        return;
    }

    busy.value = true;

    try {
        await api.post(`proxy-groups/${id}/sync`, { attach: [...selected.value] });
        selected.value = new Set();
        await Promise.all([load(), loadGroups()]);
    } finally {
        busy.value = false;
    }
}

/** Only offered while a group is being viewed, because that is the only time
 *  "bỏ khỏi nhóm" names a group without asking which one. */
async function removeFromGroup() {
    busy.value = true;

    try {
        await api.post(`proxy-groups/${group.value}/sync`, { detach: [...selected.value] });
        selected.value = new Set();
        await Promise.all([load(), loadGroups()]);
    } finally {
        busy.value = false;
    }
}

async function createGroup() {
    if (! newGroup.name.trim()) {
        return;
    }

    busy.value = true;

    try {
        await api.post('proxy-groups', { name: newGroup.name.trim(), color: newGroup.color });
        newGroup.name = '';
        await loadGroups();
    } catch (error) {
        // The one that actually happens: a second person naming a delivery
        // that already has a name.
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
        await api.put(`proxy-groups/${row.id}`, { name });
        await loadGroups();
    } catch (error) {
        window.alert(validationErrors(error).name ?? 'Không đổi tên được.');
    }
}

/*
 * The name goes; the proxies stay.
 *
 * Said out loud because "Xoá" on a row showing "412 proxy" reads like it takes
 * them with it, and the one thing worse than losing a grouping is thinking you
 * have lost a supplier's whole delivery.
 */
async function deleteGroup(row) {
    if (! window.confirm(`Xoá nhóm "${row.name}"?\n\n${row.proxies_count} proxy trong nhóm vẫn được giữ nguyên, chỉ mất nhãn nhóm.`)) {
        return;
    }

    await api.delete(`proxy-groups/${row.id}`);

    if (group.value === String(row.id)) {
        group.value = '';
    }

    await Promise.all([load(), loadGroups()]);
}
async function removeSelected() {
    if (! confirmDelete(selected.value.size, selectedAccounts.value)) {
        return;
    }

    busy.value = true;

    try {
        const { data } = await api.delete('proxies', { data: { ids: [...selected.value] } });

        // Only when there is something to say. A confirmed delete that freed
        // nothing has already told the operator everything by the row going
        // away, and an alert for it is a second click for no news.
        if (data.unassigned) {
            window.alert(`Đã xoá ${data.deleted} proxy. ${data.unassigned} tài khoản đang chờ gán proxy khác.`);
        }

        selected.value = new Set();
        await load();
    } finally {
        busy.value = false;
    }
}

async function check(proxy) {
    checking.value.add(proxy.id);
    checking.value = new Set(checking.value);

    try {
        await api.post(`proxies/${proxy.id}/check`);
        await load();
    } finally {
        checking.value.delete(proxy.id);
        checking.value = new Set(checking.value);
    }
}

async function remove(proxy) {
    if (! confirmDelete(1, proxy.assigned_count ?? 0)) {
        return;
    }

    try {
        const { data } = await api.delete(`proxies/${proxy.id}`);

        if (data.unassigned) {
            window.alert(`Đã xoá proxy. ${data.unassigned} tài khoản đang chờ gán proxy khác.`);
        }

        await load();
    } catch (error) {
        window.alert(error.response?.data?.message ?? 'Không xoá được.');
    }
}
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Proxy</h1>
            <div class="flex gap-2">
                <button class="xp-btn-ghost" @click="showBulk = !showBulk">
                    {{ showBulk ? 'Đóng nhập hàng loạt' : 'Nhập hàng loạt' }}
                </button>
                <button class="xp-btn-primary" @click="startCreate">+ Thêm proxy</button>
            </div>
        </div>

        <p class="mb-5 max-w-3xl text-[13px] text-neutral-600">
            Gán proxy theo tài khoản để nhiều tài khoản không cùng đi ra từ một IP. Nút "Kiểm tra" gọi thẳng
            <code class="rounded bg-neutral-100 px-1">api.x.com</code> — proxy ra được internet nhưng bị X chặn thì vẫn vô dụng —
            rồi hỏi tiếp vị trí thật của IP đi ra.
        </p>

        <!--
            Bulk paste.

            Suppliers deliver a text blob and each one orders the columns
            differently, so the parser recognises each field by what it looks
            like rather than by where it sits. Nothing here has to be
            rearranged before pasting.
        -->
        <div v-if="showBulk" class="xp-card mb-5 p-4">
            <p class="mb-2 text-[13px] font-medium">Mỗi dòng một proxy</p>
            <textarea
                v-model="bulkText"
                rows="8"
                class="xp-field w-full font-mono text-xs"
                placeholder="1.2.3.4:8080:user:pass&#10;1.2.3.5|8080|user|pass&#10;user|pass|1.2.3.6|8080&#10;socks5://user:pass@1.2.3.7:1080&#10;1.2.3.8:8080"
            ></textarea>

            <div class="mt-2 flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-[12.5px] text-neutral-600">
                    Giao thức
                    <select v-model="bulkScheme" class="xp-field py-1 text-[12.5px]">
                        <option value="http">HTTP</option>
                        <option value="https">HTTPS</option>
                        <option value="socks4">SOCKS4</option>
                        <option value="socks5">SOCKS5</option>
                    </select>
                </label>

                <button class="xp-btn-primary" :disabled="busy || !bulkCount" @click="addBulk">
                    Thêm {{ bulkCount }} dòng
                </button>
            </div>

            <p class="mt-2 text-[11.5px] leading-relaxed text-neutral-500">
                Ngăn cách bằng <code class="rounded bg-neutral-100 px-1">|</code>
                <code class="rounded bg-neutral-100 px-1">:</code>
                <code class="rounded bg-neutral-100 px-1">,</code> tab hoặc dấu cách.
                Thứ tự cột tuỳ ý — host nhận ra qua IP/tên miền, port là số nằm cạnh host, còn lại là user rồi password.
                Không có user/password vẫn thêm được. Dòng nào ghi rõ <code class="rounded bg-neutral-100 px-1">socks5://</code>
                thì dùng giao thức đó thay cho ô bên trên.
            </p>

            <div v-if="bulkResult" class="mt-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-[12.5px]">
                <p v-if="bulkResult.message" class="text-red-700">{{ bulkResult.message }}</p>
                <p v-else class="font-medium">
                    Đã thêm {{ bulkResult.created }}, cập nhật {{ bulkResult.updated }}.
                </p>

                <!-- Named, not counted: a count of failures is not something anyone can act on. -->
                <div v-if="bulkResult.skipped.length" class="mt-2">
                    <p class="mb-1 text-red-700">{{ bulkResult.skipped.length }} dòng không đọc được — đã giữ lại ở ô trên:</p>
                    <ul class="max-h-40 space-y-0.5 overflow-y-auto font-mono text-[11.5px] text-neutral-600">
                        <li v-for="(row, index) in bulkResult.skipped" :key="index">
                            {{ row.line }} <span class="font-sans text-red-600">— {{ row.reason }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <form v-if="editing" class="xp-card mb-5 grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="save">
            <label class="space-y-1">
                <span class="xp-label">Nhãn</span>
                <input v-model="form.label" type="text" class="xp-field">
            </label>

            <label class="space-y-1">
                <span class="xp-label">Giao thức</span>
                <select v-model="form.scheme" class="xp-field">
                    <option value="http">HTTP</option>
                    <option value="https">HTTPS</option>
                    <option value="socks4">SOCKS4</option>
                    <option value="socks5">SOCKS5</option>
                </select>
            </label>

            <label class="space-y-1">
                <span class="xp-label">Quốc gia (ISO-2)</span>
                <input v-model="form.country" type="text" maxlength="2" class="xp-field" placeholder="VN">
                <!-- Overwritten by the next check, which reads it off the exit IP. -->
                <span class="text-[11px] text-neutral-500">Lần kiểm tra sau sẽ ghi đè theo IP thật.</span>
            </label>

            <label class="space-y-1 sm:col-span-2">
                <span class="xp-label">Host</span>
                <input v-model="form.host" type="text" required class="xp-field">
                <span v-if="errors.host" class="text-xs text-red-600">{{ errors.host }}</span>
            </label>

            <label class="space-y-1">
                <span class="xp-label">Port</span>
                <input v-model.number="form.port" type="number" min="1" max="65535" required class="xp-field">
            </label>

            <label class="space-y-1">
                <span class="xp-label">Username</span>
                <input v-model="form.username" type="text" class="xp-field" autocomplete="off">
            </label>

            <label class="space-y-1">
                <span class="xp-label">Password</span>
                <input v-model="form.password" type="password" class="xp-field" autocomplete="new-password" placeholder="Để trống nếu không đổi">
            </label>

            <label class="space-y-1">
                <span class="xp-label">Tài khoản tối đa</span>
                <input v-model.number="form.max_accounts" type="number" min="1" class="xp-field">
            </label>

            <div class="flex gap-2 sm:col-span-2 lg:col-span-3">
                <button type="submit" class="xp-btn-primary" :disabled="busy">Lưu</button>
                <button type="button" class="xp-btn-ghost" @click="editing = null">Huỷ</button>
            </div>
        </form>

        <!--
            Said before a campaign says it.

            A proxy that has started refusing keeps its green badge until
            something asks it again, and the thing that usually asks is a
            publish run -- which then fails for every account behind it.
        -->
        <div
            v-if="staleCount"
            class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 text-[13px] text-amber-900"
        >
            <strong class="font-semibold">{{ staleCount }} proxy đang hiện "healthy" từ hơn 24 giờ trước.</strong>
            <span>Kết quả cũ không nói được proxy còn dùng được hay không — bài đăng sẽ là thứ phát hiện ra.</span>
            <button
                class="ml-auto rounded border border-amber-400 px-2 py-1 text-[12.5px] transition hover:bg-amber-100 disabled:opacity-50"
                :disabled="busy"
                @click="checkStale"
            >Kiểm tra lại trang này</button>
        </div>
        <!--
            Filtered on the server, so this searches the pool rather than the
            page -- which is the only version of the question worth asking once
            a supplier has delivered two thousand of them.
        -->
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <input
                v-model="search"
                class="xp-field max-w-[280px] py-1.5 text-[13px]"
                placeholder="Tìm host, IP đi ra, nhãn, quốc gia…"
            >

            <select v-model="health" class="xp-field max-w-[170px] py-1.5 text-[13px]">
                <option value="">Mọi tình trạng</option>
                <option value="healthy">healthy</option>
                <option value="degraded">degraded</option>
                <option value="dead">dead</option>
                <option value="unknown">unknown</option>
            </select>

            <!--
                Filtered on the server like the rest: a group of two hundred
                spread over four pages would otherwise come back looking like
                whichever fifty had already been downloaded.
            -->
            <select v-model="group" class="xp-field max-w-[200px] py-1.5 text-[13px]">
                <option value="">Mọi nhóm</option>
                <option v-for="row in groups" :key="row.id" :value="String(row.id)">
                    {{ row.name }} ({{ row.proxies_count }})
                </option>
            </select>

            <button class="text-[12.5px] text-neutral-500 underline-offset-2 hover:underline" @click="managing = true">
                Quản lý nhóm
            </button>

            <span v-if="meta" class="ml-auto text-[12.5px] text-neutral-500">
                {{ meta.total.toLocaleString('vi-VN') }} proxy
            </span>
        </div>

        <!--
            Only present when there is a selection. A permanently visible bar
            with disabled buttons is a row of things you cannot do.
        -->
        <div
            v-if="selected.size"
            class="mb-3 flex flex-wrap items-center gap-3 rounded-lg border border-neutral-900/10 bg-neutral-900 px-4 py-2.5 text-[13px] text-white"
        >
            <span class="font-medium">Đã chọn {{ selected.size }}</span>
            <button class="rounded px-2 py-1 transition hover:bg-white/15" :disabled="busy" @click="openPicker">
                Gắn tài khoản
            </button>
            <button class="rounded px-2 py-1 transition hover:bg-white/15" :disabled="busy" @click="checkSelected">
                Kiểm tra
            </button>

            <!--
                A select rather than a dialog: picking a name off a short list is
                the whole interaction, and it resets itself so the same group can
                be chosen again for the next selection.
            -->
            <select
                class="rounded bg-white/10 px-2 py-1 text-[13px] text-white transition hover:bg-white/15 disabled:opacity-50"
                :disabled="busy || !groups.length"
                :value="''"
                @change="assignGroup($event.target.value); $event.target.value = ''"
            >
                <option value="" class="text-neutral-900">Gán nhóm…</option>
                <option v-for="row in groups" :key="row.id" :value="row.id" class="text-neutral-900">
                    {{ row.name }}
                </option>
            </select>

            <!-- Only while a group is being viewed, because only then does
                 "bỏ khỏi nhóm" name a group without asking which. -->
            <button
                v-if="group"
                class="rounded px-2 py-1 transition hover:bg-white/15"
                :disabled="busy"
                @click="removeFromGroup"
            >
                Bỏ khỏi nhóm
            </button>
            <button class="rounded px-2 py-1 text-red-300 transition hover:bg-white/15" :disabled="busy" @click="removeSelected">
                Xoá
            </button>
            <button class="ml-auto text-white/60 transition hover:text-white" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <!--
            Creating and renaming the names themselves.

            A dialog rather than a page of its own: a group is a name and a
            colour, and a whole route for two fields would be a heavier thing
            than the thing it manages.
        -->
        <div v-if="managing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="managing = false">
            <div class="flex max-h-[85vh] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-[15px] font-semibold">Nhóm proxy</h2>
                    <p class="mt-0.5 text-[12.5px] text-neutral-500">
                        Đặt tên cho từng lô proxy — theo nhà cung cấp, quốc gia hay mục đích.
                        Một proxy có thể thuộc nhiều nhóm.
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
                        <span class="shrink-0 text-[12.5px] text-neutral-500">{{ row.proxies_count }} proxy</span>
                        <button class="shrink-0 text-neutral-500 hover:underline" @click="renameGroup(row)">Đổi tên</button>
                        <button class="shrink-0 text-red-600 hover:underline" @click="deleteGroup(row)">Xoá</button>
                    </div>
                </div>

                <div class="border-t border-neutral-200 px-5 py-3 text-right">
                    <button class="xp-btn-ghost" @click="managing = false">Đóng</button>
                </div>
            </div>
        </div>
        <!--
            Choosing who goes behind the selected proxies.

            A dialog rather than a page: the proxy selection is the context, and
            navigating away to the accounts page would lose it.
        -->
        <div v-if="picker" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="picker = false">
            <div class="flex max-h-[85vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl bg-white shadow-xl">
                <div class="border-b border-neutral-200 px-5 py-4">
                    <h2 class="text-[15px] font-semibold">Gắn {{ selected.size }} proxy cho tài khoản</h2>
                    <p class="mt-0.5 text-[12.5px] text-neutral-500">
                        Tài khoản được chia đều vào các proxy đã chọn, proxy rỗng nhất trước.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 px-5 py-3">
                    <input v-model="pickerSearch" class="xp-field max-w-[240px] py-1 text-[12.5px]" placeholder="Tìm @handle hoặc tên…">
                    <label class="flex items-center gap-1.5 text-[12.5px] text-neutral-600">
                        <input v-model="pickerUnassignedOnly" type="checkbox">
                        Chỉ tài khoản chưa có proxy
                    </label>
                    <span v-if="pickerMeta" class="ml-auto text-[12.5px] text-neutral-500">
                        {{ pickerMeta.total }} kết quả
                    </span>
                </div>

                <div class="min-h-0 flex-1 overflow-y-auto">
                    <table class="w-full text-left text-[13px]">
                        <thead class="sticky top-0 border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                            <tr>
                                <th class="w-10 py-2.5 pl-5 pr-1">
                                    <input type="checkbox" :checked="pickerAllSelected" @change="togglePickedAll">
                                </th>
                                <th class="px-4 py-2.5 font-medium">Tài khoản</th>
                                <th class="px-4 py-2.5 font-medium">Proxy hiện tại</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100">
                            <tr v-if="pickerLoading"><td colspan="3" class="px-5 py-8 text-center text-neutral-500">Đang tải…</td></tr>
                            <tr v-else-if="!pickerAccounts.length">
                                <td colspan="3" class="px-5 py-8 text-center text-neutral-500">
                                    Không có tài khoản nào khớp.
                                </td>
                            </tr>

                            <tr
                                v-for="account in pickerAccounts"
                                :key="account.id"
                                class="cursor-pointer hover:bg-neutral-50"
                                @click="togglePicked(account.id)"
                            >
                                <td class="w-10 py-2 pl-5 pr-1">
                                    <input type="checkbox" :checked="pickerSelected.has(account.id)" @click.stop="togglePicked(account.id)">
                                </td>
                                <td class="px-4 py-2">
                                    <p class="font-medium">{{ account.handle }}</p>
                                    <p class="truncate text-xs text-neutral-500">{{ account.name }}</p>
                                </td>
                                <!--
                                    Shown because reassigning is a real choice:
                                    an account already behind a working proxy is
                                    usually not the one you meant to move.
                                -->
                                <td class="px-4 py-2">
                                    <span v-if="account.proxy" class="text-neutral-600">
                                        <span class="font-mono text-[12px]">{{ account.proxy.host }}:{{ account.proxy.port }}</span>
                                        <span class="text-xs text-neutral-400">{{ account.proxy.country || '' }}</span>
                                    </span>
                                    <span v-else class="text-xs text-amber-600">IP server</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-3 border-t border-neutral-200 px-5 py-3">
                    <button
                        v-if="pickerMeta && pickerMeta.last_page > 1"
                        class="xp-btn-ghost"
                        :disabled="pickerPage <= 1"
                        @click="pickerPage--"
                    >‹ Trước</button>
                    <span v-if="pickerMeta && pickerMeta.last_page > 1" class="text-[12.5px] text-neutral-500">
                        Trang {{ pickerMeta.current_page }}/{{ pickerMeta.last_page }}
                    </span>
                    <button
                        v-if="pickerMeta && pickerMeta.last_page > 1"
                        class="xp-btn-ghost"
                        :disabled="pickerPage >= pickerMeta.last_page"
                        @click="pickerPage++"
                    >Sau ›</button>

                    <button class="xp-btn-ghost ml-auto" @click="picker = false">Huỷ</button>
                    <button class="xp-btn-primary" :disabled="busy || !pickerSelected.size" @click="assignPicked">
                        Gắn {{ pickerSelected.size }} tài khoản
                    </button>
                </div>
            </div>
        </div>

        <p v-if="pending.length" class="mb-3 text-[12.5px] text-neutral-500">
            Đang kiểm tra {{ pending.length }} proxy trong hàng đợi — kết quả hiện dần, không cần chờ ở đây.
        </p>

        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-[13px]">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="w-10 pl-4 pr-1 py-2.5">
                            <input type="checkbox" :checked="allSelected" class="align-middle" @change="toggleAll">
                        </th>
                        <th class="px-4 py-2.5 font-medium">Proxy</th>
                        <th class="px-4 py-2.5 font-medium">Vị trí</th>
                        <th class="px-4 py-2.5 font-medium">Tình trạng</th>
                        <th class="px-4 py-2.5 font-medium">Độ trễ</th>
                        <th class="px-4 py-2.5 font-medium">Tài khoản</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    <tr v-if="loading"><td colspan="7" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td></tr>
                    <tr v-else-if="!proxies.length"><td colspan="7" class="px-4 py-8 text-center text-neutral-500">Chưa có proxy nào.</td></tr>

                    <tr v-for="proxy in proxies" :key="proxy.id" class="hover:bg-neutral-50" :class="selected.has(proxy.id) ? 'bg-neutral-50' : ''">
                        <td class="w-10 py-3 pl-4 pr-1">
                            <input type="checkbox" :checked="selected.has(proxy.id)" class="align-middle" @change="toggle(proxy.id)">
                        </td>
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ proxy.label || `${proxy.host}:${proxy.port}` }}</p>
                            <p class="text-xs text-neutral-500">{{ proxy.scheme }}://{{ proxy.host }}:{{ proxy.port }}</p>

                            <!--
                                Under the identity rather than in a column of
                                their own: the table is already at its minimum
                                width, and which batch a line came from is part
                                of what the line is.
                            -->
                            <p v-if="proxy.groups?.length" class="mt-1 flex flex-wrap gap-1">
                                <button
                                    v-for="row in proxy.groups"
                                    :key="row.id"
                                    type="button"
                                    class="rounded-full px-1.5 py-0.5 text-[11px] leading-tight transition hover:brightness-95"
                                    :style="{ backgroundColor: `${row.color}1f`, color: row.color }"
                                    @click="group = String(row.id)"
                                >{{ row.name }}</button>
                            </p>
                        </td>

                        <!--
                            Read off the exit IP by the check, not off the label
                            the seller wrote. The two disagree often enough that
                            assigning accounts by country off the label is
                            guesswork.
                        -->
                        <td class="px-4 py-3">
                            <p v-if="proxy.country" class="text-neutral-700">
                                {{ [proxy.city, proxy.region, proxy.country].filter(Boolean).join(', ') }}
                            </p>
                            <p v-else class="text-neutral-400">—</p>
                            <p v-if="proxy.exit_ip" class="font-mono text-xs text-neutral-500">{{ proxy.exit_ip }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <!--
                                The previous verdict stays readable while a new
                                one is being worked out -- "đang kiểm tra" that
                                erased what was there would lose the only thing
                                on the row worth knowing.
                            -->
                            <span v-if="proxy.check_queued_at" class="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">
                                đang kiểm tra…
                            </span>
                            <template v-else>
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="isStale(proxy) ? 'bg-neutral-100 text-neutral-500' : healthClass[proxy.health_status]"
                                >{{ proxy.health_status }}</span>

                                <!--
                                    The age, always. A badge with no date is a
                                    claim about now made from whenever.
                                -->
                                <p
                                    class="mt-0.5 text-[11px]"
                                    :class="isStale(proxy) ? 'text-amber-700' : 'text-neutral-400'"
                                >{{ verdictAge(proxy) }}</p>
                            </template>
                        </td>
                        <td class="px-4 py-3 text-neutral-600">{{ proxy.latency_ms ? `${proxy.latency_ms} ms` : '—' }}</td>
                        <td class="px-4 py-3 text-neutral-600">{{ proxy.assigned_count }}/{{ proxy.max_accounts }}</td>
                        <td class="px-4 py-3 text-right">
                            <button class="mr-2 text-blue-600 hover:underline" :disabled="checking.has(proxy.id)" @click="check(proxy)">
                                {{ checking.has(proxy.id) ? 'Đang kiểm tra…' : 'Kiểm tra' }}
                            </button>
                            <button class="mr-2 text-neutral-600 hover:underline" @click="startEdit(proxy)">Sửa</button>
                            <button class="text-red-600 hover:underline" @click="remove(proxy)">Xoá</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Only when there is more than one page to be on. -->
        <div v-if="meta && meta.last_page > 1" class="mt-4 flex items-center gap-3">
            <button class="xp-btn-ghost" :disabled="page <= 1" @click="page--">Trước</button>
            <span class="text-[13px] text-neutral-600">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
            <button class="xp-btn-ghost" :disabled="page >= meta.last_page" @click="page++">Sau</button>
        </div>
    </div>
</template>
