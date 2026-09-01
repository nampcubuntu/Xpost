<script setup>
import { computed, ref } from 'vue';

/*
 * A day-by-day line, drawn rather than approximated.
 *
 * The page used to scale a row of divs by percentage of the tallest bar, which
 * cannot show a zero day, cannot label an axis, and turns a month of data into
 * thirty rectangles a reader has to hover one at a time to read. This draws the
 * series properly: a value axis with real numbers on it, a date axis, and one
 * crosshair that reads every series at once.
 *
 * No charting library. The whole thing is an SVG path over a linear scale --
 * a dependency for this would be several hundred kilobytes to draw a line.
 */
const props = defineProps({
    /** @type {{key: string, label: string, color: string, values: number[]}[]} */
    series: { type: Array, required: true },
    labels: { type: Array, required: true },
    /** Bars sit on the same grid but read as volume rather than a trend. */
    kind: { type: String, default: 'area' },
    height: { type: Number, default: 200 },
    format: { type: Function, default: (v) => Number(v ?? 0).toLocaleString('vi-VN') },
});

const W = 760;
const PAD = { top: 12, right: 10, bottom: 24, left: 46 };

// The right scale needs room the single-axis layout does not.
const padRight = computed(() => (hasRight.value ? 46 : PAD.right));

const H = computed(() => props.height);
const plotW = computed(() => W - PAD.left - padRight.value);
const plotH = computed(() => H.value - PAD.top - PAD.bottom);

const count = computed(() => props.labels.length);

/*
 * The top of the axis, rounded up to something a person would choose.
 *
 * A scale that ends at 4,173 puts gridlines on numbers nobody can compare
 * against; rounding to 5,000 makes the halfway line mean half.
 */
function roundUp(peak) {
    if (peak <= 0) {
        return 1;
    }

    const magnitude = 10 ** Math.floor(Math.log10(peak));
    const step = [1, 2, 2.5, 5, 10].find((m) => peak <= magnitude * m) ?? 10;

    return magnitude * step;
}

const onAxis = (side) => props.series.filter((s) => (s.axis ?? 'left') === side);

/*
 * A scale per axis, because one cannot hold both.
 *
 * Twenty-eight published against nine hundred failed on a shared axis pins
 * the published series flat against the floor -- the series someone opened
 * the page for, drawn as a line of nothing. A second axis keeps both on one
 * chart, and each axis is written in its own series' colour so nobody reads
 * a bar against the wrong numbers.
 */
const max = computed(() => ({
    left: roundUp(Math.max(0, ...onAxis('left').flatMap((s) => s.values.map(Number)))),
    right: roundUp(Math.max(0, ...onAxis('right').flatMap((s) => s.values.map(Number)))),
}));

const hasRight = computed(() => onAxis('right').length > 0);

const fractions = [0, 0.25, 0.5, 0.75, 1];

const ticks = computed(() => fractions.map((f) => ({
    left: max.value.left * f,
    right: max.value.right * f,
    y: PAD.top + plotH.value * (1 - f),
})));

/** The colour each axis is labelled in, so a scale points at its series. */
const axisColour = computed(() => ({
    left: onAxis('left')[0]?.color ?? '#a3a3a3',
    right: onAxis('right')[0]?.color ?? '#a3a3a3',
}));

/** One point per day, spread across the plot even when there is only one. */
const x = (i) => (count.value <= 1
    ? PAD.left + plotW.value / 2
    : PAD.left + (plotW.value * i) / (count.value - 1));

const y = (v, axis = 'left') => PAD.top + plotH.value * (1 - Number(v ?? 0) / max.value[axis]);

