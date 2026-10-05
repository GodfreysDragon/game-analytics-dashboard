<script setup>
import { computed, reactive, ref, watch } from 'vue';
import ChartCard from '../components/ChartCard.vue';
import FunnelChart from '../components/charts/FunnelChart.vue';
import { platformColor } from '../components/charts/scale';
import { api } from '../lib/api';
import { duration, eventLabel, minutesLabel, number, percent } from '../lib/format';
import { game, range, state } from '../lib/store';
import { useReport } from '../lib/useReport';

const WINDOWS = [5, 15, 30, 60, 120, 360, 1440, 4320, 10080];
const MAX_STEPS = 8;

const funnels = ref([]);
const catalog = ref([]);
const draft = reactive({ id: null, name: '', steps: [], window_minutes: 60 });
const saving = ref(false);
const formError = ref('');

const saved = computed(() => funnels.value.find((f) => f.id === draft.id) ?? null);
const dirty = computed(
    () =>
        !saved.value ||
        saved.value.name !== draft.name ||
        saved.value.window_minutes !== draft.window_minutes ||
        JSON.stringify(saved.value.steps) !== JSON.stringify(draft.steps),
);
const definitionValid = computed(() => draft.steps.length >= 2 && draft.steps.every(Boolean));

function edit(funnel) {
    Object.assign(draft, { id: funnel?.id ?? null, name: funnel?.name ?? '', steps: [...(funnel?.steps ?? ['first_open', 'tutorial_complete'])], window_minutes: funnel?.window_minutes ?? 60 });
    formError.value = '';
}

async function loadFunnels(selectId = draft.id) {
    const { data } = await api.get(`/games/${game.value.id}/funnels`);
    funnels.value = data;
    edit(data.find((f) => f.id === selectId) ?? data[0] ?? null);
}

loadFunnels();
api.get(`/games/${game.value.id}/events/catalog`).then((r) => (catalog.value = r.names));

/** Unsaved edits are evaluated through the preview endpoint, so results follow the builder live. */
const { data, loading, error } = useReport(
    () => {
        if (!definitionValid.value) return null;
        const filters = { ...range.value, platform: state.platform };
        return !dirty.value
            ? { url: `/games/${game.value.id}/funnels/${draft.id}/report`, params: filters }
            : { url: `/games/${game.value.id}/funnels/preview`, params: { ...filters, steps: draft.steps, window_minutes: draft.window_minutes } };
    },
    ({ url, params }, signal) => api.get(url, params, { signal }),
);

function move(index, delta) {
    const target = index + delta;
    [draft.steps[index], draft.steps[target]] = [draft.steps[target], draft.steps[index]];
}

async function save(asNew = false) {
    saving.value = true;
    formError.value = '';
    const body = { name: draft.name, steps: draft.steps, window_minutes: draft.window_minutes };
    try {
        const { data: funnel } = draft.id && !asNew
            ? await api.put(`/games/${game.value.id}/funnels/${draft.id}`, body)
            : await api.post(`/games/${game.value.id}/funnels`, body);
        await loadFunnels(funnel.id);
    } catch (e) {
        formError.value = Object.values(e.errors ?? {})[0]?.[0] ?? e.message;
    } finally {
        saving.value = false;
    }
}

async function remove() {
    if (!draft.id || !confirm(`Delete the "${saved.value.name}" funnel?`)) return;
    await api.delete(`/games/${game.value.id}/funnels/${draft.id}`);
    await loadFunnels(null);
}

const summary = computed(() => {
    const steps = data.value?.steps ?? [];
    if (!steps.length) return null;
    const worst = steps.slice(1).reduce((w, s) => (w === null || (s.step_conversion ?? 1) < (w.step_conversion ?? 1) ? s : w), null);
    return { entered: steps[0].players, completed: steps[steps.length - 1].players, conversion: steps[steps.length - 1].conversion, worst };
});

