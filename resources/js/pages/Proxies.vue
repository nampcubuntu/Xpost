<script setup>
import { onMounted, reactive, ref } from 'vue';
import api, { validationErrors } from '../lib/api';

const proxies = ref([]);
const loading = ref(true);
const busy = ref(false);
const editing = ref(null);
const errors = ref({});
const checking = ref(new Set());

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
    loading.value = true;

    try {
        const { data } = await api.get('proxies');
        proxies.value = data.data;
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
            <button class="xp-btn-primary" @click="startCreate">+ Thêm proxy</button>
        </div>

        <p class="mb-5 max-w-3xl text-[13px] text-neutral-600">
            Gán proxy theo tài khoản để nhiều tài khoản không cùng đi ra từ một IP. Nút "Kiểm tra" gọi thẳng
            <code class="rounded bg-neutral-100 px-1">api.x.com</code> — proxy ra được internet nhưng bị X chặn thì vẫn vô dụng.
        </p>

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

        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-[13px]">
                <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Proxy</th>
                        <th class="px-4 py-2.5 font-medium">Tình trạng</th>
                        <th class="px-4 py-2.5 font-medium">Độ trễ</th>
                        <th class="px-4 py-2.5 font-medium">Tài khoản</th>
                        <th class="px-4 py-2.5 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                    <tr v-if="loading"><td colspan="5" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td></tr>
                    <tr v-else-if="!proxies.length"><td colspan="5" class="px-4 py-8 text-center text-neutral-500">Chưa có proxy nào.</td></tr>

                    <tr v-for="proxy in proxies" :key="proxy.id" class="hover:bg-neutral-50">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ proxy.label || `${proxy.host}:${proxy.port}` }}</p>
                            <p class="text-xs text-neutral-500">{{ proxy.scheme }}://{{ proxy.host }}:{{ proxy.port }} · {{ proxy.country || '—' }}</p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="healthClass[proxy.health_status]">
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
    </div>
</template>
