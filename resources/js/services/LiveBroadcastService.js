const normaliseStreamPayload = (payload = {}) => {
    const result = {
        source: payload.source ?? 'microphone',
        route: payload.route ?? [],
        locations: payload.locations ?? payload.zones ?? [],
        nests: payload.nests ?? [],
        options: payload.options ?? {},
    };
    if (payload.mixer !== undefined) {
        result.mixer = payload.mixer;
    }
    return result;
};

const normalisePlaylistPayload = (payload = {}) => ({
    recordings: payload.recordings ?? [],
    route: payload.route ?? [],
    locations: payload.locations ?? payload.zones ?? [],
    nests: payload.nests ?? [],
    options: payload.options ?? {},
});

export default {
    startBroadcast(payload = {}) {
        return http.post('live-broadcast/start', normaliseStreamPayload(payload)).then(response => response.data);
    },

    stopBroadcast(reason = null) {
        return http.post('live-broadcast/stop', reason ? {reason} : {}).then(response => response.data);
    },

    updateRuntimeInput(payload = {}) {
        return http.post('live-broadcast/runtime', {
            source: payload.source,
            volume: payload.volume,
        }).then(response => response.data);
    },

    getStatus() {
        return http.get('live-broadcast/status').then(response => response.data);
    },

    getSources() {
        return http.get('live-broadcast/sources').then(response => response.data?.sources ?? []);
    },

    getAudioDevices() {
        return http.get('live-broadcast/audio-devices').then(response => response.data?.devices ?? {});
    },

    enqueuePlaylist(payload) {
        return http.post('live-broadcast/playlist', normalisePlaylistPayload(payload)).then(response => response.data);
    },

    cancelPlaylist(id) {
        return http.post(`live-broadcast/playlist/${id}/cancel`).then(response => response.data);
    },

    selectLiveSource(identifier, volume = null) {
        const payload = typeof identifier === 'object' && identifier !== null ? {...identifier} : {};

        if (typeof identifier === 'string' && identifier.trim() !== '') {
            if (payload.identifier === undefined) {
                payload.identifier = identifier;
            }
            if (payload.source === undefined) {
                payload.source = identifier;
            }
        }

        if (volume !== null && volume !== undefined && payload.volume === undefined) {
            payload.volume = volume;
        }

        return http.post('source', payload).then(response => response.data);
    },

    controlLive(action, payload = {}) {
        return http.post('live/control', {action, ...payload}).then(response => response.data);
    },

    startRecording(payload = {}) {
        return http.post('recording/start', payload).then(response => response.data);
    },

    stopRecording() {
        return http.post('recording/stop').then(response => response.data);
    }
}
