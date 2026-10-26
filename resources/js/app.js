import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import App from './App.vue';
import { api, handleGameNotFound, handleUnauthorized } from './lib/api';
import { loadGames, state } from './lib/store';

const routes = [
    { path: '/login', name: 'login', component: () => import('./pages/LoginPage.vue'), meta: { guest: true, title: 'Sign in' } },
    { path: '/', name: 'overview', component: () => import('./pages/OverviewPage.vue'), meta: { title: 'Overview' } },
    { path: '/events', name: 'events', component: () => import('./pages/EventsPage.vue'), meta: { title: 'Events' } },
    { path: '/retention', name: 'retention', component: () => import('./pages/RetentionPage.vue'), meta: { title: 'Retention', platform: true } },
    { path: '/funnels', name: 'funnels', component: () => import('./pages/FunnelsPage.vue'), meta: { title: 'Funnels', platform: true } },
    { path: '/players', name: 'players', component: () => import('./pages/PlayersPage.vue'), meta: { title: 'Players', noRange: true } },
    { path: '/players/:id', name: 'player', component: () => import('./pages/PlayerPage.vue'), meta: { title: 'Player', noRange: true } },
    { path: '/settings', name: 'settings', component: () => import('./pages/SettingsPage.vue'), meta: { title: 'Games & keys', noRange: true, noGame: true } },
    { path: '/:pathMatch(.*)*', redirect: '/' },
];

const router = createRouter({ history: createWebHistory(), routes });

router.beforeEach((to) => {
    if (state.user === null && !to.meta.guest) return { name: 'login', query: to.fullPath !== '/' ? { next: to.fullPath } : {} };
    if (state.user && to.meta.guest) return { name: 'overview' };
});

router.afterEach((to) => {
    document.title = `${to.meta.title ?? 'Dashboard'} · Game Analytics`;
});

handleUnauthorized(() => {
    if (state.user === null) return;
    state.user = null;
    router.push({ name: 'login' });
});

let reloadingGames = null;
handleGameNotFound(() => {
    if (state.user === null || reloadingGames) return;
    reloadingGames = loadGames().catch(() => {}).finally(() => (reloadingGames = null));
});

// Resolve the session before the router's first navigation so guards know who is signed in.
try {
    state.user = (await api.get('/me')).user;
    if (state.user) await loadGames();
} catch {
    state.user = null;
}

createApp(App).use(router).mount('#app');
