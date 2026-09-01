<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/*
 * A day-by-day chart, drawn rather than approximated.
 *
 * The page used to scale a row of divs by percentage of the tallest bar, which
 * cannot show a zero day, cannot label an axis, and turns a month of data into
 * thirty rectangles a reader has to hover one at a time to read.
 *
 * No charting library: this is an SVG path over a linear scale, and a
 * dependency to draw a line would be several hundred kilobytes.
 */
const props = defineProps({
    /** @type {{key: string, label: string, color: string, values: number[]}[]} */
    series: { type: Array, required: true },
    labels: { type: Array, required: true },
    /**
     * `stacked` puts one column per day and splits it by share, so a day with
     * 500 published and 10 failed is a tall column that is almost all black
     * with a sliver of pink -- the ratio read off the column itself, instead of
     * two bars on two scales that cannot be compared by eye at all.
     */
    kind: { type: String, default: 'area' },
    height: { type: Number, default: 200 },
    format: { type: Function, default: (v) => Number(v ?? 0).toLocaleString('vi-VN') },
});

/*
 * The drawing is measured in real pixels.
 *
 * It used to stretch a fixed viewBox to the container with
 * preserveAspectRatio="none", which scales x and y by different amounts -- so
 * every circle came out an oval and every stroke thickened along one axis.
 * Matching the viewBox to the actual width costs a ResizeObserver and makes one
 * unit mean one pixel again.
 */
const wrap = ref(null);
const width = ref(760);
let observer;

onMounted(() => {
    observer = new ResizeObserver(([entry]) => {
        width.value = Math.max(240, entry.contentRect.width);
    });

    observer.observe(wrap.value);
    width.value = Math.max(240, wrap.value.clientWidth);
});

onBeforeUnmount(() => observer?.disconnect());

const PAD = { top: 12, right: 12, bottom: 22, left: 44 };

const plotW = computed(() => width.value - PAD.left - PAD.right);
const plotH = computed(() => props.height - PAD.top - PAD.bottom);
const count = computed(() => props.labels.length);

/** What each day adds up to, which is what a stacked column is tall by. */
const totals = computed(() => props.labels.map(
    (_, i) => props.series.reduce((sum, s) => sum + Number(s.values[i] ?? 0), 0),
));

/*
 * The top of the axis, rounded up to something a person would choose.
 *
 * A scale ending at 4,173 puts gridlines on numbers nobody can compare
 * against; rounding to 5,000 makes the halfway line mean half.
 */
const max = computed(() => {
    const peak = props.kind === 'stacked'
        ? Math.max(0, ...totals.value)
        : Math.max(0, ...props.series.flatMap((s) => s.values.map(Number)));

    if (peak <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(peak));

    const steps = [1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10];

    return magnitude * (steps.find((m) => peak <= magnitude * m) ?? 10);
});

const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => ({
    value: max.value * f,
    y: PAD.top + plotH.value * (1 - f),
})));

/** One position per day, centred when there is only one. */
const x = (i) => (count.value <= 1
    ? PAD.left + plotW.value / 2
    : PAD.left + (plotW.value * i) / (count.value - 1));

const y = (v) => PAD.top + plotH.value * (1 - Number(v ?? 0) / max.value);

