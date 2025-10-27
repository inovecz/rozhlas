export default {
    status() {
        return http.get('audio').then(response => response.data?.status ?? {});
    },

    setInput(identifier) {
        return http.post('audio/input', {identifier}).then(response => response.data?.status ?? {});
    },

    setOutput(identifier) {
        return http.post('audio/output', {identifier}).then(response => response.data?.status ?? {});
    },

    setVolume({scope, value = null, mute = null}) {
        const payload = {scope};
        if (value !== null && value !== undefined) {
            payload.value = value;
        }
        if (mute !== null && mute !== undefined) {
            payload.mute = mute;
        }

        return http.post('audio/volume', payload).then(response => response.data ?? {});
    }
}
