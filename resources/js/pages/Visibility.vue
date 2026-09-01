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
const rows = ref([]);
const meta = ref(null);
const stats = ref(null);
const page = ref(1);
const search = ref('');
const state = ref('');
const loading = ref(true);
const busy = ref(false);
const flash = ref(null);
const selected = ref(new Set());
const configured = ref(true);

/*
 * A list of handles, kept here rather than read off the account pool.
 *
 * The question is asked about names, and the useful ones include handles this
 * install has never connected -- one being bought, one disconnected after it
 * went quiet, a competitor. Requiring a connected account meant those could
 * not be asked about at all.
 */
const showPaste = ref(true);
const pasteText = ref('');

/** One per line, or comma separated, with or without the @ or a full URL. */
const pastedHandles = computed(() => pasteText.value
    .split(/[\n,;]+/)
    .map((line) => line.trim())
    .filter(Boolean));

const openFor = ref(null);
const toggleDetail = (id) => (openFor.value = openFor.value === id ? null : id);

const states = [
    { value: '', label: 'Mọi trạng thái' },
    { value: 'ok', label: 'Bình thường' },
    { value: 'restricted', label: 'Bị hạn chế' },
    { value: 'protected', label: 'Riêng tư' },
    { value: 'unknown', label: 'Chưa rõ' },
    { value: 'unchecked', label: 'Chưa kiểm tra' },
];

/*
 * Three states, never two.
 *
 * "Không phát hiện hạn chế" and "không hỏi được" are different answers, and
 * collapsing them is how a page reports a clean bill of health for a handle it
 * never reached.
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
        await swr(key, async () => (await api.get('visibility', {
            params: {
                search: search.value || undefined,
                state: state.value || undefined,
                page: page.value,
                per_page: 50,
            },
        })).data, (data) => {
            rows.value = data.data;
            meta.value = data.meta;
            stats.value = { by_state: data.by_state, total: data.total };
            configured.value = data.configured;

            // Drop ticks for rows that are gone, or the count in the bar
            // promises rows the next action cannot find.
            const live = new Set(rows.value.map((r) => r.id));
            selected.value = new Set([...selected.value].filter((id) => live.has(id)));
        });
    } finally {
        loading.value = false;
    }
}

let timer;
watch([search, state], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(page, load);

/*
 * Verdicts arrive on the queue, so the page asks again until they do.
 *
 * It once looked exactly once, five seconds after the click -- and a check
 * takes three to five seconds on its own, so that look routinely landed before
 * the answer and nothing was left that would ever fetch it.
 */
let poller;

const pending = computed(() => rows.value.filter((r) => ! r.checked_at).length);

/*
 * Why the unknown rows are unknown, said once at the top.
 *
 * Fifty rows reading "không hỏi được" with the reason buried one click
 * deep in each of them is a page that makes someone go looking at X for a
 * problem that is on this side -- a spent Sorsa quota, in the run that
 * prompted this. When they all failed for the same reason, say it once.
 */
const sharedError = computed(() => {
    const reasons = new Set(rows.value.map((r) => r.detail?.error).filter(Boolean));

    return reasons.size === 1 ? [...reasons][0] : null;
});

const unknownRows = computed(() => rows.value.filter((r) => r.detail?.error).length);

async function pollUntilSettled(attempt = 0) {
    clearTimeout(poller);

    // Backing off rather than hammering: a list of a hundred takes minutes.
    const wait = Math.min(2000 * 2 ** attempt, 15000);

    poller = setTimeout(async () => {
        await load();

        // Twenty rounds at the cap is about four minutes. Past that the fault
        // is upstream and more polling will not find it.
        if (pending.value > 0 && attempt < 20 && ! document.hidden) {
            pollUntilSettled(attempt + 1);
        }
    }, wait);
}

onMounted(load);
onUnmounted(() => clearTimeout(poller));

function toggle(id) {
    selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id);
    selected.value = new Set(selected.value);
}

const allSelected = computed(() => rows.value.length > 0
    && rows.value.every((r) => selected.value.has(r.id)));

function toggleAll() {
    const clearing = allSelected.value;

    rows.value.forEach((r) => {
        clearing ? selected.value.delete(r.id) : selected.value.add(r.id);
    });

    selected.value = new Set(selected.value);
}

