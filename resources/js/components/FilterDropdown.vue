<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import NavIcon from './NavIcon.vue';

const props = defineProps({
    /** The word before the colon, as Ads Manager writes it: "Account:". */
    label: { type: String, default: null },
    /** What is currently chosen, shown after the label. */
    value: { type: String, default: null },
    /** A count badge instead of a value, for the checkbox panel. */
    count: { type: Number, default: 0 },
    width: { type: Number, default: 240 },
});

/*
 * The three controls on the posts toolbar are one mechanism with three
 * fillings: a button that says what is chosen, and a panel that lets it be
 * chosen. Written once because the third copy is where they start to drift --
 * one closing on Escape, one not; one repositioning on scroll, one left behind.
 */
const open = ref(false);
const root = ref(null);
const panel = ref(null);
const style = ref({});

/*
 * Teleported and positioned in viewport coordinates.
 *
 * The toolbar sits above a table in an overflow-x-auto container, and a panel
 * positioned inside one is clipped at its edge however high the z-index. The
 * timezone picker and the error popover both landed here first.
 */
function place() {
    const box = root.value?.getBoundingClientRect();

    if (! box) {
        return;
    }

    // Right-aligned to the button, and pulled back in when that would put it
    // off-screen -- these sit at the right end of the toolbar.
    const left = Math.min(box.right - props.width, window.innerWidth - props.width - 12);

    style.value = {
        position: 'fixed',
        width: `${props.width}px`,
        left: `${Math.max(12, left)}px`,
        top: `${box.bottom + 6}px`,
    };
}

async function toggle() {
    open.value = ! open.value;

    if (open.value) {
        place();
        await nextTick();
    }
}

function close() {
    open.value = false;
}

defineExpose({ close });

function onOutside(event) {
    if (! root.value?.contains(event.target) && ! panel.value?.contains(event.target)) {
        open.value = false;
    }
}

const onKey = (event) => event.key === 'Escape' && (open.value = false);
const reposition = () => open.value && place();

/** Bound only while open: a closed panel has nothing to dismiss or move. */
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

watch(open, (isOpen) => (isOpen ? bind() : unbind()));
onBeforeUnmount(unbind);
</script>

<template>
    <span ref="root" class="inline-flex">
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border px-3 py-2 text-[13px] transition"
            :class="open
                ? 'border-neutral-900 text-neutral-900'
                : 'border-neutral-300 text-neutral-600 hover:border-neutral-400'"
            :aria-expanded="open"
            @click.stop="toggle"
        >
            <slot name="icon" />

            <span v-if="label" class="text-neutral-500">{{ label }}:</span>
            <span v-if="value" class="font-medium text-neutral-900">{{ value }}</span>

            <span
                v-if="count"
                class="rounded bg-neutral-900 px-1.5 text-[11px] font-medium text-white"
            >{{ count }}</span>

            <NavIcon name="chevron" :size="13" class="text-neutral-400" :class="open ? 'rotate-180' : ''" />
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="panel"
                class="z-[70] overflow-hidden rounded-lg border border-neutral-200 bg-white py-1 shadow-lg"
                :style="style"
                @click.stop
            >
                <slot :close="close" />
            </div>
        </Teleport>
    </span>
</template>
