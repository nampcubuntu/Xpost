<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useAuthStore } from '../stores/auth';
import api, { validationErrors } from '../lib/api';
import { cached, swr } from '../lib/cache';
import AudiencePicker from '../components/AudiencePicker.vue';
import TimezoneSelect from '../components/TimezoneSelect.vue';

const auth = useAuthStore();

const schedules = ref([]);
const loading = ref(true);
const busy = ref(false);
const editing = ref(null);
const errors = ref({});
const quote = ref(null);
const quoting = ref(false);

/*
 * ISO weekday numbers, which is what the server stores. Monday first, because
 * that is how a week reads in Vietnamese -- Sunday is "chủ nhật", the last day.
 */
const WEEKDAYS = [
    { iso: 1, short: 'T2' },
    { iso: 2, short: 'T3' },
    { iso: 3, short: 'T4' },
    { iso: 4, short: 'T5' },
    { iso: 5, short: 'T6' },
    { iso: 6, short: 'T7' },
    { iso: 7, short: 'CN' },
];

/*
 * Ready-made slot sets. Typing four times into a picker is tedious and it is
 * where people fat-finger 19:00 into 09:00, so the common shapes are one click.
 */
const PRESETS = [
    { label: '3 bài/ngày', times: ['09:00', '13:00', '19:00'] },
    { label: '4 bài/ngày', times: ['08:00', '12:00', '16:00', '20:00'] },
    { label: '5 bài/ngày', times: ['07:30', '11:00', '14:30', '18:00', '21:00'] },
    { label: 'Giờ vàng VN', times: ['07:00', '12:00', '20:30'] },
];

/*
 * Whose clock the times are read against. The pool is global, so this is a real
 * choice rather than a formality: one instant worldwide, or the same local hour
 * in each account's own country.
 */
const TIMING_MODES = [
    {
        value: 'fixed_time',
        label: 'Một thời điểm chung',
        hint: 'Mọi tài khoản đăng cùng một lúc, theo múi giờ của lịch.',
    },
    {
        value: 'per_account_timezone',
        label: 'Giờ địa phương từng tài khoản',
        hint: 'Mỗi tài khoản đăng lúc 09:00 giờ nước nó — trải ra suốt 24 giờ.',
    },
];

/** The operator's own zone beats a hardcoded one for a pool spread worldwide. */
const defaultTimezone = () => auth.user?.timezone
    || Intl.DateTimeFormat().resolvedOptions().timeZone
    || 'UTC';

const blank = () => ({
    name: '',
    times: ['09:00', '13:00', '19:00'],
    days_of_week: [],
    timezone: defaultTimezone(),
    starts_on: '',
    ends_on: '',
    is_active: true,
    catch_up_minutes: 15,
    template: {
        body: '',
        reply_settings: 'everyone',
        destination_type: 'none',
        website_url: '',
        variant_strategy: 'spintax',
        schedule_mode: 'fixed_time',
        audience: { mode: 'all', groups: [], accounts: [], filters: {}, shuffle: true },
    },
});

const form = reactive(blank());
const newTime = ref('09:00');

const perAccountLocal = computed(() => form.template.schedule_mode === 'per_account_timezone');

