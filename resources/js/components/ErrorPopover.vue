<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    /** The whole `failure` object from the API, or null. */
    failure: { type: Object, default: null },
});

const code = computed(() => props.failure?.code ?? null);
const message = computed(() => props.failure?.message ?? '');

/*
 * Who to go and fix, said in one line at the top.
 *
 * A code is a status, not a diagnosis: `internal_error` says the publisher
 * reached its catch-all, which is equally true of a dead proxy, a bug here, and
 * a library throwing something nobody anticipated. Three problems, three
 * owners, and nothing on the page telling them apart.
 */
const blame = computed(() => ({
    proxy: { label: 'Lỗi từ proxy', tone: 'bg-amber-50 text-amber-800' },
    x: { label: 'X từ chối', tone: 'bg-red-50 text-red-700' },
    account: { label: 'Lỗi từ tài khoản', tone: 'bg-red-50 text-red-700' },
    xpost: { label: 'Lỗi từ Xpost', tone: 'bg-red-50 text-red-700' },
}[props.failure?.blame] ?? { label: 'Lỗi khi đăng', tone: 'bg-red-50 text-red-700' }));

/** Only the rows that have something in them. */
const facts = computed(() => [
    { label: 'Giai đoạn', value: props.failure?.stage },
    { label: 'Tài khoản', value: props.failure?.account },
    { label: 'Proxy', value: props.failure?.proxy },
    {
        label: 'Lúc',
        value: props.failure?.at ? new Date(props.failure.at).toLocaleString('vi-VN') : null,
    },
].filter((row) => row.value));

/*
 * A mark on the row, and the reason a click away.
 *
 * The message was printed into the cell, which put a three line sentence in a
 * column two words wide and pushed every row taller than the thumbnail beside
 * it. A list is for scanning: the row has to say that something went wrong and
 * where to look, and the sentence itself belongs where it can be read.
 */
const open = ref(false);
const root = ref(null);
const panel = ref(null);
const style = ref({});
const copied = ref(false);

/*
 * Teleported to the body and positioned in viewport coordinates.
 *
 * The posts table is an overflow-x-auto container, and an absolutely positioned
 * panel inside one is clipped at the cell edge however high its z-index. The
 * timezone picker hit exactly this and for exactly this reason.
 */
function place() {
    const box = root.value?.getBoundingClientRect();

    if (! box) {
        return;
    }

    const width = 320;
    const height = 180;

    // Flipped when the row is near an edge, which the last row of a long table
    // always is.
    const up = window.innerHeight - box.bottom < height && box.top > height;
    const left = Math.min(box.left, window.innerWidth - width - 12);

    style.value = {
        position: 'fixed',
        width: `${width}px`,
        left: `${Math.max(12, left)}px`,
        ...(up ? { bottom: `${window.innerHeight - box.top + 6}px` } : { top: `${box.bottom + 6}px` }),
    };
}

async function toggle() {
    open.value = ! open.value;

    if (open.value) {
        place();
        await nextTick();
    }
}

function onOutside(event) {
    // Both, because the panel is no longer a descendant of the button.
    if (! root.value?.contains(event.target) && ! panel.value?.contains(event.target)) {
        open.value = false;
    }
}

