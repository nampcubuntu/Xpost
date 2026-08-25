<script setup>
import TimezoneSelect from './TimezoneSelect.vue';

const props = defineProps({
    modelValue: { type: Object, required: true },
});

const emit = defineEmits(['update:modelValue', 'change']);

const modes = [
    { value: 'immediate', label: 'Đăng ngay', hint: 'Gửi ngay khi hàng đợi và hạn mức cho phép.' },
    { value: 'fixed_time', label: 'Đúng giờ hẹn', hint: 'Mọi tài khoản đăng cùng một thời điểm.' },
    { value: 'drip', label: 'Rải đều', hint: 'Chia đều trong khoảng thời gian đã chọn.' },
    { value: 'jitter', label: 'Ngẫu nhiên', hint: 'Mỗi tài khoản lệch một khoảng ngẫu nhiên — khó bị nhận diện là bot.' },
    { value: 'per_account_timezone', label: 'Theo giờ địa phương', hint: 'Cùng giờ đồng hồ, khác múi giờ theo từng tài khoản.' },
];

function update(patch) {
    emit('update:modelValue', { ...props.modelValue, ...patch });
    emit('change');
}

function needsWindow(mode) {
    return mode === 'drip' || mode === 'jitter';
}
</script>

<template>
    <div class="space-y-3">
        <div class="space-y-1.5">
            <label
                v-for="mode in modes"
                :key="mode.value"
                class="flex cursor-pointer items-start gap-2.5 rounded-md border px-3 py-2 transition"
                :class="modelValue.schedule_mode === mode.value
                    ? 'border-neutral-900 ring-1 ring-neutral-900'
                    : 'border-neutral-300 hover:bg-neutral-50'"
            >
                <input
                    type="radio"
                    class="mt-0.5"
                    :checked="modelValue.schedule_mode === mode.value"
                    @change="update({ schedule_mode: mode.value })"
                >
                <span class="min-w-0">
                    <span class="block text-[13px] font-medium">{{ mode.label }}</span>
                    <span class="block text-xs text-neutral-500">{{ mode.hint }}</span>
                </span>
            </label>
        </div>

        <div v-if="modelValue.schedule_mode !== 'immediate'" class="space-y-2">
            <div class="grid grid-cols-2 gap-2">
                <label class="space-y-1">
                    <span class="xp-hint">Bắt đầu lúc</span>
                    <input
                        type="datetime-local"
                        class="xp-field"
                        :value="modelValue.scheduled_at"
                        @input="update({ scheduled_at: $event.target.value || null })"
                    >
                </label>

                <label v-if="needsWindow(modelValue.schedule_mode)" class="space-y-1">
                    <span class="xp-hint">Kết thúc lúc</span>
                    <input
                        type="datetime-local"
                        class="xp-field"
                        :value="modelValue.window_ends_at"
                        @input="update({ window_ends_at: $event.target.value || null })"
                    >
                </label>
            </div>

            <!--
                Without this the hour above is ambiguous: the browser sends a bare
                wall clock and the server has to guess whose clock it was. With a
                global account pool that guess is wrong for most of them.
            -->
            <label class="space-y-1 block">
                <span class="xp-hint">
                    {{ modelValue.schedule_mode === 'per_account_timezone'
                        ? 'Múi giờ dự phòng (tài khoản chưa khai múi giờ)'
                        : 'Giờ trên đọc theo múi giờ' }}
                </span>
                <TimezoneSelect
                    :model-value="modelValue.timezone"
                    @update:model-value="update({ timezone: $event })"
                />
            </label>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <label v-if="modelValue.schedule_mode === 'jitter'" class="space-y-1">
                <span class="xp-hint">Độ lệch tối đa (giây)</span>
                <input
                    type="number"
                    min="0"
                    max="86400"
                    class="xp-field"
                    :value="modelValue.jitter_seconds"
                    @input="update({ jitter_seconds: Number($event.target.value) || 0 })"
                >
            </label>

            <label class="space-y-1">
                <span class="xp-hint">Tối đa mỗi phút</span>
                <input
                    type="number"
                    min="1"
                    class="xp-field"
                    placeholder="không giới hạn"
                    :value="modelValue.per_minute_cap"
                    @input="update({ per_minute_cap: $event.target.value ? Number($event.target.value) : null })"
                >
            </label>
        </div>
    </div>
</template>