const linePath = (values, axis) => values
    .map((v, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(v, axis).toFixed(1)}`)
    .join(' ');

const areaPath = (values, axis) => (values.length === 0
    ? ''
    : `${linePath(values, axis)} L${x(values.length - 1).toFixed(1)},${PAD.top + plotH.value} `
        + `L${x(0).toFixed(1)},${PAD.top + plotH.value} Z`);

/** Bar geometry, for the volume chart that shares this grid. */
const barWidth = computed(() => {
    const slot = (plotW.value / Math.max(count.value, 1)) - 3;

    return Math.max(2, Math.min(20, slot) / Math.max(1, props.series.length));
});

/*
 * Side by side, never overlaid.
 *
 * Two series read against two different scales, so a taller bar does not
 * mean a bigger number -- drawing one in front of the other would invite
 * exactly that comparison.
 */
const barX = (i, slot) => x(i) - (barWidth.value * props.series.length) / 2 + slot * barWidth.value;

/*
 * Which dates get printed.
 *
 * Ninety labels overlap into a grey smear, so only as many as fit are drawn --
 * always including the last, since "up to when" is the one a reader looks for.
 */
const labelEvery = computed(() => Math.ceil(count.value / 8) || 1);

const showLabel = (i) => i === count.value - 1 || i % labelEvery.value === 0;

const shortDay = (day) => String(day).slice(5).replace('-', '/');

const hover = ref(null);

function track(event) {
    if (count.value === 0) {
        return;
    }

    const box = event.currentTarget.getBoundingClientRect();
    // The SVG is scaled to its container, so the pointer has to come back
    // through the same ratio before it means anything in plot coordinates.
    const at = ((event.clientX - box.left) / box.width) * W;
    const step = count.value <= 1 ? plotW.value : plotW.value / (count.value - 1);

    hover.value = Math.max(0, Math.min(count.value - 1, Math.round((at - PAD.left) / step)));
}

const tooltipSide = computed(() => (hover.value !== null && hover.value > count.value / 2 ? 'right' : 'left'));
</script>

<template>
    <div class="relative">
        <svg :viewBox="`0 0 ${W} ${H}`" class="w-full" :style="{ height: `${H}px` }" preserveAspectRatio="none">
            <!-- Value axis. Drawn behind everything, in the lightest ink that
                 still reads, so the data stays the thing being looked at. -->
            <g>
                <line
                    v-for="t in ticks"
                    :key="`grid-${t.y}`"
                    :x1="PAD.left"
                    :x2="W - padRight"
                    :y1="t.y"
                    :y2="t.y"
                    stroke="currentColor"
                    class="text-neutral-200"
                    stroke-width="1"
                />
            </g>

            <template v-if="kind === 'bars'">
                <g v-for="(s, slot) in series" :key="s.key">
                    <rect
                        v-for="(v, i) in s.values"
                        :key="`${s.key}-${i}`"
                        :x="barX(i, slot)"
                        :y="y(v, s.axis ?? 'left')"
                        :width="barWidth"
                        :height="Math.max(0, PAD.top + plotH - y(v, s.axis ?? 'left'))"
                        :fill="s.color"
                        rx="1.5"
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

                <path v-for="s in series" :key="`area-${s.key}`" :d="areaPath(s.values, s.axis ?? 'left')" :fill="`url(#fade-${s.key})`" />

                <path
                    v-for="s in series"
                    :key="`line-${s.key}`"
                    :d="linePath(s.values, s.axis ?? 'left')"
                    fill="none"
                    :stroke="s.color"
                    stroke-width="2"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                    vector-effect="non-scaling-stroke"
                />
            </template>

            <!-- Crosshair: one line, every series read at once, rather than a
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
                    :key="`dot-${s.key}`"
                    :cx="x(hover)"
                    :cy="y(s.values[hover], s.axis ?? 'left')"
                    r="3.5"
                    :fill="s.color"
                    stroke="#fff"
                    stroke-width="1.5"
                />
            </g>

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

        <!--
            Axis text lives in HTML, not SVG.

            preserveAspectRatio="none" stretches the drawing to the container,
            which would stretch letterforms with it.
        -->
        <div class="pointer-events-none absolute inset-0">
            <span
                v-for="t in ticks"
                :key="`tick-l-${t.y}`"
                class="absolute -translate-y-1/2 text-[10.5px] tabular-nums"
                :style="{ top: `${(t.y / H) * 100}%`, left: 0, color: hasRight ? axisColour.left : undefined }"
                :class="hasRight ? '' : 'text-neutral-400'"
            >{{ format(Math.round(t.left)) }}</span>

            <!-- The second scale, written in its own series' colour so a bar
                 is never read against the wrong numbers. -->
            <span
                v-for="t in ticks"
                v-show="hasRight"
                :key="`tick-r-${t.y}`"
                class="absolute -translate-y-1/2 text-[10.5px] tabular-nums"
                :style="{ top: `${(t.y / H) * 100}%`, right: 0, color: axisColour.right }"
            >{{ format(Math.round(t.right)) }}</span>

            <span
                v-for="(day, i) in labels"
                v-show="showLabel(i)"
                :key="`day-${day}`"
                class="absolute -translate-x-1/2 text-[10.5px] tabular-nums text-neutral-400"
                :style="{ left: `${(x(i) / W) * 100}%`, bottom: '2px' }"
            >{{ shortDay(day) }}</span>
        </div>

        <div
            v-if="hover !== null"
            class="pointer-events-none absolute top-2 rounded-lg bg-neutral-900 px-2.5 py-1.5 text-[11.5px] text-white shadow-lg"
            :class="tooltipSide === 'right' ? 'right-2' : 'left-12'"
        >
            <p class="mb-0.5 font-medium">{{ labels[hover] }}</p>
            <p v-for="s in series" :key="`tip-${s.key}`" class="flex items-center gap-1.5 whitespace-nowrap">
                <span class="h-1.5 w-1.5 rounded-full" :style="{ background: s.color }"></span>
                {{ s.label }}
                <span class="ml-auto pl-2 font-medium tabular-nums">{{ format(s.values[hover]) }}</span>
            </p>
        </div>
    </div>
</template>
