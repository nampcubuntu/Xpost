<script setup>
import { computed, ref } from 'vue';
import BrandLogo from '../components/BrandLogo.vue';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();

/*
 * Live cost estimator.
 *
 * X moved to pay-per-use in February 2026: $0.015 per post, but $0.200 the
 * moment a post contains a link. That 13x cliff is the single most expensive
 * thing an operator can get wrong, so it is on the homepage rather than buried
 * in docs.
 */
const PRICE_PLAIN = 0.015;
const PRICE_LINK = 0.2;

const accounts = ref(2000);
const withLink = ref(true);
const postsPerAccount = ref(1);

const unitPrice = computed(() => (withLink.value ? PRICE_LINK : PRICE_PLAIN));
const totalPosts = computed(() => accounts.value * postsPerAccount.value);
const cost = computed(() => totalPosts.value * unitPrice.value);
const savings = computed(() => totalPosts.value * (PRICE_LINK - PRICE_PLAIN));

const money = (value) =>
    value.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });

const number = (value) => value.toLocaleString('vi-VN');

const features = [
    {
        title: 'Pool nhiều X App',
        body: 'Hạn mức POST gắn theo app, không theo tài khoản. Xpost tự gán tài khoản vào app còn chỗ và né app đang bị chặn, nên một app cạn kiệt không làm đứng cả hệ thống.',
        tag: 'Throughput',
    },
    {
        title: 'Mỗi tài khoản một nội dung',
        body: 'Spintax {a|b|c} lồng nhau, biến thể luân phiên hoặc ngẫu nhiên theo trọng số, token theo tài khoản. Hàng nghìn bài giống hệt nhau là tín hiệu spam rõ nhất.',
        tag: 'Anti-spam',
    },
    {
        title: 'Năm chế độ lên lịch',
        body: 'Đăng ngay, đúng giờ, rải đều, lệch ngẫu nhiên, hoặc theo múi giờ từng tài khoản. Không bao giờ để 10.000 bài rời đi trong cùng một giây.',
        tag: 'Scheduling',
    },
    {
        title: 'Không đăng trùng, không mất bài',
        body: 'Claim bằng FOR UPDATE SKIP LOCKED, commit trước khi đẩy job. Worker chết giữa chừng để lại việc mồ côi cho reaper nhặt, chứ không bao giờ đăng hai lần.',
        tag: 'Correctness',
    },
    {
        title: 'Đếm ký tự đúng chuẩn X',
        body: 'URL luôn tính 23 ký tự, chữ có dấu và CJK tính 2. Đếm bằng strlen sẽ cho bài tràn lọt qua rồi bị X trả 403 cho cả chiến dịch.',
        tag: 'Precision',
    },
    {
        title: 'Phân tích trên ClickHouse',
        body: 'Sự kiện đăng đệm qua Redis rồi flush theo lô, materialized view dựng sẵn rollup ngày. Analytics chết thì tự ngắt mạch, không kéo hệ thống đăng bài theo.',
        tag: 'Analytics',
    },
];

const steps = [
    { n: '01', title: 'Kết nối tài khoản', body: 'OAuth 2.0 PKCE từng tài khoản, hoặc import hàng loạt token OAuth 1.0a từ CSV. Token mã hoá ở tầng cast, tự refresh trước khi hết hạn.' },
    { n: '02', title: 'Soạn một lần', body: 'Composer dựng theo X Ads Manager: nội dung, link đích, media, quyền trả lời. Bộ đếm ký tự và ước tính đối tượng chạy realtime từ server.' },
    { n: '03', title: 'Fan-out', body: 'Một hàng cho mỗi cặp (bài, tài khoản), chèn theo lô 500. Nội dung đã render được đóng băng ngay lúc này — sửa bài sau không viết lại lịch sử.' },
    { n: '04', title: 'Đăng có kiểm soát', body: 'Token bucket Redis hai tầng, backoff theo từng loại lỗi, tự hạ tài khoản lỗi liên tục xuống cooldown trước khi X kịp khoá.' },
];

