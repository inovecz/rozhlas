export default {
    fetchWhitelist() {
        return http.get('gsm/whitelist').then(response => response.data?.whitelist ?? response.data);
    },

    createWhitelist(entry) {
        return http.post('gsm/whitelist', entry).then(response => response.data?.entry ?? response.data);
    },

    updateWhitelist(id, entry) {
        return http.put(`gsm/whitelist/${id}`, entry).then(response => response.data?.entry ?? response.data);
    },

    deleteWhitelist(id) {
        return http.delete(`gsm/whitelist/${id}`).then(response => response.data?.deleted ?? response.data);
    },

    verifyPin(payload) {
        return http.post('gsm/pin/verify', payload).then(response => response.data?.result ?? response.data);
    }
}
