<script setup>
import { computed, ref } from 'vue';
import ChartCard from '../components/ChartCard.vue';
import LineChart from '../components/charts/LineChart.vue';
import RetentionHeatmap from '../components/charts/RetentionHeatmap.vue';
import { api } from '../lib/api';
import { number, percent } from '../lib/format';
import { game, range, state } from '../lib/store';
import { useReport } from '../lib/useReport';

const maxDay = ref(14);

const { data, loading, error } = useReport(
    () => (game.value ? { id: game.value.id, ...range.value, platform: state.platform, max_day: maxDay.value } : null),
    ({ id, ...params }, signal) => api.get(`/games/${id}/retention`, params, { signal }),
);

const milestones = computed(() => {
    const days = [1, 7, maxDay.value === 7 ? null : maxDay.value].filter(Boolean);
    return days.map((day) => ({ day, ...(data.value?.average[day] ?? { rate: null, cohorts: 0 }) }));
});

const curve = computed(() => [
    {
        key: 'avg',
        label: 'Weighted average retention',
        color: 'var(--series-1)',
        points: (data.value?.average ?? []).filter((a) => a.day > 0 && a.rate !== null).map((a) => ({ date: a.day, value: a.rate })),
    },
]);
</script>

<template>
    <div class="space-y-5">
        <p v-if="error" class="card p-4 text-sm text-bad" role="alert">Couldn't load retention: {{ error.message }}</p>

        <div class="grid grid-cols-1 gap-3 transition-opacity sm:grid-cols-3" :class="loading && data ? 'opacity-50' : ''">
            <div v-for="m in milestones" :key="m.day" class="card p-4">
                <div class="text-sm text-ink-2">Day {{ m.day }} retention</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight text-ink">{{ data ? percent(m.rate) : '…' }}</div>
                <div class="mt-1 text-xs text-muted">
                    <template v-if="m.cohorts">Across {{ m.cohorts }} cohort{{ m.cohorts === 1 ? '' : 's' }} old enough to measure</template>
                    <template v-else>No cohort in range is {{ m.day }} days old yet</template>
                </div>
            </div>
        </div>

        <ChartCard title="Cohort retention" :loading="loading" :table-toggle="false">
            <template #subtitle>
                Players grouped by first-seen day (UTC); each cell is the share active again exactly N days later.
                <template v-if="state.platform"> Platform: {{ state.platform }}.</template>
            </template>
            <template #actions>
                <label class="sr-only" for="max-day">Days to show</label>
                <select id="max-day" v-model.number="maxDay" class="field !py-1 text-xs">
                    <option :value="7">7 days</option>
                    <option :value="14">14 days</option>
                    <option :value="30">30 days</option>
                </select>
            </template>
            <RetentionHeatmap v-if="data" :cohorts="data.cohorts" :average="data.average" :max-day="data.max_day" />
        </ChartCard>

        <ChartCard v-if="data && curve[0].points.length" title="Retention curve" subtitle="Weighted average of every cohort old enough to observe each day" :loading="loading">
            <LineChart
                :series="curve"
                :format="(v) => percent(v, 0)"
                :x-format="(d) => `D${d}`"
                :x-title-format="(d) => `Day ${d}`"
                area
                aria-label="Average retention by days since first seen"
            />
            <template #table>
                <table class="data-table">
                    <thead><tr><th>Day</th><th class="text-right">Retention</th><th class="text-right">Cohorts measured</th></tr></thead>
                    <tbody>
                        <tr v-for="a in data.average.filter((a) => a.day > 0)" :key="a.day">
                            <td>D{{ a.day }}</td>
                            <td class="text-right">{{ percent(a.rate) }}</td>
                            <td class="text-right">{{ number(a.cohorts) }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </ChartCard>
    </div>
</template>
