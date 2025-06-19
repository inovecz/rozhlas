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
    }
}