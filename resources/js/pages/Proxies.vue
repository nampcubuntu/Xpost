<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import api, { validationErrors } from '../lib/api';
import { cached, swr } from '../lib/cache';

const proxies = ref([]);
const meta = ref(null);
const page = ref(1);
const search = ref('');
const health = ref('');
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
    const key = `proxies:${search.value}:${health.value}:${page.value}`;

    // Last answer first, then the true one: arriving at a page that has not
    // changed should not mean staring at an empty table while it is proved.
    loading.value = cached(key) === undefined;

    try {
        await swr(key, async () => (await api.get('proxies', {
            params: {
                search: search.value || undefined,
                health: health.value || undefined,
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
watch([search, health], () => {
    page.value = 1;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});
watch(page, load);

onMounted(load);

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

async function removeSelected() {
    const count = selected.value.size;

    // Confirmed because accounts are assigned to these: a proxy deleted out
    // from under them is a publish that starts failing tomorrow.
    if (!window.confirm(`Xoá ${count} proxy đã chọn?`)) {
        return;
    }

    busy.value = true;

    try {
        const { data } = await api.delete('proxies', { data: { ids: [...selected.value] } });

        // Named, not counted: "2 bị bỏ qua" is not something anyone can act on.
        if (data.skipped.length) {
            window.alert(
                `Đã xoá ${data.deleted}. Còn ${data.skipped.length} proxy đang gán tài khoản:\n\n`
                + data.skipped.map((row) => `• ${row.label}`).join('\n')
            );
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
    if (!window.confirm('Xoá proxy này?')) {
        return;
    }

    try {
        await api.delete(`proxies/${proxy.id}`);
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
            <button class="rounded px-2 py-1 text-red-300 transition hover:bg-white/15" :disabled="busy" @click="removeSelected">
                Xoá
            </button>
            <button class="ml-auto text-white/60 transition hover:text-white" @click="selected = new Set()">Bỏ chọn</button>
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
                            <span v-else class="rounded-full px-2 py-0.5 text-xs font-medium" :class="healthClass[proxy.health_status]">
                                {{ proxy.health_status }}
                            </span>
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
