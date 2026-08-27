<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../lib/api';
import NavIcon from '../components/NavIcon.vue';

/*
 * The Cloudflare KV namespace a Worker reads, edited here instead of in the
 * Cloudflare dashboard -- which has no bulk entry, no search beyond a prefix,
 * and takes one click per pair.
 *
 * Nothing is mirrored locally. A stale copy of someone else's store would show
 * keys that no longer resolve and hide ones written from anywhere else, which
 * is worse than a page that takes a moment to load.
 */
const rows = ref([]);
const cursor = ref(null);
const pages = ref([]);        // cursors already visited, so Back works
const loading = ref(true);
const busy = ref(false);
const flash = ref(null);
const configured = ref(true);

const prefix = ref('');
const newKey = ref('');
const newValue = ref('');
const bulkText = ref('');
const showBulk = ref(false);

/*
 * Which host to show a code under.
 *
 * Every domain routes to the same Worker over the same namespace, so any code
 * resolves on all of them -- this only decides what gets copied, and switching
 * it rewrites every row at once.
 */
const domains = ref([]);
const viewDomain = ref(null);
const newDomain = ref('');
const showDomains = ref(false);

const activeDomains = computed(() => domains.value.filter((d) => d.is_active));

const currentHost = computed(() => activeDomains.value.find((d) => d.id === viewDomain.value)
    ?? activeDomains.value.find((d) => d.is_default)
    ?? activeDomains.value[0]
    ?? null);

/*
 * The prefix that separates kinds of entry in the namespace is the same thing
 * that routes them at the Worker: `r:abc` is stored under one colon and served
 * from `/r/abc`. Dropping the prefix instead produced links that looked right
 * and resolved to nothing.
 */
const pathFor = (key) => key.replaceAll(':', '/');

const shortUrl = (key) => (currentHost.value
    ? `https://${currentHost.value.host}/${pathFor(key)}`
    : null);

async function addDomain() {
    const host = newDomain.value.trim();

    if (!host) {
        return;
    }

    busy.value = true;

    try {
        const { data } = await api.post('link-domains', { host });
        domains.value = [...domains.value, data.data];
        newDomain.value = '';
        flash.value = { type: 'ok', message: `Đã thêm ${data.data.host}.` };
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không thêm được.' };
    } finally {
        busy.value = false;
    }
}

async function updateDomain(domain, changes) {
    busy.value = true;

    try {
        await api.put(`link-domains/${domain.id}`, changes);
        const { data } = await api.get('link-domains');
        domains.value = data.data;
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không lưu được.' };
    } finally {
        busy.value = false;
    }
}

async function removeDomain(domain) {
    if (!window.confirm(`Xoá ${domain.host}?\n\nLink đã đăng dùng domain này vẫn hoạt động — Cloudflare mới là nơi định tuyến.`)) {
        return;
    }

    busy.value = true;

    try {
        await api.delete(`link-domains/${domain.id}`);
        const { data } = await api.get('link-domains');
        domains.value = data.data;

        if (viewDomain.value === domain.id) {
            viewDomain.value = null;
        }
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không xoá được.' };
    } finally {
        busy.value = false;
    }
}

/*
 * What the two fields will actually be saved as.
 *
 * The server does this shaping too and is the authority; this is here so it is
 * visible while typing rather than a surprise in the row that appears after.
 */
const keyPreview = computed(() => {
    const typed = newKey.value.trim();

    if (!typed) {
        return 'r:<tự sinh>';
    }

    return typed.includes(':') ? typed : `r:${typed}`;
});

const valuePreview = computed(() => {
    const typed = newValue.value.trim();

    if (!typed || typed.includes('|')) {
        return typed;
    }

    return currentHost.value ? `${typed}|https://${currentHost.value.host}/` : typed;
});

/*
 * TinyURL, minted with the entry and never on load.
 *
 * Listing a page is a read; TinyURL mints a new code per request, so shortening
 * every row a page happens to show would leave a trail of unused codes behind
 * each visit.
 */
const tinyEnabled = ref(false);

async function shortenRows(keys) {
    busy.value = true;

    try {
        const { data } = await api.post('links/shorten', { keys });

        rows.value = rows.value.map((row) => (data.data[row.key]
            ? { ...row, tiny_url: data.data[row.key] }
            : row));

        const made = Object.values(data.data).filter(Boolean).length;
        flash.value = made
            ? { type: 'ok', message: `Đã rút gọn ${made} link.` }
            : { type: 'error', message: 'TinyURL không trả về link nào.' };
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không rút gọn được.' };
    } finally {
        busy.value = false;
    }
}