/** Add handles to the list and check them in the same step. */
async function addHandles() {
    if (! pastedHandles.value.length) {
        return;
    }

    busy.value = true;

    try {
        const { data } = await api.post('visibility', { handles: pastedHandles.value });

        /*
         * Named, not counted. "38 of 40" leaves someone holding two lines they
         * cannot find, which is the failure the proxy and link importers were
         * built to avoid -- so the bad ones stay in the box, alone.
         */
        flash.value = data.invalid.length
            ? {
                type: 'error',
                message: `Đã thêm ${data.added} handle. ${data.invalid.length} dòng không phải handle X hợp lệ `
                    + '(chỉ chữ, số, gạch dưới, tối đa 15 ký tự) và được giữ lại trong ô.',
            }
            : {
                type: 'ok',
                message: data.configured
                    ? `Đã thêm ${data.added} handle và đưa vào hàng đợi. Mỗi handle mất khoảng 3–5 giây; `
                        + 'trang tự cập nhật khi có kết quả.'
                    : `Đã thêm ${data.added} handle. Chưa có SORSA_API_KEY nên chưa kiểm tra được.`,
            };

        pasteText.value = data.invalid.join('\n');
        await load();
        pollUntilSettled();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không thêm được.' };
    } finally {
        busy.value = false;
    }
}

/** Ask again about rows already on the list. */
async function recheck(ids) {
    busy.value = true;

    try {
        const { data } = await api.post('visibility/check', { ids });

        flash.value = { type: 'ok', message: `Đã đưa ${data.queued} handle vào hàng đợi.` };
        selected.value = new Set();
        await load();
        pollUntilSettled();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không kiểm tra được.' };
    } finally {
        busy.value = false;
    }
}

async function remove(ids) {
    if (! window.confirm(`Bỏ ${ids.length} handle khỏi danh sách theo dõi?`)) {
        return;
    }

    busy.value = true;

    try {
        await api.delete('visibility', { data: { ids } });
        selected.value = new Set();
        await load();
    } finally {
        busy.value = false;
    }
}

const checkedAt = (iso) => (iso
    ? new Date(iso).toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false })
    : null);
/*
 * What each check means, in the account's own terms.
 *
 * Split into what X did and what the account chose, because the second group
 * explains the first: a protected account cannot appear in search at all, so a
 * search ban reading on one is unmeasurable rather than positive.
 */