function onKey(event) {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

// Capture, so a scroll inside the table body is seen and not only one on the
// window -- otherwise the panel stays where the row used to be.
const reposition = () => open.value && place();

/*
 * Bound only while open.
 *
 * A closed popover has nothing to dismiss or reposition, and there is one of
 * these per failed row. Twenty-five rows would otherwise be seventy-five global
 * listeners, one of them a capturing scroll handler.
 */
function bind() {
    document.addEventListener('click', onOutside);
    document.addEventListener('keydown', onKey);
    window.addEventListener('scroll', reposition, true);
    window.addEventListener('resize', reposition);
}

function unbind() {
    document.removeEventListener('click', onOutside);
    document.removeEventListener('keydown', onKey);
    window.removeEventListener('scroll', reposition, true);
    window.removeEventListener('resize', reposition);
}

watch(open, (isOpen) => {
    isOpen ? bind() : unbind();
    copied.value = false;
});

// A row filtered away mid-click would otherwise leave its listeners behind.
onBeforeUnmount(unbind);

/*
 * The first sentence, for the button's tooltip.
 *
 * Hovering should answer "what kind of problem" without a click; the rest is
 * what the panel is for.
 */
const summary = computed(() => `${blame.value.label} — ${message.value.split('. ')[0]}`);

async function copy() {
    try {
        // The whole picture, because what gets pasted into a supplier ticket
        // has to say which proxy and which stage, not just the sentence.
        await navigator.clipboard.writeText([
            `[${code.value}] ${message.value}`,
            ...facts.value.map((row) => `${row.label}: ${row.value}`),
            props.failure?.raw ? `Nguyên văn: ${props.failure.raw}` : null,
        ].filter(Boolean).join('\n'));

        copied.value = true;
    } catch {
        // A denied clipboard is not worth an error state -- the text is on
        // screen and selectable either way.
    }
}
</script>

<template>
    <span ref="root" class="inline-flex align-middle">
        <button
            type="button"
            class="inline-flex h-[18px] w-[18px] items-center justify-center rounded-full border border-red-200 bg-red-50 text-red-600 transition hover:bg-red-100"
            :class="open ? 'ring-2 ring-red-200' : ''"
            :title="summary"
            :aria-label="`Chi tiết lỗi: ${summary}`"
            :aria-expanded="open"
            @click.stop="toggle"
        >
            <!-- Drawn rather than borrowed from NavIcon: those are stroked line
                 icons at nav weight, and this has to read as a mark at 18px. -->
            <svg viewBox="0 0 16 16" class="h-[11px] w-[11px]" fill="currentColor" aria-hidden="true">
                <path d="M8 3a.9.9 0 0 1 .9.98l-.35 4.2a.55.55 0 0 1-1.1 0l-.35-4.2A.9.9 0 0 1 8 3Z" />
                <circle cx="8" cy="11.6" r="1.05" />
            </svg>
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="panel"
                class="z-[70] overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-lg"
                :style="style"
                @click.stop
            >
                <!--
                    Who to go and fix, before what went wrong. A proxy refusing
                    and X refusing are the same red badge on the row and two
                    different jobs for whoever reads this.
                -->
                <div class="flex items-center gap-2 border-b border-neutral-100 px-3 py-2" :class="blame.tone">
                    <span class="text-[12px] font-semibold">{{ blame.label }}</span>
                    <code v-if="code" class="ml-auto rounded bg-white/70 px-1.5 py-0.5 font-mono text-[11px]">{{ code }}</code>
                </div>

                <!-- Selectable and wrapped: the whole point is reading it, and
                     these messages carry a host:port worth copying by hand. -->
                <p class="max-h-40 select-text overflow-y-auto whitespace-pre-wrap px-3 py-2.5 text-[12.5px] leading-relaxed text-neutral-700">
                    {{ message }}
                </p>

                <!--
                    Where it came from. The account and the proxy are the two
                    things that make a failure actionable, and neither was on
                    the page anywhere.
                -->
                <dl v-if="facts.length" class="border-t border-neutral-100 px-3 py-2 text-[12px]">
                    <div v-for="row in facts" :key="row.label" class="flex gap-2 py-0.5">
                        <dt class="w-[68px] shrink-0 text-neutral-400">{{ row.label }}</dt>
                        <dd class="min-w-0 flex-1 break-words text-neutral-700">{{ row.value }}</dd>
                    </div>
                </dl>

                <!-- Kept when the message was rewritten, so nothing is hidden
                     from whoever is comparing this against a log. -->
                <details v-if="failure?.raw" class="border-t border-neutral-100 px-3 py-1.5">
                    <summary class="cursor-pointer text-[11.5px] text-neutral-400 hover:text-neutral-600">Nguyên văn</summary>
                    <p class="mt-1 select-text whitespace-pre-wrap font-mono text-[11px] leading-relaxed text-neutral-500">
                        {{ failure.raw }}
                    </p>
                </details>

                <div class="flex items-center gap-3 border-t border-neutral-100 px-3 py-2">
                    <button class="text-[12px] text-neutral-600 transition hover:text-neutral-900" @click="copy">
                        {{ copied ? 'Đã chép' : 'Chép lỗi' }}
                    </button>
                    <slot name="actions" />
                </div>
            </div>
        </Teleport>
    </span>
</template>
