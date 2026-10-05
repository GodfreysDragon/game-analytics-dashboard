<script setup>
import { computed, ref } from 'vue';
import { niceTicks, tickIndices, useWidth } from './scale';
import { shortDate, longDate } from '../../lib/format';

const props = defineProps({
    /** [{ date, value }] */
    points: { type: Array, required: true },
    format: { type: Function, default: (v) => v },
    color: { type: String, default: 'var(--series-1)' },
    height: { type: Number, default: 200 },
    ariaLabel: { type: String, default: 'Column chart' },
});

const { el, width } = useWidth();
const hover = ref(null);
const margin = { top: 18, right: 8, bottom: 26, left: 48 };

const plotW = computed(() => width.value - margin.left - margin.right);
const plotH = computed(() => props.height - margin.top - margin.bottom);
const max = computed(() => Math.max(0, ...props.points.map((p) => p.value ?? 0)));
const ticks = computed(() => niceTicks(max.value));
const yMax = computed(() => ticks.value[ticks.value.length - 1] || 1);
const slot = computed(() => plotW.value / Math.max(1, props.points.length));
const barW = computed(() => Math.max(2, Math.min(24, slot.value - 2)));

const y = (v) => margin.top + plotH.value - (v / yMax.value) * plotH.value;
const cx = (i) => margin.left + slot.value * (i + 0.5);

/** Column with a 4px rounded data end and a square baseline. */
function columnPath(i, value) {
    const w = barW.value;
    const x0 = cx(i) - w / 2;
    const top = y(value);
    const base = y(0);
    const r = Math.min(4, w / 2, base - top);
    if (base - top < 0.5) return '';
    return `M${x0},${base}V${top + r}Q${x0},${top} ${x0 + r},${top}H${x0 + w - r}Q${x0 + w},${top} ${x0 + w},${top + r}V${base}Z`;
}

const peak = computed(() => {
    let best = -1;
    props.points.forEach((p, i) => {
        if (p.value > 0 && (best < 0 || p.value > props.points[best].value)) best = i;
    });
    return best;
});

const xTicks = computed(() => tickIndices(props.points.length, Math.max(2, Math.floor(plotW.value / 90))));

function onKey(event) {
    const last = props.points.length - 1;
    if (event.key === 'ArrowRight') hover.value = Math.min(last, (hover.value ?? -1) + 1);
    else if (event.key === 'ArrowLeft') hover.value = Math.max(0, (hover.value ?? last + 1) - 1);
    else return;
    event.preventDefault();
}
</script>

<template>
    <div ref="el" class="relative" :style="{ height: `${height}px` }">
        <svg :width="width" :height="height" class="block outline-none" role="img" :aria-label="ariaLabel" tabindex="0" @keydown="onKey" @blur="hover = null">
            <line
                v-for="t in ticks"
                :key="t"
                :x1="margin.left"
                :x2="width - margin.right"
                :y1="y(t)"
                :y2="y(t)"
                :stroke="t === 0 ? 'var(--axis)' : 'var(--grid)'"
                shape-rendering="crispEdges"
            />
            <text v-for="t in ticks" :key="`l${t}`" :x="margin.left - 8" :y="y(t)" dy="0.32em" text-anchor="end" class="fill-muted text-[11px] tabular-nums">
                {{ format(t) }}
            </text>
            <text
                v-for="i in xTicks"
                :key="`x${i}`"
                :x="cx(i)"
                :y="height - 6"
                :text-anchor="i === 0 ? 'start' : i === points.length - 1 ? 'end' : 'middle'"
                class="fill-muted text-[11px]"
            >
                {{ shortDate(points[i].date) }}
            </text>

            <g v-for="(p, i) in points" :key="p.date">
                <path :d="columnPath(i, p.value)" :fill="color" :opacity="hover === null || hover === i ? 1 : 0.55" />
                <rect
                    :x="cx(i) - slot / 2"
                    :y="margin.top"
                    :width="slot"
                    :height="plotH"
                    fill="transparent"
                    @pointerenter="hover = i"
                    @pointerleave="hover = null"
                />
            </g>
            <text v-if="peak >= 0 && hover === null" :x="cx(peak)" :y="y(points[peak].value) - 5" text-anchor="middle" class="fill-ink-2 text-[11px] font-medium tabular-nums">
                {{ format(points[peak].value) }}
            </text>
        </svg>

        <div
            v-if="hover !== null"
            class="pointer-events-none absolute top-0 z-10 rounded-lg border border-line bg-surface px-3 py-2 text-xs shadow-lg"
            :style="cx(hover) > width - 180 ? { right: `${width - cx(hover) + 14}px` } : { left: `${cx(hover) + 14}px` }"
            role="status"
        >
            <div class="font-semibold text-ink tabular-nums">{{ format(points[hover].value) }}</div>
            <div class="text-muted">{{ longDate(points[hover].date) }}</div>
        </div>
    </div>
</template>