function checks(row) {
    const d = row.detail ?? {};

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
</script>


<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Trạng thái hiển thị</h1>
                <p class="mt-1 max-w-3xl text-[13px] text-neutral-500">
                    X có đang âm thầm hạn chế handle nào không. Không có API nào trả lời thẳng câu này,
                    nên bài kiểm tra được ghép từ hai lượt hỏi: handle có xuất hiện trong tìm kiếm người dùng,
                    và <code class="rounded bg-neutral-100 px-1 py-0.5 font-mono text-[12px]">from:@handle</code>
                    có trả về bài nào không. Handle nào cũng kiểm được — không cần nối vào Xpost.
                </p>
            </div>

            <button class="xp-btn-ghost" @click="showPaste = !showPaste">
                {{ showPaste ? 'Ẩn ô thêm' : 'Thêm handle' }}
            </button>
        </div>

        <div
            v-if="!configured"
            class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900"
        >
            <strong class="font-semibold">Chưa cấu hình SORSA_API_KEY.</strong>
            Thêm handle vẫn được, nhưng chưa kiểm tra được. Đặt khoá trong
            <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">.env</code> rồi chạy
            <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">artisan config:clear</code>.
        </div>

        <!--
            The list someone already has. No hunt through a table, and no
            requirement that the handle be a connected account.
        -->
        <div v-if="showPaste" class="xp-card mb-4 px-4 py-4">
            <label class="xp-label">Dán handle — mỗi dòng một cái, hoặc ngăn bằng dấu phẩy</label>
            <textarea
                v-model="pasteText"
                rows="5"
                class="xp-field mt-1 w-full font-mono text-[12.5px]"
                placeholder="nancy_2z&#10;@MDN_Design&#10;https://x.com/GoodingAngie"
            ></textarea>
            <p class="xp-hint mt-1">
                Có hay không có <code>@</code> đều được, dán cả link hồ sơ cũng được.
                Thêm xong là kiểm luôn — mỗi handle tốn 2 lượt gọi API.
            </p>

            <button
                class="xp-btn-primary mt-3"
                :disabled="busy || !pastedHandles.length"
                @click="addHandles"
            >Thêm và kiểm tra {{ pastedHandles.length || '' }} handle</button>
        </div>

        <div v-if="flash" class="mb-4 rounded-lg px-4 py-3 text-[13px]"
             :class="flash.type === 'ok' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'">
            {{ flash.message }}
        </div>

        <div v-if="sharedError" class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-[13px] text-amber-900 ring-1 ring-amber-200">
            <span class="font-medium">{{ unknownRows }} handle chưa có kết luận</span> vì lần kiểm tra hỏng: {{ sharedError }}
            <span v-if="sharedError.includes('403')" class="block mt-1 text-amber-800">
                Gói Sorsa đã hết lượt gọi. Mỗi handle tốn 2 request. Nâng gói hoặc chờ hạn mức làm mới rồi bấm kiểm tra lại.
            </span>
        </div>

        <!-- Never checked is its own tile: not the same as checked and unclear. -->
        <div v-if="stats && stats.total" class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-5">
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
            <input v-model="search" type="search" class="xp-field max-w-xs" placeholder="Tìm handle…">
            <select v-model="state" class="xp-field max-w-[190px]">
                <option v-for="option in states" :key="option.value" :value="option.value">{{ option.label }}</option>
            </select>
            <span v-if="meta" class="ml-auto text-[12.5px] tabular-nums text-neutral-500">
                {{ meta.total.toLocaleString('vi-VN') }} handle
            </span>
        </div>

        <div v-if="selected.size" class="mb-3 flex flex-wrap items-center gap-3 rounded-lg bg-neutral-900 px-4 py-2.5 text-[13px] text-white">
            <span class="font-medium">Đã chọn {{ selected.size }}</span>
            <button
                class="rounded-md bg-white/10 px-2.5 py-1 transition hover:bg-white/20 disabled:opacity-40"
                :disabled="busy"
                @click="recheck([...selected])"
            >Kiểm tra lại</button>

            <span class="h-5 w-px bg-white/20"></span>

            <button
                class="rounded-md px-2.5 py-1 text-red-300 transition hover:bg-red-500/20 hover:text-red-200 disabled:opacity-40"
                :disabled="busy"
                @click="remove([...selected])"
            >Bỏ khỏi danh sách</button>

            <button class="ml-auto text-white/60 transition hover:text-white" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1080px] text-left text-[13px]">
                    <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th class="w-10 py-2.5 pl-4 pr-1">
                                <input type="checkbox" :checked="allSelected" class="align-middle" @change="toggleAll">
                            </th>
                            <th class="px-4 py-2.5 font-medium">Handle</th>
                            <th class="px-4 py-2.5 font-medium">Kết luận</th>
                            <!--
                                The first three are things X did; the last two
                                are settings the account chose, and are what
                                explain the first three -- a private account
                                cannot appear in search at all.
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
                        <tr v-else-if="!rows.length">
                            <td colspan="10" class="px-4 py-10 text-center">
                                <p class="text-[13px] text-neutral-600">Chưa có handle nào trong danh sách.</p>
                                <p class="mt-1 text-[12.5px] text-neutral-500">Dán handle vào ô phía trên để bắt đầu theo dõi.</p>
                            </td>
                        </tr>

                        <template v-for="row in rows" :key="row.id">
                        <tr class="hover:bg-neutral-50" :class="selected.has(row.id) ? 'bg-neutral-50' : ''">
                            <td class="w-10 py-2.5 pl-4 pr-1">
                                <input type="checkbox" :checked="selected.has(row.id)" class="align-middle" @change="toggle(row.id)">
                            </td>
                            <td class="cursor-pointer px-4 py-2.5" @click="toggleDetail(row.id)">
                                <p class="flex items-center gap-1.5 font-medium">
                                    <NavIcon
                                        name="chevron"
                                        :size="12"
                                        class="text-neutral-400 transition"
                                        :class="openFor === row.id ? 'rotate-180' : '-rotate-90'"
                                    />
                                    @{{ row.handle }}
                                </p>
                            </td>
                            <td class="px-4 py-2.5">
                                <span v-if="row.state"
                                      class="rounded-full px-2 py-0.5 text-xs font-medium"
                                      :class="badge[row.state]?.class">
                                    {{ badge[row.state]?.label ?? row.state }}
                                </span>
                                <span v-else class="text-xs text-neutral-400">chưa kiểm tra</span>
                            </td>

                            <td class="px-4 py-2.5">
                                <span v-if="row.detail?.search" :title="row.detail?.error" :class="testLabel[row.detail.search]?.class">
                                    {{ testLabel[row.detail.search]?.text }}
                                    <!-- 20 with a cursor behind it is 20+, so
                                         the column must not print it as a total. -->
                                    <span v-if="row.detail.posts_found !== null" class="text-neutral-400">
                                        ({{ row.detail.posts_found }}{{ row.detail.posts_more ? '+' : '' }} bài)
                                    </span>
                                </span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <span v-if="row.detail?.search_suggestion" :class="testLabel[row.detail.search_suggestion]?.class">
                                    {{ testLabel[row.detail.search_suggestion]?.text }}
                                </span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <span v-if="row.detail?.replies" :class="testLabel[row.detail.replies]?.class">
                                    {{ testLabel[row.detail.replies]?.text }}
                                </span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>

                            <!-- A setting reads yes/no, not detected/clear: it is
                                 something the account chose, and calling it a
                                 restriction sends someone looking for something
                                 X never did. -->
                            <td class="border-l border-neutral-200 px-4 py-2.5">
                                <span v-if="row.detail?.sensitive === true" class="font-medium text-amber-700">CÓ</span>
                                <span v-else-if="row.detail?.sensitive === false" class="text-neutral-500">không</span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <span v-if="row.detail?.protected === true" class="font-medium text-blue-700">CÓ</span>
                                <span v-else-if="row.detail?.protected === false" class="text-neutral-500">công khai</span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>

                            <!-- A verdict with no date is a claim about now made
                                 from whenever. -->
                            <td class="whitespace-nowrap px-4 py-2.5 text-[12.5px] text-neutral-600">
                                {{ checkedAt(row.checked_at) ?? '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <button
                                    class="rounded-md border border-neutral-200 px-2.5 py-1 text-[12.5px] text-neutral-700 transition hover:border-neutral-300 hover:bg-neutral-50 disabled:opacity-50"
                                    :disabled="busy"
                                    @click="recheck([row.id])"
                                >Kiểm tra</button>
                            </td>
                        </tr>

                        <!--
                            All five checks, grouped the way they actually
                            differ: three are things X did, two are settings the
                            account chose -- and the second pair is what
                            explains the first, since a private account cannot
                            appear in search at all.
                        -->
                        <tr v-if="openFor === row.id">
                            <td colspan="10" class="bg-neutral-50 px-4 py-3">
                                <p v-if="!row.checked_at" class="text-[13px] text-neutral-500">
                                    Chưa kiểm tra handle này lần nào.
                                </p>

                                <div v-else class="max-w-3xl overflow-hidden rounded-lg border border-neutral-200 bg-white">
                                    <template v-for="(check, i) in checks(row)" :key="check.title">
                                        <p
                                            v-if="i === 0 || checks(row)[i - 1].group !== check.group"
                                            class="border-b border-neutral-100 bg-neutral-50 px-4 py-1.5 text-[11px] uppercase tracking-wide text-neutral-500"
                                        >{{ check.group }}</p>

                                        <div class="flex items-start gap-3 border-b border-neutral-100 px-4 py-2.5 last:border-b-0">
                                            <div class="min-w-0 flex-1">
                                                <p class="text-[13px] font-medium">{{ check.title }}</p>
                                                <p class="mt-0.5 text-[12.5px] leading-relaxed text-neutral-600">
                                                    {{ check.say[check.state] ?? 'Chưa có dữ liệu.' }}
                                                </p>
                                            </div>

                                            <span
                                                class="shrink-0 rounded-full px-2 py-0.5 text-[11.5px] font-medium"
                                                :class="verdictClass[check.state] ?? verdictClass.unknown"
                                            >{{ verdictWord[check.group][check.state] ?? 'Chưa rõ' }}</span>
                                        </div>
                                    </template>
                                </div>

                                <!--
                                    Why the check could not answer, when it could not.

                                    "Chưa rõ" with nothing behind it sends someone
                                    looking at X for a problem that is on this side.
                                -->
                                <p v-if="row.detail?.error" class="mt-2 max-w-3xl rounded-lg bg-amber-50 px-3 py-2 text-[12.5px] text-amber-900 ring-1 ring-amber-200">
                                    Lần kiểm tra này hỏng nên chưa có kết luận: {{ row.detail.error }}
                                </p>
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
