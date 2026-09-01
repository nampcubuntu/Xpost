<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import BrandLogo from '../components/BrandLogo.vue';
import NavIcon from '../components/NavIcon.vue';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const teamMenuOpen = ref(false);
const drawerOpen = ref(false);

/** Composer and its edit route are the same destination as far as the sidebar cares. */
const isActive = (name) => route.name === name || route.name?.startsWith(`${name}.`);

// Navigating from inside the drawer should close it, or the new page opens
// underneath a still-open overlay.
watch(() => route.fullPath, () => {
    drawerOpen.value = false;
});

/*
 * Grouped the way X Ads Manager groups its sidebar, but every entry points at a
 * page that actually exists. Adding a "Shopping Manager" link that 404s would
 * look right and be useless.
 *
 * `ability` names what a row needs, matching TeamRole::abilities(). A member
 * who cannot compose should not be shown a composer that refuses them: the
 * server still decides, but a link that always 403s reads as a broken app
 * rather than as a boundary.
 */
const nav = [
    {
        heading: 'Chiến dịch',
        items: [
            { name: 'analytics', label: 'Analytics', icon: 'analytics' },
            { name: 'posts', label: 'Bài đăng', icon: 'posts' },
        ],
    },
    {
        heading: 'Nội dung',
        items: [
            { name: 'composer', label: 'Composer', icon: 'composer', ability: 'compose' },
            { name: 'schedules', label: 'Lịch lặp lại', icon: 'schedules', ability: 'compose' },
        ],
    },
    {
        heading: 'Công cụ',
        items: [
            { name: 'accounts', label: 'Tài khoản X', icon: 'accounts', ability: 'manage_accounts' },
            { name: 'visibility', label: 'Hiển thị', icon: 'eye', ability: 'manage_accounts' },
            { name: 'apps', label: 'X Apps', icon: 'apps', ability: 'manage_infrastructure' },
            { name: 'proxies', label: 'Proxy', icon: 'globe', ability: 'manage_infrastructure' },
            { name: 'links', label: 'Short link', icon: 'link', ability: 'manage_infrastructure' },
        ],
    },
    {
        heading: 'Team',
        items: [
            // No ability: seeing who else is on the team explains whose
            // campaigns these are, and the page hides what a member cannot do.
            { name: 'members', label: 'Thành viên', icon: 'members' },
        ],
    },
];

/** The nav as this role sees it, with empty groups dropped entirely. */
const visibleNav = computed(() => nav
    .map((group) => ({
        ...group,
        items: group.items.filter((item) => !item.ability || auth.can(item.ability)),
    }))
    .filter((group) => group.items.length > 0));

async function logout() {
    await auth.logout();

    // Back to the public homepage rather than the login form -- signing out is
    // not the same as being asked to sign in.
    router.push({ name: 'landing' });
}
</script>

