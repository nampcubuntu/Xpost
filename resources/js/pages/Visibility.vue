<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import api from '../lib/api';
import { cached, swr } from '../lib/cache';
import NavIcon from '../components/NavIcon.vue';

/*
 * Whether X is quietly limiting what these accounts reach.
 *
 * There is no shadowban endpoint anywhere -- the check is composed from two
 * calls to Sorsa per account, so it runs on the queue and the verdicts arrive
 * after the click, the same way the proxy checks do.
 */
const accounts = ref([]);
const meta = ref(null);
const stats = ref(null);
const page = ref(1);
const search = ref('');
const state = ref('');
const loading = ref(true);
const busy = ref(false);
const flash = ref(null);
const selected = ref(new Set());

/*
 * The four checks, expanded under the row that was clicked.
 *
 * Not four more columns: the table is already at its minimum width, and
 * two of the four are the account's own settings rather than anything X
 * imposed -- which is a distinction that needs a sentence, not a cell.
 */
const openFor = ref(null);

const toggleDetail = (id) => (openFor.value = openFor.value === id ? null : id);

const states = [
    { value: '', label: 'Mọi trạng thái' },
    { value: 'ok', label: 'Bình thường' },
    { value: 'restricted', label: 'Bị hạn chế' },
    { value: 'protected', label: 'Tài khoản riêng tư' },
    { value: 'unknown', label: 'Chưa rõ' },
];

/*
 * Three states, never two.
 *
 * "Không phát hiện hạn chế" and "không hỏi được" are different answers, and
 * collapsing them is how a page reports a clean bill of health for an account
 * it never reached.
 */
