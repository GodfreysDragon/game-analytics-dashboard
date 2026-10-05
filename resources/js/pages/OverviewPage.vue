<script setup>
import { computed } from 'vue';
import ChartCard from '../components/ChartCard.vue';
import BarList from '../components/charts/BarList.vue';
import ColumnChart from '../components/charts/ColumnChart.vue';
import LineChart from '../components/charts/LineChart.vue';
import StatTile from '../components/charts/StatTile.vue';
import { platformColor } from '../components/charts/scale';
import { api } from '../lib/api';
import { duration, longDate, money, number, percent, shortDate } from '../lib/format';
import { game, range } from '../lib/store';
import { useReport } from '../lib/useReport';

const { data, loading, error } = useReport(
    () => (game.value ? { id: game.value.id, ...range.value } : null),
    ({ id, from, to }, signal) => api.get(`/games/${id}/overview`, { from, to }, { signal }),
);

const TILES = [
    { key: 'active_players', label: 'Active players', format: number, trend: 'dau' },
    { key: 'new_players', label: 'New players', format: number, trend: 'new_players' },
    { key: 'revenue', label: 'Revenue', format: (v) => money(v), trend: 'revenue' },
    { key: 'avg_session_seconds', label: 'Avg session length', format: duration, trend: 'avg_session_seconds' },
    { key: 'avg_dau', label: 'Avg daily active', format: number },
    { key: 'stickiness', label: 'Stickiness (avg DAU ÷ active)', format: (v) => percent(v) },
    { key: 'arpdau', label: 'ARPDAU', format: (v) => money(v) },
    { key: 'paying_players', label: 'Paying players', format: number },
];

const kpis = computed(() => Object.fromEntries((data.value?.kpis ?? []).map((k) => [k.key, k])));
const previousLabel = computed(() => (data.value ? `prior ${data.value.range.days} days` : 'previous period'));

const dauSeries = computed(() => [{ key: 'dau', label: 'Daily active players', color: 'var(--series-1)', points: data.value?.series.dau ?? [] }]);

const totalNew = computed(() => (data.value?.series.new_players ?? []).reduce((s, p) => s + p.value, 0));
const totalRevenue = computed(() => kpis.value.revenue?.value ?? 0);
</script>

<template>
    <div class="space-y-5">
        <p v-if="error" class="card border-critical/40 p-4 text-sm text-bad" role="alert">Couldn't load the overview: {{ error.message }}</p>

        <div class="grid grid-cols-2 gap-3 transition-opacity lg:grid-cols-4" :class="loading && data ? 'opacity-50' : ''">
            <StatTile
                v-for="tile in TILES"
                :key="tile.key"
                :label="tile.label"
                :value="kpis[tile.key] ? tile.format(kpis[tile.key].value) : '…'"
                :change="kpis[tile.key]?.change ?? null"
                :compared-to="previousLabel"
                :trend="tile.trend && data ? data.series[tile.trend].map((p) => p.value) : null"
            />
        </div>

        <ChartCard v-if="data" title="Daily active players" :subtitle="`${longDate(data.range.from)} – ${longDate(data.range.to)} (UTC). Today is still in progress.`" :loading="loading">
            <LineChart :series="dauSeries" :format="number" area aria-label="Daily active players over time" />
            <template #table>
                <table class="data-table">
                    <thead><tr><th>Date</th><th class="text-right">Active players</th><th class="text-right">Sessions</th><th class="text-right">Avg session</th></tr></thead>
                    <tbody>
                        <tr v-for="(p, i) in data.series.dau" :key="p.date">
                            <td>{{ shortDate(p.date) }}</td>
                            <td class="text-right">{{ number(p.value, { exact: true }) }}</td>
                            <td class="text-right">{{ number(data.series.sessions[i].value, { exact: true }) }}</td>
                            <td class="text-right">{{ duration(data.series.avg_session_seconds[i].value) }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </ChartCard>

        <div v-if="data" class="grid gap-5 lg:grid-cols-2">
            <ChartCard title="New players per day" :subtitle="`${number(totalNew, { exact: true })} first seen in range`" :loading="loading">
                <ColumnChart :points="data.series.new_players" :format="number" aria-label="New players per day" />
                <template #table>
                    <table class="data-table">
                        <thead><tr><th>Date</th><th class="text-right">New players</th></tr></thead>
                        <tbody><tr v-for="p in data.series.new_players" :key="p.date"><td>{{ shortDate(p.date) }}</td><td class="text-right">{{ p.value }}</td></tr></tbody>
                    </table>
                </template>
            </ChartCard>

            <ChartCard title="Revenue per day" :subtitle="`${money(totalRevenue)} from purchase events`" :loading="loading">
                <ColumnChart :points="data.series.revenue" :format="(v) => money(v, { cents: false })" aria-label="Revenue per day" />
                <template #table>
                    <table class="data-table">
                        <thead><tr><th>Date</th><th class="text-right">Revenue</th></tr></thead>
                        <tbody><tr v-for="p in data.series.revenue" :key="p.date"><td>{{ shortDate(p.date) }}</td><td class="text-right">{{ money(p.value) }}</td></tr></tbody>
                    </table>
                </template>
            </ChartCard>

            <ChartCard title="Active players by platform" subtitle="Share of players active in the range" :loading="loading" :table-toggle="false">
                <BarList
                    :rows="data.platforms.map((p) => ({ key: p.label, label: p.label, value: p.share, note: `${number(p.players, { exact: true })} players`, color: platformColor(p.label) }))"
                    :format="(v) => percent(v)"
                    :max="1"
                >
                    <template #label="{ row }">
                        <span class="inline-flex items-center gap-1.5">
                            <span class="inline-block h-2.5 w-2.5 rounded-[2px]" :style="{ background: row.color }" aria-hidden="true" />{{ row.label }}
                        </span>
                    </template>
                </BarList>
            </ChartCard>

            <ChartCard title="Top countries" subtitle="Active players in the range" :loading="loading" :table-toggle="false">
                <BarList :rows="data.countries.map((c) => ({ key: c.label, label: c.label, value: c.players, note: percent(c.share) }))" :format="number" />
            </ChartCard>
        </div>
    </div>
</template>
