export default {
    uploadRecording(recordingFormData) {
        return new Promise((resolve, reject) => {
            http.post('/upload', recordingFormData, {
                headers: {
                    'Content-Type': 'multipart/form-data'
                }
            }).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },
    createFromCentralFile(payload) {
        return new Promise((resolve, reject) => {
            http.post('records/copy-from-central-file', payload).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject(error);
            });
        });
    }
}
