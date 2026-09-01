<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import api from '../lib/api';
import { useAuthStore } from '../stores/auth';
import { listen, socketState } from '../lib/echo';
import NavIcon from '../components/NavIcon.vue';
import ErrorPopover from '../components/ErrorPopover.vue';
import FilterDropdown from '../components/FilterDropdown.vue';
import PhonePreview from '../components/PhonePreview.vue';

const auth = useAuthStore();
const router = useRouter();

const posts = ref([]);
const meta = ref(null);
const loading = ref(true);
const type = ref('');
const search = ref('');
const page = ref(1);
const copied = ref(null);

const statuses = [
    { value: '', label: 'Tất cả trạng thái' },
    { value: 'draft', label: 'Nháp' },
    { value: 'scheduled', label: 'Đã lên lịch' },
    { value: 'publishing', label: 'Đang đăng' },
    { value: 'published', label: 'Đã đăng' },
    { value: 'partially_failed', label: 'Đăng một phần' },
    { value: 'failed', label: 'Thất bại' },
];

/*
 * One dropdown for "which of my posts", the way Ads Manager asks it.
 *
 * Promoted and organic are a column, scheduled and drafts are a status. They
 * belong together because that is the single question being asked -- splitting
 * it across two controls let someone pick a combination with no rows in it.
 */
const types = [
    { value: '', label: 'Tất cả' },
    { value: 'promoted', label: 'Promoted' },
    { value: 'organic', label: 'Organic' },
    { value: 'scheduled', label: 'Đã lên lịch' },
    { value: 'drafts', label: 'Nháp' },
];

/*
 * The checkbox groups behind "Bộ lọc".
 *
 * Every box starts ticked, because a filter panel that hides rows before it has
 * been opened is a list that lies. Nothing ticked and everything ticked ask the
 * same question, and the server treats them the same.
 *
 * Ads Manager has a "Replies & mentions" group here. Xpost has no such thing --
 * every post it creates is an original -- and a control that can never change
 * the result is worse than a missing one.
 */
const destinations = [
    { value: 'app', label: 'App' },
    { value: 'website', label: 'Website' },
    { value: 'none', label: 'Không có link' },
];

const mediaKinds = [
    { value: 'photo', label: 'Ảnh' },
    { value: 'video', label: 'Video' },
    { value: 'none', label: 'Không có media' },
];

const outcomes = [
    { value: 'published', label: 'Đã đăng' },
    { value: 'publishing', label: 'Đang đăng' },
    { value: 'partially_failed', label: 'Đăng một phần' },
    { value: 'failed', label: 'Thất bại' },
    { value: 'draft', label: 'Nháp' },
    { value: 'scheduled', label: 'Đã lên lịch' },
];

const picked = reactive({
    destinations: destinations.map((row) => row.value),
    media: mediaKinds.map((row) => row.value),
    statuses: outcomes.map((row) => row.value),
});

function toggleIn(group, value) {
    picked[group] = picked[group].includes(value)
        ? picked[group].filter((v) => v !== value)
        : [...picked[group], value];

    page.value = 1;
    load();
}

/** Ads Manager counts the ticked boxes, not the narrowed ones. */
const filterCount = computed(() =>
    picked.destinations.length + picked.media.length + picked.statuses.length);

/* ── Account ─────────────────────────────────────────────────────────────── */

/*
 * Searched on the server rather than listed.
 *
 * Ads Manager can put every account in this dropdown because it has one. Here
 * there are a thousand, and a dropdown that downloads them all is the mistake
 * the proxy page already made once.
 */
const accountId = ref('');
const accountLabel = ref('Tất cả');
const accountSearch = ref('');
const accountResults = ref([]);

let accountTimer;

async function findAccounts() {
    clearTimeout(accountTimer);

    accountTimer = setTimeout(async () => {
        const { data } = await api.get('accounts', {
            params: { search: accountSearch.value || undefined, per_page: 20 },
        });

        accountResults.value = data.data;
    }, 250);
}

function chooseAccount(account) {
    accountId.value = account?.id ?? '';
    accountLabel.value = account ? `@${account.username}` : 'Tất cả';
    page.value = 1;
    load();
}

watch(accountSearch, findAccounts);

const badgeClass = {
    draft: 'bg-neutral-100 text-neutral-700',
    scheduled: 'bg-blue-50 text-blue-700',
    publishing: 'bg-amber-50 text-amber-700',
    published: 'bg-green-50 text-green-700',
    partially_failed: 'bg-orange-50 text-orange-700',
    failed: 'bg-red-50 text-red-700',
    cancelled: 'bg-neutral-100 text-neutral-500',
};

/**
 * The scheduler is a separate process from the queue workers, and when it
 * stops, everything else stays up and healthy while no post is ever
 * dispatched. The only symptom is scheduled posts sitting at 0/N, which says
 * nothing about the cause -- so say it here, where they are looked at.
 */
const schedulerLastRunAt = ref(null);
const schedulerDown = computed(() => schedulerLastRunAt.value === null);

const menuFor = ref(null);
const busy = ref(null);
const flash = ref(null);

/*
 * The post being looked at in the phone, or null.
 *
 * Held as the row itself rather than an id: the list already carries the body,
 * the media and the destination, so the preview costs no request. Fetching one
 * would put a round trip between the click and the answer, on a page whose
 * whole job is scanning.
 */
const previewFor = ref(null);

/*
 * Rows ticked for a bulk action.
 *
 * Clearing out a test run meant the same confirm dialog forty times, which is
 * how people learn to click through confirms without reading them -- and this
 * particular one carries the warning that published tweets stay on X.
 */
const selected = ref(new Set());

const allSelected = computed(() => posts.value.length > 0
    && posts.value.every((post) => selected.value.has(post.id)));

function toggle(id) {
    selected.value.has(id) ? selected.value.delete(id) : selected.value.add(id);
    selected.value = new Set(selected.value);
}

function toggleAll() {
    // Read once, before the loop: mutating a Set is reactive, so testing the
    // computed inside would flip halfway and the loop would undo its own work.
    const clearing = allSelected.value;

    posts.value.forEach((post) => {
        clearing ? selected.value.delete(post.id) : selected.value.add(post.id);
    });

    selected.value = new Set(selected.value);
}

