export default {
    startBroadcast() {
        return new Promise((resolve, reject) => {
            http.get(`live-broadcast/start`).then(response => resolve(response.data)).catch(error => reject(error));
        });
    },
    stopBroadcast() {
        return new Promise((resolve, reject) => {
            http.get(`live-broadcast/stop`).then(response => resolve(response.data)).catch(error => reject(error));
        });
    }
}