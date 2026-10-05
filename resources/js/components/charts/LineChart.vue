<script setup>
import { computed, ref } from 'vue';
import { niceTicks, tickIndices, useWidth } from './scale';
import { shortDate, longDate } from '../../lib/format';

const props = defineProps({
    /** [{ key, label, color, points: [{ date, value }] }] - all series share the same dates */
    series: { type: Array, required: true },
    format: { type: Function, default: (v) => v },
    height: { type: Number, default: 220 },
    area: { type: Boolean, default: false },
    ariaLabel: { type: String, default: 'Line chart' },
    /** Axis tick and tooltip heading for each x value (dates by default). */
    xFormat: { type: Function, default: shortDate },
    xTitleFormat: { type: Function, default: longDate },
    /** Fixed y-axis maximum, e.g. 1 for rates. */
    yMaxValue: { type: Number, default: null },
});

const { el, width } = useWidth();
const hover = ref(null);

const showEndLabels = computed(() => props.series.length >= 2 && props.series.length <= 4);
const margin = computed(() => ({ top: 12, right: showEndLabels.value ? 104 : 16, bottom: 26, left: 48 }));
const plotW = computed(() => width.value - margin.value.left - margin.value.right);
const plotH = computed(() => props.height - margin.value.top - margin.value.bottom);

const dates = computed(() => props.series[0]?.points.map((p) => p.date) ?? []);
const max = computed(() => Math.max(0, ...props.series.flatMap((s) => s.points.map((p) => p.value ?? 0))));
const ticks = computed(() => niceTicks(props.yMaxValue ?? max.value));
const yMax = computed(() => ticks.value[ticks.value.length - 1] || 1);

const x = (i) => margin.value.left + (dates.value.length <= 1 ? plotW.value / 2 : (i / (dates.value.length - 1)) * plotW.value);
const y = (v) => margin.value.top + plotH.value - (v / yMax.value) * plotH.value;

