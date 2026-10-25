import { computed, reactive, watch } from 'vue';
import { api } from './api';
import { isoDay } from './format';

export const PRESETS = [
    { key: '7d', label: 'Last 7 days', days: 7 },
    { key: '14d', label: 'Last 14 days', days: 14 },
    { key: '30d', label: 'Last 30 days', days: 30 },
    { key: '60d', label: 'Last 60 days', days: 60 },
    { key: '90d', label: 'Last 90 days', days: 90 },
];

/** Hosted demo: the dashboard is read-only, so write actions are disabled in the UI too. */
export const DEMO = document.querySelector('meta[name="demo-mode"]')?.content === '1';

/** Mirrors config('analytics.platforms'), which the API validates against. */
export const PLATFORMS = ['ios', 'android', 'pc', 'web', 'console'];

function read(key, fallback) {
    try {
        return localStorage.getItem(key) ?? fallback;
    } catch {
        return fallback;
    }
}

function write(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {
        // Private mode or blocked storage: the preference just won't persist.
    }
}

export const state = reactive({
    user: null,
    games: [],
    gameId: Number(read('gameId', 0)) || null,
    preset: read('preset', '30d'),
    customFrom: null,
    customTo: null,
    platform: '',
    theme: read('theme', 'system'),
});

export const game = computed(() => state.games.find((g) => g.id === state.gameId) ?? state.games[0] ?? null);

/** The date filter as the API expects it. "Today" is the UTC calendar day. */
export const range = computed(() => {
    if (state.preset === 'custom' && state.customFrom && state.customTo) {
        return { from: state.customFrom, to: state.customTo };
    }
    const days = PRESETS.find((p) => p.key === state.preset)?.days ?? 30;
    const to = new Date();
    const from = new Date(Date.UTC(to.getUTCFullYear(), to.getUTCMonth(), to.getUTCDate() - (days - 1)));
    return { from: isoDay(from), to: isoDay(to) };
});

export async function loadGames() {
    const { data } = await api.get('/games');
    state.games = data;
    // Default to the game with the most players rather than whichever sorts first.
    if (!data.some((g) => g.id === state.gameId)) state.gameId = [...data].sort((a, b) => b.players_count - a.players_count)[0]?.id ?? null;
}

export function applyTheme(theme) {
    state.theme = theme;
    write('theme', theme);
    if (theme === 'system') delete document.documentElement.dataset.theme;
    else document.documentElement.dataset.theme = theme;
}

watch(() => state.gameId, (id) => id && write('gameId', String(id)));
watch(() => state.preset, (preset) => preset !== 'custom' && write('preset', preset));
