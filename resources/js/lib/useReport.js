import { ref, shallowRef, watch } from 'vue';

/**
 * Fetch a report whenever its inputs change. While a refetch is in flight the previous data
 * stays on screen (pages dim it) instead of flashing a skeleton; stale responses are dropped.
 *
 * @param {() => object|null} source  reactive inputs; return null to skip fetching
 * @param {(inputs: object, signal: AbortSignal) => Promise<any>} fetcher
 */
export function useReport(source, fetcher) {
    const data = shallowRef(null);
    const loading = ref(false);
    const error = ref(null);
    let controller = null;

    async function load() {
        const inputs = source();
        if (!inputs) return;

        controller?.abort();
        controller = new AbortController();
        const current = controller;

        loading.value = true;
        error.value = null;

        try {
            const result = await fetcher(inputs, current.signal);
            if (current === controller) data.value = result;
        } catch (e) {
            if (e.name !== 'AbortError' && current === controller) error.value = e;
        } finally {
            if (current === controller) loading.value = false;
        }
    }

    watch(() => JSON.stringify(source()), load, { immediate: true });

    return { data, loading, error, reload: load };
}
