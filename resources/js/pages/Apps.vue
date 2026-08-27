<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import api, { validationErrors } from '../lib/api';

const apps = ref([]);
const loading = ref(true);
const busy = ref(false);
const errors = ref({});
const editing = ref(null);
const flash = ref(null);

const blank = () => ({
    name: '',
    provider_app_id: '',
    tier: 'basic',
    client_id: '',
    client_secret: '',
    consumer_key: '',
    consumer_secret: '',
    callback_url: `${window.location.origin}/x/oauth/callback`,
    max_accounts: 500,
    weight: 1,
    is_active: true,
});

const form = reactive(blank());

/** Errors on fields that have no message slot of their own in the form. */
const otherErrors = computed(() => Object.entries(errors.value)
    .filter(([field]) => !['name', 'tier'].includes(field))
    .map(([, message]) => message));

async function load() {
    loading.value = true;

    try {
        const { data } = await api.get('apps');
        apps.value = data.data;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

function startCreate() {
    Object.assign(form, blank());
    editing.value = 'new';
    errors.value = {};
}

function startEdit(app) {
    Object.assign(form, {
        ...blank(),
        ...app,
        // Secrets never come back from the API; leaving them blank keeps them.
        client_secret: '',
        consumer_secret: '',
    });
    editing.value = app.id;
    errors.value = {};
}

async function save() {
    busy.value = true;
    errors.value = {};

    try {
        if (editing.value === 'new') {
            await api.post('apps', form);
        } else {
            await api.put(`apps/${editing.value}`, form);
        }

        editing.value = null;
        flash.value = { type: 'ok', message: 'Đã lưu.' };
        await load();
    } catch (error) {
        errors.value = validationErrors(error);

        /*
         * A 500 produces no validation bag at all, and the form only renders an
         * error next to a couple of fields -- so a server-side failure looked
         * exactly like the Save button doing nothing. Always surface something.
         */
        flash.value = {
            type: 'error',
            message: Object.values(errors.value)[0]
                ?? error.response?.data?.message
                ?? 'Không lưu được. Xem storage/logs/laravel.log.',
        };
    } finally {
        busy.value = false;
    }
}

async function remove(app) {
    if (!window.confirm(`Xoá app "${app.name}"?`)) {
        return;
    }

    try {
        await api.delete(`apps/${app.id}`);
        await load();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không xoá được.' };
    }
}

async function connect(app) {
    const { data } = await api.get(`apps/${app.id}/authorize-url`);
    window.location.href = data.url;
}
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-xl font-semibold">X Apps</h1>
            <button class="xp-btn-primary" @click="startCreate">+ Thêm app</button>
        </div>

        <p class="mb-5 max-w-3xl text-[13px] text-neutral-600">
            Mỗi X developer app có hạn mức POST riêng. Với vài nghìn tài khoản, hạn mức của một app sẽ là nút thắt —
            thêm nhiều app vào pool để chia tải, Xpost tự gán tài khoản vào app còn chỗ trống.
        </p>

        <div
            v-if="flash"
            class="mb-4 rounded-md px-3 py-2 text-sm"
            :class="flash.type === 'ok' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'"
        >
            {{ flash.message }}
        </div>

        <!-- Editor -->
        <form v-if="editing" class="xp-card mb-5 grid gap-4 p-5 sm:grid-cols-2" @submit.prevent="save">
            <label class="space-y-1">
                <span class="xp-label">Tên app</span>
                <input v-model="form.name" type="text" required class="xp-field">
                <span v-if="errors.name" class="text-xs text-red-600">{{ errors.name }}</span>
            </label>

            <!--
                Ads API access is granted per app, and X names apps by this
                number in its mail. Without it here, "your app 33347024 has been
                provided with Standard Ads API access" cannot be matched to any
                row -- and connecting accounts through the wrong app produces
                cards that only fail at publish.
            -->
            <label class="space-y-1">
                <span class="xp-label">App ID trên X</span>
                <input
                    v-model="form.provider_app_id"
                    type="text"
                    inputmode="numeric"
                    class="xp-field"
                    placeholder="33347024"
                >
                <span class="text-xs text-neutral-500">
                    Số hiển thị ở developer.x.com. Chỉ để đối chiếu, không dùng để gọi API.
                </span>
            </label>

            <label class="space-y-1">
                <span class="xp-label">Tier</span>
                <select v-model="form.tier" class="xp-field">
                    <option value="free">Free</option>
                    <option value="basic">Basic</option>
                    <option value="pro">Pro</option>
                    <option value="enterprise">Enterprise</option>
                </select>
                <span v-if="errors.tier" class="text-xs text-red-600">{{ errors.tier }}</span>
            </label>

            <!--
                Anything the server rejected that has no field of its own. Without
                this a rule on callback_url or max_accounts failed silently.
            -->
            <div v-if="otherErrors.length" class="sm:col-span-2 rounded-md bg-red-50 px-3 py-2 text-xs text-red-700">
                <p v-for="message in otherErrors" :key="message">{{ message }}</p>
            </div>

            <label class="space-y-1">
                <span class="xp-label">OAuth 2.0 Client ID</span>
                <input v-model="form.client_id" type="text" class="xp-field" autocomplete="off">
            </label>

            <label class="space-y-1">
                <span class="xp-label">OAuth 2.0 Client Secret</span>
                <input v-model="form.client_secret" type="password" class="xp-field" autocomplete="new-password" placeholder="Để trống nếu không đổi">
            </label>

            <label class="space-y-1">
                <span class="xp-label">OAuth 1.0a Consumer Key</span>
                <input v-model="form.consumer_key" type="text" class="xp-field" autocomplete="off">
            </label>

            <label class="space-y-1">
                <span class="xp-label">OAuth 1.0a Consumer Secret</span>
                <input v-model="form.consumer_secret" type="password" class="xp-field" autocomplete="new-password" placeholder="Để trống nếu không đổi">
            </label>

            <label class="space-y-1 sm:col-span-2">
                <span class="xp-label">Callback URL</span>
                <input v-model="form.callback_url" type="url" class="xp-field">
                <span class="xp-hint">Dán đúng giá trị này vào phần "Callback URI" trên X Developer Portal.</span>
            </label>

            <label class="space-y-1">
                <span class="xp-label">Số tài khoản tối đa</span>
                <input v-model.number="form.max_accounts" type="number" min="1" class="xp-field">
            </label>

            <label class="space-y-1">
                <span class="xp-label">Trọng số ưu tiên</span>
                <input v-model.number="form.weight" type="number" min="1" class="xp-field">
            </label>

            <div class="flex gap-2 sm:col-span-2">
                <button type="submit" class="xp-btn-primary" :disabled="busy">Lưu</button>
                <button type="button" class="xp-btn-ghost" @click="editing = null">Huỷ</button>
            </div>
        </form>

        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-[13px]">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">App</th>
                        <th class="px-4 py-2.5 font-medium">Xác thực</th>
                        <th class="px-4 py-2.5 font-medium">Tài khoản</th>
                        <th class="px-4 py-2.5 font-medium">Ngân sách tháng</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    <tr v-if="loading"><td colspan="5" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td></tr>
                    <tr v-else-if="!apps.length">
                        <td colspan="5" class="px-4 py-8 text-center text-neutral-500">Chưa có app nào.</td>
                    </tr>

                    <tr v-for="app in apps" :key="app.id" class="hover:bg-neutral-50">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ app.name }}</p>
                            <p class="text-xs uppercase text-neutral-500">
                                {{ app.tier }}
                                <span v-if="app.provider_app_id" class="normal-case text-neutral-400">
                                    · ID {{ app.provider_app_id }}
                                </span>
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <span v-if="app.has_oauth2" class="mr-1 rounded bg-green-50 px-1.5 py-0.5 text-xs text-green-700">OAuth 2.0</span>
                            <span v-if="app.has_oauth1" class="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-700">OAuth 1.0a</span>
                            <span v-if="!app.has_oauth2 && !app.has_oauth1" class="text-xs text-red-600">Thiếu credential</span>

                            <!--
                                Observed, not configured: X answers 403 on the
                                Ads API for an app without access, so the first
                                OAuth 1.0a connect through this app settles it.
                                Only an app with access can mint cards.
                            -->
                            <p v-if="app.ads_api_access === true" class="mt-1 text-xs text-green-700">Ads API ✓</p>
                            <p v-else-if="app.ads_api_access === false" class="mt-1 text-xs text-red-600">Không có Ads API</p>
                            <p v-else class="mt-1 text-xs text-neutral-400">Ads API: chưa rõ</p>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="h-1.5 w-20 overflow-hidden rounded-full bg-neutral-200">
                                    <div class="h-full rounded-full bg-neutral-900" :style="{ width: `${Math.min(app.capacity_percent, 100)}%` }"></div>
                                </div>
                                <span class="text-xs text-neutral-500">{{ app.assigned_count }}/{{ app.max_accounts }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-neutral-600">
                            {{ app.monthly_posts_used.toLocaleString('vi-VN') }} / {{ app.monthly_post_cap.toLocaleString('vi-VN') }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button v-if="app.has_oauth2" class="mr-2 text-blue-600 hover:underline" @click="connect(app)">Kết nối tài khoản</button>
                            <button class="mr-2 text-neutral-600 hover:underline" @click="startEdit(app)">Sửa</button>
                            <button class="text-red-600 hover:underline" @click="remove(app)">Xoá</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</template>
