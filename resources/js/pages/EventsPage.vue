<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import ChartCard from '../components/ChartCard.vue';
import LineChart from '../components/charts/LineChart.vue';
import { SERIES_COLORS, platformColor } from '../components/charts/scale';
import { api } from '../lib/api';
import { dateTime, eventLabel, money, number, percent, shortDate } from '../lib/format';
import { game, range } from '../lib/store';
import { useReport } from '../lib/useReport';

const MAX_TRENDS = 4;

/** name -> colour slot. A name keeps its slot while selected, so toggling others never repaints it. */
const slots = reactive({});
const selected = computed(() => Object.keys(slots));

const { data, loading, error } = useReport(
    () => (game.value ? { id: game.value.id, ...range.value, names: selected.value } : null),
    ({ id, from, to, names }, signal) => api.get(`/games/${id}/events/report`, { from, to, names }, { signal }),
);

// Seed the selection with the server's default (most frequent names) on first load.
watch(data, (report) => {
    if (report && selected.value.length === 0) {
        report.trends.forEach((t, i) => (slots[t.name] = i));
    }
});

function toggle(name) {
    if (name in slots) {
        if (selected.value.length > 1) delete slots[name];
        return;
    }
    if (selected.value.length >= MAX_TRENDS) return;
    const used = new Set(Object.values(slots));
    slots[name] = [0, 1, 2, 3].find((s) => !used.has(s));
}

const trendSeries = computed(() =>
    (data.value?.trends ?? [])
        .filter((t) => t.name in slots)
        .map((t) => ({ key: t.name, label: eventLabel(t.name), color: SERIES_COLORS[slots[t.name]], points: t.points })),
);

// ---- Live event stream -------------------------------------------------------------------

const stream = ref([]);
const streamFilters = reactive({ name: '', player: '' });
const live = ref(true);
const expanded = ref(null);
const catalog = ref([]);
let timer = null;

async function loadStream({ append = false } = {}) {
    if (!game.value) return;
    const params = { name: streamFilters.name, player: streamFilters.player.trim() };
    if (append && stream.value.length) params.after_id = stream.value[0].id;

    const { data: events } = await api.get(`/games/${game.value.id}/events`, params);
    stream.value = append ? [...events, ...stream.value].slice(0, 200) : events;
}

function schedule() {
    clearInterval(timer);
    if (live.value) timer = setInterval(() => loadStream({ append: true }).catch(() => {}), 3000);
}

watch(() => [streamFilters.name, streamFilters.player], () => loadStream(), { immediate: true });
watch(live, schedule, { immediate: true });
onBeforeUnmount(() => clearInterval(timer));

if (game.value) api.get(`/games/${game.value.id}/events/catalog`).then((r) => (catalog.value = r.names));
</script>