const badge = {
    ok: { label: 'Bình thường', class: 'bg-green-50 text-green-700' },
    restricted: { label: 'Bị hạn chế', class: 'bg-red-50 text-red-700 ring-1 ring-red-200' },
    protected: { label: 'Riêng tư', class: 'bg-blue-50 text-blue-700' },
    unknown: { label: 'Chưa rõ', class: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200' },
};

const testLabel = {
    ok: { text: 'không bị chặn', class: 'text-green-700' },
    restricted: { text: 'BỊ CHẶN', class: 'text-red-600 font-medium' },
    unknown: { text: 'không hỏi được', class: 'text-amber-700' },
};

async function load() {
    const key = `visibility:${search.value}:${state.value}:${page.value}`;

    loading.value = cached(key) === undefined;

    try {
        await swr(key, async () => (await api.get('accounts', {
            params: {
                search: search.value || undefined,
                visibility: state.value || undefined,
                page: page.value,
                per_page: 50,
            },
        })).data, (data) => {
            accounts.value = data.data;
            meta.value = data.meta;

            // Drop ticks for rows that are gone, or the count in the bar
            // promises rows the next action cannot find.
            const live = new Set(accounts.value.map((a) => a.id));
            selected.value = new Set([...selected.value].filter((id) => live.has(id)));
        });
    } finally {
        loading.value = false;
    }
}

async function loadStats() {
    const { data } = await api.get('accounts/visibility/stats');

    stats.value = data;
}

let timer;
watch([search, state], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(page, load);

/*
 * The verdicts arrive on the queue, so the page asks again while anything is
 * still outstanding -- and stops when nothing is.
 */
let poller;

onMounted(() => {
    load();
    loadStats();
});

onUnmounted(() => clearTimeout(poller));

function toggle(id) {
    selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id);
    selected.value = new Set(selected.value);
}

const allSelected = computed(() => accounts.value.length > 0
    && accounts.value.every((a) => selected.value.has(a.id)));

function toggleAll() {
    const clearing = allSelected.value;

    accounts.value.forEach((a) => {
        clearing ? selected.value.delete(a.id) : selected.value.add(a.id);
    });

    selected.value = new Set(selected.value);
}

async function check(ids) {
    busy.value = true;

    try {
        const { data } = await api.post('accounts/visibility', { account_ids: ids });

        flash.value = {
            type: 'ok',
            message: `Đã đưa ${data.queued} tài khoản vào hàng đợi. `
                + 'Mỗi tài khoản tốn 2 lượt gọi API và Sorsa giới hạn 20 lượt/giây, '
                + 'nên kết quả hiện dần — không cần chờ ở đây.',
        };

        selected.value = new Set();

        // Verdicts land on the queue; look again while any are outstanding.
        clearTimeout(poller);
        poller = setTimeout(() => { load(); loadStats(); }, 5000);
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không kiểm tra được.' };
    } finally {
        busy.value = false;
    }
}

/*
 * What each check means, in the account's own terms.
 *
 * Split into what X did and what the account chose, because the second group
 * explains the first: a protected account cannot appear in search at all, so a
 * search ban reading on one is unmeasurable rather than positive.
 */
function checks(account) {
    const d = account.visibility_detail ?? {};

    return [
        {
            group: 'Hạn chế do X đặt',
            title: 'Chặn tìm kiếm bài',
            state: d.search,
            say: {
                ok: `Bài của bạn xuất hiện trong tìm kiếm X. from: trả về ${d.posts_found}${d.posts_more ? '+' : ''} bài`
                    + (d.posts_more ? ' (trang đầu — còn nữa).' : '.'),
                restricted: `Bài bị ẩn khỏi tìm kiếm X. from: trả về 0 bài dù hồ sơ có ${d.posts_total} bài.`,
                unknown: d.posts_total === 0
                    ? 'Tài khoản chưa đăng bài nào nên không có gì để kiểm tra — đây không phải hạn chế.'
                    : 'Không hỏi được X ở lượt này.',
            },
        },
        {
            group: 'Hạn chế do X đặt',
            title: 'Chặn gợi ý handle',
            state: d.search_suggestion,
            say: {
                ok: 'Handle của bạn xuất hiện trong gợi ý tìm kiếm của X như bình thường.',
                restricted: 'X không trả handle này trong tìm kiếm người dùng.',
                unknown: 'Không hỏi được X ở lượt này.',
            },
        },
        {
            group: 'Hạn chế do X đặt',
            title: 'Hạn chế trả lời',
            state: d.replies,
            say: {
                ok: 'Không bài nào trong số vừa kiểm bị X giới hạn phần trả lời.',
                restricted: `X giới hạn ai xem được phần trả lời của ${d.replies_limited} bài.`,
                unknown: 'Không hỏi được X ở lượt này.',
            },
        },
        {
            group: 'Cài đặt hồ sơ',
            title: 'Hồ sơ nhạy cảm',
            // A setting, so the words are flagged/not flagged rather than
            // detected/not detected.
            state: d.sensitive === null || d.sensitive === undefined
                ? 'unknown'
                : (d.sensitive ? 'restricted' : 'ok'),
            say: {
                ok: 'Tài khoản không bị đánh dấu nhạy cảm. Media hiện bình thường, không có cảnh báo nội dung.',
                restricted: 'Tài khoản bị đánh dấu nhạy cảm — media sẽ kèm cảnh báo nội dung trước khi xem được.',
                unknown: 'Chưa đọc được cờ này.',
            },
        },
        {
            group: 'Cài đặt hồ sơ',
            title: 'Quyền riêng tư',
            state: d.protected === null || d.protected === undefined
                ? 'unknown'
                : (d.protected ? 'restricted' : 'ok'),
            say: {
                ok: 'Tài khoản ở chế độ công khai, nên hai bài kiểm tra trên chạy bình thường.',
                restricted: 'Tài khoản ở chế độ riêng tư. Hai bài kiểm tra trên không đo được — đó là cài đặt của bạn, không phải X hạn chế.',
                unknown: 'Chưa đọc được cờ này.',
            },
        },
    ];
}

/** A setting reads "đã bật", a restriction reads "phát hiện". */
const verdictWord = {
    'Hạn chế do X đặt': { ok: 'Không có', restricted: 'PHÁT HIỆN', unknown: 'Chưa rõ' },
    'Cài đặt hồ sơ': { ok: 'Không', restricted: 'CÓ', unknown: 'Chưa rõ' },
};

const verdictClass = {
    ok: 'bg-green-50 text-green-700',
    restricted: 'bg-red-50 text-red-700 ring-1 ring-red-200',
    unknown: 'bg-amber-50 text-amber-800 ring-1 ring-amber-200',
};
const checkedAt = (iso) => (iso
    ? new Date(iso).toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false })
    : null);
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Trạng thái hiển thị</h1>
                <p class="mt-1 max-w-3xl text-[13px] text-neutral-500">
                    X có đang âm thầm hạn chế tài khoản không. Không có API nào trả lời thẳng câu này,
                    nên bài kiểm tra được ghép từ hai lượt hỏi: handle có xuất hiện trong tìm kiếm người dùng,
                    và <code class="rounded bg-neutral-100 px-1 py-0.5 font-mono text-[12px]">from:@handle</code>
                    có trả về bài nào không.
                </p>
            </div>

            <button
                class="xp-btn-primary"
                :disabled="busy || !selected.size"
                @click="check([...selected])"
            >Kiểm tra {{ selected.size || '' }} tài khoản</button>
        </div>

        <div
            v-if="stats && !stats.configured"
            class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900"
        >
            <strong class="font-semibold">Chưa cấu hình SORSA_API_KEY.</strong>
            Đặt khoá trong <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">.env</code> rồi chạy
            <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">artisan config:clear</code>.
            Mỗi tài khoản tốn 2 lượt gọi, nên một lượt quét cả pool bằng hai lần số tài khoản.
        </div>

        <div v-if="flash" class="mb-4 rounded-lg px-4 py-3 text-[13px]"
             :class="flash.type === 'ok' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'">
            {{ flash.message }}
        </div>

        <!-- Never checked is its own tile: it is not the same as checked and unclear. -->
        <div v-if="stats" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div v-for="tile in [
                { key: 'ok', label: 'Bình thường', tone: 'text-green-700' },
                { key: 'restricted', label: 'Bị hạn chế', tone: 'text-red-700' },
                { key: 'protected', label: 'Riêng tư', tone: 'text-blue-700' },
                { key: 'unknown', label: 'Chưa rõ', tone: 'text-amber-700' },
                { key: 'unchecked', label: 'Chưa kiểm tra', tone: 'text-neutral-400' },
            ]" :key="tile.key" class="xp-card px-4 py-3">
                <p class="text-xs text-neutral-500">{{ tile.label }}</p>
                <p class="mt-0.5 text-xl font-semibold"
                   :class="stats.by_state[tile.key] ? tile.tone : 'text-neutral-300'">
                    {{ (stats.by_state[tile.key] ?? 0).toLocaleString('vi-VN') }}
                </p>
            </div>
        </div>

        <div class="mb-3 flex flex-wrap items-center gap-2">
            <input v-model="search" type="search" class="xp-field max-w-xs" placeholder="Tìm @username…">
            <select v-model="state" class="xp-field max-w-[190px]">
                <option v-for="option in states" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <span v-if="meta" class="ml-auto text-[12.5px] tabular-nums text-neutral-500">
                {{ meta.total.toLocaleString('vi-VN') }} tài khoản
            </span>
        </div>

        <div v-if="selected.size" class="mb-3 flex flex-wrap items-center gap-3 rounded-lg bg-neutral-900 px-4 py-2.5 text-[13px] text-white">
            <span class="font-medium">Đã chọn {{ selected.size }}</span>
            <button
                class="rounded-md bg-white/10 px-2.5 py-1 transition hover:bg-white/20 disabled:opacity-40"
                :disabled="busy"
                @click="check([...selected])"
            >Kiểm tra</button>
            <button class="ml-auto text-white/60 transition hover:text-white" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1180px] text-left text-[13px]">
                    <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th class="w-10 py-2.5 pl-4 pr-1">
                                <input type="checkbox" :checked="allSelected" class="align-middle" @change="toggleAll">
                            </th>
                            <th class="px-4 py-2.5 font-medium">Tài khoản</th>
                            <th class="px-4 py-2.5 font-medium">Kết luận</th>
                            <!--
                                All four on the row. The first two are things X
                                did; the last two are the account's own settings
                                and are what explain the first two -- a private
                                account cannot appear in search at all.
                            -->
                            <th class="px-4 py-2.5 font-medium">Tìm kiếm bài</th>
                            <th class="px-4 py-2.5 font-medium">Gợi ý handle</th>
                            <th class="px-4 py-2.5 font-medium">Hạn chế trả lời</th>
                            <th class="border-l border-neutral-200 px-4 py-2.5 font-medium">Nhạy cảm</th>
                            <th class="px-4 py-2.5 font-medium">Riêng tư</th>
                            <th class="px-4 py-2.5 font-medium">Kiểm lúc</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-if="loading"><td colspan="10" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td></tr>
                        <tr v-else-if="!accounts.length"><td colspan="10" class="px-4 py-8 text-center text-neutral-500">Không có tài khoản nào.</td></tr>

                        <template v-for="account in accounts" :key="account.id">
                        <tr class="hover:bg-neutral-50"
                            :class="selected.has(account.id) ? 'bg-neutral-50' : ''">
                            <td class="w-10 py-2.5 pl-4 pr-1">
                                <input type="checkbox" :checked="selected.has(account.id)" class="align-middle" @change="toggle(account.id)">
                            </td>
                            <td class="cursor-pointer px-4 py-2.5" @click="toggleDetail(account.id)">
                                <p class="flex items-center gap-1.5 font-medium">
                                    <NavIcon
                                        name="chevron"
                                        :size="12"
                                        class="text-neutral-400 transition"
                                        :class="openFor === account.id ? 'rotate-180' : '-rotate-90'"
                                    />
                                    {{ account.handle }}
                                </p>
                                <p class="truncate text-xs text-neutral-500">{{ account.name }}</p>
                            </td>
                            <td class="px-4 py-2.5">
                                <span v-if="account.visibility_state"
                                      class="rounded-full px-2 py-0.5 text-xs font-medium"
                                      :class="badge[account.visibility_state]?.class">
                                    {{ badge[account.visibility_state]?.label ?? account.visibility_state }}
                                </span>
                                <span v-else class="text-xs text-neutral-400">chưa kiểm tra</span>
                            </td>

                            <!-- The two tests, spelled out. A one-word verdict
                                 nobody can check is a verdict nobody trusts. -->
                            <td class="px-4 py-2.5">
                                <span v-if="account.visibility_detail?.search"
                                      :class="testLabel[account.visibility_detail.search]?.class">
                                    {{ testLabel[account.visibility_detail.search]?.text }}
                                    <!-- 20 with a cursor behind it is 20+, and
                                         the column was printing it as a total
                                         the same way the sentence used to. -->
                                    <span v-if="account.visibility_detail.posts_found !== null" class="text-neutral-400">
                                        ({{ account.visibility_detail.posts_found }}{{ account.visibility_detail.posts_more ? '+' : '' }} bài)
                                    </span>
                                </span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <span v-if="account.visibility_detail?.search_suggestion"
                                      :class="testLabel[account.visibility_detail.search_suggestion]?.class">
                                    {{ testLabel[account.visibility_detail.search_suggestion]?.text }}
                                </span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>

                            <td class="px-4 py-2.5">
                                <span v-if="account.visibility_detail?.replies"
                                      :class="testLabel[account.visibility_detail.replies]?.class">
                                    {{ testLabel[account.visibility_detail.replies]?.text }}
                                </span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>

                            <!--
                                A setting reads yes/no, not detected/clear: it is
                                something the account chose, and calling it a
                                restriction would send someone looking for
                                something X never did.
                            -->
                            <td class="border-l border-neutral-200 px-4 py-2.5">
                                <span v-if="account.visibility_detail?.sensitive === true" class="font-medium text-amber-700">CÓ</span>
                                <span v-else-if="account.visibility_detail?.sensitive === false" class="text-neutral-500">không</span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <span v-if="account.visibility_detail?.protected === true" class="font-medium text-blue-700">CÓ</span>
                                <span v-else-if="account.visibility_detail?.protected === false" class="text-neutral-500">công khai</span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>

                            <!-- A verdict with no date is a claim about now made
                                 from whenever. -->
                            <td class="whitespace-nowrap px-4 py-2.5 text-[12.5px] text-neutral-600">
                                {{ checkedAt(account.visibility_checked_at) ?? '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <button
                                    class="rounded-md border border-neutral-200 px-2.5 py-1 text-[12.5px] text-neutral-700 transition hover:border-neutral-300 hover:bg-neutral-50 disabled:opacity-50"
                                    :disabled="busy"
                                    @click="check([account.id])"
                                >Kiểm tra</button>
                            </td>
                        </tr>

                        <!--
                            All four checks, grouped the way they actually
                            differ: two are things X did, two are the account's
                            own settings -- and the second pair is what explains
                            the first, since a private account cannot appear in
                            search at all.
                        -->
                        <tr v-if="openFor === account.id">
                            <td colspan="10" class="bg-neutral-50 px-4 py-3">
                                <p v-if="!account.visibility_checked_at" class="text-[13px] text-neutral-500">
                                    Chưa kiểm tra tài khoản này lần nào.
                                </p>

                                <div v-else class="max-w-3xl overflow-hidden rounded-lg border border-neutral-200 bg-white">
                                    <template v-for="(row, i) in checks(account)" :key="row.title">
                                        <p
                                            v-if="i === 0 || checks(account)[i - 1].group !== row.group"
                                            class="border-b border-neutral-100 bg-neutral-50 px-4 py-1.5 text-[11px] uppercase tracking-wide text-neutral-500"
                                        >{{ row.group }}</p>

                                        <div class="flex items-start gap-3 border-b border-neutral-100 px-4 py-2.5 last:border-b-0">
                                            <div class="min-w-0 flex-1">
                                                <p class="text-[13px] font-medium">{{ row.title }}</p>
                                                <p class="mt-0.5 text-[12.5px] leading-relaxed text-neutral-600">
                                                    {{ row.say[row.state] ?? 'Chưa có dữ liệu.' }}
                                                </p>
                                            </div>

                                            <span
                                                class="shrink-0 rounded-full px-2 py-0.5 text-[11.5px] font-medium"
                                                :class="verdictClass[row.state] ?? verdictClass.unknown"
                                            >{{ verdictWord[row.group][row.state] ?? 'Chưa rõ' }}</span>
                                        </div>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="meta && meta.last_page > 1" class="mt-4 flex items-center gap-3">
            <button class="xp-btn-ghost" :disabled="page <= 1" @click="page--">Trước</button>
            <span class="text-[13px] text-neutral-600">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
            <button class="xp-btn-ghost" :disabled="page >= meta.last_page" @click="page++">Sau</button>
        </div>
    </div>
</template>