<template>
    <div class="flex h-full bg-neutral-100 lg:p-2">
        <!--
            Sidebar. 239px measured off the reference in DevTools.

            Below lg it becomes an overlay drawer, which is what X does on an
            iPad: at 768px a fixed rail plus the composer column leaves the card
            about 120px wide, which is not a layout so much as a crash.
        -->
        <div
            v-if="drawerOpen"
            class="fixed inset-0 z-40 bg-black/30 lg:hidden"
            @click="drawerOpen = false"
        ></div>

        <aside
            class="flex w-[239px] shrink-0 flex-col border-neutral-200 bg-white transition-transform lg:relative lg:z-auto lg:translate-x-0 lg:rounded-xl lg:border"
            :class="[
                'fixed inset-y-0 left-0 z-50 border-r',
                drawerOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            ]"
        >
            <!-- Brand -->
            <div class="flex items-start justify-between px-[14px] pt-3">
                <RouterLink :to="{ name: 'landing' }" class="flex items-start gap-2">
                    <BrandLogo variant="mark" :size="17" class="mt-px" />
                    <span class="text-[13px] font-semibold leading-[1.15]">Ads<br>Manager</span>
                </RouterLink>

                <button
                    type="button"
                    class="rounded p-1 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700"
                    title="Chế độ tối — chưa khả dụng"
                >
                    <NavIcon name="moon" :size="14" />
                </button>
            </div>

            <!-- Team switcher -->
            <div class="relative mx-2 mt-3">
                <button
                    type="button"
                    class="flex w-full items-center gap-2 rounded-md px-1.5 py-1.5 text-left transition hover:bg-neutral-100"
                    @click="teamMenuOpen = !teamMenuOpen"
                >
                    <span class="grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full bg-neutral-800 text-[10px] font-semibold text-white">
                        {{ (auth.currentTeam?.name ?? 'X').slice(0, 1).toUpperCase() }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13px] font-medium leading-tight">
                            {{ auth.currentTeam?.name ?? '—' }}
                        </span>
                        <span class="block truncate font-mono text-[10px] leading-tight text-neutral-400">
                            {{ auth.currentTeam?.slug ?? '' }}
                        </span>
                    </span>
                    <NavIcon name="selector" :size="13" class="text-neutral-400" />
                </button>

                <div
                    v-if="teamMenuOpen"
                    class="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-lg border border-neutral-200 bg-white py-1 shadow-lg"
                >
                    <button
                        v-for="team in auth.teams"
                        :key="team.id"
                        type="button"
                        class="block w-full px-3 py-1.5 text-left text-[12.5px] transition hover:bg-neutral-50"
                        :class="team.id === auth.user?.current_team_id ? 'font-medium' : ''"
                        @click="auth.switchTeam(team.id); teamMenuOpen = false"
                    >{{ team.name }}</button>
                </div>
            </div>

            <!-- Navigation -->
            <nav class="mt-1 flex-1 overflow-y-auto pb-4">
                <template v-for="group in visibleNav" :key="group.heading">
                    <p class="px-3.5 pb-0.5 pt-4 text-[11.5px] text-neutral-400">{{ group.heading }}</p>

                    <!--
                        Active state is a rule against the sidebar edge and a solid
                        icon, the way the reference marks it -- not a filled pill.
                        The rule alone was doing all the work: on a 1.3px stroke a
                        colour change is invisible until you look straight at it.

                        Bound explicitly rather than via RouterLink's `active-class`:
                        Tailwind 4 does not scan that attribute, so arbitrary variants
                        written there are silently never generated.
                    -->
                    <RouterLink
                        v-for="item in group.items"
                        :key="item.name"
                        :to="{ name: item.name }"
                        class="relative flex items-center gap-2.5 py-[3px] pl-3.5 pr-2 text-[13px] transition"
                        :class="isActive(item.name)
                            ? 'font-medium text-neutral-900'
                            : 'text-neutral-600 hover:text-neutral-900'"
                    >
                        <span
                            v-if="isActive(item.name)"
                            class="absolute left-0 top-1/2 h-[18px] w-[2.5px] -translate-y-1/2 rounded-r bg-neutral-900"
                        ></span>
                        <NavIcon
                            :name="item.icon"
                            :size="16"
                            :active="isActive(item.name)"
                            :class="isActive(item.name) ? 'text-neutral-900' : 'text-neutral-400'"
                        />
                        {{ item.label }}
                    </RouterLink>
                </template>
            </nav>

            <!-- Footer -->
            <div class="border-t border-neutral-200 py-2">
                <!--
                    Horizon shows every team's jobs at once, so it belongs to
                    whoever runs Xpost rather than to whoever owns a team. The
                    gate already refuses everyone else; this stops offering them
                    a link that would only refuse them.
                -->
                <a
                    v-if="auth.can('view_queue')"
                    href="/horizon"
                    target="_blank"
                    rel="noopener"
                    class="flex items-center gap-2.5 py-[3px] pl-3.5 pr-2 text-[13px] text-neutral-600 transition hover:text-neutral-900"
                >
                    <NavIcon name="queue" class="text-neutral-400" />
                    Hàng đợi
                </a>

                <button
                    type="button"
                    class="flex w-full items-center gap-2.5 py-[3px] pl-3.5 pr-2 text-[13px] text-neutral-600 transition hover:text-neutral-900"
                    @click="logout"
                >
                    <NavIcon name="logout" class="text-neutral-400" />
                    Đăng xuất
                </button>

                <div class="mx-2 mt-1 flex items-center gap-2 rounded-md px-1.5 py-1.5">
                    <span class="grid h-[22px] w-[22px] shrink-0 place-items-center rounded-full bg-neutral-200 text-[10px] font-semibold text-neutral-700">
                        {{ (auth.user?.name ?? '?').slice(0, 1).toUpperCase() }}
                    </span>
                    <span class="min-w-0 flex-1 truncate text-[13px] text-neutral-700">{{ auth.user?.name }}</span>
                    <NavIcon name="selector" :size="13" class="text-neutral-400" />
                </div>
            </div>
        </aside>

        <!-- ─── Main ────────────────────────────────────────────────────── -->
        <main class="flex min-w-0 flex-1 flex-col overflow-y-auto bg-[#f7f9f9] lg:ml-2 lg:rounded-xl">
            <!-- Mobile bar: 56px, matching the reference's collapsed header -->
            <div class="flex h-14 shrink-0 items-center gap-3 border-b border-neutral-200 bg-white px-4 lg:hidden">
                <button
                    type="button"
                    class="grid h-8 w-8 place-items-center rounded-md text-neutral-600 transition hover:bg-neutral-100"
                    aria-label="Mở menu"
                    @click="drawerOpen = true"
                >
                    <svg width="17" height="17" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round">
                        <path d="M2 4h12M2 8h12M2 12h12" />
                    </svg>
                </button>

                <p class="min-w-0 truncate text-[13px]">
                    <span class="font-semibold">Xpost</span>
                    <span class="mx-1.5 text-neutral-300">/</span>
                    <span class="text-neutral-500">{{ auth.currentTeam?.name }}</span>
                </p>
            </div>

            <div class="min-h-0 flex-1">
                <slot />
            </div>
        </main>
    </div>
</template>
