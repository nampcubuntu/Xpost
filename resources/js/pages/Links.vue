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
const baseUrl = ref('');
const loading = ref(true);
const busy = ref(false);
const flash = ref(null);
const configured = ref(true);

const prefix = ref('');
const newKey = ref('');
const newValue = ref('');
const bulkText = ref('');
const showBulk = ref(false);

const shortUrl = (key) => (baseUrl.value
    // The Worker is reached by the code, not by the key it is stored under.
    ? `${baseUrl.value}/${key.replace(/^[^:]*:/, '')}`
    : null);

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
        baseUrl.value = data.base_url ?? '';
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

            <button class="xp-btn-ghost" @click="showBulk = !showBulk">
                {{ showBulk ? 'Đóng nhập hàng loạt' : 'Nhập hàng loạt' }}
            </button>
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
                CLOUDFLARE_API_TOKEN=<br>
                XPOST_LINK_BASE_URL=https://…
            </code>
            <p class="mt-1 text-xs">
                Token chỉ cần đúng một quyền: <strong>Workers KV Storage: Edit</strong>. Đừng dùng Global API Key.
            </p>
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
        <div class="xp-card mb-4 grid gap-3 p-4 sm:grid-cols-[220px_1fr_auto]">
            <label class="space-y-1">
                <span class="xp-label">Key</span>
                <input v-model="newKey" class="xp-field font-mono text-[13px]" placeholder="để trống = tự sinh">
            </label>
            <label class="space-y-1">
                <span class="xp-label">Value</span>
                <input v-model="newValue" class="xp-field font-mono text-[13px]" placeholder="https://…|https://…">
            </label>
            <div class="flex items-end">
                <button class="xp-btn-primary w-full sm:w-auto" :disabled="busy || !newValue.trim()" @click="addEntry">
                    Thêm
                </button>
            </div>
        </div>

        <!-- Search -->
        <form class="mb-4 flex flex-wrap items-center gap-2" @submit.prevent="search">
            <input v-model="prefix" class="xp-field max-w-xs font-mono text-[13px]" placeholder="Lọc theo tiền tố key…">
            <button class="xp-btn-ghost">Tìm</button>
            <span v-if="prefix" class="text-xs text-neutral-500">KV chỉ tìm được theo tiền tố, không tìm trong value.</span>
        </form>

        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-[13px]">
                    <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Key</th>
                            <th class="px-4 py-2.5 font-medium">Value</th>
                            <th class="w-24 px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-if="loading"><td colspan="3" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td></tr>
                        <tr v-else-if="!rows.length">
                            <td colspan="3" class="px-4 py-8 text-center text-neutral-500">Chưa có cặp nào.</td>
                        </tr>

                        <tr v-for="row in rows" :key="row.key" class="hover:bg-neutral-50">
                            <td class="px-4 py-2.5 align-top">
                                <p class="font-mono">{{ row.key }}</p>
                                <button
                                    v-if="shortUrl(row.key)"
                                    class="mt-0.5 flex items-center gap-1 text-[11px] text-blue-600 hover:underline"
                                    @click="copy(shortUrl(row.key))"
                                >
                                    <NavIcon name="copy" :size="11" />
                                    {{ shortUrl(row.key) }}
                                </button>
                            </td>
                            <td class="px-4 py-2.5 align-top">
                                <p class="break-all font-mono text-[12px] text-neutral-700">{{ row.value ?? '—' }}</p>
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