/** Every row on this page that has no tiny link yet. */
function shortenPage() {
    const missing = rows.value.filter((row) => !row.tiny_url).map((row) => row.key);

    if (missing.length) {
        shortenRows(missing);
    }
}

const bulkCount = computed(() => bulkText.value
    .split('\n')
    .filter((line) => line.includes('=') || line.includes('\t'))
    .length);

async function load(nextCursor = null) {
    loading.value = true;

    try {
        const { data } = await api.get('links', {
            params: { prefix: prefix.value || undefined, cursor: nextCursor || undefined },
        });

        rows.value = data.data;
        cursor.value = data.cursor;
        domains.value = data.domains ?? [];
        tinyEnabled.value = data.tinyurl_enabled === true;
        configured.value = true;
    } catch (error) {
        configured.value = error.response?.data?.configured !== false;
        rows.value = [];
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không tải được.' };
    } finally {
        loading.value = false;
    }
}

function search() {
    pages.value = [];
    load();
}

function nextPage() {
    pages.value.push(cursor.value);
    load(cursor.value);
}

function previousPage() {
    pages.value.pop();
    load(pages.value[pages.value.length - 1] ?? null);
}

async function addEntry() {
    if (!newValue.value.trim()) {
        return;
    }

    busy.value = true;

    try {
        const { data } = await api.post('links', {
            key: newKey.value.trim() || undefined,
            value: newValue.value.trim(),
        });

        flash.value = { type: 'ok', message: `Đã thêm ${data.data.key}.` };
        newKey.value = '';
        newValue.value = '';
        await load(pages.value[pages.value.length - 1] ?? null);
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không thêm được.' };
    } finally {
        busy.value = false;
    }
}

/**
 * One pair per line, `key = value` or key and value separated by a tab -- so a
 * spreadsheet column pastes straight in.
 */
function parseBulk() {
    return bulkText.value
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
        .map((line) => {
            const at = line.includes('\t') ? line.indexOf('\t') : line.indexOf('=');

            return at === -1
                ? null
                : { key: line.slice(0, at).trim(), value: line.slice(at + 1).trim() };
        })
        .filter((pair) => pair && pair.key && pair.value);
}

async function addBulk() {
    const pairs = parseBulk();

    if (!pairs.length) {
        flash.value = { type: 'error', message: 'Chưa nhận ra dòng nào. Mỗi dòng: key = value' };

        return;
    }

    busy.value = true;

    try {
        const { data } = await api.post('links/bulk', { pairs });
        flash.value = { type: 'ok', message: `Đã ghi ${data.written} cặp.` };
        bulkText.value = '';
        showBulk.value = false;
        await load();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không ghi được.' };
    } finally {
        busy.value = false;
    }
}

async function remove(key) {
    // Confirmed because a live post may be pointing at it: deleting the key
    // does not unpublish anything, it just makes the link stop resolving.
    if (!window.confirm(`Xoá "${key}"?\n\nBài đã đăng trỏ tới link này sẽ hỏng.`)) {
        return;
    }

    busy.value = true;

    try {
        await api.delete('links', { data: { key } });
        rows.value = rows.value.filter((row) => row.key !== key);
        flash.value = { type: 'ok', message: `Đã xoá ${key}.` };
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không xoá được.' };
    } finally {
        busy.value = false;
    }
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        flash.value = { type: 'ok', message: 'Đã sao chép.' };
    } catch {
        // Clipboard is blocked outside a secure context; the text is on screen.
    }
}

