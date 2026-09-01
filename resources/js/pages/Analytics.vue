<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import api from '../lib/api';
import TrendChart from '../components/TrendChart.vue';

/*
 * What X will actually tell us about a post.
 *
 * X Premium's own post view shows impressions, engagements, engagement rate,
 * profile visits, likes, reposts, replies, bookmarks -- and also detail
 * expands, new followers and link clicks, which the API does not return at
 * this access level. The live response for one of this install's posts:
 *
 *   "non_public_metrics": {"engagements":0,"impression_count":3,"user_profile_clicks":0}
 *   "organic_metrics":    {"impression_count":3,"like_count":0,"reply_count":0,
 *                          "retweet_count":0,"user_profile_clicks":0}
 *   "public_metrics":     {"bookmark_count":0,"impression_count":3,"like_count":0,
 *                          "quote_count":0,"reply_count":0,"retweet_count":0}
 *
 * There is no url_link_clicks in either block. A column of permanent zeroes
 * would read as "nobody clicked" rather than "X does not say", so the ones that
 * cannot be measured are named once, in plain words, and never given a tile.
 */
const overview = ref(null);
const posts = ref([]);
const accounts = ref([]);
const loading = ref(true);
const tab = ref('posts');

const from = ref(new Date(Date.now() - 29 * 86400000).toISOString().slice(0, 10));
const to = ref(new Date().toISOString().slice(0, 10));

const params = () => ({ from: from.value, to: to.value });

