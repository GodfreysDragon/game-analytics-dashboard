<script setup>
import { computed, ref } from 'vue';
import { duration, eventLabel, number, percent, signedPoints } from '../../lib/format';

/**
 * One row per step: the bar is players who reached it, the faint track behind it is
 * the previous step's level, so the gap between them is the drop-off. With `previous`
 * (the same funnel over the period before), a tick marks where each step stood then.
 */
const props = defineProps({
    steps: { type: Array, required: true },
    previous: { type: Array, default: null },
});

const hover = ref(null);
const entered = computed(() => Math.max(1, props.steps[0]?.players ?? 0));

/** Change in overall conversion vs the previous period, per step (null when not comparable). */
const changes = computed(() =>
    props.steps.map((step, i) => {
        const before = props.previous?.[i]?.conversion;
        return before === null || before === undefined || step.conversion === null ? null : step.conversion - before;
    }),
);

function changeClass(change) {
    if (change === null || Math.abs(change) < 0.0005) return 'text-muted';
    return change > 0 ? 'text-good' : 'text-bad';
}
</script>

<template>
    <ol class="space-y-3">
        <li
            v-for="(step, i) in steps"
            :key="i"
            class="grid grid-cols-[1.5rem_1fr] gap-x-2 sm:grid-cols-[1.5rem_11rem_1fr]"
            tabindex="0"
            @pointerenter="hover = i"
            @pointerleave="hover = null"
            @focus="hover = i"
            @blur="hover = null"
        >
            <span class="pt-0.5 text-xs text-muted tabular-nums">{{ i + 1 }}</span>
            <div class="min-w-0 pt-0.5 text-sm">
                <div class="truncate font-medium text-ink">{{ eventLabel(step.name) }}</div>
                <div class="text-xs text-muted">
                    <template v-if="i === 0">entered</template>
                    <template v-else>{{ percent(step.step_conversion) }} of previous · median {{ duration(step.median_seconds) }}</template>
                </div>
            </div>
            <div class="col-span-2 col-start-2 mt-1.5 sm:col-span-1 sm:col-start-3 sm:mt-0">
                <div class="flex items-center gap-3">
                    <div class="relative h-6 flex-1">
                        <div
                            v-if="i > 0"
                            class="absolute inset-y-0 left-0 rounded-r-[4px] bg-surface-2"
                            :style="{ width: `${(steps[i - 1].players / entered) * 100}%` }"
                        />
                        <div
                            class="absolute inset-y-0 left-0 rounded-r-[4px] transition-opacity"
                            :style="{ width: `${Math.max(0.4, (step.players / entered) * 100)}%`, background: 'var(--series-1)', opacity: hover === null || hover === i ? 1 : 0.6 }"
                        />
                        <div
                            v-if="i > 0 && previous?.[i]?.conversion != null"
                            class="absolute -inset-y-1 w-0.5 -translate-x-1/2 rounded-full bg-ink"
                            :style="{ left: `${previous[i].conversion * 100}%` }"
                            aria-hidden="true"
                        />
                    </div>
                    <div class="w-28 shrink-0 text-right text-sm tabular-nums">
                        <span class="font-semibold text-ink">{{ percent(step.conversion) }}</span>
                        <span class="ml-1 text-xs text-muted">{{ number(step.players) }}</span>
                        <div v-if="previous && i > 0" class="text-xs" :class="changeClass(changes[i])">
                            {{ signedPoints(changes[i]) ?? '–' }}<span class="sr-only"> vs the previous period ({{ percent(previous[i]?.conversion) }})</span>
                        </div>
                    </div>
                </div>
                <div v-if="i > 0 && hover === i" class="mt-1 text-xs text-ink-2">
                    {{ number(step.dropped, { exact: true }) }} players dropped before this step<template v-if="previous?.[i]">
                        · previous period {{ percent(previous[i].conversion) }} ({{ number(previous[i].players, { exact: true }) }} players)</template>
                </div>
            </div>
        </li>
    </ol>
</template>
