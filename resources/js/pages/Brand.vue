<script setup>
import { ref } from 'vue';
import BrandLogo from '../components/BrandLogo.vue';

/**
 * Brand reference page.
 *
 * Exists so the mark has one canonical definition rather than being redrawn by
 * hand every time someone needs it in a deck or an email signature.
 */
const copied = ref(null);

const palette = [
    { name: 'Ink', hex: '#08080A', note: 'Nền chính, chữ trên nền sáng' },
    { name: 'Surface', hex: '#12121A', note: 'Thẻ, khối nổi trên nền tối' },
    { name: 'Iris', hex: '#8F87F5', note: 'Nhấn — dùng tiết chế' },
    { name: 'Iris Deep', hex: '#2D2A5E', note: 'Nhấn chìm, viền trên nền tối' },
    { name: 'Paper', hex: '#FFFFFF', note: 'Nền ứng dụng, chữ trên nền tối' },
    { name: 'Mist', hex: '#8B8B94', note: 'Chữ phụ' },
];

const markSvg = `<svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
  <path fill="currentColor"
        d="M10 50 L88 10.3 L88 34.1 L10 50 L88 41.1 L88 58.9 L10 50 L88 65.9 L88 89.7 Z"/>
</svg>`;

async function copy(text, key) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => (copied.value = null), 1600);
    } catch {
        copied.value = null;
    }
}
</script>