const paths = computed(() =>
    props.series.map((s) => {
        const line = s.points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.value ?? 0).toFixed(1)}`).join('');
        const base = y(0).toFixed(1);
        const fill = s.points.length ? `${line}L${x(s.points.length - 1).toFixed(1)},${base}L${x(0).toFixed(1)},${base}Z` : '';
        return { ...s, line, fill };
    }),
);

const xTicks = computed(() => tickIndices(dates.value.length, Math.max(2, Math.floor(plotW.value / 90))));

/** End labels only when they don't collide; otherwise the legend and tooltip carry identity. */
const endLabels = computed(() => {
    if (!showEndLabels.value || !dates.value.length) return [];
    const last = dates.value.length - 1;
    const labels = props.series
        .map((s) => ({ key: s.key, label: s.label, color: s.color, y: y(s.points[last]?.value ?? 0) }))
        .sort((a, b) => a.y - b.y);
    const collides = labels.some((l, i) => i > 0 && l.y - labels[i - 1].y < 14);
    return collides ? [] : labels;
});

function indexAt(clientX) {
    const rect = el.value.getBoundingClientRect();
    const px = clientX - rect.left - margin.value.left;
    const ratio = Math.min(1, Math.max(0, px / Math.max(1, plotW.value)));
    return Math.round(ratio * (dates.value.length - 1));
}

function onMove(event) {
    if (dates.value.length) hover.value = indexAt(event.clientX);
}

function onKey(event) {
    const last = dates.value.length - 1;
    if (event.key === 'ArrowRight') hover.value = Math.min(last, (hover.value ?? -1) + 1);
    else if (event.key === 'ArrowLeft') hover.value = Math.max(0, (hover.value ?? last + 1) - 1);
    else if (event.key === 'Home') hover.value = 0;
    else if (event.key === 'End') hover.value = last;
    else return;
    event.preventDefault();
}

const tooltip = computed(() => {
    if (hover.value === null || !dates.value.length) return null;
    const i = hover.value;
    const left = x(i);
    return {
        date: props.xTitleFormat(dates.value[i]),
        left,
        flip: left > width.value - 200,
        rows: props.series.map((s) => ({ key: s.key, label: s.label, color: s.color, value: props.format(s.points[i]?.value ?? 0) })),
    };
});
</script>

<template>
    <div>
        <ul v-if="series.length >= 2" class="mb-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-ink-2">
            <li v-for="s in series" :key="s.key" class="inline-flex items-center gap-1.5">
                <span class="inline-block h-0.5 w-3.5 rounded-full" :style="{ background: s.color }" aria-hidden="true" />
                {{ s.label }}
            </li>
        </ul>
        <div ref="el" class="relative" :style="{ height: `${height}px` }">
            <svg
                :width="width"
                :height="height"
                class="block touch-pan-y outline-none"
                role="img"
                :aria-label="ariaLabel"
                tabindex="0"
                @pointermove="onMove"
                @pointerdown="onMove"
                @pointerleave="hover = null"
                @keydown="onKey"
                @blur="hover = null"
            >
                <g>
                    <line
                        v-for="t in ticks"
                        :key="t"
                        :x1="margin.left"
                        :x2="width - margin.right"
                        :y1="y(t)"
                        :y2="y(t)"
                        :stroke="t === 0 ? 'var(--axis)' : 'var(--grid)'"
                        stroke-width="1"
                        shape-rendering="crispEdges"
                    />
                    <text
                        v-for="t in ticks"
                        :key="`l${t}`"
                        :x="margin.left - 8"
                        :y="y(t)"
                        dy="0.32em"
                        text-anchor="end"
                        class="fill-muted text-[11px] tabular-nums"
                    >
                        {{ format(t) }}
                    </text>
                    <text
                        v-for="i in xTicks"
                        :key="`x${i}`"
                        :x="x(i)"
                        :y="height - 6"
                        :text-anchor="i === 0 ? 'start' : i === dates.length - 1 ? 'end' : 'middle'"
                        class="fill-muted text-[11px]"
                    >
                        {{ xFormat(dates[i]) }}
                    </text>
                </g>

                <g v-for="s in paths" :key="s.key">
                    <path v-if="area" :d="s.fill" :fill="s.color" fill-opacity="0.1" />
                    <path :d="s.line" fill="none" :stroke="s.color" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                </g>

                <g v-for="l in endLabels" :key="`e${l.key}`">
                    <text :x="width - margin.right + 10" :y="l.y" dy="0.32em" class="fill-ink-2 text-[11px]">{{ l.label }}</text>
                </g>

                <g v-if="tooltip">
                    <line :x1="tooltip.left" :x2="tooltip.left" :y1="margin.top" :y2="y(0)" stroke="var(--ink-2)" stroke-width="1" shape-rendering="crispEdges" />
                    <circle
                        v-for="s in series"
                        :key="`d${s.key}`"
                        :cx="tooltip.left"
                        :cy="y(s.points[hover]?.value ?? 0)"
                        r="4"
                        :fill="s.color"
                        stroke="var(--surface-1)"
                        stroke-width="2"
                    />
                </g>
            </svg>

            <div
                v-if="tooltip"
                class="pointer-events-none absolute top-1 z-10 min-w-36 rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg"
                :style="tooltip.flip ? { right: `${width - tooltip.left + 12}px` } : { left: `${tooltip.left + 12}px` }"
                role="status"
            >
                <div class="mb-1 text-muted">{{ tooltip.date }}</div>
                <div v-for="row in tooltip.rows" :key="row.key" class="flex items-center gap-2">
                    <span class="inline-block h-0.5 w-3 rounded-full" :style="{ background: row.color }" aria-hidden="true" />
                    <span class="font-semibold text-ink tabular-nums">{{ row.value }}</span>
                    <span v-if="series.length > 1" class="text-ink-2">{{ row.label }}</span>
                </div>
            </div>
        </div>
    </div>
</template>