/** How many of the ticked rows already have tweets standing on X. */
const selectedPublished = computed(() => posts.value
    .filter((post) => selected.value.has(post.id))
    .reduce((total, post) => total + (post.progress?.published ?? 0), 0));

async function destroySelected() {
    const published = selectedPublished.value;

    /*
     * The same warning as the single delete, because it is the same surprise:
     * the row goes and the tweets do not. Said once for the whole selection
     * rather than once per row -- a dialog nobody reads protects nobody.
     */
    const warning = published
        ? `\n\n${published} bài đã đăng lên X sẽ KHÔNG bị xoá khỏi X.`
        : '';

    if (! window.confirm(`Xoá ${selected.value.size} bài khỏi Xpost?${warning}`)) {
        return;
    }

    busy.value = 'bulk';

    try {
        const { data } = await api.delete('posts', { data: { ids: [...selected.value] } });

        flash.value = {
            type: 'ok',
            message: data.published
                ? `Đã xoá ${data.deleted} bài khỏi Xpost. ${data.published} bài vẫn còn trên X.`
                : `Đã xoá ${data.deleted} bài.`,
        };

        selected.value = new Set();
        await load();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không xoá được.' };
    } finally {
        busy.value = null;
    }
}

/*
 * What this team has spent at X today, against its ceiling.
 *
 * Shown here rather than on a settings page, because this is where the money is
 * spent and a settings page is somewhere nobody opens until after the invoice.
 * A null budget means no ceiling, which is the default.
 */
const budget = ref({ spent_usd: 0, budget_usd: null, remaining_usd: null });
const editingBudget = ref(false);
const budgetInput = ref('');

const budgetPercent = computed(() => (budget.value.budget_usd
    ? Math.min(100, Math.round((budget.value.spent_usd / budget.value.budget_usd) * 100))
    : 0));

function startEditBudget() {
    budgetInput.value = budget.value.budget_usd ?? '';
    editingBudget.value = true;
}

async function saveBudget() {
    busy.value = 'budget';

    try {
        const value = String(budgetInput.value).trim();
        const { data } = await api.put('publishing/budget', {
            // Empty clears it: no ceiling has to stay reachable, or the first
            // number typed can never be undone.
            daily_budget_usd: value === '' ? null : Number(value),
        });

        budget.value = data.data;
        editingBudget.value = false;
        flash.value = {
            type: 'ok',
            message: data.data.budget_usd === null
                ? 'Đã bỏ trần chi tiêu.'
                : `Trần chi tiêu: $${data.data.budget_usd}/ngày.`,
        };
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không lưu được.' };
    } finally {
        busy.value = null;
    }
}

/**
 * X refuses every account at once when the balance is spent, so publishing
 * stops as a whole rather than ten thousand posts failing one at a time.
 * Nothing clears this on its own -- Xpost cannot read the balance, and X
 * exposes no endpoint that would.
 */
const halt = ref(null);

async function resumePublishing() {
    busy.value = 'halt';

    try {
        await api.post('publishing/resume');
        flash.value = { type: 'ok', message: 'Đã tiếp tục đăng. Bài đang chờ sẽ chạy ở lượt kế tiếp.' };
        await load();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không tiếp tục được.' };
    } finally {
        busy.value = null;
    }
}

/** Only work that has not gone out yet can be called back. */
const canCancel = (post) => ['scheduled', 'publishing', 'draft'].includes(post.status);

/** Matches PostStatus::isEditable() -- the API refuses anything further along. */
const canEdit = (post) => ['draft', 'scheduled'].includes(post.status);

/** Close the open row menu on any click that is not inside it. */
function closeMenus() {
    menuFor.value = null;
}

let stopListening = () => {};

onMounted(() => {
    document.addEventListener('click', closeMenus);
    document.addEventListener('visibilitychange', onVisibility);

    /*
     * The team channel, not one per post. An operator watching this list is
     * watching every row on it, and a subscription per row would mean a
     * hundred of them and a resubscribe on every filter change.
     */
    const teamId = auth.user?.current_team_id;

    if (teamId) {
        stopListening = listen(`team.${teamId}`, '.post.progress', receiveProgress);
    }
});

onUnmounted(() => {
    document.removeEventListener('click', closeMenus);
    document.removeEventListener('visibilitychange', onVisibility);
    // Or the page keeps polling for a list nobody is looking at any more.
    clearTimeout(refreshTimer);
    // And keeps patching rows it no longer renders.
    stopListening();
    // A frame booked for a page that is being torn down.
    cancelAnimationFrame(frame);
});

async function act(post, request, describe) {
    busy.value = post.id;

    try {
        flash.value = { type: 'ok', message: describe(await request()) };
        await load();
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không thực hiện được.' };
    } finally {
        busy.value = null;
        menuFor.value = null;
    }
}

function retryFailed(post) {
    // The targets go back to pending; the scheduler picks them up on its next
    // pass rather than anything being published from here.
    act(post, () => api.post(`posts/${post.id}/retry-failed`),
        ({ data }) => `Đã đưa ${data.requeued} bài lỗi vào hàng đợi.`);
}

function cancelPost(post) {
    act(post, () => api.post(`posts/${post.id}/cancel`),
        ({ data }) => `Đã huỷ ${data.cancelled} bài chưa gửi.`);
}

/**
 * Copy this post and open the copy.
 *
 * Landing in the composer rather than back in the list: nobody duplicates a
 * post to have two identical ones, they duplicate it to change something, and
 * the copy is a draft until they do.
 */
async function duplicatePost(post) {
    menuFor.value = null;
    busy.value = post.id;

    try {
        const { data } = await api.post(`posts/${post.id}/duplicate`);
        router.push({ name: 'composer.edit', params: { id: String(data.data.id) } });
    } catch (error) {
        flash.value = { type: 'error', message: error.response?.data?.message ?? 'Không nhân bản được.' };
    } finally {
        busy.value = null;
    }
}

