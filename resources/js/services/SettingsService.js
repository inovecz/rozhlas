export default {
    fetchSmtpSettings() {
        return new Promise((resolve, reject) => {
            http.get('settings/smtp').then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },

    saveSmtpSettings(settings) {
        return new Promise((resolve, reject) => {
            http.post('settings/smtp', settings).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject(error.response.data);
            });
        });
    }
}