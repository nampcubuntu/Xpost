<script setup>
import { computed, onMounted, ref } from 'vue';
import api from '../lib/api';

const props = defineProps({
    modelValue: { type: String, default: null },
    /** Shown as an empty first option — for pickers used as a bulk action. */
    placeholder: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

/*
 * The full IANA list is ~420 entries, which is unusable as a flat dropdown but
 * fine grouped by region. The zones the team's own accounts sit in go first --
 * for most teams that is the only group they ever open.
 */
const groups = ref([]);
const inUse = ref([]);
const loaded = ref(false);

/* One fetch per page load, shared by every picker on it. */
let request;

onMounted(async () => {
    request ??= api.get('timezones');

    try {
        const { data } = await request;
        groups.value = data.data;
        inUse.value = data.in_use;
    } finally {
        loaded.value = true;
    }
});

/*
 * A saved zone that is not in the list -- tzdata dropped it, or the row predates
 * a rename -- would otherwise be silently swapped for the first option the
 * moment the form is touched.
 */
const isKnown = computed(() => !loaded.value
    || groups.value.some((group) => group.zones.some((zone) => zone.value === props.modelValue)));
</script>

<template>
    <select
        :value="modelValue"
        class="xp-field"
        @change="emit('update:modelValue', $event.target.value)"
    >
        <option v-if="placeholder" value="">{{ placeholder }}</option>
        <option v-if="modelValue && !isKnown" :value="modelValue">{{ modelValue }}</option>
        <option v-if="!loaded && !placeholder" :value="modelValue">{{ modelValue || 'Đang tải…' }}</option>

        <optgroup v-if="inUse.length" label="Tài khoản của bạn đang ở">
            <option v-for="zone in inUse" :key="`used-${zone.value}`" :value="zone.value">
                {{ zone.label }} ({{ zone.offset }}) — {{ zone.accounts }} tài khoản
            </option>
        </optgroup>

        <optgroup v-for="group in groups" :key="group.region" :label="group.region">
            <option v-for="zone in group.zones" :key="zone.value" :value="zone.value">
                {{ zone.label }} ({{ zone.offset }})
            </option>
        </optgroup>
    </select>
</template>