<template>
    <div class="space-y-5">
        <p v-if="error" class="card p-4 text-sm text-bad" role="alert">Couldn't load events: {{ error.message }}</p>

        <ChartCard title="Daily volume" :loading="loading">
            <template #subtitle>Up to {{ MAX_TRENDS }} events. Pick them in the table below.</template>
            <LineChart v-if="trendSeries.length" :series="trendSeries" :format="number" :height="240" aria-label="Daily event counts for the selected events" />
            <template #table>
                <table class="data-table">
                    <thead>
                        <tr><th>Date</th><th v-for="s in trendSeries" :key="s.key" class="text-right">{{ s.label }}</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(p, i) in trendSeries[0]?.points ?? []" :key="p.date">
                            <td>{{ shortDate(p.date) }}</td>
                            <td v-for="s in trendSeries" :key="s.key" class="text-right">{{ number(s.points[i].value, { exact: true }) }}</td>
                        </tr>
                    </tbody>
                </table>
            </template>
        </ChartCard>

        <ChartCard v-if="data" title="Events in range" :subtitle="`${data.events.length} event types`" :loading="loading" :table-toggle="false">
            <div class="relative overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th class="w-8"><span class="sr-only">Charted</span></th>
                            <th>Event</th>
                            <th class="text-right">Count</th>
                            <th class="hidden text-right sm:table-cell">Share</th>
                            <th class="text-right">Players</th>
                            <th class="hidden text-right md:table-cell">Reach</th>
                            <th class="hidden text-right md:table-cell">Per player</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="e in data.events" :key="e.name" class="hover:bg-surface-2">
                            <td>
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 align-middle"
                                    :style="{ accentColor: e.name in slots ? SERIES_COLORS[slots[e.name]] : undefined }"
                                    :checked="e.name in slots"
                                    :disabled="!(e.name in slots) && selected.length >= MAX_TRENDS"
                                    :aria-label="`Chart ${e.name}`"
                                    @change="toggle(e.name)"
                                />
                            </td>
                            <td class="font-mono text-xs">
                                <span class="inline-flex items-center gap-1.5">
                                    <span v-if="e.name in slots" class="inline-block h-0.5 w-3 rounded-full" :style="{ background: SERIES_COLORS[slots[e.name]] }" aria-hidden="true" />
                                    {{ e.name }}
                                </span>
                            </td>
                            <td class="text-right">{{ number(e.total, { exact: true }) }}</td>
                            <td class="hidden text-right text-ink-2 sm:table-cell">{{ percent(e.share) }}</td>
                            <td class="text-right">{{ number(e.players, { exact: true }) }}</td>
                            <td class="hidden text-right text-ink-2 md:table-cell">{{ percent(e.reach) }}</td>
                            <td class="hidden text-right text-ink-2 md:table-cell">{{ e.per_player }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </ChartCard>

        <ChartCard title="Live event stream" subtitle="Newest first, as received by the ingestion API" :table-toggle="false">
            <template #actions>
                <label class="inline-flex items-center gap-1.5 text-xs text-ink-2">
                    <input v-model="live" type="checkbox" class="h-3.5 w-3.5" />
                    <span class="inline-flex items-center gap-1">
                        <span class="inline-block h-2 w-2 rounded-full" :class="live ? 'animate-pulse bg-good' : 'bg-muted'" aria-hidden="true" />
                        Live
                    </span>
                </label>
            </template>
            <div class="mb-3 flex flex-wrap gap-2">
                <select v-model="streamFilters.name" class="field" aria-label="Filter by event">
                    <option value="">All events</option>
                    <option v-for="n in catalog" :key="n" :value="n">{{ n }}</option>
                </select>
                <input v-model.lazy="streamFilters.player" class="field" placeholder="Exact player id…" aria-label="Filter by player id" />
            </div>
            <div class="max-h-[28rem] overflow-auto">
                <table class="data-table">
                    <thead class="sticky top-0 bg-surface">
                        <tr><th>Time (local)</th><th>Event</th><th>Player</th><th class="hidden sm:table-cell">Details</th></tr>
                    </thead>
                    <tbody>
                        <template v-for="e in stream" :key="e.id">
                            <tr class="cursor-pointer hover:bg-surface-2" @click="expanded = expanded === e.id ? null : e.id">
                                <td class="whitespace-nowrap text-xs text-ink-2">{{ dateTime(e.occurred_at) }}</td>
                                <td class="font-mono text-xs">{{ e.name }}</td>
                                <td class="text-xs">
                                    <router-link :to="{ name: 'player', params: { id: e.player.id } }" class="inline-flex items-center gap-1.5 text-accent-ink hover:underline" @click.stop>
                                        <span class="inline-block h-2 w-2 rounded-[2px]" :style="{ background: platformColor(e.player.platform) }" :title="e.player.platform" />
                                        {{ e.player.external_id }}
                                    </router-link>
                                </td>
                                <td class="hidden max-w-xs truncate font-mono text-xs text-muted sm:table-cell">
                                    <span v-if="e.value !== null" class="mr-2 font-sans font-medium text-ink">{{ money(e.value) }}</span>{{ JSON.stringify(e.properties) === '{}' ? '' : JSON.stringify(e.properties) }}
                                </td>
                            </tr>
                            <tr v-if="expanded === e.id">
                                <td colspan="4" class="bg-surface-2">
                                    <pre class="overflow-x-auto font-mono text-xs text-ink-2">{{ JSON.stringify(e, null, 2) }}</pre>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="!stream.length"><td colspan="4" class="py-6 text-center text-sm text-muted">No events match.</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-muted">
                Tip: run <code class="font-mono">php artisan analytics:simulate</code> to stream live traffic into this table.
            </p>
        </ChartCard>
    </div>
</template>
