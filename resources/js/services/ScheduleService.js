export default {
    fetchRecords(paginatorUrl, search, pageLength, orderColumn, orderAsc) {
        let page = 1;
        if (paginatorUrl) {
            page = paginatorUrl.match(/page=(\d+)/);
            page = page ? parseInt(page[1]) : 1;
        }

        let filter = [];

        return new Promise((resolve, reject) => {
            http.post('schedules/list', {
                type: 'RECORDING',
                page,
                search: search,
                length: pageLength,
                order: [{'column': orderColumn, 'dir': orderAsc ? 'asc' : 'desc'}],
                filter
            }).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },
    checkTimeConflict(datetime, duration, schedule_id = null) {
        return new Promise((resolve, reject) => {
            http.post('schedules/check-time-conflict', {datetime, duration, schedule_id}).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },

    deleteRecord(id) {
        return new Promise((resolve, reject) => {
            http.delete('schedules/' + id).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    }
}