<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import api from '../lib/api';
import NavIcon from './NavIcon.vue';

const props = defineProps({
    modelValue: { type: String, default: null },
    /** Shown when nothing is chosen — for pickers used as a bulk action. */
    placeholder: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

/*
 * A searchable list rather than a native select.
 *
 * There are ~420 zones. A native dropdown can only be walked, and walking 420
 * rows to reach Asia/Ho Chi Minh is the reason the old grouped-by-region
 * version existed -- which traded one problem for another, because "the zones
 * beginning with P" is not a question anyone has. Typing three letters is.
 *
 * Ordered by offset, the way X Ads Manager orders its own: the zones an hour
 * apart end up next to each other instead of four hundred rows apart.
 */
const zones = ref([]);
const inUse = ref([]);
const loaded = ref(false);

const open = ref(false);
const search = ref('');
const root = ref(null);
const panel = ref(null);
const field = ref(null);

/*
 * The panel is teleported to the body and positioned in viewport coordinates.
 *
 * A native select draws its popup outside the page entirely, so it never
 * mattered that this control sits inside the accounts table -- which is an
 * overflow-x container, and an absolutely positioned panel inside one is
 * clipped at the cell edge however high its z-index. Replacing the select with
 * a div would have hidden the list in the one place it is used most.
 */
const style = ref({});

function place() {
    const box = root.value?.getBoundingClientRect();

    if (!box) {
        return;
    }

    const height = 320;
    // Flipped above when the row is near the bottom of the window, which is
    // where the last rows of a long table always are.
    const below = window.innerHeight - box.bottom;
    const up = below < height && box.top > below;

    style.value = {
        position: 'fixed',
        left: `${box.left}px`,
        width: `${Math.max(box.width, 280)}px`,
        ...(up ? { bottom: `${window.innerHeight - box.top + 4}px` } : { top: `${box.bottom + 4}px` }),
    };
}

/* One fetch per page load, shared by every picker on it. */
let request;

onMounted(async () => {
    request ??= api.get('timezones');

    try {
        const { data } = await request;
        zones.value = data.data;
        inUse.value = data.in_use;
    } finally {
        loaded.value = true;
    }
});

const selected = computed(() => zones.value.find((zone) => zone.value === props.modelValue));

/*
 * A saved zone the list does not carry -- tzdata dropped it, or it is one of
 * the backward-compatibility names a browser hands out, like Asia/Saigon.
 * Showing the raw identifier is right: it is what will be used, and pretending
 * it is unset would invite someone to overwrite a working value by accident.
 */
const label = computed(() => selected.value?.label ?? props.modelValue ?? null);
const offset = computed(() => selected.value?.offset ?? '');

const matches = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return zones.value;
    }

    // Matched against the offset too, so "utc+7" and "+7" both find the zone
    // someone is actually thinking in.
    return zones.value.filter((zone) => zone.label.toLowerCase().includes(term)
        || zone.offset.toLowerCase().includes(term)
        || zone.offset.toLowerCase().replace('utc', '').includes(term));
});

/** Pinned above the list, and only when nothing is being searched for. */
const pinned = computed(() => (search.value.trim() ? [] : inUse.value));

function choose(value) {
    emit('update:modelValue', value);
    open.value = false;
    search.value = '';
}

async function toggle() {
    open.value = !open.value;

    if (open.value) {
        place();

        // Focused on open so the list is searchable without a second click,
        // which is the whole reason for not using a native select.
        await nextTick();
        field.value?.focus();
    }
}

function onOutside(event) {
    // Both, because the panel is no longer a descendant of the control.
    const inside = root.value?.contains(event.target) || panel.value?.contains(event.target);

    if (open.value && !inside) {
        open.value = false;
    }
}

// Capture, so a scroll inside the table body is seen and not only one on the
// window -- otherwise the panel stays where the row used to be.
const reposition = () => open.value && place();