function destroyPost(post) {
    /*
     * Confirmed because it cannot be undone here and, for anything already
     * published, does not undo what is on X either -- the row goes, the tweets
     * stay. The menu says so; this is the last chance to read it.
     */
    const published = post.progress?.published ?? 0;

    const warning = published
        ? `\n\n${published} bài đã đăng lên X sẽ KHÔNG bị xoá khỏi X.`
        : '';

    if (! window.confirm(`Xoá "${post.name || 'bài này'}" khỏi Xpost?${warning}`)) {
        return;
    }

    act(post, () => api.delete(`posts/${post.id}`), () => 'Đã xoá bài.');
}

/**
 * @param {boolean} silent  a background refresh, which must not blank the table
 */
async function load(silent = false) {
    loading.value = ! silent;

    try {
        const { data } = await api.get('posts', {
            params: {
                type: type.value || undefined,
                search: search.value || undefined,
                account_id: accountId.value || undefined,
                destinations: picked.destinations,
                media: picked.media,
                statuses: picked.statuses,
                page: page.value,
            },
        });

        posts.value = data.data;
        meta.value = data.meta;

        // Drop ticks for rows that are gone, or the count in the action bar
        // promises rows the next action cannot find.
        const live = new Set(posts.value.map((post) => post.id));
        selected.value = new Set([...selected.value].filter((id) => live.has(id)));
        // Absent means the scheduler has not run in the last few minutes.
        schedulerLastRunAt.value = data.scheduler_last_run_at ?? null;
        halt.value = data.halt ?? null;
        budget.value = data.budget ?? budget.value;
    } finally {
        loading.value = false;
        // Chained rather than on a fixed interval: the next check is scheduled
        // from the answer to the last one, so a slow reply never stacks up.
        scheduleRefresh();
    }
}

/*
 * Live status without reloading, and without asking every five seconds.
 *
 * Not webhooks: nothing here is waiting on X. The counter in this column is
 * Xpost's own fan-out -- it moves when a queue worker finishes a target, and X
 * is never told and never tells. X's webhooks carry inbound events on an
 * account, which is a different subject entirely.
 *
 * Reverb carries it now, so the five second poll is gone. It was the fallback
 * for not having a socket, and keeping it beside one is asking a question whose
 * answer has already been pushed -- twelve requests a minute per open tab, per
 * operator, for a page that is told the moment anything moves.
 *
 * What is left is one request a minute while a run is going, and only while the
 * socket is actually connected: a websocket that has quietly stopped looks
 * exactly like one with nothing to say, and the first sign either way would be
 * a counter frozen at 40%.
 */
const SOCKET_REFRESH_MS = 60000;

let refreshTimer;

/*
 * When the socket last said something.
 *
 * Shown beside the pip because "có cập nhật" and "cập nhật bằng gì" are
 * different questions, and the pip only ever answered the first. A time that
 * moves the instant a worker finishes a target is the socket; nothing else on
 * this page can produce one.
 *
 * A clock time rather than "x giây trước": the relative form needs a timer
 * ticking once a second to stay true, and a label is not worth reintroducing
 * the thing this page just removed.
 */
const lastPushAt = ref(null);

const lastPushLabel = computed(() => (lastPushAt.value
    ? lastPushAt.value.toLocaleTimeString('vi-VN', { hour12: false })
    : null));

/** Posts with work still to do. Nothing else can change on its own. */
const inFlight = computed(() => posts.value.some((post) => ['scheduled', 'publishing'].includes(post.status)
    || (post.progress?.pending ?? 0) > 0));

/*
 * The socket is not connected and there is a run that should be moving.
 *
 * Said out loud rather than quietly polled around. With the fallback gone this
 * is the state where the column stops being true, and an operator watching a
 * bar sit at 40% deserves to know the difference between a stalled queue and a
 * page that is no longer being told anything.
 */
const liveBroken = computed(() => inFlight.value && socketState.value !== 'connected');

function scheduleRefresh() {
    clearTimeout(refreshTimer);

    /*
     * A hidden tab is not being read, and every request costs a full trip to
     * the edge. It catches up on focus instead.
     *
     * Nothing is scheduled while the socket is down either: that is what the
     * banner is for. A page that keeps quietly asking is a page that hides the
     * outage it is working around.
     */
    if (! inFlight.value || document.hidden || socketState.value !== 'connected') {
        return;
    }

    refreshTimer = setTimeout(() => load(true), SOCKET_REFRESH_MS);
}

/**
 * Patch one row from a broadcast.
 *
 * Patched rather than reloaded: the message carries exactly the fields that
 * changed, and re-fetching the page on every tick would undo the point of not
 * polling. A post that is not on screen -- filtered out, or on another page --
 * is ignored, which is also why the slow poll stays: it is what brings in a row
 * that has since become relevant.
 */
function applyProgress(payload) {
    const row = posts.value.find((post) => post.id === payload.id);

    if (! row) {
        return;
    }

    row.status = payload.status;
    row.progress = { ...row.progress, ...payload.progress };

    // The status badge reads its label from the row, and the broadcast carries
    // the value rather than the translated string.
    row.status_label = statuses.find((entry) => entry.value === payload.status)?.label ?? row.status_label;
}

/*
 * Messages collected and applied a frame at a time.
 *
 * The throttle on the server is per post, so a hundred posts going out at nine
 * o'clock is a hundred messages a second on the team channel however few of
 * them this page is showing. Each one arrives in its own task, so applying it
 * where it lands is a hundred separate reactive writes and a hundred render
 * passes a second to move a progress bar that repaints sixty times at best.
 *
 * Keyed by post id, so ten ticks for one post between frames collapse to the
 * last -- which is the only one that was ever going to be true. A payload for
 * a row this page is not showing is dropped here rather than after the frame,
 * because at a hundred a second most of them are that.
 */
const queued = new Map();
let frame = null;

function flushProgress() {
    frame = null;

    queued.forEach(applyProgress);
    queued.clear();

    // Once per frame rather than once per message: it clears and re-arms a
    // timer and re-reads a computed over the whole page of rows.
    scheduleRefresh();
}

function receiveProgress(payload) {
    // Stamped from the frame, not from a request. This is what tells an
    // operator which mechanism is feeding the column -- the question the green
    // pip on its own could not answer.
    lastPushAt.value = new Date();
    queued.set(payload.id, payload);

    // requestAnimationFrame and not a timer: a hidden tab stops calling it, so
    // a run that goes out while the operator is in another tab stops doing
    // render work nobody can see. What arrives meanwhile coalesces into the one
    // frame that runs when they come back.
    frame ??= requestAnimationFrame(flushProgress);
}