async function load() {
    loading.value = true;

    try {
        const [ov, tp, ta] = await Promise.all([
            api.get('analytics/overview', { params: params() }),
            api.get('analytics/top-posts', { params: { ...params(), limit: 50 } }),
            api.get('analytics/accounts', { params: { ...params(), limit: 50 } }),
        ]);

        overview.value = ov.data;
        posts.value = tp.data.data;
        accounts.value = ta.data.top;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

/** Presets, because typing two dates to see last week is work nobody should do. */
const ranges = [
    { label: '7 ngày', days: 6 },
    { label: '28 ngày', days: 27 },
    { label: '90 ngày', days: 89 },
];

function pick(days) {
    const end = new Date();
    const start = new Date(Date.now() - days * 86400000);

    to.value = end.toISOString().slice(0, 10);
    from.value = start.toISOString().slice(0, 10);
}

watch([from, to], load);

const engagement = computed(() => overview.value?.engagement ?? {});
const coverage = computed(() => overview.value?.coverage ?? {});

/*
 * Whether these numbers speak for the whole pool.
 *
 * Metrics come back only to the account that owns the tweet, so an account
 * whose token has expired contributes nothing at all -- and a dashboard that
 * quietly averages over the accounts it could reach reads as a statement about
 * every account. Six of the seven tried on this install answered Unauthorized.
 */
const partial = computed(() => coverage.value.publishable > 0
    && coverage.value.synced < coverage.value.publishable);

const n = (value) => Number(value ?? 0).toLocaleString('vi-VN');

const pct = (value) => `${Number(value ?? 0).toFixed(2)}%`;

/*
 * Every day in the range, including the ones nothing happened on.
 *
 * Both series arrive with rows only for days that have data, so plotting
 * them as they come joins the 26th straight to the 29th and draws a slope
 * across three days that were flat. A quiet day is information.
 */
function densify(rows, keys) {
    const byDay = new Map(rows.map((r) => [String(r.day), r]));
    const out = [];

    for (let d = new Date(from.value); d <= new Date(to.value); d.setDate(d.getDate() + 1)) {
        const day = d.toISOString().slice(0, 10);
        const row = byDay.get(day);

        out.push({ day, ...Object.fromEntries(keys.map((k) => [k, Number(row?.[k] ?? 0)])) });
    }

    return out;
}

const volumeDays = computed(() => densify(overview.value?.daily ?? [], ['published', 'failed', 'attempts']));
const engagementDays = computed(() => densify(overview.value?.engagement_daily ?? [], ['impressions', 'engagements']));

const engagementSeries = computed(() => [
    { key: 'impressions', label: 'Lượt hiển thị', color: '#1d9bf0', values: engagementDays.value.map((d) => d.impressions) },
    { key: 'engagements', label: 'Tương tác', color: '#00ba7c', values: engagementDays.value.map((d) => d.engagements) },
]);

const volumeSeries = computed(() => [
    { key: 'published', label: 'Đã đăng', color: '#0f172a', values: volumeDays.value.map((d) => d.published) },
    { key: 'failed', label: 'Thất bại', color: '#f4a4a4', values: volumeDays.value.map((d) => d.failed) },
]);

const chartDays = computed(() => volumeDays.value.map((d) => d.day));

/*
 * The chart is a record of what happened; the tile is what still exists.
 *
 * A post deleted from Xpost still went out, so its destinations stay in the
 * event log and leave the two counts apart -- thirty against twenty-eight
 * here. Both are right, and a page showing the same word twice with two
 * numbers has to say which is which rather than let a reader decide one of
 * them is broken.
 */
const deletedInChart = computed(() => {
    const charted = volumeDays.value.reduce((sum, d) => sum + d.published + d.failed, 0);
    const current = Number(overview.value?.published ?? 0) + Number(overview.value?.failed ?? 0);

    return Math.max(0, charted - current);
});

/** Retries are the difference between attempts and destinations. */
const retries = computed(() => volumeDays.value.reduce(
    (sum, d) => sum + Math.max(0, d.attempts - d.published - d.failed),
    0,
));

const measured = (value) => (value
    ? new Date(value.replace(' ', 'T')).toLocaleString('vi-VN', {
        day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false,
    })
    : '—');
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Analytics</h1>
                <p class="mt-1 text-[13px] text-neutral-500">
                    Số liệu X trả về cho từng bài đã đăng, theo tài khoản đứng sau nó.
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="flex overflow-hidden rounded-md border border-neutral-200 bg-white">
                    <button
                        v-for="r in ranges"
                        :key="r.label"
                        class="px-2.5 py-1.5 text-[12.5px] transition hover:bg-neutral-50"
                        @click="pick(r.days)"
                    >{{ r.label }}</button>
                </div>

                <input v-model="from" type="date" class="xp-field w-auto">
                <span class="text-neutral-400">→</span>
                <input v-model="to" type="date" class="xp-field w-auto">
            </div>
        </div>

        <p v-if="loading" class="text-neutral-500">Đang tải…</p>

        <template v-else-if="overview">
            <!--
                Said before any number, not after.

                Without ClickHouse every engagement figure here is empty, which
                looks exactly like a month in which nobody engaged.
            -->
            <div
                v-if="!coverage.clickhouse"
                class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-[13px] text-amber-900 ring-1 ring-amber-200"
            >
                <span class="font-medium">Chưa đọc được ClickHouse.</span>
                Phần tương tác bên dưới sẽ trống — đó là thiếu kết nối, không phải không ai tương tác.
            </div>

            <div
                v-else-if="partial"
                class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-[13px] text-amber-900 ring-1 ring-amber-200"
            >
                <span class="font-medium">
                    Chỉ {{ n(coverage.synced) }}/{{ n(coverage.publishable) }} tài khoản đang đồng bộ số liệu.
                </span>
                X chỉ trả số liệu cho chính tài khoản sở hữu bài, nên tài khoản hết hạn token
                không đóng góp gì — các con số dưới đây chỉ nói thay cho phần đang đồng bộ.
            </div>

            <!-- Delivery: what this system did. -->
            <p class="mb-2 text-[11px] font-medium uppercase tracking-wider text-neutral-500">Đăng bài</p>
            <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div v-for="tile in [
                    { label: 'Đã đăng', value: n(overview.published), tone: 'text-neutral-900' },
                    { label: 'Thất bại', value: n(overview.failed), tone: overview.failed ? 'text-red-700' : 'text-neutral-400' },
                    { label: 'Tỉ lệ thành công', value: pct(overview.success_rate), tone: 'text-neutral-900' },
                    { label: 'Đang chờ', value: n(overview.pending), tone: overview.pending ? 'text-amber-700' : 'text-neutral-400' },
                ]" :key="tile.label" class="xp-card px-4 py-3">
                    <p class="text-xs text-neutral-500">{{ tile.label }}</p>
                    <p class="mt-0.5 text-xl font-semibold tabular-nums" :class="tile.tone">{{ tile.value }}</p>
                </div>
            </div>

            <!-- Engagement: what X did with it. -->
            <p class="mb-2 text-[11px] font-medium uppercase tracking-wider text-neutral-500">
                Tương tác — {{ n(engagement.posts) }} bài có số liệu
            </p>
            <div class="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div v-for="tile in [
                    { label: 'Lượt hiển thị', value: n(engagement.impressions) },
                    { label: 'Tương tác', value: n(engagement.engagements) },
                    { label: 'Tỉ lệ tương tác', value: pct(engagement.engagement_rate) },
                    { label: 'Xem hồ sơ', value: n(engagement.profile_visits) },
                    { label: 'Thích', value: n(engagement.likes) },
                    { label: 'Đăng lại', value: n(engagement.retweets) },
                ]" :key="tile.label" class="xp-card px-4 py-3">
                    <p class="text-xs text-neutral-500">{{ tile.label }}</p>
                    <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ tile.value }}</p>
                </div>
            </div>

            <!--
                Cumulative, and it has to say so.

                The counters X returns are running totals for the life of a post,
                so there is no honest way to slice them into what a given day
                earned. Letting a reader assume otherwise would be the same lie
                as a proxy showing healthy from a check five days old.
            -->
            <p class="mb-5 text-[11.5px] leading-relaxed text-neutral-500">
                Các con số tương tác là tổng luỹ kế từ khi bài được đăng, đọc ở lần đo gần nhất
                trong khoảng đã chọn — không phải phần phát sinh riêng trong khoảng đó.
                X không trả về lượt bấm link, lượt mở chi tiết và người theo dõi mới qua API,
                nên trang này không có các cột đó.
            </p>

            <!--
                Two charts, because they answer two different questions.

                The engagement line is what X did with the posts; the volume
                bars are what this system managed to send. Stacking them into
                one axis would put a number in the low thousands beside one in
                the low tens and flatten the smaller of the two into the floor.
            -->
            <div class="mb-5 grid gap-4 lg:grid-cols-3">
                <div class="xp-card p-5 lg:col-span-2">
                    <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="text-[15px] font-semibold">Hiển thị và tương tác theo ngày</h2>

                        <div class="flex gap-4 text-[11.5px] text-neutral-500">
                            <span v-for="s in engagementSeries" :key="s.key" class="flex items-center gap-1.5">
                                <span class="h-2 w-2 rounded-full" :style="{ background: s.color }"></span>
                                {{ s.label }}
                            </span>
                        </div>
                    </div>

                    <p class="mb-3 text-[11.5px] text-neutral-500">
                        Phần phát sinh trong ngày, không phải tổng luỹ kế.
                    </p>

                    <TrendChart
                        v-if="engagementDays.length"
                        :series="engagementSeries"
                        :labels="chartDays"
                        :height="200"
                    />
                    <p v-else class="py-10 text-center text-[13px] text-neutral-500">Chưa có số liệu trong khoảng này.</p>
                </div>

                <div class="xp-card p-5">
                    <h2 class="mb-4 text-[15px] font-semibold">Nguyên nhân lỗi</h2>

                    <ul v-if="overview.failures.length" class="space-y-2.5">
                        <li v-for="row in overview.failures" :key="row.error_code" class="flex items-baseline justify-between gap-2 text-[13px]">
                            <code class="truncate rounded bg-neutral-100 px-1.5 py-0.5 text-xs">{{ row.error_code || '—' }}</code>
                            <span class="shrink-0 font-medium tabular-nums">{{ n(row.total) }}</span>
                        </li>
                    </ul>

                    <p v-else class="text-[13px] text-neutral-500">Không có lỗi nào.</p>
                </div>
            </div>

            <div class="xp-card mb-5 p-5">
                <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-[15px] font-semibold">Lượng bài đăng theo ngày</h2>

                    <div class="flex gap-4 text-[11.5px] text-neutral-500">
                        <span v-for="s in volumeSeries" :key="s.key" class="flex items-center gap-1.5">
                            <span class="h-2 w-2 rounded-sm" :style="{ background: s.color }"></span>
                            {{ s.label }}
                        </span>
                    </div>
                </div>

                <!--
                    Destinations, not attempts.

                    A target that failed twice and then landed is one post
                    delivered, and the events table holds three rows for it.
                    Counting rows made this chart read fifty where the tile
                    beside it read twenty-eight.
                -->
                <p class="mb-3 text-[11.5px] text-neutral-500">
                    Đếm theo đích đến, không phải lượt gọi API.
                    <template v-if="retries > 0">
                        Có {{ n(retries) }} lượt thử lại không hiện ở đây.
                    </template>
                    <template v-if="deletedInChart > 0">
                        {{ n(deletedInChart) }} đích đến thuộc bài đã xoá nên có ở biểu đồ mà không có ở ô số liệu.
                    </template>
                </p>

                <TrendChart
                    v-if="volumeDays.length"
                    :series="volumeSeries"
                    :labels="chartDays"
                    kind="bars"
                    :height="160"
                />
                <p v-else class="py-8 text-center text-[13px] text-neutral-500">Chưa có dữ liệu trong khoảng này.</p>
            </div>

            <!--
                Per tweet, not per post.

                A post fans out to many accounts, and the whole reason to open
                this screen is that the same text does not perform the same from
                every handle. Rolling them into one row would hide exactly the
                thing being looked for.
            -->
            <div class="xp-card overflow-hidden">
                <div class="flex items-center gap-1 border-b border-neutral-200 px-4">
                    <button
                        v-for="t in [{ key: 'posts', label: 'Theo bài đăng' }, { key: 'accounts', label: 'Theo tài khoản' }]"
                        :key="t.key"
                        class="-mb-px border-b-2 px-3 py-2.5 text-[13px] font-medium transition"
                        :class="tab === t.key
                            ? 'border-neutral-900 text-neutral-900'
                            : 'border-transparent text-neutral-500 hover:text-neutral-800'"
                        @click="tab = t.key"
                    >{{ t.label }}</button>
                </div>

                <div class="overflow-x-auto">
                    <table v-if="tab === 'posts'" class="w-full text-[13px]">
                        <thead class="border-b border-neutral-200 text-left text-[11.5px] uppercase tracking-wide text-neutral-500">
                            <tr>
                                <th class="px-4 py-2.5 font-medium">Tài khoản</th>
                                <th class="px-4 py-2.5 font-medium">Nội dung</th>
                                <th class="px-4 py-2.5 text-right font-medium">Hiển thị</th>
                                <th class="px-4 py-2.5 text-right font-medium">Tương tác</th>
                                <th class="px-4 py-2.5 text-right font-medium">Tỉ lệ</th>
                                <th class="px-4 py-2.5 text-right font-medium">Thích</th>
                                <th class="px-4 py-2.5 text-right font-medium">Đăng lại</th>
                                <th class="px-4 py-2.5 text-right font-medium">Trả lời</th>
                                <th class="px-4 py-2.5 text-right font-medium">Trích</th>
                                <th class="px-4 py-2.5 text-right font-medium">Lưu</th>
                                <th class="px-4 py-2.5 text-right font-medium">Xem hồ sơ</th>
                                <th class="px-4 py-2.5 text-right font-medium">Đo lúc</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-for="row in posts" :key="row.tweet_id" class="border-b border-neutral-100 last:border-b-0 hover:bg-neutral-50">
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium">
                                    <a
                                        :href="`https://x.com/${row.username}/status/${row.tweet_id}`"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-blue-600 hover:underline"
                                    >@{{ row.username ?? '—' }}</a>
                                </td>
                                <td class="max-w-[280px] truncate px-4 py-2.5 text-neutral-600" :title="row.excerpt">{{ row.excerpt || '—' }}</td>
                                <td class="px-4 py-2.5 text-right font-medium tabular-nums">{{ n(row.impressions) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ n(row.engagements) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums" :class="Number(row.engagement_rate) > 0 ? 'text-neutral-900' : 'text-neutral-400'">
                                    {{ pct(row.engagement_rate) }}
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-neutral-600">{{ n(row.likes) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-neutral-600">{{ n(row.retweets) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-neutral-600">{{ n(row.replies) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-neutral-600">{{ n(row.quotes) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-neutral-600">{{ n(row.bookmarks) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-neutral-600">{{ n(row.profile_visits) }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right text-[12px] text-neutral-400">{{ measured(row.measured_at) }}</td>
                            </tr>

                            <tr v-if="!posts.length">
                                <td colspan="12" class="px-4 py-8 text-center text-[13px] text-neutral-500">
                                    Chưa có bài nào được đo trong khoảng này. Số liệu chỉ lấy về cho bài đăng trong 72 giờ gần nhất.
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <table v-else class="w-full text-[13px]">
                        <thead class="border-b border-neutral-200 text-left text-[11.5px] uppercase tracking-wide text-neutral-500">
                            <tr>
                                <th class="px-4 py-2.5 font-medium">Tài khoản</th>
                                <th class="px-4 py-2.5 text-right font-medium">Bài</th>
                                <th class="px-4 py-2.5 text-right font-medium">Hiển thị</th>
                                <th class="px-4 py-2.5 text-right font-medium">Tương tác</th>
                                <th class="px-4 py-2.5 text-right font-medium">Tỉ lệ</th>
                                <th class="px-4 py-2.5 text-right font-medium">Thích</th>
                                <th class="px-4 py-2.5 text-right font-medium">Xem hồ sơ</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-for="row in accounts" :key="row.account_id" class="border-b border-neutral-100 last:border-b-0 hover:bg-neutral-50">
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium">
                                    <a
                                        v-if="row.username"
                                        :href="`https://x.com/${row.username}`"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-blue-600 hover:underline"
                                    >@{{ row.username }}</a>
                                    <span v-else class="text-neutral-400">#{{ row.account_id }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ n(row.posts) }}</td>
                                <td class="px-4 py-2.5 text-right font-medium tabular-nums">{{ n(row.impressions) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ n(row.engagements) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ pct(row.engagement_rate) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-neutral-600">{{ n(row.likes) }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-neutral-600">{{ n(row.profile_visits) }}</td>
                            </tr>

                            <tr v-if="!accounts.length">
                                <td colspan="7" class="px-4 py-8 text-center text-[13px] text-neutral-500">
                                    Chưa có tài khoản nào có số liệu trong khoảng này.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>