onMounted(() => {
    document.addEventListener('click', onOutside);
    window.addEventListener('scroll', reposition, true);
    window.addEventListener('resize', reposition);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onOutside);
    window.removeEventListener('scroll', reposition, true);
    window.removeEventListener('resize', reposition);
});

// Reopening should start from the whole list, not from the last search.
watch(open, (isOpen) => {
    if (!isOpen) {
        search.value = '';
    }
});
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            class="xp-field flex w-full items-center gap-2 text-left"
            @click.stop="toggle"
        >
            <NavIcon name="schedules" :size="14" class="shrink-0 text-neutral-400" />

            <span class="min-w-0 flex-1 truncate" :class="label ? '' : 'text-neutral-400'">
                {{ label ?? placeholder ?? 'Chọn múi giờ…' }}
            </span>

            <span v-if="offset" class="shrink-0 text-[12px] text-neutral-500">{{ offset }}</span>
            <NavIcon name="chevron" :size="13" class="shrink-0 text-neutral-400" />
        </button>

        <Teleport to="body">
        <div
            v-if="open"
            ref="panel"
            class="z-[60] overflow-hidden rounded-lg border border-neutral-200 bg-white shadow-lg"
            :style="style"
            @click.stop
        >
            <div class="border-b border-neutral-100 p-2">
                <input
                    ref="field"
                    v-model="search"
                    class="xp-field w-full py-1 text-[12.5px]"
                    placeholder="Tìm múi giờ hoặc UTC+7…"
                    @keydown.esc="open = false"
                    @keydown.enter.prevent="matches.length && choose(matches[0].value)"
                >
            </div>

            <div class="max-h-64 overflow-y-auto py-1">
                <!-- Clearing is a real choice for a bulk picker, and it is not "UTC". -->
                <button
                    v-if="placeholder"
                    type="button"
                    class="flex w-full items-center px-3 py-1.5 text-left text-[13px] text-neutral-500 transition hover:bg-neutral-50"
                    @click="choose('')"
                >{{ placeholder }}</button>

                <!--
                    The zones this team already works in.
                    X has no equivalent because X has one account; here it is
                    usually the only group anyone opens.
                -->
                <template v-if="pinned.length">
                    <p class="px-3 pb-0.5 pt-1.5 text-[11px] uppercase tracking-wide text-neutral-400">
                        Tài khoản của bạn đang ở
                    </p>
                    <button
                        v-for="zone in pinned"
                        :key="`used-${zone.value}`"
                        type="button"
                        class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[13px] transition hover:bg-neutral-50"
                        :class="zone.value === modelValue ? 'font-medium' : ''"
                        @click="choose(zone.value)"
                    >
                        <span class="min-w-0 flex-1 truncate">{{ zone.label }}</span>
                        <span class="shrink-0 text-[11.5px] text-neutral-400">{{ zone.accounts }} tài khoản</span>
                        <span class="w-[62px] shrink-0 text-right text-[12px] text-neutral-500">{{ zone.offset }}</span>
                    </button>
                    <div class="my-1 border-t border-neutral-100"></div>
                </template>

                <p v-if="!loaded" class="px-3 py-3 text-[13px] text-neutral-500">Đang tải…</p>
                <p v-else-if="!matches.length" class="px-3 py-3 text-[13px] text-neutral-500">Không có múi giờ nào khớp.</p>

                <button
                    v-for="zone in matches"
                    :key="zone.value"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-[13px] transition hover:bg-neutral-50"
                    :class="zone.value === modelValue ? 'font-medium' : ''"
                    @click="choose(zone.value)"
                >
                    <span class="min-w-0 flex-1 truncate">{{ zone.label }}</span>
                    <span class="w-[62px] shrink-0 text-right text-[12px] text-neutral-500">{{ zone.offset }}</span>
                </button>
            </div>
        </div>
        </Teleport>
    </div>
</template>
