export default {
    fetchJsvvAlarms() {
        return new Promise((resolve, reject) => {
            http.get('jsvv-alarms/all').then(response => resolve(response.data)).catch(error => reject(error));
        });
    },

    getJsvvAlarm(id) {
        return new Promise((resolve, reject) => {
            http.get(`jsvv-alarms/${id}`).then(response => resolve(response.data)).catch(error => reject(error));
        });
    },

    getJsvvAudios() {
        return new Promise((resolve, reject) => {
            http.get('jsvv-alarms/audios').then(response => resolve(response.data)).catch(error => reject(error));
        });
    },

    saveJsvvAlarm(jsvvAlarm) {
        let jsvvAlarmId = null
        if (jsvvAlarm.id !== null) {
            jsvvAlarmId = jsvvAlarm.id;
            delete jsvvAlarm.id;
        }
        return new Promise((resolve, reject) => {
            http.post('jsvv-alarms' + (jsvvAlarmId ? '/' + jsvvAlarmId : ''), jsvvAlarm).then(response => resolve(response.data)).catch(error => reject(error));
        });
    },

    saveJsvvAudios(jsvvAudios) {
        return new Promise((resolve, reject) => {
            http.post('jsvv-alarms/audios', jsvvAudios).then(response => resolve(response.data)).catch(error => reject(error));
        });
    }
}