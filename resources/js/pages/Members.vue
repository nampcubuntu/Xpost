<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../lib/api';
import { useAuthStore } from '../stores/auth';

/*
 * Who is on the team and what they may do.
 *
 * Roles were only changeable by editing team_user.role in the database, which
 * in practice meant they were never changed -- everyone stayed whatever they
 * were created as, and the levels the policies enforce went unused.
 */
const auth = useAuthStore();

const members = ref([]);
const roles = ref([]);
const teams = ref([]);
const spansTeams = ref(false);
const loading = ref(true);
const busy = ref(null);
const flash = ref(null);

const newEmail = ref('');
const newRole = ref('editor');
const newTeam = ref(null);

const canManage = computed(() => auth.can('manage_accounts'));

/** Roles this caller may hand out — never above their own. */
const assignableRoles = computed(() => roles.value.filter((role) => role.assignable));

async function load() {
    loading.value = true;

    try {
        const { data } = await api.get('members');
        members.value = data.data;
        roles.value = data.roles;
        teams.value = data.teams ?? [];
        // Only the operator's view spans teams, so only it needs the column.
        spansTeams.value = data.spans_teams === true;
        newTeam.value ??= teams.value[0]?.id ?? null;
    } finally {
        loading.value = false;
    }
}

async function act(id, request, message) {
    busy.value = id;

    try {
        await request();
        flash.value = { type: 'ok', message };
        await load();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không thực hiện được.' };
    } finally {
        busy.value = null;
    }
}

function setRole(member, role) {
    if (role === member.role) {
        return;
    }

    // The row carries its own team, because the operator's list spans several
    // and "the team I am signed into" would name the wrong one.
    act(
        member.id,
        () => api.put(`members/${member.id}`, { role, team_id: member.team_id }),
        `${member.name}: đã đổi vai trò.`,
    );
}

function addMember() {
    const email = newEmail.value.trim();

    if (!email) {
        return;
    }

    act('new', () => api.post('members', { email, role: newRole.value, team_id: newTeam.value }), `Đã thêm ${email}.`)
        .then(() => { newEmail.value = ''; });
}

function removeMember(member) {
    // Confirmed because it takes their access to every campaign at once.
    if (!window.confirm(`Xoá ${member.name} khỏi team?\n\nHọ sẽ mất quyền truy cập toàn bộ tài khoản và bài đăng.`)) {
        return;
    }

    act(member.id, () => api.delete(`members/${member.id}`, { data: { team_id: member.team_id } }), `Đã xoá ${member.name}.`);
}

onMounted(load);
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <div class="mb-5">
            <h1 class="text-xl font-semibold">Thành viên</h1>
            <p class="mt-1 text-[13px] text-neutral-500">
                <template v-if="spansTeams">Mọi thành viên trên toàn hệ thống và họ được làm gì.</template>
                <template v-else>Ai ở trong team {{ auth.currentTeam?.name }} và họ được làm gì.</template>
            </p>
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
            Adding a member hands them the whole account, so the form only
            appears for someone who may actually do it.
        -->
        <form v-if="canManage" class="xp-card mb-4 grid gap-3 p-4 sm:grid-cols-[1fr_200px_auto]" @submit.prevent="addMember">
            <label class="space-y-1">
                <span class="xp-label">Email</span>
                <input v-model="newEmail" type="email" class="xp-field" placeholder="nguoi@example.com">
            </label>

            <!-- A tenant only ever adds to their own team; the operator picks. -->
            <label v-if="spansTeams" class="space-y-1 sm:col-span-3 sm:max-w-[260px]">
                <span class="xp-label">Team</span>
                <select v-model="newTeam" class="xp-field">
                    <option v-for="team in teams" :key="team.id" :value="team.id">{{ team.name }}</option>
                </select>
            </label>

            <label class="space-y-1">
                <span class="xp-label">Vai trò</span>
                <select v-model="newRole" class="xp-field">
                    <option v-for="role in assignableRoles" :key="role.value" :value="role.value">
                        {{ role.label }}
                    </option>
                </select>
            </label>
            <div class="flex items-end">
                <button class="xp-btn-primary w-full sm:w-auto" :disabled="busy === 'new' || !newEmail.trim()">
                    Thêm
                </button>
            </div>

            <p class="text-[11.5px] text-neutral-500 sm:col-span-3">
                Người đó cần đã có tài khoản Xpost — chưa có lời mời qua email.
            </p>
        </form>

        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-left text-[13px]">
                    <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th class="px-4 py-2.5 font-medium">Người dùng</th>
                            <th v-if="spansTeams" class="px-4 py-2.5 font-medium">Team</th>
                            <th class="px-4 py-2.5 font-medium">Vai trò</th>
                            <th class="w-20 px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100">
                        <tr v-if="loading"><td :colspan="spansTeams ? 4 : 3" class="px-4 py-8 text-center text-neutral-500">Đang tải…</td></tr>

                        <tr v-for="member in members" :key="`${member.team_id}-${member.id}`" class="hover:bg-neutral-50">
                            <td class="px-4 py-2.5">
                                <p class="font-medium">
                                    {{ member.name }}
                                    <span v-if="member.is_you" class="ml-1 text-[11px] font-normal text-neutral-400">— bạn</span>
                                </p>
                                <p class="text-xs text-neutral-500">{{ member.email }}</p>
                            </td>

                            <!--
                                Only the operator sees more than one team, and
                                a row without its team would be ambiguous the
                                moment the same person is on two of them.
                            -->
                            <td v-if="spansTeams" class="px-4 py-2.5 text-neutral-600">{{ member.team_name }}</td>

                            <td class="px-4 py-2.5">
                                <!--
                                    Your own row is fixed on purpose: changing
                                    your own level is how someone either locks
                                    themselves out or promotes themselves.
                                -->
                                <select
                                    v-if="canManage && !member.is_you"
                                    class="xp-field max-w-[180px] py-1 text-[12.5px]"
                                    :value="member.role"
                                    :disabled="busy === member.id"
                                    @change="setRole(member, $event.target.value)"
                                >
                                    <option v-for="role in assignableRoles" :key="role.value" :value="role.value">
                                        {{ role.label }}
                                    </option>
                                    <!-- A role above the caller's stays visible, and unselectable. -->
                                    <option v-if="!assignableRoles.some((r) => r.value === member.role)" :value="member.role" disabled>
                                        {{ member.role_label }}
                                    </option>
                                </select>
                                <span v-else class="text-neutral-700">{{ member.role_label }}</span>
                            </td>

                            <td class="px-4 py-2.5 text-right">
                                <button
                                    v-if="canManage && !member.is_you"
                                    class="text-red-600 hover:underline disabled:opacity-50"
                                    :disabled="busy === member.id"
                                    @click="removeMember(member)"
                                >Xoá</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
