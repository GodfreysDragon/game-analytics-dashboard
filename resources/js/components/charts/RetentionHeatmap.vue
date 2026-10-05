<script setup>
import { computed, ref } from 'vue';
import { number, percent, shortDate } from '../../lib/format';

const props = defineProps({
    cohorts: { type: Array, required: true },
    average: { type: Array, required: true },
    maxDay: { type: Number, required: true },
});

/** Fixed bucket edges keep colours comparable across games and date ranges. */
const EDGES = [0.05, 0.1, 0.15, 0.2, 0.3, 0.4, 0.6];
const LEGEND = ['0–5%', '5–10%', '10–15%', '15–20%', '20–30%', '30–40%', '40–60%', '60%+'];

const days = computed(() => Array.from({ length: props.maxDay }, (_, i) => i + 1));
const hover = ref(null);

function bucket(rate) {
    const index = EDGES.findIndex((edge) => rate < edge);
    return index === -1 ? 7 : index;
}

const rows = computed(() => [...props.cohorts].reverse());

function describe(cohort, day) {
    const rate = cohort.rates[day];
    if (rate === null) return day > 0 && cohort.retained[day] === null ? 'Not happened yet' : 'No players';
    return `${percent(rate)} · ${number(cohort.retained[day], { exact: true })} of ${number(cohort.size, { exact: true })}`;
}
</script>

<template>
    <div>
        <div class="mb-2 h-5 text-xs text-ink-2" aria-live="polite">
            <template v-if="hover">
                Cohort <span class="font-medium text-ink">{{ shortDate(hover.cohort.date) }}</span>, day {{ hover.day }}:
                <span class="font-semibold text-ink">{{ describe(hover.cohort, hover.day) }}</span>
            </template>
            <template v-else>Hover a cell for counts. Newest cohorts at the top.</template>
        </div>
        <div class="heat overflow-x-auto">
            <table class="w-full border-separate text-xs tabular-nums" style="border-spacing: 2px">
                <thead>
                    <tr class="text-muted">
                        <th class="sticky left-0 bg-surface px-2 py-1 text-left font-medium">Cohort</th>
                        <th class="px-2 py-1 text-right font-medium">Players</th>
                        <th v-for="d in days" :key="d" class="min-w-11 px-1 py-1 text-center font-medium">D{{ d }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="font-medium">
                        <td class="sticky left-0 bg-surface px-2 py-1.5 text-ink">Weighted avg</td>
                        <td class="px-2 py-1.5 text-right text-ink-2">{{ number(cohorts.reduce((s, c) => s + c.size, 0)) }}</td>
                        <td v-for="d in days" :key="d" class="rounded-[3px] px-1 py-1.5 text-center" :class="average[d]?.rate === null ? 'text-muted' : `heat-${bucket(average[d].rate)}`">
                            {{ average[d]?.rate === null ? '' : percent(average[d].rate, 0) }}
                        </td>
                    </tr>
                    <tr v-for="cohort in rows" :key="cohort.date">
                        <td class="sticky left-0 bg-surface px-2 py-1 whitespace-nowrap text-ink-2">{{ shortDate(cohort.date) }}</td>
                        <td class="px-2 py-1 text-right text-ink-2">{{ number(cohort.size, { exact: true }) }}</td>
                        <td
                            v-for="d in days"
                            :key="d"
                            class="rounded-[3px] px-1 py-1 text-center outline-offset-0"
                            :class="cohort.rates[d] === null ? '' : `heat-${bucket(cohort.rates[d])}`"
                            :tabindex="cohort.rates[d] === null ? -1 : 0"
                            @pointerenter="hover = { cohort, day: d }"
                            @focus="hover = { cohort, day: d }"
                            @pointerleave="hover = null"
                            @blur="hover = null"
                        >
                            {{ cohort.rates[d] === null ? '' : percent(cohort.rates[d], 0) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="heat mt-3 flex flex-wrap items-center gap-1 text-[11px] text-muted" aria-label="Colour scale">
            <span class="mr-1">Retained</span>
            <span v-for="(label, i) in LEGEND" :key="label" class="inline-flex items-center gap-1 pr-1.5">
                <span class="inline-block h-3 w-3 rounded-[2px]" :class="`heat-${i}`" />{{ label }}
            </span>
            <span class="ml-2">Blank = day hasn't happened yet</span>
        </div>
    </div>
</template>