<template>
    <div class="min-h-full bg-white text-[#08080A]">
        <header class="border-b border-neutral-200">
            <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-6">
                <RouterLink :to="{ name: 'landing' }" class="flex items-center">
                    <BrandLogo variant="full" :size="24" />
                </RouterLink>
                <RouterLink :to="{ name: 'landing' }" class="text-[13px] text-neutral-500 transition hover:text-neutral-900">
                    ← Trang chủ
                </RouterLink>
            </div>
        </header>

        <div class="mx-auto max-w-5xl px-6 py-16">
            <p class="text-[12px] font-medium uppercase tracking-[0.16em] text-neutral-400">Nhận diện</p>
            <h1 class="mt-3 text-4xl font-semibold tracking-[-0.035em]">Hệ thống logo Xpost</h1>
            <p class="mt-5 max-w-2xl text-[15px] leading-relaxed text-neutral-600">
                Một chùm đặc mở ra từ một điểm bên trái, bị hai nêm mỏng xẻ thành ba dải —
                đúng thứ sản phẩm làm: một bài đăng, nhiều tài khoản. Toàn bộ mark là
                <strong class="font-medium text-neutral-900">một path duy nhất</strong>: khoảng
                trống giữa các dải là phần âm cắt ra từ chính khối đó, không phải ba hình
                rời ghép lại. Đó là khác biệt giữa một logo và một icon giao diện.
            </p>

            <!-- Why not X's logo -->
            <div class="mt-8 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4">
                <p class="text-[13px] font-medium text-amber-900">Vì sao không dùng logo 𝕏</p>
                <p class="mt-1.5 text-[13px] leading-relaxed text-amber-800">
                    Glyph 𝕏 là nhãn hiệu của X Corp. Dùng nó làm logo sản phẩm riêng là rủi ro
                    pháp lý. Chữ “Xpost” trong tên gọi thì được — đó là nhắc tới nền tảng mà
                    công cụ này làm việc cùng, không phải mạo nhận thương hiệu.
                </p>
            </div>

            <!-- Lockups -->
            <section class="mt-16">
                <h2 class="text-lg font-semibold tracking-tight">Các biến thể</h2>

                <div class="mt-6 grid gap-4 sm:grid-cols-3">
                    <div class="rounded-xl border border-neutral-200 p-8">
                        <div class="grid h-24 place-items-center">
                            <BrandLogo variant="full" :size="30" />
                        </div>
                        <p class="mt-4 border-t border-neutral-100 pt-3 text-[12px] text-neutral-500">
                            <span class="font-medium text-neutral-800">Full lockup</span><br>
                            Header, footer, tài liệu
                        </p>
                    </div>

                    <div class="rounded-xl border border-neutral-200 p-8">
                        <div class="grid h-24 place-items-center">
                            <BrandLogo variant="mark" :size="40" />
                        </div>
                        <p class="mt-4 border-t border-neutral-100 pt-3 text-[12px] text-neutral-500">
                            <span class="font-medium text-neutral-800">Mark</span><br>
                            Khi tên đã hiện ở chỗ khác
                        </p>
                    </div>

                    <div class="rounded-xl border border-neutral-200 p-8">
                        <div class="grid h-24 place-items-center">
                            <BrandLogo variant="tile" :size="48" />
                        </div>
                        <p class="mt-4 border-t border-neutral-100 pt-3 text-[12px] text-neutral-500">
                            <span class="font-medium text-neutral-800">Tile</span><br>
                            Favicon, avatar, app icon
                        </p>
                    </div>
                </div>
            </section>

            <!-- Tone -->
            <section class="mt-14">
                <h2 class="text-lg font-semibold tracking-tight">Trên nền sáng và nền tối</h2>

                <div class="mt-6 grid gap-4 sm:grid-cols-2">
                    <div class="grid h-40 place-items-center rounded-xl border border-neutral-200 bg-white">
                        <BrandLogo variant="full" :size="32" tone="dark" />
                    </div>
                    <div class="grid h-40 place-items-center rounded-xl bg-[#08080A]">
                        <BrandLogo variant="full" :size="32" tone="light" class="text-white" />
                    </div>
                </div>
            </section>

            <!-- Scale -->
            <section class="mt-14">
                <h2 class="text-lg font-semibold tracking-tight">Kích thước</h2>
                <p class="mt-2 text-[13px] text-neutral-500">
                    Đã kiểm tra bằng cách hạ mẫu: ba dải vẫn tách bạch ở 16px. Dưới mức đó dùng tile.
                </p>

                <div class="mt-6 flex flex-wrap items-end gap-10 rounded-xl border border-neutral-200 p-8">
                    <div v-for="size in [16, 24, 32, 48, 72]" :key="size" class="text-center">
                        <BrandLogo variant="mark" :size="size" />
                        <p class="mt-3 font-mono text-[11px] text-neutral-400">{{ size }}px</p>
                    </div>
                </div>
            </section>

            <!-- Palette -->
            <section class="mt-14">
                <h2 class="text-lg font-semibold tracking-tight">Bảng màu</h2>

                <div class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <button
                        v-for="color in palette"
                        :key="color.hex"
                        type="button"
                        class="flex items-center gap-4 rounded-xl border border-neutral-200 p-3 text-left transition hover:border-neutral-300"
                        @click="copy(color.hex, color.hex)"
                    >
                        <span
                            class="h-11 w-11 shrink-0 rounded-lg border border-black/5"
                            :style="{ background: color.hex }"
                        ></span>
                        <span class="min-w-0">
                            <span class="block text-[13px] font-medium">{{ color.name }}</span>
                            <span class="block font-mono text-[11px] text-neutral-500">
                                {{ copied === color.hex ? 'Đã chép' : color.hex }}
                            </span>
                            <span class="mt-0.5 block truncate text-[11px] text-neutral-400">{{ color.note }}</span>
                        </span>
                    </button>
                </div>
            </section>

            <!-- Typography -->
            <section class="mt-14">
                <h2 class="text-lg font-semibold tracking-tight">Kiểu chữ</h2>

                <div class="mt-6 rounded-xl border border-neutral-200 p-8">
                    <p class="text-[11px] uppercase tracking-wider text-neutral-400">Inter</p>
                    <p class="mt-3 text-4xl font-semibold tracking-[-0.035em]">Một bài đăng. Mười nghìn tài khoản.</p>
                    <p class="mt-4 text-[15px] leading-relaxed text-neutral-600">
                        Tiêu đề dùng weight 600 với tracking −0.035em. Chữ thân dùng weight 400,
                        không siết tracking. Inter được chọn vì bộ dấu tiếng Việt đầy đủ và
                        không vỡ ở cỡ nhỏ trong bảng dữ liệu.
                    </p>
                </div>
            </section>

            <!-- Source -->
            <section class="mt-14">
                <div class="flex items-baseline justify-between">
                    <h2 class="text-lg font-semibold tracking-tight">Mã nguồn SVG</h2>
                    <button
                        type="button"
                        class="text-[13px] text-neutral-500 transition hover:text-neutral-900"
                        @click="copy(markSvg, 'svg')"
                    >{{ copied === 'svg' ? 'Đã chép ✓' : 'Chép' }}</button>
                </div>

                <pre class="mt-4 overflow-x-auto rounded-xl border border-neutral-200 bg-neutral-50 p-5 font-mono text-[12px] leading-relaxed text-neutral-700">{{ markSvg }}</pre>

                <p class="mt-3 text-[13px] text-neutral-500">
                    Trong ứng dụng, dùng component
                    <code class="rounded bg-neutral-100 px-1.5 py-0.5 font-mono text-[12px]">&lt;BrandLogo /&gt;</code>
                    thay vì dán SVG — mọi chỗ sẽ cùng cập nhật khi mark thay đổi.
                </p>
            </section>

            <!-- Don'ts -->
            <section class="mt-14 mb-8">
                <h2 class="text-lg font-semibold tracking-tight">Không nên</h2>

                <ul class="mt-5 space-y-2.5 text-[13.5px] leading-relaxed text-neutral-600">
                    <li class="flex gap-3">
                        <span class="text-red-500">✕</span>
                        Đổi tỉ lệ ba dải — 15,5° / 13° / 15,5° với nêm 5° là thứ giữ mark đọc được ở 16px.
                    </li>
                    <li class="flex gap-3">
                        <span class="text-red-500">✕</span>
                        Đặt mark lên ảnh nền nhiều chi tiết mà không có tile.
                    </li>
                    <li class="flex gap-3">
                        <span class="text-red-500">✕</span>
                        Tô mark bằng gradient, đổ bóng, viền hay hiệu ứng nổi. Mark luôn là một khối đặc, một màu.
                    </li>
                    <li class="flex gap-3">
                        <span class="text-red-500">✕</span>
                        Ghép mark cạnh logo X để trông như quan hệ đối tác chính thức.
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
