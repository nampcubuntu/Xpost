<script setup>
import { reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { validationErrors } from '../lib/api';
import BrandLogo from '../components/BrandLogo.vue';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const mode = ref('login');
const busy = ref(false);
const errors = ref({});

const form = reactive({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    team_name: '',
    remember: true,
});

async function submit() {
    busy.value = true;
    errors.value = {};

    try {
        if (mode.value === 'login') {
            await auth.login({ email: form.email, password: form.password, remember: form.remember });
        } else {
            await auth.register(form);
        }

        router.push(route.query.redirect ?? { name: 'composer' });
    } catch (error) {
        errors.value = validationErrors(error);

        if (!Object.keys(errors.value).length) {
            errors.value = { email: 'Đăng nhập thất bại. Kiểm tra lại email và mật khẩu.' };
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="grid min-h-full place-items-center bg-neutral-100 p-6">
        <div class="w-full max-w-sm">
            <RouterLink :to="{ name: 'landing' }" class="mb-6 flex items-center">
                <BrandLogo variant="full" :size="26" />
            </RouterLink>

            <form class="xp-card space-y-4 p-6" @submit.prevent="submit">
                <h1 class="text-base font-semibold">
                    {{ mode === 'login' ? 'Đăng nhập' : 'Tạo tài khoản' }}
                </h1>

                <template v-if="mode === 'register'">
                    <label class="block space-y-1">
                        <span class="xp-label">Họ tên</span>
                        <input v-model="form.name" type="text" required class="xp-field">
                    </label>

                    <label class="block space-y-1">
                        <span class="xp-label">Tên nhóm làm việc</span>
                        <input v-model="form.team_name" type="text" required class="xp-field">
                    </label>
                </template>

                <label class="block space-y-1">
                    <span class="xp-label">Email</span>
                    <input v-model="form.email" type="email" required autocomplete="email" class="xp-field">
                    <span v-if="errors.email" class="text-xs text-red-600">{{ errors.email }}</span>
                </label>

                <label class="block space-y-1">
                    <span class="xp-label">Mật khẩu</span>
                    <input
                        v-model="form.password"
                        type="password"
                        required
                        :autocomplete="mode === 'login' ? 'current-password' : 'new-password'"
                        class="xp-field"
                    >
                    <span v-if="errors.password" class="text-xs text-red-600">{{ errors.password }}</span>
                </label>

                <label v-if="mode === 'register'" class="block space-y-1">
                    <span class="xp-label">Nhập lại mật khẩu</span>
                    <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" class="xp-field">
                </label>

                <label v-if="mode === 'login'" class="flex cursor-pointer items-center gap-2.5 pt-0.5">
                    <input
                        v-model="form.remember"
                        type="checkbox"
                        class="h-4 w-4 rounded border-neutral-300 accent-neutral-900"
                    >
                    <span class="text-[13px] text-neutral-700">Ghi nhớ đăng nhập</span>
                </label>

                <button type="submit" class="xp-btn-primary w-full" :disabled="busy">
                    {{ busy ? 'Đang xử lý…' : (mode === 'login' ? 'Đăng nhập' : 'Đăng ký') }}
                </button>

                <button
                    type="button"
                    class="w-full text-center text-[13px] text-neutral-500 hover:text-neutral-900"
                    @click="mode = mode === 'login' ? 'register' : 'login'"
                >
                    {{ mode === 'login' ? 'Chưa có tài khoản? Đăng ký' : 'Đã có tài khoản? Đăng nhập' }}
                </button>
            </form>
        </div>
    </div>
</template>