async function load() {
    // Last answer first, then the true one: arriving at a page that has not
    // changed should not mean staring at an empty table while it is proved.
    loading.value = cached('schedules') === undefined;

    try {
        await swr('schedules', async () => (await api.get('schedules')).data, (data) => {
            schedules.value = data.data;
        });
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function startCreate() {
    Object.assign(form, blank());
    editing.value = 'new';
    errors.value = {};
    quote.value = null;
    refreshQuote();
}

function startEdit(schedule) {
    Object.assign(form, {
        ...blank(),
        ...schedule,
        days_of_week: schedule.days_of_week ?? [],
        starts_on: schedule.starts_on ?? '',
        ends_on: schedule.ends_on ?? '',
        template: { ...blank().template, ...schedule.template },
    });

    editing.value = schedule.id;
    errors.value = {};
    refreshQuote();
}

// ── Slot picking ────────────────────────────────────────────────────────────

/** Sorted and de-duplicated, the same way the server treats them. */
const sortedTimes = computed(() => [...new Set(form.times)].sort());

function addTime() {
    if (newTime.value && !form.times.includes(newTime.value)) {
        form.times = [...form.times, newTime.value].sort();
    }
}

function removeTime(time) {
    form.times = form.times.filter((t) => t !== time);
}

function applyPreset(preset) {
    form.times = [...preset.times];
}

function toggleDay(iso) {
    form.days_of_week = form.days_of_week.includes(iso)
        ? form.days_of_week.filter((d) => d !== iso)
        : [...form.days_of_week, iso].sort();
}

// ── Cost ────────────────────────────────────────────────────────────────────

/*
 * Priced server-side rather than in the browser: the audience count needs a
 * query, and the unit price lives in config where the publisher can see it too.
 */
let quoteTimer;
function refreshQuote() {
    clearTimeout(quoteTimer);

    quoteTimer = setTimeout(async () => {
        if (!editing.value) {
            return;
        }

        quoting.value = true;

        try {
            const { data } = await api.post('schedules/preview', {
                template: form.template,
                times: sortedTimes.value,
            });

            quote.value = data;
        } catch {
            quote.value = null;
        } finally {
            quoting.value = false;
        }
    }, 350);
}

watch(
    () => [
        sortedTimes.value.join(),
        form.template.destination_type,
        form.template.schedule_mode,
        JSON.stringify(form.template.audience),
    ],
    refreshQuote,
);

/* Unit prices are sub-cent ($0.015), so rounding to 2 places would print "$0.02". */
const money = (value) => {
    const number = Number(value ?? 0);
    const places = number > 0 && number < 1 ? 3 : 2;

    return `$${number.toLocaleString('en-US', { minimumFractionDigits: places, maximumFractionDigits: places })}`;
};

// ── Persistence ─────────────────────────────────────────────────────────────

async function save() {
    busy.value = true;
    errors.value = {};

    const payload = {
        ...form,
        times: sortedTimes.value,
        // The server reads an empty list as "every day"; sending [] and sending
        // null must not mean two different things.
        days_of_week: form.days_of_week.length ? form.days_of_week : null,
        starts_on: form.starts_on || null,
        ends_on: form.ends_on || null,
    };

    try {
        if (editing.value === 'new') {
            await api.post('schedules', payload);
        } else {
            await api.put(`schedules/${editing.value}`, payload);
        }

        editing.value = null;
        await load();
    } catch (error) {
        errors.value = validationErrors(error);
    } finally {
        busy.value = false;
    }
}

async function toggle(schedule) {
    await api.post(`schedules/${schedule.id}/toggle`);
    await load();
}

async function remove(schedule) {
    if (!window.confirm(`Xoá lịch "${schedule.name}"? Bài đã tạo trước đó vẫn giữ nguyên.`)) {
        return;
    }

    await api.delete(`schedules/${schedule.id}`);
    await load();
}

const formatDateTime = (iso) => (iso
    ? new Date(iso).toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
    : '—');

const daysLabel = (days) => (days?.length
    ? WEEKDAYS.filter((d) => days.includes(d.iso)).map((d) => d.short).join(', ')
    : 'Mọi ngày');
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">Lịch lặp lại</h1>
            <button class="xp-btn-primary" @click="startCreate">+ Tạo lịch</button>
        </div>

        <p class="mb-5 max-w-3xl text-[13px] text-neutral-600">
            Một lịch tự sinh bài theo khung giờ mỗi ngày — mỗi khung giờ tạo một bài, rồi bài đó fan-out ra
            toàn bộ tài khoản trong tệp đối tượng. Ba khung giờ với 1.000 tài khoản là 3.000 bài/ngày, nên hãy
            xem kỹ ô chi phí trước khi lưu.
        </p>

        <!-- ── Editor ─────────────────────────────────────────────────────── -->
        <form v-if="editing" class="xp-card mb-6 p-5" @submit.prevent="save">
            <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
                <div class="space-y-5">
                    <label class="space-y-1 block">
                        <span class="xp-label">Tên lịch</span>
                        <input v-model="form.name" type="text" required class="xp-field" placeholder="VD: Đăng đều 3 khung mỗi ngày">
                        <span v-if="errors.name" class="text-xs text-red-600">{{ errors.name }}</span>
                    </label>

                    <label class="space-y-1 block">
                        <span class="xp-label">Nội dung</span>
                        <textarea
                            v-model="form.template.body"
                            rows="4"
                            class="xp-field resize-y"
                            placeholder="{Chào|Xin chào} mọi người — hôm nay có gì mới?"
                        ></textarea>
                        <span class="xp-hint">
                            Spintax <code class="rounded bg-neutral-100 px-1">{a|b|c}</code> cho mỗi tài khoản một
                            biến thể khác nhau, cố định theo tài khoản nên chạy lại vẫn ra đúng câu đó.
                        </span>
                    </label>

                    <!-- Slot picker: the centre of this page -->
                    <div class="space-y-2">
                        <span class="xp-label">Khung giờ mỗi ngày</span>

                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="time in sortedTimes"
                                :key="time"
                                class="inline-flex items-center gap-1.5 rounded-full border border-neutral-900 bg-neutral-900 px-3 py-1 text-xs font-medium text-white"
                            >
                                {{ time }}
                                <button type="button" class="opacity-60 hover:opacity-100" @click="removeTime(time)">×</button>
                            </span>

                            <span v-if="!sortedTimes.length" class="xp-hint py-1">Chưa có khung giờ nào.</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <input v-model="newTime" type="time" class="xp-field w-32 py-1.5">
                            <button type="button" class="xp-btn-ghost py-1.5" @click="addTime">Thêm khung giờ</button>

                            <span class="mx-1 h-5 w-px bg-neutral-200"></span>

                            <button
                                v-for="preset in PRESETS"
                                :key="preset.label"
                                type="button"
                                class="rounded-full border border-neutral-300 px-3 py-1 text-xs transition hover:bg-neutral-50"
                                @click="applyPreset(preset)"
                            >{{ preset.label }}</button>
                        </div>

                        <span v-if="errors.times" class="text-xs text-red-600">{{ errors.times }}</span>
                    </div>

                    <!-- Days of week -->
                    <div class="space-y-2">
                        <span class="xp-label">Ngày trong tuần</span>

                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="day in WEEKDAYS"
                                :key="day.iso"
                                type="button"
                                class="h-9 w-11 rounded-md border text-xs font-medium transition"
                                :class="form.days_of_week.includes(day.iso)
                                    ? 'border-neutral-900 bg-neutral-900 text-white'
                                    : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'"
                                @click="toggleDay(day.iso)"
                            >{{ day.short }}</button>

                            <button
                                v-if="form.days_of_week.length"
                                type="button"
                                class="ml-1 text-xs text-neutral-500 hover:underline"
                                @click="form.days_of_week = []"
                            >Bỏ chọn</button>
                        </div>

                        <span class="xp-hint">Không chọn ngày nào nghĩa là chạy mọi ngày.</span>
                    </div>

                    <!-- Whose clock -->
                    <div class="space-y-2">
                        <span class="xp-label">Tính giờ theo</span>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <button
                                v-for="mode in TIMING_MODES"
                                :key="mode.value"
                                type="button"
                                class="rounded-md border px-3 py-2 text-left text-[13px] transition"
                                :class="form.template.schedule_mode === mode.value
                                    ? 'border-neutral-900 ring-1 ring-neutral-900'
                                    : 'border-neutral-300 hover:bg-neutral-50'"
                                @click="form.template.schedule_mode = mode.value"
                            >
                                <span class="block font-medium">{{ mode.label }}</span>
                                <span class="mt-0.5 block text-xs text-neutral-500">{{ mode.hint }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <label class="space-y-1 sm:col-span-2">
                            <span class="xp-label">
                                {{ perAccountLocal ? 'Múi giờ dự phòng' : 'Múi giờ của lịch' }}
                            </span>
                            <TimezoneSelect v-model="form.timezone" />
                            <span class="xp-hint">
                                {{ perAccountLocal
                                    ? 'Chỉ dùng cho tài khoản chưa khai múi giờ; còn lại đăng theo giờ nước mình.'
                                    : 'Khung giờ ở trên được đọc theo múi giờ này.' }}
                            </span>
                        </label>

                        <label class="space-y-1">
                            <span class="xp-label">Bắt đầu</span>
                            <input v-model="form.starts_on" type="date" class="xp-field">
                        </label>

                        <label class="space-y-1">
                            <span class="xp-label">Kết thúc</span>
                            <input v-model="form.ends_on" type="date" class="xp-field">
                            <span v-if="errors.ends_on" class="text-xs text-red-600">{{ errors.ends_on }}</span>
                        </label>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="space-y-1">
                            <span class="xp-label">Đích đến</span>
                            <select v-model="form.template.destination_type" class="xp-field">
                                <option value="none">Không có link</option>
                                <option value="website">Website</option>
                            </select>
                        </label>

                        <label v-if="form.template.destination_type === 'website'" class="space-y-1">
                            <span class="xp-label">URL</span>
                            <input v-model="form.template.website_url" type="url" class="xp-field" placeholder="https://…">
                        </label>

                        <label class="space-y-1">
                            <span class="xp-label">Bỏ qua nếu trễ quá (phút)</span>
                            <input v-model.number="form.catch_up_minutes" type="number" min="0" max="1440" class="xp-field">
                            <span class="xp-hint">
                                Sau sự cố, khung giờ trễ hơn mức này sẽ bị bỏ thay vì đăng dồn một lúc.
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Sidebar: audience + what it costs -->
                <div class="space-y-5">
                    <div>
                        <span class="xp-label mb-2 block">Tệp đối tượng</span>
                        <AudiencePicker v-model="form.template.audience" />
                    </div>

                    <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-4">
                        <p class="mb-3 text-[13px] font-medium">Quy mô &amp; chi phí</p>

                        <p v-if="quoting" class="xp-hint">Đang tính…</p>

                        <dl v-else-if="quote" class="space-y-1.5 text-[13px]">
                            <div class="flex justify-between">
                                <dt class="text-neutral-500">Tài khoản</dt>
                                <dd class="font-medium">{{ quote.accounts.toLocaleString('vi-VN') }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-neutral-500">Khung giờ/ngày</dt>
                                <dd class="font-medium">{{ quote.times_per_day }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-neutral-200 pt-1.5">
                                <dt class="text-neutral-500">Bài/ngày</dt>
                                <dd class="font-medium">{{ quote.posts_per_day.toLocaleString('vi-VN') }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-neutral-500">Bài/tháng</dt>
                                <dd class="font-medium">{{ quote.posts_per_month.toLocaleString('vi-VN') }}</dd>
                            </div>
                            <div class="flex justify-between border-t border-neutral-200 pt-1.5">
                                <dt class="text-neutral-500">Đơn giá X</dt>
                                <dd class="font-medium">{{ money(quote.unit_price_usd) }}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-neutral-500">Chi phí/ngày</dt>
                                <dd class="font-medium">{{ money(quote.cost_per_day_usd) }}</dd>
                            </div>
                            <div class="flex justify-between text-[15px]">
                                <dt class="font-medium">Chi phí/tháng</dt>
                                <dd class="font-semibold">{{ money(quote.cost_per_month_usd) }}</dd>
                            </div>

                            <p
                                v-if="quote.per_account_local && quote.accounts_without_timezone"
                                class="mt-2 rounded-md bg-amber-50 px-2.5 py-2 text-xs text-amber-800"
                            >
                                {{ quote.accounts_without_timezone.toLocaleString('vi-VN') }} tài khoản chưa khai múi giờ —
                                số này sẽ đăng theo {{ form.timezone }} thay vì giờ địa phương.
                                Đặt múi giờ hàng loạt ở trang Tài khoản X.
                            </p>

                            <p v-if="quote.has_link" class="mt-2 rounded-md bg-amber-50 px-2.5 py-2 text-xs text-amber-800">
                                Bài có link bị X tính {{ money(quote.unit_price_usd) }} thay vì
                                {{ money(quote.base_price_usd) }} — đắt hơn {{ Math.round(quote.unit_price_usd / quote.base_price_usd) }} lần.
                                Bỏ link sẽ còn {{ money(quote.posts_per_month * quote.base_price_usd) }}/tháng.
                            </p>
                        </dl>

                        <p v-else class="xp-hint">Chọn khung giờ và tệp đối tượng để xem ước tính.</p>
                    </div>
                </div>
            </div>

            <div class="mt-5 flex gap-2 border-t border-neutral-200 pt-4">
                <button type="submit" class="xp-btn-primary" :disabled="busy || !sortedTimes.length">
                    {{ busy ? 'Đang lưu…' : 'Lưu lịch' }}
                </button>
                <button type="button" class="xp-btn-ghost" @click="editing = null">Huỷ</button>
            </div>
        </form>

        <!-- ── List ───────────────────────────────────────────────────────── -->
        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[860px] text-left text-[13px]">
                    <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Lịch</th>
                            <th class="px-4 py-2.5 font-medium">Khung giờ</th>
                            <th class="px-4 py-2.5 font-medium">Ngày</th>
                            <th class="px-4 py-2.5 font-medium">Lần chạy tới</th>
                            <th class="px-4 py-2.5 font-medium">Đã tạo</th>
                            <th class="px-4 py-2.5 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-if="loading"><td colspan="6" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td></tr>
                        <tr v-else-if="!schedules.length">
                            <td colspan="6" class="px-4 py-8 text-center text-neutral-500">
                                Chưa có lịch nào. Tạo một lịch để hệ thống tự đăng theo khung giờ mỗi ngày.
                            </td>
                        </tr>

                        <tr v-for="schedule in schedules" :key="schedule.id" class="hover:bg-neutral-50">
                            <td class="px-4 py-3">
                                <p class="flex items-center gap-2 font-medium">
                                    {{ schedule.name }}
                                    <span
                                        class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="schedule.is_active ? 'bg-green-50 text-green-700' : 'bg-neutral-100 text-neutral-500'"
                                    >{{ schedule.is_active ? 'Đang chạy' : 'Tạm dừng' }}</span>
                                </p>
                                <p class="truncate text-xs text-neutral-500" :title="schedule.template?.body">
                                    {{ schedule.template?.body || '(không có nội dung)' }}
                                </p>
                                <p v-if="schedule.last_error" class="mt-0.5 truncate text-xs text-red-600" :title="schedule.last_error">
                                    Lỗi lần chạy trước: {{ schedule.last_error }}
                                </p>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span
                                        v-for="time in schedule.times"
                                        :key="time"
                                        class="rounded bg-neutral-100 px-1.5 py-0.5 text-xs"
                                    >{{ time }}</span>
                                </div>
                                <p class="mt-0.5 text-xs text-neutral-500">
                                    {{ schedule.template?.schedule_mode === 'per_account_timezone'
                                        ? 'giờ địa phương từng tài khoản'
                                        : schedule.timezone }}
                                </p>
                            </td>
                            <td class="px-4 py-3 text-neutral-600">{{ daysLabel(schedule.days_of_week) }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ formatDateTime(schedule.next_run_at) }}</td>
                            <td class="px-4 py-3 text-neutral-600">{{ schedule.runs_count }} lần</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button class="mr-2 text-blue-600 hover:underline" @click="toggle(schedule)">
                                    {{ schedule.is_active ? 'Tạm dừng' : 'Chạy lại' }}
                                </button>
                                <button class="mr-2 text-neutral-600 hover:underline" @click="startEdit(schedule)">Sửa</button>
                                <button class="text-red-600 hover:underline" @click="remove(schedule)">Xoá</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
