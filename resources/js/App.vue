<script setup>
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import FilterBar from './components/FilterBar.vue';
import { api, setCsrfToken } from './lib/api';
import { DEMO, applyTheme, game, state } from './lib/store';

const route = useRoute();
const router = useRouter();
const menuOpen = ref(false);

const nav = [
    { name: 'overview', label: 'Overview', icon: 'M3 13h4v8H3zM10 9h4v12h-4zM17 4h4v17h-4z' },
    { name: 'events', label: 'Events', icon: 'M4 6h16M4 12h16M4 18h10' },
    { name: 'retention', label: 'Retention', icon: 'M4 4h4v4H4zM10 4h4v4h-4zM16 4h4v4h-4zM4 10h4v4H4zM10 10h4v4h-4zM4 16h4v4H4z' },
    { name: 'funnels', label: 'Funnels', icon: 'M3 5h18l-7 8v6l-4 2v-8z' },
    { name: 'players', label: 'Players', icon: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0' },
    { name: 'settings', label: 'Games & keys', icon: 'M15 7a4 4 0 1 1-3.9 4.9L4 19v-3l2-2v-2h2l1.1-1.1A4 4 0 0 1 15 7z' },
];

const signedIn = computed(() => state.user !== null && !route.meta.guest);
const themeLabel = computed(() => ({ system: 'Auto', light: 'Light', dark: 'Dark' })[state.theme]);

function cycleTheme() {
    applyTheme({ system: 'light', light: 'dark', dark: 'system' }[state.theme] ?? 'system');
}

async function logout() {
    const { csrf_token } = await api.post('/logout');
    setCsrfToken(csrf_token);
    state.user = null;
    router.push({ name: 'login' });
}
</script>

<template>
    <router-view v-if="!signedIn" />

    <div v-else class="min-h-full lg:grid lg:grid-cols-[14rem_1fr]">
        <aside
            class="fixed inset-y-0 left-0 z-30 w-56 -translate-x-full border-r border-line bg-surface transition-transform lg:static lg:translate-x-0"
            :class="menuOpen ? 'translate-x-0' : ''"
        >
            <div class="flex h-full flex-col p-4">
                <div class="mb-6 flex items-center gap-2 px-2">
                    <svg viewBox="0 0 24 24" class="h-6 w-6 text-accent" fill="currentColor" aria-hidden="true">
                        <path d="M12 2l2.4 6.9H22l-6 4.6 2.3 7.1L12 16.3l-6.3 4.3L8 13.5 2 8.9h7.6z" />
                    </svg>
                    <span class="font-semibold text-ink">Game Analytics</span>
                </div>
                <nav class="flex-1 space-y-0.5" aria-label="Main">
                    <router-link
                        v-for="item in nav"
                        :key="item.name"
                        :to="{ name: item.name }"
                        class="flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-ink-2 hover:bg-surface-2 hover:text-ink"
                        active-class="!bg-surface-2 !text-ink font-medium"
                        :exact-active-class="item.name === 'overview' ? '!bg-surface-2 !text-ink font-medium' : ''"
                        @click="menuOpen = false"
                    >
                        <svg viewBox="0 0 24 24" class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true">
                            <path :d="item.icon" />
                        </svg>
                        {{ item.label }}
                    </router-link>
                </nav>
                <div class="space-y-2 border-t border-line pt-4 text-sm">
                    <button type="button" class="flex w-full items-center justify-between rounded-lg px-2.5 py-1.5 text-ink-2 hover:bg-surface-2" @click="cycleTheme">
                        Theme <span class="text-xs text-muted">{{ themeLabel }}</span>
                    </button>
                    <div class="px-2.5 text-xs text-muted">{{ state.user.email }}</div>
                    <button type="button" class="w-full rounded-lg px-2.5 py-1.5 text-left text-ink-2 hover:bg-surface-2" @click="logout">Sign out</button>
                </div>
            </div>
        </aside>
        <div v-if="menuOpen" class="fixed inset-0 z-20 bg-black/30 lg:hidden" @click="menuOpen = false" />

        <div class="min-w-0">
            <header class="sticky top-0 z-10 border-b border-line bg-page/90 backdrop-blur">
                <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
                    <button type="button" class="btn !px-2 lg:hidden" aria-label="Open menu" @click="menuOpen = true">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
                    </button>
                    <h1 class="mr-auto text-lg font-semibold text-ink">{{ route.meta.title }}</h1>
                    <FilterBar />
                </div>
            </header>
            <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
                <p v-if="DEMO" class="mb-4 text-xs text-muted">
                    Read-only demo: the players are simulated, and new events arrive every few minutes. Saving and key changes are turned off.
                </p>
                <div v-if="!game && !route.meta.noGame" class="card p-8 text-center text-sm text-ink-2">
                    No games yet. <router-link :to="{ name: 'settings' }" class="text-accent-ink underline">Register a game</router-link> to get an ingest key.
                </div>
                <router-view v-else :key="`${state.gameId}`" />
            </main>
        </div>
    </div>
</template>
