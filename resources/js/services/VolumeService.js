export default {
    fetchLiveLevels() {
        return http.get('live-broadcast/volume').then(response => response.data);
    },

    updateLevel(payload) {
        return http.post('live-broadcast/volume', payload).then(response => response.data);
    },

    applyRuntimeLevel(payload) {
        return http.post('live-broadcast/volume/runtime', payload).then(response => response.data);
    },

    fetchSettings() {
        return http.get('settings/volume').then(response => response.data);
    },

    saveSettings(groups) {
        return http.post('settings/volume', {groups}).then(response => response.data);
    }
}