/*
 * Coming back to the tab is the moment the answer is most stale and most
 * wanted, so it refreshes then rather than waiting out the interval.
 */
function onVisibility() {
    if (! document.hidden && inFlight.value) {
        load(true);
    }
}

onMounted(load);

let timer;
watch([status, type, search], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(page, load);

async function copyId(id) {
    try {
        await navigator.clipboard.writeText(id);
        copied.value = id;
        setTimeout(() => (copied.value = null), 1500);
    } catch {
        // Clipboard is blocked outside a secure context; the id is on screen anyway.
    }
}

const shortDate = (value) => (value
    ? new Date(value).toLocaleDateString('vi-VN', { day: 'numeric', month: 'short' })
    : '—');

const fullDate = (value) => (value
    ? new Date(value).toLocaleDateString('vi-VN', { day: 'numeric', month: 'short', year: 'numeric' })
    : null);

const time = (value) => (value
    ? new Date(value).toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' })
    : null);

/** The date a row should show: when it went out, else when it is due. */
const rowDate = (post) => post.published_at ?? post.sample?.published_at ?? post.scheduled_at;

const isVideo = (media) => media.mime?.startsWith('video/');

/**
 * Thumbnails are a fixed box, deliberately not the media's own shape.
 *
 * They used to take each file's aspect ratio, clamped -- which still let a 9:16
 * clip stand four times taller than a 16:9 one and drag its whole row with it.
 * A list is scanned down the left edge, and rows that change height every time
 * the creative changes orientation are much harder to read than a slightly
 * cropped preview. Clamping only softened that; it did not fix it.
 */

/**
 * What the corner of the thumbnail says: duration, shape, and how many more
 * files there are.
 *
 * The shape used to be visible in the box itself. Now that every box is the
 * same, a vertical clip cropped to 16:9 looks like a landscape one -- so the
 * orientation has to be written down, or the preview quietly misleads.
 */
function mediaBadge(post) {
    const media = post.media?.[0];

    if (! media) {
        return null;
    }

    const parts = [];

    if (isVideo(media) && media.duration_ms) {
        parts.push(duration(media.duration_ms));
    }

    if (media.width && media.height && media.height > media.width) {
        parts.push('dọc');
    }

    if (post.media.length > 1) {
        parts.push(`+${post.media.length - 1}`);
    }

    return parts.length ? parts.join(' · ') : null;
}

const duration = (ms) => {
    const total = Math.round(ms / 1000);

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
};

const destinationHost = (url) => {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
};
</script>

<template>
    <div class="px-5 py-5 lg:px-8 lg:py-6">
        <!-- Header -->
        <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Bài đăng</h1>
                <p class="mt-1 flex items-center gap-2 text-[13px] text-neutral-500">
                    Soạn, lên lịch và theo dõi các bài đứng sau creative của bạn.

                    <!--
                        Only while something can still change. Saying "live" on
                        a settled list would be a claim that quietly stops being
                        true, and nobody would know which state they were in.
                    -->
                    <span
                        v-if="inFlight && !liveBroken"
                        class="inline-flex items-center gap-1.5 text-[12px] text-green-700"
                        :title="`Reverb (${socketState}) — trạng thái được đẩy tới ngay khi đổi. Trang không hỏi lại theo nhịp.`"
                    >
                        <span class="relative flex h-1.5 w-1.5">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-green-500 opacity-75"></span>
                            <span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-green-600"></span>
                        </span>
                        Reverb
                        <span v-if="lastPushLabel" class="text-green-700/70">· tin cuối {{ lastPushLabel }}</span>
                    </span>
                </p>
            </div>

            <div class="flex items-center gap-3">
                <!--
                    Today's spend, next to the button that creates more of it.
                    A ceiling nobody chose would be worse than none, so the
                    default reads as an amount rather than a limit.
                -->
                <div class="text-right text-[13px]">
                    <p class="text-neutral-500">
                        Hôm nay
                        <span class="font-medium text-neutral-900">${{ budget.spent_usd.toFixed(2) }}</span>
                        <template v-if="budget.budget_usd !== null">
                            / ${{ budget.budget_usd.toFixed(2) }}
                        </template>
                    </p>

                    <div v-if="budget.budget_usd !== null" class="mt-1 h-1 w-32 overflow-hidden rounded-full bg-neutral-200">
                        <div
                            class="h-full rounded-full"
                            :class="budgetPercent >= 100 ? 'bg-red-600' : budgetPercent >= 80 ? 'bg-amber-500' : 'bg-neutral-900'"
                            :style="{ width: `${budgetPercent}%` }"
                        ></div>
                    </div>

                    <form v-if="editingBudget" class="mt-1 flex items-center gap-1" @submit.prevent="saveBudget">
                        <span class="text-neutral-500">$</span>
                        <input
                            v-model="budgetInput"
                            type="number"
                            step="0.01"
                            min="0"
                            class="xp-field w-24 py-1 text-right text-[12px]"
                            placeholder="không giới hạn"
                        >
                        <button class="text-[12px] text-blue-600 hover:underline" :disabled="busy === 'budget'">Lưu</button>
                        <button type="button" class="text-[12px] text-neutral-500 hover:underline" @click="editingBudget = false">Huỷ</button>
                    </form>
                    <!-- Spending is real money; only an admin moves the ceiling. -->
                    <button
                        v-else-if="auth.can('manage_budget')"
                        class="mt-0.5 text-[11px] hover:underline"
                        :class="budget.budget_usd === null ? 'font-medium text-amber-700' : 'text-blue-600'"
                        @click="startEditBudget"
                    >
                        {{ budget.budget_usd === null ? '⚠ Chưa đặt trần chi tiêu' : 'Sửa trần' }}
                    </button>

                    <!--
                        Said out loud rather than left as a quiet link.
                        No ceiling is the state where a scheduling mistake bills
                        four figures: a post with a link is $0.20, so one loop
                        across a thousand accounts is $200 before anyone looks
                        at the screen. It reads as a normal default until it is
                        priced.
                    -->
                    <p
                        v-if="budget.budget_usd === null && auth.can('manage_budget')"
                        class="mt-0.5 max-w-[240px] text-[10.5px] leading-snug text-neutral-500"
                    >
                        Không có trần thì một lần lên lịch nhầm chạy tới hết số dư ở X.
                    </p>
                </div>

                <RouterLink :to="{ name: 'composer' }" class="xp-btn-primary">
                    <NavIcon name="composer" :size="14" />
                    Soạn bài mới
                </RouterLink>
            </div>
        </div>

        <!--
            Without this the failure is invisible: posts stay at "Đã lên lịch
            0/N" and every other part of the stack reports healthy.
        -->
        <div
            v-if="flash"
            class="mb-4 flex items-start justify-between gap-3 rounded-lg border px-4 py-3 text-[13px]"
            :class="flash.type === 'error'
                ? 'border-red-200 bg-red-50 text-red-800'
                : 'border-green-200 bg-green-50 text-green-800'"
        >
            <span>{{ flash.message }}</span>
            <button class="text-current opacity-60 hover:opacity-100" @click="flash = null">✕</button>
        </div>

        <!--
            Louder than the scheduler warning, because nothing is going out at
            all and no amount of waiting fixes it.
        -->
        <div
            v-if="halt"
            class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-[13px] text-red-900"
        >
            <strong class="font-semibold">Đã dừng đăng — X từ chối vì hết số dư.</strong>
            Bài đang chờ vẫn giữ nguyên, không bài nào bị đánh hỏng. Nạp thêm credit ở
            developer.x.com rồi bấm Tiếp tục.
            <p class="mt-1 text-xs opacity-80">X trả lời: {{ halt.reason }}</p>
            <button
                class="mt-2 rounded-md border border-red-300 bg-white px-3 py-1.5 text-[13px] font-medium hover:bg-red-100 disabled:opacity-50"
                :disabled="busy === 'halt'"
                @click="resumePublishing"
            >
                Tôi đã nạp — tiếp tục đăng
            </button>
        </div>

        <!--
            The ceiling doing its job. Distinct from the out-of-credit halt
            above: that one is X refusing everyone, this one is a limit this
            team chose, and it clears by itself at midnight.
        -->
        <div
            v-if="budget.budget_usd !== null && budget.remaining_usd === 0"
            class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900"
        >
            <strong class="font-semibold">Đã hết trần chi tiêu hôm nay (${{ budget.budget_usd.toFixed(2) }}).</strong>
            Bài còn lại được hoãn, không bài nào bị đánh hỏng — nửa đêm sẽ tự chạy tiếp.
            Cần đăng ngay thì nâng trần ở góc trên.
        </div>

        <!--
            The socket is the only thing telling this column anything now.

            With the five second poll gone there is no quiet fallback left, so a
            socket that cannot connect has to be visible: an operator watching a
            bar sit at 40% would otherwise have no way to tell a stalled queue
            from a page nobody is talking to. Only while a run is going, because
            a settled list has nothing to be late about.
        -->
        <div
            v-if="liveBroken"
            class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900"
        >
            <strong class="font-semibold">Cập nhật trực tiếp đang không hoạt động.</strong>
            Trạng thái bên dưới là của lần tải gần nhất và sẽ không tự đổi nữa.
            <span class="mt-1 block">
                Reverb chưa kết nối được ({{ socketState }}). Kiểm tra
                <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">php artisan reverb:start</code>,
                và nếu vào qua tên miền thì tunnel phải chuyển tiếp cả đường
                <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">/app/</code> sang cổng 8080.
            </span>
            <button class="mt-2 rounded border border-amber-400 px-2 py-1 text-[12.5px] transition hover:bg-amber-100" @click="load(true)">
                Tải lại danh sách
            </button>
        </div>
        <div
            v-if="schedulerDown"
            class="mb-4 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-[13px] text-amber-900"
        >
            <strong class="font-semibold">Scheduler không chạy.</strong>
            Bài đã tới giờ sẽ nằm ở “Đã lên lịch” và không bao giờ đăng. Horizon chạy
            job trong hàng đợi, nhưng lệnh đẩy bài vào hàng đợi nằm ở scheduler —
            hai tiến trình khác nhau.
            <span class="mt-1 block">
                Chạy <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">php artisan schedule:work</code>,
                hoặc mở lại bằng <code class="rounded bg-amber-100 px-1 py-0.5 font-mono">dev.bat</code>.
            </span>
        </div>

        <!-- Toolbar -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="relative min-w-0 flex-1">
                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400">
                    <NavIcon name="search" :size="15" />
                </span>
                <input v-model="search" type="search" class="xp-field pl-9" placeholder="Tìm kiếm">
            </div>

            <!--
                The panel that was a decorative span.

                It rendered a border, a word and a count, and did nothing at
                all -- the badge was counting the two selects beside it.
            -->
            <FilterDropdown value="Bộ lọc" :count="filterCount" :width="248">
                <template #icon>
                    <NavIcon name="filter" :size="14" class="text-neutral-500" />
                </template>

                <div class="max-h-[420px] overflow-y-auto">
                    <template v-for="group in [
                        { key: 'destinations', title: 'Đích đến', rows: destinations },
                        { key: 'media', title: 'Loại media', rows: mediaKinds },
                        { key: 'statuses', title: 'Trạng thái', rows: outcomes },
                    ]" :key="group.key">
                        <p class="px-3 pb-1 pt-2 text-[11px] uppercase tracking-wide text-neutral-400">
                            {{ group.title }}
                        </p>
                        <label
                            v-for="row in group.rows"
                            :key="row.value"
                            class="flex cursor-pointer items-center gap-2.5 px-3 py-1.5 text-[13px] transition hover:bg-neutral-50"
                        >
                            <input
                                type="checkbox"
                                class="accent-neutral-900"
                                :checked="picked[group.key].includes(row.value)"
                                @change="toggleIn(group.key, row.value)"
                            >
                            {{ row.label }}
                        </label>
                    </template>
                </div>
            </FilterDropdown>

            <!--
                Searched, not listed. Ads Manager can name every account here
                because it has one; this pool is a thousand.
            -->
            <FilterDropdown ref="accountMenu" label="Account" :value="accountLabel" :width="272">
                <div class="border-b border-neutral-100 p-2">
                    <input
                        v-model="accountSearch"
                        class="xp-field w-full py-1 text-[12.5px]"
                        placeholder="Tìm @handle…"
                        @click.stop
                    >
                </div>

                <div class="max-h-64 overflow-y-auto">
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[13px] transition hover:bg-neutral-50"
                        @click="chooseAccount(null); $refs.accountMenu.close()"
                    >
                        <span class="flex-1">Tất cả tài khoản</span>
                        <NavIcon v-if="!accountId" name="check" :size="13" />
                    </button>

                    <button
                        v-for="account in accountResults"
                        :key="account.id"
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[13px] transition hover:bg-neutral-50"
                        @click="chooseAccount(account); $refs.accountMenu.close()"
                    >
                        <span class="min-w-0 flex-1 truncate">@{{ account.username }}</span>
                        <NavIcon v-if="accountId === account.id" name="check" :size="13" />
                    </button>

                    <p v-if="!accountResults.length" class="px-3 py-3 text-[12.5px] text-neutral-500">
                        Gõ để tìm tài khoản.
                    </p>
                </div>
            </FilterDropdown>

            <FilterDropdown
                ref="typeMenu"
                label="Posts"
                :value="types.find((option) => option.value === type)?.label"
                :width="200"
            >
                <button
                    v-for="option in types"
                    :key="option.value"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[13px] transition hover:bg-neutral-50"
                    @click="type = option.value; page = 1; load(); $refs.typeMenu.close()"
                >
                    <span class="flex-1">{{ option.label }}</span>
                    <NavIcon v-if="type === option.value" name="check" :size="13" />
                </button>
            </FilterDropdown>
        </div>

        <!--
            Only present when something is ticked. A bar that is always there
            with a disabled button is a row of things you cannot do.
        -->
        <div
            v-if="selected.size"
            class="mb-3 flex flex-wrap items-center gap-3 rounded-lg bg-neutral-900 px-4 py-2.5 text-[13px] text-white"
        >
            <span class="font-medium">Đã chọn {{ selected.size }}</span>

            <!--
                Said before the click, not only in the confirm: the number of
                tweets that will stay on X is the fact that decides whether this
                is the right action at all.
            -->
            <span v-if="selectedPublished" class="text-white/60">
                {{ selectedPublished }} bài đã lên X sẽ vẫn còn trên đó
            </span>

            <button
                class="rounded px-2 py-1 text-red-300 transition hover:bg-white/15 disabled:opacity-50"
                :disabled="busy === 'bulk'"
                @click="destroySelected"
            >Xoá khỏi Xpost</button>

            <button class="ml-auto text-white/60 transition hover:text-white" @click="selected = new Set()">Bỏ chọn</button>
        </div>

        <!-- Table -->
        <div class="xp-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left text-[13px]">
                    <thead class="border-b border-neutral-200 text-xs text-neutral-500">
                        <tr>
                            <th class="w-10 py-3 pl-4 pr-1">
                                <input type="checkbox" :checked="allSelected" aria-label="Chọn tất cả" @change="toggleAll">
                            </th>
                            <th class="px-4 py-3 font-normal">Tên bài</th>
                            <th class="px-4 py-3 font-normal">Đăng</th>
                            <th class="px-4 py-3 font-normal">Post ID</th>
                            <th class="px-4 py-3 font-normal">Đích đến</th>
                            <th class="px-4 py-3 font-normal">Trạng thái</th>
                            <th class="px-4 py-3 font-normal">Thời điểm</th>
                            <th class="w-12 px-4 py-3"><span class="sr-only">Thao tác</span></th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-neutral-100">
                        <tr v-if="loading">
                            <td colspan="8" class="px-4 py-10 text-center text-neutral-500">Đang tải…</td>
                        </tr>

                        <tr v-else-if="!posts.length">
                            <td colspan="8" class="px-4 py-10 text-center text-neutral-500">Chưa có bài đăng nào.</td>
                        </tr>

                        <tr
                            v-for="post in posts"
                            :key="post.id"
                            class="align-top hover:bg-neutral-50"
                            :class="selected.has(post.id) ? 'bg-neutral-50' : ''"
                        >
                            <td class="w-10 py-4 pl-4 pr-1">
                                <input
                                    type="checkbox"
                                    :checked="selected.has(post.id)"
                                    :aria-label="post.name || 'Không tiêu đề'"
                                    @change="toggle(post.id)"
                                >
                            </td>

                            <!-- Tên bài -->
                            <td class="max-w-[220px] px-4 py-4">
                                <RouterLink
                                    :to="{ name: 'posts.show', params: { id: post.id } }"
                                    class="text-[13px] leading-snug text-neutral-900 hover:underline"
                                >{{ post.name || 'Không tiêu đề' }}</RouterLink>
                            </td>

                            <!-- Đăng: ai, khi nào, nội dung, ảnh -->
                            <td class="px-4 py-4">
                                <div class="flex items-start gap-2.5">
                                    <img
                                        v-if="post.sample?.account?.avatar_url"
                                        :src="post.sample.account.avatar_url"
                                        class="mt-0.5 h-8 w-8 shrink-0 rounded-full object-cover"
                                        alt=""
                                    >
                                    <div v-else class="mt-0.5 h-8 w-8 shrink-0 rounded-full bg-neutral-200"></div>

                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-x-1.5 text-[13px]">
                                            <template v-if="post.sample?.account">
                                                <span class="font-semibold">{{ post.sample.account.name }}</span>
                                                <span class="text-neutral-500">@{{ post.sample.account.username }}</span>
                                            </template>
                                            <span v-else class="text-neutral-500">Chưa fan-out</span>

                                            <span class="text-neutral-400">· {{ shortDate(rowDate(post)) }}</span>

                                            <a
                                                v-if="post.sample?.permalink"
                                                :href="post.sample.permalink"
                                                target="_blank"
                                                rel="noopener"
                                                class="text-neutral-400 hover:text-neutral-900"
                                                title="Mở trên X"
                                            >↗</a>
                                        </p>

                                        <!--
                                            A post here fans out to many accounts, unlike the
                                            reference where one post is one account. Saying so
                                            keeps the row from reading as a single send.
                                        -->
                                        <p v-if="post.progress.total > 1" class="text-xs text-neutral-500">
                                            và {{ (post.progress.total - 1).toLocaleString('vi-VN') }} tài khoản khác
                                        </p>

                                        <p class="mt-1 line-clamp-2 max-w-[320px] text-[13px] text-neutral-800">
                                            {{ post.body }}
                                        </p>

                                        <!--
                                            One fixed box for every row, whatever the creative's
                                            shape. object-cover fills it from the centre, which
                                            is where the subject of a vertical clip almost always
                                            is. A <video> with preload="metadata" paints its
                                            first frame, saving the server generating posters.
                                        -->
                                        <div
                                            v-if="post.media?.[0]"
                                            class="relative mt-2 aspect-video w-32 shrink-0 overflow-hidden rounded-lg border border-neutral-200 bg-neutral-100"
                                        >
                                            <video
                                                v-lazy-media
                                                v-if="isVideo(post.media[0])"
                                                :src="post.media[0].url"
                                                class="h-full w-full bg-black object-cover"
                                                preload="metadata"
                                                muted
                                            ></video>
                                            <img
                                                v-else
                                                :src="post.media[0].url"
                                                class="h-full w-full object-cover"
                                                alt=""
                                            >

                                            <span
                                                v-if="isVideo(post.media[0])"
                                                class="absolute inset-0 flex items-center justify-center"
                                            >
                                                <span class="flex h-6 w-6 items-center justify-center rounded-full bg-black/55">
                                                    <svg viewBox="0 0 16 16" class="h-2.5 w-2.5 fill-white">
                                                        <path d="M5 3.5v9l7.5-4.5z" />
                                                    </svg>
                                                </span>
                                            </span>

                                            <!--
                                                Orientation is worth stating now that the box no
                                                longer shows it, and the count of extra files
                                                rides along rather than costing another line.
                                            -->
                                            <span
                                                v-if="mediaBadge(post)"
                                                class="absolute bottom-1 left-1 rounded bg-black/65 px-1 text-[10px] font-medium text-white"
                                            >{{ mediaBadge(post) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Post ID -->
                            <td class="px-4 py-4">
                                <button
                                    v-if="post.sample?.x_tweet_id"
                                    class="inline-flex items-center gap-1.5 font-mono text-xs text-neutral-700 hover:text-neutral-900"
                                    :title="copied === post.sample.x_tweet_id ? 'Đã sao chép' : 'Sao chép'"
                                    @click="copyId(post.sample.x_tweet_id)"
                                >
                                    <NavIcon :name="copied === post.sample.x_tweet_id ? 'check' : 'copy'" :size="13" />
                                    {{ post.sample.x_tweet_id }}
                                </button>
                                <span v-else class="text-neutral-400">—</span>
                            </td>

                            <!-- Đích đến -->
                            <td class="px-4 py-4">
                                <template v-if="post.destination_type === 'website' && post.website_url">
                                    <p class="flex items-center gap-1.5 font-medium text-neutral-900">
                                        <NavIcon name="globe" :size="13" class="text-neutral-400" />
                                        Website
                                    </p>
                                    <p v-if="post.headline" class="text-neutral-500">{{ post.headline }}</p>
                                    <a
                                        :href="post.website_url"
                                        target="_blank"
                                        rel="noopener"
                                        class="text-neutral-500 hover:underline"
                                    >{{ destinationHost(post.website_url) }}</a>
                                </template>
                                <span v-else class="text-neutral-400">—</span>
                            </td>

                            <!-- Trạng thái -->
                            <td class="px-4 py-4">
                                <span
                                    class="inline-block rounded px-2 py-0.5 text-xs font-medium"
                                    :class="badgeClass[post.status]"
                                >{{ post.status_label }}</span>

                                <p class="mt-1 text-xs" :class="post.promoted_only ? 'text-neutral-500' : 'text-neutral-400'">
                                    {{ post.promoted_only ? 'Promoted' : 'Organic' }}
                                </p>

                                <!--
                                    A mark, not a paragraph.

                                    A count is not a diagnosis -- "1 lỗi" sent
                                    whoever read it into the post to find out
                                    what happened. Printing the sentence here
                                    instead put three lines of prose in a column
                                    two words wide. The mark says something went
                                    wrong; the popover says what.
                                -->
                                <p v-if="post.progress.total" class="mt-1 flex items-center gap-1.5 text-xs text-neutral-500">
                                    <span>{{ post.progress.published }}/{{ post.progress.total }}</span>
                                    <span v-if="post.progress.failed" class="text-red-600">· {{ post.progress.failed }} lỗi</span>

                                    <ErrorPopover
                                        v-if="post.last_error"
                                        :failure="post.last_error"
                                    >
                                        <template #actions>
                                            <RouterLink
                                                :to="{ name: 'posts.show', params: { id: String(post.id) } }"
                                                class="text-[12px] text-blue-600 transition hover:underline"
                                            >Xem từng tài khoản</RouterLink>
                                        </template>
                                    </ErrorPopover>
                                </p>
                            </td>

                            <!-- Thời điểm -->
                            <td class="whitespace-nowrap px-4 py-4 text-neutral-600">
                                <template v-if="fullDate(rowDate(post))">
                                    <p>{{ fullDate(rowDate(post)) }}</p>
                                    <p class="text-neutral-400">{{ time(rowDate(post)) }}</p>
                                </template>
                                <span v-else class="text-neutral-400">—</span>
                            </td>

                            <!--
                                Everything here already existed on the API and had
                                no way to be reached: a failed post could not be
                                retried and a finished one could not be cleared.
                            -->
                            <td class="relative px-4 py-4 text-right">
                                <button
                                    class="rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-700"
                                    :aria-expanded="menuFor === post.id"
                                    aria-haspopup="menu"
                                    @click.stop="menuFor = menuFor === post.id ? null : post.id"
                                >
                                    <svg viewBox="0 0 16 16" class="h-4 w-4 fill-current">
                                        <circle cx="8" cy="3" r="1.4" />
                                        <circle cx="8" cy="8" r="1.4" />
                                        <circle cx="8" cy="13" r="1.4" />
                                    </svg>
                                </button>

                                <div
                                    v-if="menuFor === post.id"
                                    class="absolute right-4 top-12 z-20 w-56 overflow-hidden rounded-lg border border-neutral-200 bg-white py-1 text-left shadow-lg"
                                    role="menu"
                                    @click.stop
                                >
                                    <!--
                                        Xem trước / Xem trên X / Sửa / Nhân bản,
                                        the four the reference offers, above the
                                        ones that only apply to a fan-out.
                                    -->
                                    <button
                                        class="block w-full px-3 py-2 text-left text-[13px] hover:bg-neutral-50"
                                        role="menuitem"
                                        @click="previewFor = post; menuFor = null"
                                    >
                                        Xem trước
                                    </button>

                                    <!--
                                        One post is many tweets here, which the
                                        reference never has to deal with. With a
                                        single account it opens that tweet; with
                                        more it opens the delivery log, because
                                        "the post" is not one thing to open.
                                    -->
                                    <a
                                        v-if="post.sample?.permalink && post.progress?.published === 1"
                                        :href="post.sample.permalink"
                                        target="_blank"
                                        rel="noopener"
                                        class="block w-full px-3 py-2 text-[13px] hover:bg-neutral-50"
                                        role="menuitem"
                                        @click="menuFor = null"
                                    >
                                        Xem trên X
                                    </a>
                                    <RouterLink
                                        v-else-if="post.progress?.published"
                                        :to="{ name: 'posts.show', params: { id: String(post.id) } }"
                                        class="block w-full px-3 py-2 text-[13px] hover:bg-neutral-50"
                                        role="menuitem"
                                    >
                                        Xem {{ post.progress.published }} bài trên X
                                    </RouterLink>

                                    <button
                                        class="block w-full px-3 py-2 text-left text-[13px] hover:bg-neutral-50 disabled:opacity-50"
                                        :disabled="busy === post.id"
                                        role="menuitem"
                                        @click="duplicatePost(post)"
                                    >
                                        Nhân bản
                                    </button>

                                    <div class="my-1 border-t border-neutral-100"></div>

                                    <button
                                        v-if="post.progress?.failed"
                                        class="block w-full px-3 py-2 text-[13px] hover:bg-neutral-50 disabled:opacity-50"
                                        :disabled="busy === post.id"
                                        role="menuitem"
                                        @click="retryFailed(post)"
                                    >
                                        Đăng lại {{ post.progress.failed }} bài lỗi
                                    </button>

                                    <button
                                        v-if="canCancel(post)"
                                        class="block w-full px-3 py-2 text-[13px] hover:bg-neutral-50 disabled:opacity-50"
                                        :disabled="busy === post.id"
                                        role="menuitem"
                                        @click="cancelPost(post)"
                                    >
                                        Huỷ những bài chưa gửi
                                    </button>

                                    <!--
                                        Only a draft or a scheduled post can be
                                        edited -- once it has started going out,
                                        changing it would mean two versions of the
                                        same campaign on different accounts.
                                    -->
                                    <RouterLink
                                        v-if="canEdit(post)"
                                        :to="{ name: 'composer.edit', params: { id: String(post.id) } }"
                                        class="block w-full px-3 py-2 text-[13px] hover:bg-neutral-50"
                                        role="menuitem"
                                    >
                                        Sửa bài
                                    </RouterLink>

                                    <div class="my-1 border-t border-neutral-100"></div>

                                    <button
                                        class="block w-full px-3 py-2 text-left text-[13px] text-red-600 hover:bg-red-50 disabled:opacity-50"
                                        :disabled="busy === post.id"
                                        role="menuitem"
                                        @click="destroyPost(post)"
                                    >
                                        Xoá khỏi Xpost
                                    </button>

                                    <!--
                                        Deleting here does not reach X. Saying so in
                                        the menu is cheaper than letting someone find
                                        out from their own timeline.
                                    -->
                                    <p
                                        v-if="post.progress?.published"
                                        class="px-3 pb-2 pt-1 text-[11px] leading-snug text-neutral-500"
                                    >
                                        {{ post.progress.published }} bài đã lên X sẽ vẫn còn trên đó.
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="meta && meta.last_page > 1" class="mt-4 flex items-center gap-2">
            <button class="xp-btn-ghost" :disabled="page <= 1" @click="page--">Trước</button>
            <span class="text-[13px] text-neutral-600">Trang {{ meta.current_page }} / {{ meta.last_page }}</span>
            <button class="xp-btn-ghost" :disabled="page >= meta.last_page" @click="page++">Sau</button>
        </div>

        <!--
            Xem trước.

            The same phone the composer draws, so what is checked here is what
            was checked before publishing rather than a second rendering that
            could disagree with it. The account shown is the one the row
            already stands for.
        -->
        <div
            v-if="previewFor"
            class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-6"
            @click.self="previewFor = null"
        >
            <div class="rounded-xl bg-white p-5 shadow-xl" @click.stop>
                <div class="mb-3 flex items-start justify-between gap-6">
                    <div class="min-w-0">
                        <p class="truncate text-[14px] font-semibold">{{ previewFor.name || 'Không tiêu đề' }}</p>
                        <p class="text-[12px] text-neutral-500">
                            {{ previewFor.status_label }}
                            <span v-if="previewFor.progress?.total"> · {{ previewFor.progress.total }} tài khoản</span>
                        </p>
                    </div>

                    <button
                        type="button"
                        class="shrink-0 rounded p-1 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-700"
                        aria-label="Đóng"
                        @click="previewFor = null"
                    >
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                            <path d="M4 4l8 8M12 4l-8 8" />
                        </svg>
                    </button>
                </div>

                <PhonePreview
                    :body="previewFor.body ?? ''"
                    :headline="previewFor.headline ?? ''"
                    :website-url="previewFor.destination_type === 'website' ? (previewFor.website_url ?? '') : ''"
                    :media="previewFor.media ?? []"
                    :account="previewFor.sample?.account ?? null"
                    :promoted-only="previewFor.promoted_only === true"
                />
            </div>
        </div>
    </div>
</template>
