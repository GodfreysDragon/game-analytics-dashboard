<script setup>
import { computed, ref } from 'vue';
import { api } from '../lib/api';
import { number, timeAgo } from '../lib/format';
import { loadGames, state } from '../lib/store';

const newName = ref('');
const formError = ref('');
const revealed = ref(null); // { gameId, key } - plaintext keys are shown once, never stored client-side
const copied = ref(false);

const origin = window.location.origin;

const curl = computed(() => {
    const key = revealed.value?.key ?? 'gk_your_ingest_key';
    const now = new Date().toISOString();
    return `curl -X POST ${origin}/api/v1/events \\
  -H "Authorization: Bearer ${key}" \\
  -H "Content-Type: application/json" \\
  -d '{"events":[{"uuid":"${crypto.randomUUID()}","name":"level_complete","player_id":"player-123","session_id":"sess-1","timestamp":"${now}","platform":"pc","properties":{"level":4,"stars":3}}]}'`;
});

async function create() {
    formError.value = '';
    try {
        const response = await api.post('/games', { name: newName.value });
        revealed.value = { gameId: response.data.id, key: response.ingest_key };
        newName.value = '';
        await loadGames();
        state.gameId = response.data.id;
    } catch (e) {
        formError.value = e.errors?.name?.[0] ?? e.message;
    }
}

async function rotate(game) {
    if (!confirm(`Rotate the ingest key for ${game.name}? Clients using the current key will be rejected immediately.`)) return;
    const response = await api.post(`/games/${game.id}/rotate-key`);
    revealed.value = { gameId: game.id, key: response.ingest_key };
    await loadGames();
}

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        // Clipboard blocked: the text is selectable on screen.
    }
}
</script>

<template>
    <div class="space-y-5">
        <section class="card relative overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr><th>Game</th><th>Ingest key</th><th class="text-right">Players</th><th class="text-right">Last event</th><th><span class="sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="g in state.games" :key="g.id">
                        <td class="font-medium">{{ g.name }} <span class="ml-1 font-mono text-xs text-muted">{{ g.slug }}</span></td>
                        <td class="font-mono text-xs text-ink-2">{{ g.ingest_key_prefix }}••••••••</td>
                        <td class="text-right">{{ number(g.players_count, { exact: true }) }}</td>
                        <td class="text-right text-ink-2">{{ timeAgo(g.last_event_at) }}</td>
                        <td class="text-right"><button type="button" class="btn !py-1 text-xs" @click="rotate(g)">Rotate key</button></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <div v-if="revealed" class="card border-accent/50 p-4" role="status">
            <p class="text-sm font-medium text-ink">New ingest key for {{ state.games.find((g) => g.id === revealed.gameId)?.name }}</p>
            <p class="mt-1 text-xs text-ink-2">Copy it now. Only a hash is stored, so it can't be shown again.</p>
            <div class="mt-2 flex items-center gap-2">
                <code class="min-w-0 flex-1 truncate rounded-lg bg-surface-2 px-3 py-2 font-mono text-sm text-ink select-all">{{ revealed.key }}</code>
                <button type="button" class="btn" @click="copy(revealed.key)">{{ copied ? 'Copied' : 'Copy' }}</button>
            </div>
        </div>

        <form class="card flex flex-wrap items-end gap-3 p-4" @submit.prevent="create">
            <label class="block flex-1 space-y-1">
                <span class="label">Register a game</span>
                <input v-model="newName" class="field w-full" placeholder="Game name" required maxlength="100" />
            </label>
            <button type="submit" class="btn btn-primary">Create &amp; issue key</button>
            <p v-if="formError" class="w-full text-sm text-bad" role="alert">{{ formError }}</p>
        </form>

        <section class="card space-y-3 p-4">
            <h2 class="text-sm font-semibold text-ink">Sending events</h2>
            <p class="text-sm text-ink-2">
                POST batches of up to 500 events to <code class="font-mono text-xs">/api/v1/events</code>. Each event needs a client-generated
                <code class="font-mono text-xs">uuid</code>, so retrying a batch never double-counts. Timestamps older than the ingestion window (30 days by default) are rejected.
            </p>
            <pre class="overflow-x-auto rounded-lg bg-surface-2 p-3 font-mono text-xs text-ink-2">{{ curl }}</pre>
            <button type="button" class="btn text-xs" @click="copy(curl)">Copy command</button>
        </section>
    </div>
</template>