onMounted(load);
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Short link</h1>
                <p class="mt-1 text-[13px] text-neutral-500">
                    Các cặp key/value trong Cloudflare KV mà Worker đọc để phân giải link.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <!--
                    Every domain resolves every code, so this only decides what
                    gets copied -- switching it rewrites every row at once.
                -->
                <select v-if="activeDomains.length > 1" v-model="viewDomain" class="xp-field max-w-[220px] py-2 text-[13px]">
                    <option :value="null">Domain mặc định</option>
                    <option v-for="domain in activeDomains" :key="domain.id" :value="domain.id">
                        {{ domain.host }}
                    </option>
                </select>

                <button
                    v-if="tinyEnabled"
                    class="xp-btn-ghost"
                    :disabled="busy || loading"
                    title="Rút gọn mọi dòng trên trang này chưa có link tiny"
                    @click="shortenPage"
                >
                    Rút gọn cả trang
                </button>

                <button class="xp-btn-ghost" @click="showDomains = !showDomains">
                    Domain ({{ activeDomains.length }})
                </button>
                <button class="xp-btn-ghost" @click="showBulk = !showBulk">
                    {{ showBulk ? 'Đóng nhập hàng loạt' : 'Nhập hàng loạt' }}
                </button>
            </div>
        </div>

        <div
            v-if="flash"
            class="mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-[13px]"
            :class="flash.type === 'error'
                ? 'border-red-200 bg-red-50 text-red-800'
                : 'border-green-200 bg-green-50 text-green-800'"
        >
            <span>{{ flash.message }}</span>
            <button class="opacity-60 hover:opacity-100" @click="flash = null">✕</button>
        </div>

        <!--
            Nothing on this page works without the three credentials, and the
            failure is otherwise a generic 422 on every action.
        -->
        <div
            v-if="!configured"
            class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900"
        >
            <strong class="font-semibold">Chưa kết nối Cloudflare KV.</strong>
            Điền vào <code class="rounded bg-amber-100 px-1 font-mono">.env</code>:
            <code class="mt-1 block font-mono text-xs">
                CLOUDFLARE_ACCOUNT_ID=<br>
                CLOUDFLARE_KV_NAMESPACE_ID=<br>
                CLOUDFLARE_API_TOKEN=
            </code>
            <p class="mt-1 text-xs">
                Token chỉ cần đúng một quyền: <strong>Workers KV Storage: Edit</strong>. Đừng dùng Global API Key.
                Domain thì quản lý ngay trên trang này, không cần đặt trong <code class="font-mono">.env</code>.
            </p>
        </div>

        <!-- Domains -->
        <div v-if="showDomains" class="xp-card mb-4 p-4">
            <p class="mb-1 text-[13px] font-medium">Domain phục vụ short link</p>
            <p class="mb-3 text-xs text-neutral-500">
                Mọi domain trỏ về cùng một Worker đều phân giải được mọi mã — nên đây là lựa chọn
                lúc dùng link, không phải thuộc tính của key. Xoá domain ở đây không tắt nó trên
                Cloudflare.
            </p>

            <ul class="mb-3 divide-y divide-neutral-100 rounded-md border border-neutral-200">
                <li v-if="!domains.length" class="px-3 py-2 text-[13px] text-neutral-500">
                    Chưa có domain nào — thêm một cái để dựng được short URL.
                </li>
                <li v-for="domain in domains" :key="domain.id" class="flex items-center gap-3 px-3 py-2">
                    <span class="flex-1 font-mono text-[13px]" :class="domain.is_active ? '' : 'text-neutral-400 line-through'">
                        {{ domain.host }}
                    </span>
                    <span v-if="domain.label" class="text-xs text-neutral-500">{{ domain.label }}</span>

                    <span v-if="domain.is_default" class="rounded bg-neutral-900 px-1.5 py-0.5 text-[11px] text-white">
                        mặc định
                    </span>
                    <button
                        v-else
                        class="text-[12px] text-blue-600 hover:underline"
                        :disabled="busy"
                        @click="updateDomain(domain, { is_default: true })"
                    >Đặt mặc định</button>

                    <button
                        class="text-[12px] text-neutral-600 hover:underline"
                        :disabled="busy"
                        @click="updateDomain(domain, { is_active: !domain.is_active })"
                    >{{ domain.is_active ? 'Tắt' : 'Bật' }}</button>

                    <button class="text-[12px] text-red-600 hover:underline" :disabled="busy" @click="removeDomain(domain)">
                        Xoá
                    </button>
                </li>
            </ul>

            <form class="flex flex-wrap gap-2" @submit.prevent="addDomain">
                <input v-model="newDomain" class="xp-field max-w-xs font-mono text-[13px]" placeholder="link.zenomedia.co">
                <button class="xp-btn-primary" :disabled="busy || !newDomain.trim()">Thêm domain</button>
                <span class="self-center text-xs text-neutral-500">Dán cả URL cũng được — chỉ phần host được giữ.</span>
            </form>
        </div>

        <!-- Bulk -->
        <div v-if="showBulk" class="xp-card mb-4 p-4">
            <p class="mb-2 text-[13px] font-medium">Mỗi dòng một cặp</p>
            <textarea
                v-model="bulkText"
                rows="8"
                class="xp-field w-full font-mono text-xs"
                placeholder="r:095Lrdja = https://imgur.com/a/l8qLXZa|https://twvideo.top/"
            ></textarea>
            <div class="mt-2 flex items-center gap-3">
                <button class="xp-btn-primary" :disabled="busy || !bulkCount" @click="addBulk">
                    Ghi {{ bulkCount }} cặp
                </button>
                <span class="text-xs text-neutral-500">
                    Ngăn cách bằng <code class="rounded bg-neutral-100 px-1">=</code> hoặc tab, nên dán thẳng từ bảng tính được.
                </span>
            </div>
        </div>

        <!-- Add one -->
        <div class="xp-card mb-4 p-4">
            <div class="grid gap-3 sm:grid-cols-[220px_1fr_auto]">
                <label class="space-y-1">
                    <span class="xp-label">Key</span>
                    <input v-model="newKey" class="xp-field font-mono text-[13px]" placeholder="095Lrdja">
                </label>
                <label class="space-y-1">
                    <span class="xp-label">Value</span>
                    <input v-model="newValue" class="xp-field font-mono text-[13px]" placeholder="https://imgur.com/a/…">
                </label>
                <div class="flex items-end">
                    <button class="xp-btn-primary w-full sm:w-auto" :disabled="busy || !newValue.trim()" @click="addEntry">
                        Thêm
                    </button>
                </div>
            </div>

            <!--
                The prefix and the destination are filled in on save. Shown here
                because a field that quietly rewrites what was typed is worse
                than one that does not help at all.
            -->
            <p class="mt-2 truncate font-mono text-[11px] text-neutral-500">
                Sẽ lưu: <span class="text-neutral-800">{{ keyPreview }}</span>
                <span v-if="valuePreview"> = <span class="text-neutral-800">{{ valuePreview }}</span></span>
            </p>
        </div>

        <!-- Search -->
        <form class="mb-4 flex flex-wrap items-center gap-2" @submit.prevent="search">
            <input v-model="prefix" class="xp-field max-w-xs font-mono text-[13px]" placeholder="Lọc theo tiền tố key…">
            <button class="xp-btn-ghost">Tìm</button>
            <span v-if="prefix" class="text-xs text-neutral-500">KV chỉ tìm được theo tiền tố, không tìm trong value.</span>
        </form>

        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1100px] text-left text-[13px]">
                    <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Key</th>
                            <th class="px-4 py-2.5 font-medium">Value</th>
                            <th class="px-4 py-2.5 font-medium">Short link</th>
                            <th class="px-4 py-2.5 font-medium">Tiny</th>
                            <th class="w-24 px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-if="loading"><td colspan="5" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td></tr>
                        <tr v-else-if="!rows.length">
                            <td colspan="5" class="px-4 py-8 text-center text-neutral-500">Chưa có cặp nào.</td>
                        </tr>

                        <tr v-for="row in rows" :key="row.key" class="hover:bg-neutral-50">
                            <td class="whitespace-nowrap px-4 py-2.5 align-top font-mono">{{ row.key }}</td>

                            <td class="px-4 py-2.5 align-top">
                                <p class="break-all font-mono text-[12px] text-neutral-700">{{ row.value ?? '—' }}</p>
                            </td>

                            <td class="px-4 py-2.5 align-top">
                                <button
                                    v-if="shortUrl(row.key)"
                                    class="flex items-center gap-1 whitespace-nowrap font-mono text-[12px] text-blue-600 hover:underline"
                                    :title="'Sao chép ' + shortUrl(row.key)"
                                    @click="copy(shortUrl(row.key))"
                                >
                                    <NavIcon name="copy" :size="11" class="shrink-0" />
                                    {{ shortUrl(row.key) }}
                                </button>
                                <span v-else class="text-[12px] text-neutral-400">chưa có domain</span>
                            </td>

                            <!--
                                Minted with the entry, or here for rows that
                                predate it. Never on load: listing a page is a
                                read, and TinyURL mints a new code per request.
                            -->
                            <td class="px-4 py-2.5 align-top">
                                <button
                                    v-if="row.tiny_url"
                                    class="flex items-center gap-1 whitespace-nowrap font-mono text-[12px] text-blue-600 hover:underline"
                                    :title="'Sao chép ' + row.tiny_url"
                                    @click="copy(row.tiny_url)"
                                >
                                    <NavIcon name="copy" :size="11" class="shrink-0" />
                                    {{ row.tiny_url }}
                                </button>
                                <button
                                    v-else-if="tinyEnabled"
                                    class="whitespace-nowrap text-[12px] text-neutral-500 hover:underline disabled:opacity-50"
                                    :disabled="busy"
                                    @click="shortenRows([row.key])"
                                >
                                    Rút gọn
                                </button>
                                <span v-else class="text-[12px] text-neutral-400">—</span>
                            </td>
                            <td class="px-4 py-2.5 text-right align-top">
                                <button class="text-red-600 hover:underline" :disabled="busy" @click="remove(row.key)">
                                    Xoá
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!--
            KV pages by cursor, not by page number: it can go forward, and back
            only through cursors already seen.
        -->
        <div class="mt-4 flex items-center gap-2">
            <button class="xp-btn-ghost" :disabled="!pages.length" @click="previousPage">Trước</button>
            <button class="xp-btn-ghost" :disabled="!cursor" @click="nextPage">Sau</button>
        </div>
    </div>
</template>
