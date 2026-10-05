<script setup>
import { reactive, ref } from 'vue';
import { platformColor } from '../components/charts/scale';
import { api } from '../lib/api';
import { money, number, timeAgo } from '../lib/format';
import { PLATFORMS, game } from '../lib/store';
import { useReport } from '../lib/useReport';

const filters = reactive({ q: '', platform: '', payers: false, sort: 'last_seen' });
const page = ref(1);

const { data, loading, error } = useReport(
    () => ({ id: game.value.id, ...filters, payers: filters.payers ? 1 : '', page: page.value }),
    ({ id, ...params }, signal) => api.get(`/games/${id}/players`, params, { signal }),
);

function setFilter(key, value) {
    filters[key] = value;
    page.value = 1;
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <input
                :value="filters.q"
                class="field w-56"
                placeholder="Player id starts with…"
                aria-label="Search players by id prefix"
                @change="setFilter('q', $event.target.value.trim())"
            />
            <select :value="filters.platform" class="field" aria-label="Platform" @change="setFilter('platform', $event.target.value)">
                <option value="">All platforms</option>
                <option v-for="p in PLATFORMS" :key="p" :value="p">{{ p }}</option>
            </select>
            <select :value="filters.sort" class="field" aria-label="Sort by" @change="setFilter('sort', $event.target.value)">
                <option value="last_seen">Recently active</option>
                <option value="first_seen">Newest players</option>
                <option value="ltv">Highest spend</option>
                <option value="events">Most events</option>
                <option value="sessions">Most sessions</option>
            </select>
            <label class="inline-flex items-center gap-1.5 text-sm text-ink-2">
                <input type="checkbox" :checked="filters.payers" class="h-4 w-4" @change="setFilter('payers', $event.target.checked)" />
                Paying only
            </label>
            <span v-if="data" class="ml-auto text-xs text-muted">{{ number(data.meta.total, { exact: true }) }} players</span>
        </div>

        <p v-if="error" class="card p-4 text-sm text-bad" role="alert">{{ error.message }}</p>

        <div class="card overflow-x-auto transition-opacity" :class="loading && data ? 'opacity-50' : ''">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Player</th>
                        <th class="hidden sm:table-cell">Country</th>
                        <th class="hidden md:table-cell">Version</th>
                        <th class="text-right">Sessions</th>
                        <th class="hidden text-right sm:table-cell">Events</th>
                        <th class="text-right">Spend</th>
                        <th class="text-right">Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in data?.data ?? []" :key="p.id" class="hover:bg-surface-2">
                        <td>
                            <router-link :to="{ name: 'player', params: { id: p.id } }" class="inline-flex items-center gap-2 font-mono text-xs text-accent-ink hover:underline">
                                <span class="inline-block h-2.5 w-2.5 rounded-[2px]" :style="{ background: platformColor(p.platform) }" :title="p.platform ?? 'unknown platform'" />
                                {{ p.external_id }}
                            </router-link>
                        </td>
                        <td class="hidden sm:table-cell">{{ p.country ?? '–' }}</td>
                        <td class="hidden font-mono text-xs md:table-cell">{{ p.app_version ?? '–' }}</td>
                        <td class="text-right">{{ number(p.session_count, { exact: true }) }}</td>
                        <td class="hidden text-right sm:table-cell">{{ number(p.event_count, { exact: true }) }}</td>
                        <td class="text-right">{{ p.lifetime_value > 0 ? money(p.lifetime_value) : '–' }}</td>
                        <td class="text-right whitespace-nowrap text-ink-2">{{ timeAgo(p.last_seen_at) }}</td>
                    </tr>
                    <tr v-if="data && !data.data.length"><td colspan="7" class="py-8 text-center text-muted">No players match.</td></tr>
                </tbody>
            </table>
        </div>

        <nav v-if="data && data.meta.last_page > 1" class="flex items-center justify-between text-sm" aria-label="Pagination">
            <button type="button" class="btn" :disabled="page <= 1" @click="page--">← Previous</button>
            <span class="text-ink-2">Page {{ data.meta.current_page }} of {{ data.meta.last_page }}</span>
            <button type="button" class="btn" :disabled="page >= data.meta.last_page" @click="page++">Next →</button>
        </nav>
    </div>
</template>
