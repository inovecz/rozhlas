const normaliseStreamPayload = (payload = {}) => ({
    source: payload.source ?? 'microphone',
    route: payload.route ?? [],
    locations: payload.locations ?? payload.zones ?? [],
    nests: payload.nests ?? [],
    options: payload.options ?? {},
});

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

    getStatus() {
        return http.get('live-broadcast/status').then(response => response.data);
    },

    getSources() {
        return http.get('live-broadcast/sources').then(response => response.data?.sources ?? []);
    },

    enqueuePlaylist(payload) {
        return http.post('live-broadcast/playlist', normalisePlaylistPayload(payload)).then(response => response.data);
    },

    cancelPlaylist(id) {
        return http.post(`live-broadcast/playlist/${id}/cancel`).then(response => response.data);
    }
}
