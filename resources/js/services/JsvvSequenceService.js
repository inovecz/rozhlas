export default {
    planSequence(items, options = {}) {
        const payload = {
            items,
            priority: options.priority ?? undefined,
            zones: options.zones ?? undefined,
            holdSeconds: options.holdSeconds ?? undefined,
            locations: options.locations ?? undefined,
            audioInputId: options.audioInputId ?? options.audio_input_id ?? undefined,
            audioOutputId: options.audioOutputId ?? options.audio_output_id ?? undefined,
            playbackSource: options.playbackSource ?? options.playback_source ?? undefined,
            frequency: options.frequency ?? options.frequency_mhz ?? options.frequencyMhz ?? undefined,
            frequency_hz: options.frequency_hz ?? options.frequencyHz ?? undefined,
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
