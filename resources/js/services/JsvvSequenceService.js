export default {
    planSequence(items, options = {}) {
        const payload = {
            items,
            priority: options.priority ?? undefined,
            zones: options.zones ?? undefined,
            holdSeconds: options.holdSeconds ?? undefined,
        };
        return http.post('jsvv/sequences', payload).then(response => response.data?.sequence ?? response.data);
    },

    triggerSequence(sequenceId) {
        return http.post(`jsvv/sequences/${sequenceId}/trigger`).then(response => response.data?.sequence ?? response.data);
    },

    fetchAssets(params = {}) {
        return http.get('jsvv/assets', {params}).then(response => response.data?.assets ?? []);
    }
}