const stack = ['Laravel 13', 'PHP 8.5', 'MySQL 8.4', 'Redis 8.10', 'Vue 3', 'ClickHouse'];
</script>

<template>
    <div class="min-h-full bg-[#08080A] text-white antialiased">
        <!-- ─── Nav ─────────────────────────────────────────────────────── -->
        <header class="sticky top-0 z-50 border-b border-white/[0.06] bg-[#08080A]/80 backdrop-blur-xl">
            <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-6">
                <BrandLogo variant="full" :size="26" tone="light" />

                <nav class="hidden items-center gap-8 text-[13px] text-white/55 md:flex">
                    <a href="#features" class="transition hover:text-white">Tính năng</a>
                    <a href="#how" class="transition hover:text-white">Cách hoạt động</a>
                    <a href="#cost" class="transition hover:text-white">Chi phí</a>
                </nav>

                <div class="flex items-center gap-3">
                    <RouterLink
                        v-if="auth.user"
                        :to="{ name: 'composer' }"
                        class="rounded-lg bg-white px-4 py-2 text-[13px] font-medium text-[#08080A] transition hover:bg-white/90"
                    >Vào ứng dụng</RouterLink>

                    <template v-else>
                        <RouterLink :to="{ name: 'login' }" class="text-[13px] text-white/60 transition hover:text-white">
                            Đăng nhập
                        </RouterLink>
                        <RouterLink
                            :to="{ name: 'login' }"
                            class="rounded-lg bg-white px-4 py-2 text-[13px] font-medium text-[#08080A] transition hover:bg-white/90"
                        >Bắt đầu</RouterLink>
                    </template>
                </div>
            </div>
        </header>

        <!-- ─── Hero ────────────────────────────────────────────────────── -->
        <section class="relative overflow-hidden">
            <!-- Ambient glow. Pure CSS so there is no image to load. -->
            <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                <div class="absolute left-1/2 top-[-18rem] h-[36rem] w-[56rem] -translate-x-1/2 rounded-full bg-[#4F46E5]/20 blur-[140px]"></div>
                <div class="absolute left-[18%] top-[6rem] h-[22rem] w-[22rem] rounded-full bg-[#7C3AED]/10 blur-[120px]"></div>
            </div>

            <div class="relative mx-auto max-w-6xl px-6 pb-24 pt-20 md:pt-28">
                <div class="mx-auto max-w-3xl text-center">
                    <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.03] px-3 py-1 text-[12px] text-white/60">
                        <span class="h-1.5 w-1.5 rounded-full bg-emerald-400"></span>
                        Laravel 13 · PHP 8.5 · Redis 8.10
                    </span>

                    <h1 class="mt-7 text-[2.75rem] font-semibold leading-[1.05] tracking-[-0.04em] md:text-[4rem]">
                        Một bài đăng.<br>
                        <span class="bg-gradient-to-r from-white via-white to-[#A5A0FF] bg-clip-text text-transparent">
                            Mười nghìn tài khoản.
                        </span>
                    </h1>

                    <p class="mx-auto mt-6 max-w-xl text-[15px] leading-relaxed text-white/55 md:text-base">
                        Nền tảng đăng và lên lịch bài lên X ở quy mô 1.000–10.000 tài khoản.
                        Mỗi tài khoản một nội dung riêng, mỗi bài một thời điểm riêng, và
                        không bao giờ đăng trùng.
                    </p>

                    <div class="mt-9 flex flex-col items-center justify-center gap-3 sm:flex-row">
                        <RouterLink
                            :to="auth.user ? { name: 'composer' } : { name: 'login' }"
                            class="w-full rounded-xl bg-white px-6 py-3 text-sm font-medium text-[#08080A] transition hover:bg-white/90 sm:w-auto"
                        >{{ auth.user ? 'Mở Composer' : 'Dùng thử ngay' }}</RouterLink>

                        <a
                            href="#how"
                            class="w-full rounded-xl border border-white/12 bg-white/[0.03] px-6 py-3 text-sm font-medium text-white/80 transition hover:bg-white/[0.07] sm:w-auto"
                        >Xem cách hoạt động</a>
                    </div>
                </div>

                <!-- Product frame -->
                <div class="mt-16 md:mt-20">
                    <div class="rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.06] to-white/[0.02] p-1.5 shadow-2xl shadow-black/60">
                        <div class="overflow-hidden rounded-xl bg-[#0C0C10]">
                            <!-- chrome -->
                            <div class="flex items-center gap-2 border-b border-white/[0.06] px-4 py-3">
                                <span class="h-2.5 w-2.5 rounded-full bg-white/12"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-white/12"></span>
                                <span class="h-2.5 w-2.5 rounded-full bg-white/12"></span>
                                <span class="ml-3 text-[11px] text-white/30">Composer — Chiến dịch tháng 8</span>
                            </div>

                            <div class="grid gap-px bg-white/[0.05] sm:grid-cols-3">
                                <div
                                    v-for="tile in [
                                        { label: 'Tài khoản mục tiêu', value: '8.412', tone: 'text-white' },
                                        { label: 'Biến thể nội dung', value: '1.296', tone: 'text-[#A5A0FF]' },
                                        { label: 'Rải trong', value: '3h 20m', tone: 'text-white' },
                                    ]"
                                    :key="tile.label"
                                    class="bg-[#0C0C10] px-5 py-6"
                                >
                                    <p class="text-[11px] uppercase tracking-wider text-white/35">{{ tile.label }}</p>
                                    <p class="mt-1.5 text-2xl font-semibold tracking-tight" :class="tile.tone">{{ tile.value }}</p>
                                </div>
                            </div>

                            <!-- rendered variants: the differentiator, shown not told -->
                            <div class="space-y-px bg-white/[0.05]">
                                <div
                                    v-for="row in [
                                        { handle: '@dung43', body: 'Xin chào @dung43 — hôm nay có gì mới?', state: 'Đã đăng', tone: 'text-emerald-400' },
                                        { handle: '@trach04', body: 'Chào @trach04 — tuần này có gì mới?', state: 'Đã đăng', tone: 'text-emerald-400' },
                                        { handle: '@giao.sam', body: 'Xin chào @giao.sam — tuần này có gì mới?', state: 'Đang gửi', tone: 'text-amber-400' },
                                        { handle: '@kim.lai', body: 'Chào @kim.lai — hôm nay có gì mới?', state: 'Chờ 14:20', tone: 'text-white/40' },
                                    ]"
                                    :key="row.handle"
                                    class="flex items-center gap-4 bg-[#0C0C10] px-5 py-3 text-[13px]"
                                >
                                    <span class="w-24 shrink-0 font-medium text-white/70">{{ row.handle }}</span>
                                    <span class="min-w-0 flex-1 truncate text-white/45">{{ row.body }}</span>
                                    <span class="shrink-0 text-[11px]" :class="row.tone">{{ row.state }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- stack strip -->
                <div class="mt-14 flex flex-wrap items-center justify-center gap-x-8 gap-y-3">
                    <span
                        v-for="item in stack"
                        :key="item"
                        class="text-[12px] font-medium tracking-wide text-white/25"
                    >{{ item }}</span>
                </div>
            </div>
        </section>

        <!-- ─── Features ────────────────────────────────────────────────── -->
        <section id="features" class="border-t border-white/[0.06] py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-2xl">
                    <p class="text-[12px] font-medium uppercase tracking-[0.16em] text-white/35">Tính năng</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-[-0.03em] md:text-4xl">
                        Sáu bài toán mà quy mô tạo ra
                    </h2>
                    <p class="mt-4 text-[15px] leading-relaxed text-white/50">
                        Đăng từ 10 tài khoản là một vòng lặp. Đăng từ 10.000 tài khoản là một
                        hệ thống phân tán, và đây là những chỗ nó thường vỡ.
                    </p>
                </div>

                <div class="mt-14 grid gap-px overflow-hidden rounded-2xl border border-white/[0.07] bg-white/[0.06] md:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="feature in features"
                        :key="feature.title"
                        class="group bg-[#08080A] p-7 transition hover:bg-[#0D0D12]"
                    >
                        <span class="text-[11px] font-medium uppercase tracking-wider text-[#8F87F5]">{{ feature.tag }}</span>
                        <h3 class="mt-3 text-[17px] font-semibold tracking-tight">{{ feature.title }}</h3>
                        <p class="mt-2.5 text-[13.5px] leading-relaxed text-white/45">{{ feature.body }}</p>
                    </article>
                </div>
            </div>
        </section>

        <!-- ─── How it works ────────────────────────────────────────────── -->
        <section id="how" class="border-t border-white/[0.06] py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="max-w-2xl">
                    <p class="text-[12px] font-medium uppercase tracking-[0.16em] text-white/35">Cách hoạt động</p>
                    <h2 class="mt-3 text-3xl font-semibold tracking-[-0.03em] md:text-4xl">
                        Từ một ô soạn thảo tới hàng nghìn dòng thời gian
                    </h2>
                </div>

                <div class="mt-14 grid gap-8 md:grid-cols-2 lg:grid-cols-4">
                    <div v-for="step in steps" :key="step.n" class="relative">
                        <div class="flex items-center gap-3">
                            <span class="font-mono text-[13px] text-[#8F87F5]">{{ step.n }}</span>
                            <span class="h-px flex-1 bg-white/[0.08]"></span>
                        </div>
                        <h3 class="mt-4 text-[16px] font-semibold tracking-tight">{{ step.title }}</h3>
                        <p class="mt-2 text-[13.5px] leading-relaxed text-white/45">{{ step.body }}</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ─── Cost calculator ─────────────────────────────────────────── -->
        <section id="cost" class="border-t border-white/[0.06] py-24">
            <div class="mx-auto max-w-6xl px-6">
                <div class="grid gap-14 lg:grid-cols-2 lg:items-center">
                    <div>
                        <p class="text-[12px] font-medium uppercase tracking-[0.16em] text-white/35">Chi phí thật</p>
                        <h2 class="mt-3 text-3xl font-semibold tracking-[-0.03em] md:text-4xl">
                            Link làm bài đăng đắt gấp 13 lần
                        </h2>
                        <p class="mt-5 text-[15px] leading-relaxed text-white/50">
                            Từ tháng 2/2026, X chuyển sang tính tiền theo lượt dùng:
                            <span class="font-medium text-white">$0,015</span> cho bài thường, nhưng
                            <span class="font-medium text-white">$0,20</span> ngay khi bài có chứa link.
                        </p>
                        <p class="mt-4 text-[15px] leading-relaxed text-white/50">
                            Đây là chỗ đắt nhất mà một người vận hành có thể bấm nhầm, nên Xpost
                            hiện chi phí ước tính ngay cạnh nút đăng — trước khi bấm, không phải
                            sau khi nhận hoá đơn.
                        </p>
                    </div>

                    <div class="rounded-2xl border border-white/[0.08] bg-white/[0.02] p-7">
                        <label class="block">
                            <div class="flex items-baseline justify-between">
                                <span class="text-[13px] text-white/60">Số tài khoản</span>
                                <span class="font-mono text-[13px] text-white">{{ number(accounts) }}</span>
                            </div>
                            <input
                                v-model.number="accounts"
                                type="range"
                                min="100"
                                max="10000"
                                step="100"
                                class="mt-3 w-full accent-[#8F87F5]"
                            >
                        </label>

                        <label class="mt-6 block">
                            <div class="flex items-baseline justify-between">
                                <span class="text-[13px] text-white/60">Bài mỗi tài khoản</span>
                                <span class="font-mono text-[13px] text-white">{{ postsPerAccount }}</span>
                            </div>
                            <input
                                v-model.number="postsPerAccount"
                                type="range"
                                min="1"
                                max="10"
                                step="1"
                                class="mt-3 w-full accent-[#8F87F5]"
                            >
                        </label>

                        <label class="mt-6 flex items-center justify-between rounded-lg border border-white/[0.08] px-4 py-3">
                            <span class="text-[13px] text-white/70">Bài có chứa link đích</span>
                            <input v-model="withLink" type="checkbox" class="h-4 w-4 accent-[#8F87F5]">
                        </label>

                        <div class="mt-7 border-t border-white/[0.08] pt-6">
                            <div class="flex items-baseline justify-between text-[13px] text-white/45">
                                <span>{{ number(totalPosts) }} bài × {{ withLink ? '$0,200' : '$0,015' }}</span>
                            </div>
                            <p class="mt-2 text-4xl font-semibold tracking-tight" :class="withLink ? 'text-[#FF8A8A]' : 'text-emerald-400'">
                                {{ money(cost) }}
                            </p>

                            <p v-if="withLink" class="mt-3 text-[13px] leading-relaxed text-white/45">
                                Bỏ link đi tiết kiệm <span class="font-medium text-white">{{ money(savings) }}</span>
                                cho cùng chiến dịch này.
                            </p>
                            <p v-else class="mt-3 text-[13px] leading-relaxed text-white/45">
                                Thêm link vào sẽ thành <span class="font-medium text-white">{{ money(totalPosts * PRICE_LINK) }}</span>.
                            </p>
                        </div>

                        <p class="mt-5 text-[11px] leading-relaxed text-white/25">
                            Giá tham chiếu theo bảng pay-per-use công bố trên docs.x.com. Chưa gồm
                            chi phí đọc dữ liệu và có thể thay đổi — hãy đối chiếu với tài khoản
                            developer của bạn.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ─── CTA ─────────────────────────────────────────────────────── -->
        <section class="border-t border-white/[0.06] py-24">
            <div class="mx-auto max-w-3xl px-6 text-center">
                <BrandLogo variant="tile" :size="48" class="mb-7" />
                <h2 class="text-3xl font-semibold tracking-[-0.03em] md:text-4xl">
                    Sẵn sàng chạy chiến dịch đầu tiên
                </h2>
                <p class="mx-auto mt-4 max-w-lg text-[15px] leading-relaxed text-white/50">
                    Kết nối X App, import tài khoản, soạn một bài. Phần còn lại là việc của
                    hàng đợi.
                </p>
                <RouterLink
                    :to="auth.user ? { name: 'composer' } : { name: 'login' }"
                    class="mt-8 inline-block rounded-xl bg-white px-7 py-3 text-sm font-medium text-[#08080A] transition hover:bg-white/90"
                >{{ auth.user ? 'Mở Composer' : 'Bắt đầu' }}</RouterLink>
            </div>
        </section>

        <!-- ─── Footer ──────────────────────────────────────────────────── -->
        <footer class="border-t border-white/[0.06] py-10">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-5 px-6 sm:flex-row">
                <BrandLogo variant="full" :size="22" tone="light" class="text-white/70" />

                <p class="text-center text-[12px] leading-relaxed text-white/30 sm:text-right">
                    Xpost là công cụ độc lập, không liên kết hay được X Corp bảo trợ.<br class="hidden sm:block">
                    Người dùng chịu trách nhiệm tuân thủ Điều khoản dịch vụ của X.
                </p>
            </div>
        </footer>
    </div>
</template>
