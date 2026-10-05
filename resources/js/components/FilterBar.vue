<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { PLATFORMS, PRESETS, range, state } from '../lib/store';
import { isoDay } from '../lib/format';

const route = useRoute();
const today = isoDay(new Date());

const preset = computed({
    get: () => state.preset,
    set(value) {
        if (value === 'custom') {
            state.customFrom = range.value.from;
            state.customTo = range.value.to;
        }
        state.preset = value;
    },
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Filters">
        <label v-if="!route.meta.noGame && state.games.length" class="sr-only" for="filter-game">Game</label>
        <select v-if="!route.meta.noGame && state.games.length" id="filter-game" v-model="state.gameId" class="field font-medium">
            <option v-for="g in state.games" :key="g.id" :value="g.id">{{ g.name }}</option>
        </select>

        <template v-if="!route.meta.noRange">
            <label class="sr-only" for="filter-range">Date range</label>
            <select id="filter-range" v-model="preset" class="field">
                <option v-for="p in PRESETS" :key="p.key" :value="p.key">{{ p.label }}</option>
                <option value="custom">Custom range…</option>
            </select>
            <template v-if="state.preset === 'custom'">
                <input v-model="state.customFrom" type="date" class="field" :max="state.customTo || today" aria-label="From" />
                <span class="text-muted" aria-hidden="true">–</span>
                <input v-model="state.customTo" type="date" class="field" :min="state.customFrom" :max="today" aria-label="To" />
            </template>
        </template>

        <template v-if="route.meta.platform">
            <label class="sr-only" for="filter-platform">Platform</label>
            <select id="filter-platform" v-model="state.platform" class="field">
                <option value="">All platforms</option>
                <option v-for="p in PLATFORMS" :key="p" :value="p">{{ p }}</option>
            </select>
        </template>
    </div>
</template>