const linePath = (values) => values
    .map((v, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(v).toFixed(1)}`)
    .join(' ');

const areaPath = (values) => (values.length === 0
    ? ''
    : `${linePath(values)} L${x(values.length - 1).toFixed(1)},${PAD.top + plotH.value} `
        + `L${x(0).toFixed(1)},${PAD.top + plotH.value} Z`);

const barWidth = computed(() => Math.max(
    2,
    Math.min(26, (plotW.value / Math.max(count.value, 1)) - 2),
));

/**
 * Every segment of every column, bottom up.
 *
 * Built here rather than in the template, because a stacked segment needs the
 * running total underneath it and working that out inline reads as arithmetic
 * rather than as a chart.
 */
const columns = computed(() => props.labels.map((_, i) => {
    const segments = [];
    let below = 0;

    for (const s of props.series) {
        const value = Number(s.values[i] ?? 0);

        if (value > 0) {
            segments.push({
                key: s.key,
                color: s.color,
                y: y(below + value),
                // One failure out of five hundred is a hairline, and a hairline
                // rounded away is a day reported as clean.
                height: Math.max(1, y(below) - y(below + value)),
            });
        }

        below += value;
    }

    return segments;
}));

/*
 * Which dates get printed.
 *
 * Ninety labels overlap into a grey smear, so only as many as fit are drawn --
 * always including the last, since "up to when" is what a reader looks for.
 */
const labelEvery = computed(
    () => Math.ceil(count.value / Math.max(3, Math.floor(width.value / 90))) || 1,
);

const showLabel = (i) => i === count.value - 1 || i % labelEvery.value === 0;

const shortDay = (day) => String(day).slice(5).replace('-', '/');

const hover = ref(null);

function track(event) {
    if (count.value === 0) {
        return;
    }

    const box = event.currentTarget.getBoundingClientRect();
    const step = count.value <= 1 ? plotW.value : plotW.value / (count.value - 1);

    hover.value = Math.max(0, Math.min(count.value - 1, Math.round((event.clientX - box.left) / step)));
}

const hoverTotal = computed(() => (hover.value === null ? 0 : totals.value[hover.value]));

/** The share each series holds of the hovered day, which is the point of stacking. */
const share = (value) => (hoverTotal.value > 0
    ? `${Math.round((value / hoverTotal.value) * 100)}%`
    : '0%');

const tooltipSide = computed(() => (hover.value !== null && hover.value > count.value / 2 ? 'right' : 'left'));
</script>

<template>
    <div ref="wrap" class="relative">
        <svg :width="width" :height="height" :viewBox="`0 0 ${width} ${height}`">
            <!-- Value axis, in the lightest ink that still reads, so the data
                 stays the thing being looked at. -->
            <line
                v-for="t in ticks"
                :key="`grid-${t.y}`"
                :x1="PAD.left"
                :x2="width - PAD.right"
                :y1="t.y"
                :y2="t.y"
                stroke="currentColor"
                class="text-neutral-200"
                stroke-width="1"
            />

            <template v-if="kind === 'stacked'">
                <g v-for="(column, i) in columns" :key="`col-${i}`">
                    <rect
                        v-for="seg in column"
                        :key="`${seg.key}-${i}`"
                        :x="x(i) - barWidth / 2"
                        :y="seg.y"
                        :width="barWidth"
                        :height="seg.height"
                        :fill="seg.color"
                    />
                </g>
            </template>

            <template v-else>
                <defs>
                    <linearGradient v-for="s in series" :id="`fade-${s.key}`" :key="`g-${s.key}`" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" :stop-color="s.color" stop-opacity="0.18" />
                        <stop offset="100%" :stop-color="s.color" stop-opacity="0" />
                    </linearGradient>
                </defs>

                <path v-for="s in series" :key="`area-${s.key}`" :d="areaPath(s.values)" :fill="`url(#fade-${s.key})`" />

                <path
                    v-for="s in series"
                    :key="`line-${s.key}`"
                    :d="linePath(s.values)"
                    fill="none"
                    :stroke="s.color"
                    stroke-width="2"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                />
            </template>

            <!-- One crosshair reading every series at once, rather than a
                 hover target per bar. -->
            <g v-if="hover !== null">
                <line
                    :x1="x(hover)"
                    :x2="x(hover)"
                    :y1="PAD.top"
                    :y2="PAD.top + plotH"
                    stroke="currentColor"
                    class="text-neutral-400"
                    stroke-width="1"
                    stroke-dasharray="3 3"
                />
                <circle
                    v-for="s in series"
                    v-show="kind !== 'stacked'"
                    :key="`dot-${s.key}`"
                    :cx="x(hover)"
                    :cy="y(s.values[hover])"
                    r="3.5"
                    :fill="s.color"
                    stroke="#fff"
                    stroke-width="1.5"
                />
            </g>

            <text
                v-for="t in ticks"
                :key="`tick-${t.y}`"
                :x="PAD.left - 8"
                :y="t.y + 3.5"
                text-anchor="end"
                class="fill-neutral-400 text-[10.5px] tabular-nums"
            >{{ format(Math.round(t.value)) }}</text>

            <text
                v-for="(day, i) in labels"
                v-show="showLabel(i)"
                :key="`day-${day}`"
                :x="x(i)"
                :y="height - 6"
                text-anchor="middle"
                class="fill-neutral-400 text-[10.5px] tabular-nums"
            >{{ shortDay(day) }}</text>

            <rect
                :x="PAD.left"
                :y="PAD.top"
                :width="plotW"
                :height="plotH"
                fill="transparent"
                @mousemove="track"
                @mouseleave="hover = null"
            />
        </svg>

        <div
            v-if="hover !== null"
            class="pointer-events-none absolute top-2 rounded-lg bg-neutral-900 px-2.5 py-1.5 text-[11.5px] text-white shadow-lg"
            :class="tooltipSide === 'right' ? 'right-3' : 'left-14'"
        >
            <p class="mb-0.5 font-medium">{{ labels[hover] }}</p>

            <p v-for="s in series" :key="`tip-${s.key}`" class="flex items-center gap-1.5 whitespace-nowrap">
                <span class="h-1.5 w-1.5 shrink-0 rounded-full" :style="{ background: s.color }"></span>
                {{ s.label }}
                <span class="ml-auto pl-3 font-medium tabular-nums">{{ format(s.values[hover]) }}</span>
                <span v-if="kind === 'stacked'" class="w-9 text-right tabular-nums text-white/60">
                    {{ share(Number(s.values[hover] ?? 0)) }}
                </span>
            </p>

            <p
                v-if="kind === 'stacked'"
                class="mt-1 flex items-center gap-1.5 whitespace-nowrap border-t border-white/20 pt-1"
            >
                <span class="h-1.5 w-1.5 shrink-0"></span>
                Tổng
                <span class="ml-auto pl-3 font-medium tabular-nums">{{ format(hoverTotal) }}</span>
                <span class="w-9"></span>
            </p>
        </div>
    </div>
</template>
