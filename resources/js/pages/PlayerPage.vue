<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import ChartCard from '../components/ChartCard.vue';
import BarList from '../components/charts/BarList.vue';
import { platformColor } from '../components/charts/scale';
import { api } from '../lib/api';
import { dateTime, duration, isoDay, money, number, shortDate } from '../lib/format';
import { game } from '../lib/store';
import { useReport } from '../lib/useReport';

const route = useRoute();

const { data, error } = useReport(
    () => (route.params.id ? { id: game.value.id, player: route.params.id } : null),
    ({ id, player }, signal) => api.get(`/games/${id}/players/${player}`, {}, { signal }),
);

const timeline = ref([]);
const nextCursor = ref(null);
const nameFilter = ref('');

async function loadTimeline(cursor = null) {
    const response = await api.get(`/games/${game.value.id}/players/${route.params.id}/events`, { cursor, name: nameFilter.value });
    timeline.value = cursor ? [...timeline.value, ...response.data] : response.data;
    nextCursor.value = response.meta.next_cursor;
}

watch([() => route.params.id, nameFilter], () => route.params.id && loadTimeline(), { immediate: true });

/** Activity strip: one square per day since first seen, filled when the player was active. */
const activity = computed(() => {
    if (!data.value) return [];
    const active = new Set(data.value.active_days);
    const start = new Date(`${data.value.player.first_seen_at.slice(0, 10)}T00:00:00Z`);
    const days = [];
    for (let d = new Date(start); d <= new Date() && days.length < 120; d.setUTCDate(d.getUTCDate() + 1)) {
        const key = isoDay(d);
        days.push({ date: key, active: active.has(key) });
    }
    return days;
});
</script>

<template>
    <div class="space-y-5">
        <router-link :to="{ name: 'players' }" class="text-sm text-accent-ink hover:underline">← All players</router-link>
        <p v-if="error" class="card p-4 text-sm text-bad" role="alert">{{ error.status === 404 ? 'Player not found in this game.' : error.message }}</p>

        <template v-if="data">
            <section class="card flex flex-wrap items-center gap-x-8 gap-y-3 p-5">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="inline-block h-3 w-3 rounded-[3px]" :style="{ background: platformColor(data.player.platform) }" aria-hidden="true" />
                        <h2 class="font-mono text-lg font-semibold text-ink">{{ data.player.external_id }}</h2>
                    </div>
                    <p class="mt-1 text-sm text-ink-2">
                        {{ data.player.platform ?? 'unknown platform' }} · {{ data.player.country ?? '??' }} · v{{ data.player.app_version ?? '?' }}
                    </p>
                </div>
                <dl class="grid flex-1 grid-cols-2 gap-4 text-sm sm:grid-cols-5">
                    <div><dt class="text-xs text-muted">First seen</dt><dd class="font-medium text-ink">{{ dateTime(data.player.first_seen_at) }}</dd></div>
                    <div><dt class="text-xs text-muted">Last seen</dt><dd class="font-medium text-ink">{{ dateTime(data.player.last_seen_at) }}</dd></div>
                    <div><dt class="text-xs text-muted">Sessions</dt><dd class="font-medium text-ink">{{ number(data.player.session_count) }} · avg {{ duration(data.avg_session_seconds) }}</dd></div>
                    <div><dt class="text-xs text-muted">Events</dt><dd class="font-medium text-ink">{{ number(data.player.event_count) }}</dd></div>
                    <div><dt class="text-xs text-muted">Lifetime spend</dt><dd class="font-medium text-ink">{{ money(data.player.lifetime_value) }}</dd></div>
                </dl>
            </section>

            <ChartCard title="Activity" :subtitle="`Active ${data.active_days.length} of ${activity.length} days since first seen (UTC)`" :table-toggle="false">
                <div class="flex flex-wrap gap-[3px]" role="list">
                    <span
                        v-for="d in activity"
                        :key="d.date"
                        role="listitem"
                        class="h-3.5 w-3.5 rounded-[3px]"
                        :style="{ background: d.active ? 'var(--series-1)' : 'var(--seq-0)' }"
                        :title="`${shortDate(d.date)}: ${d.active ? 'active' : 'inactive'}`"
                        :aria-label="`${shortDate(d.date)}: ${d.active ? 'active' : 'inactive'}`"
                    />
                </div>
            </ChartCard>

            <div class="grid gap-5 lg:grid-cols-[1fr_2fr]">
                <ChartCard title="Event mix" subtitle="All-time events by name" :table-toggle="false">
                    <BarList :rows="data.event_mix.map((e) => ({ key: e.name, label: e.name, value: e.total }))" :format="number" />
                </ChartCard>

                <ChartCard title="Recent sessions" :table-toggle="false">
                    <div class="overflow-x-auto">
                        <table class="data-table">
                            <thead><tr><th>Started</th><th class="text-right">Length</th><th class="text-right">Events</th></tr></thead>
                            <tbody>
                                <tr v-for="s in data.sessions" :key="s.id">
                                    <td class="text-ink-2">{{ dateTime(s.started_at) }}</td>
                                    <td class="text-right">{{ duration(s.duration_seconds) }}</td>
                                    <td class="text-right">{{ s.event_count }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </ChartCard>
            </div>

            <ChartCard title="Event timeline" subtitle="Newest first" :table-toggle="false">
                <template #actions>
                    <select v-model="nameFilter" class="field !py-1 text-xs" aria-label="Filter timeline by event">
                        <option value="">All events</option>
                        <option v-for="e in data.event_mix" :key="e.name" :value="e.name">{{ e.name }}</option>
                    </select>
                </template>
                <ol class="divide-y divide-line">
                    <li v-for="e in timeline" :key="e.id" class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 py-2 text-sm">
                        <span class="w-36 shrink-0 text-xs text-ink-2 tabular-nums">{{ dateTime(e.occurred_at) }}</span>
                        <span class="font-mono text-xs font-medium text-ink">{{ e.name }}</span>
                        <span v-if="e.value !== null" class="text-xs font-medium text-good">{{ money(e.value) }}</span>
                        <span class="min-w-0 flex-1 truncate font-mono text-xs text-muted">{{ JSON.stringify(e.properties) === '{}' ? '' : JSON.stringify(e.properties) }}</span>
                    </li>
                </ol>
                <button v-if="nextCursor" type="button" class="btn mt-3" @click="loadTimeline(nextCursor)">Load older events</button>
            </ChartCard>
        </template>
    </div>
</template>