watch(() => draft.steps.length, (n) => n > MAX_STEPS && draft.steps.splice(MAX_STEPS));
</script>

<template>
    <div class="grid gap-5 lg:grid-cols-[20rem_1fr]">
        <div class="space-y-5">
            <section class="card p-4">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-ink">Saved funnels</h2>
                    <button type="button" class="btn !px-2 !py-1 text-xs" @click="edit(null)">New</button>
                </div>
                <ul class="space-y-0.5">
                    <li v-for="f in funnels" :key="f.id">
                        <button
                            type="button"
                            class="w-full rounded-lg px-2.5 py-1.5 text-left text-sm hover:bg-surface-2"
                            :class="f.id === draft.id ? 'bg-surface-2 font-medium text-ink' : 'text-ink-2'"
                            @click="edit(f)"
                        >
                            {{ f.name }}
                            <span class="block text-xs font-normal text-muted">{{ f.steps.length }} steps · {{ minutesLabel(f.window_minutes) }}</span>
                        </button>
                    </li>
                    <li v-if="!funnels.length" class="px-2.5 py-1.5 text-sm text-muted">None yet.</li>
                </ul>
            </section>

            <form class="card space-y-4 p-4" @submit.prevent="save(false)">
                <h2 class="text-sm font-semibold text-ink">{{ draft.id ? 'Edit funnel' : 'New funnel' }}</h2>
                <label class="block space-y-1">
                    <span class="label">Name</span>
                    <input v-model="draft.name" class="field w-full" placeholder="e.g. Onboarding" maxlength="100" />
                </label>

                <fieldset class="space-y-2">
                    <legend class="label mb-1">Steps, in order</legend>
                    <div v-for="(step, i) in draft.steps" :key="i" class="flex items-center gap-1.5">
                        <span class="w-4 text-right text-xs text-muted tabular-nums">{{ i + 1 }}</span>
                        <select v-model="draft.steps[i]" class="field min-w-0 flex-1 font-mono !text-xs" :aria-label="`Step ${i + 1}`">
                            <option v-if="!catalog.includes(step)" :value="step">{{ step }}</option>
                            <option v-for="n in catalog" :key="n" :value="n">{{ n }}</option>
                        </select>
                        <button type="button" class="btn !px-1.5 !py-1 text-xs" :disabled="i === 0" :aria-label="`Move step ${i + 1} up`" @click="move(i, -1)">↑</button>
                        <button type="button" class="btn !px-1.5 !py-1 text-xs" :disabled="i === draft.steps.length - 1" :aria-label="`Move step ${i + 1} down`" @click="move(i, 1)">↓</button>
                        <button type="button" class="btn !px-1.5 !py-1 text-xs" :disabled="draft.steps.length <= 2" :aria-label="`Remove step ${i + 1}`" @click="draft.steps.splice(i, 1)">✕</button>
                    </div>
                    <button type="button" class="btn !py-1 text-xs" :disabled="draft.steps.length >= MAX_STEPS" @click="draft.steps.push(catalog[0] ?? '')">+ Add step</button>
                </fieldset>

                <label class="block space-y-1">
                    <span class="label">Conversion window (from the first step)</span>
                    <select v-model.number="draft.window_minutes" class="field w-full">
                        <option v-for="w in WINDOWS" :key="w" :value="w">{{ minutesLabel(w) }}</option>
                    </select>
                </label>

                <p v-if="formError" class="text-sm text-bad" role="alert">{{ formError }}</p>
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary" :disabled="saving || !dirty || !draft.name">{{ draft.id ? 'Save' : 'Save funnel' }}</button>
                    <button v-if="draft.id" type="button" class="btn" :disabled="saving || !draft.name" @click="save(true)">Save as new</button>
                    <button v-if="draft.id" type="button" class="btn ml-auto text-bad" @click="remove">Delete</button>
                </div>
                <p v-if="dirty && draft.id" class="text-xs text-muted">Unsaved changes. Results show the edited definition.</p>
            </form>
        </div>

        <div class="min-w-0 space-y-5">
            <p v-if="error" class="card p-4 text-sm text-bad" role="alert">Couldn't run the funnel: {{ error.message }}</p>

            <div v-if="summary" class="grid grid-cols-2 gap-3 transition-opacity xl:grid-cols-4" :class="loading ? 'opacity-50' : ''">
                <div class="card p-4"><div class="text-sm text-ink-2">Entered</div><div class="mt-2 text-2xl font-semibold text-ink">{{ number(summary.entered) }}</div></div>
                <div class="card p-4"><div class="text-sm text-ink-2">Completed</div><div class="mt-2 text-2xl font-semibold text-ink">{{ number(summary.completed) }}</div></div>
                <div class="card p-4"><div class="text-sm text-ink-2">Overall conversion</div><div class="mt-2 text-2xl font-semibold text-ink">{{ percent(summary.conversion) }}</div></div>
                <div class="card p-4">
                    <div class="text-sm text-ink-2">Biggest drop-off</div>
                    <div class="mt-2 truncate font-mono text-base font-semibold text-ink">{{ summary.worst?.name ?? '–' }}</div>
                    <div class="text-xs text-muted" v-if="summary.worst">{{ percent(1 - summary.worst.step_conversion) }} lost at this step</div>
                </div>
            </div>

            <ChartCard
                v-if="data"
                :title="draft.name || 'Untitled funnel'"
                :subtitle="`Ordered steps within ${minutesLabel(data.window_minutes)} of entering · entries ${data.range.from} to ${data.range.to}${state.platform ? ` · ${state.platform}` : ''}`"
                :loading="loading"
            >
                <FunnelChart :steps="data.steps" />
                <template #table>
                    <table class="data-table">
                        <thead>
                            <tr><th>#</th><th>Step</th><th class="text-right">Players</th><th class="text-right">Overall</th><th class="text-right">From previous</th><th class="text-right">Median time</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="(s, i) in data.steps" :key="i">
                                <td>{{ i + 1 }}</td>
                                <td class="font-mono text-xs">{{ s.name }}</td>
                                <td class="text-right">{{ number(s.players, { exact: true }) }}</td>
                                <td class="text-right">{{ percent(s.conversion) }}</td>
                                <td class="text-right">{{ i ? percent(s.step_conversion) : '–' }}</td>
                                <td class="text-right">{{ i ? duration(s.median_seconds) : '–' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </template>
            </ChartCard>
            <div v-else-if="!definitionValid" class="card p-8 text-center text-sm text-muted">Add at least two steps to see results.</div>

            <ChartCard v-if="data && data.breakdown.length > 1 && !state.platform" title="Conversion by platform" subtitle="Entered → completed, per platform" :loading="loading" :table-toggle="false">
                <div class="overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Platform</th>
                                <th v-for="(s, i) in data.steps" :key="i" class="text-right">
                                    <span class="font-mono">{{ i + 1 }}</span><span class="sr-only"> {{ eventLabel(s.name) }}</span>
                                </th>
                                <th class="w-1/3">Overall conversion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in data.breakdown" :key="row.label">
                                <td>
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="inline-block h-2.5 w-2.5 rounded-[2px]" :style="{ background: platformColor(row.label) }" aria-hidden="true" />{{ row.label }}
                                    </span>
                                </td>
                                <td v-for="(count, i) in row.counts" :key="i" class="text-right text-ink-2">{{ number(count) }}</td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <div class="h-2.5 flex-1">
                                            <div class="h-full rounded-r-[4px]" :style="{ width: `${(row.conversion ?? 0) * 100}%`, background: platformColor(row.label) }" />
                                        </div>
                                        <span class="w-12 text-right text-xs font-medium">{{ percent(row.conversion) }}</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </ChartCard>
        </div>
    </div>
</template>
