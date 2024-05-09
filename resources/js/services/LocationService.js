export default {
    fetchRecords(paginate = true, paginatorUrl, search, pageLength, orderColumn, orderAsc) {
        let page = 1;
        if (paginatorUrl) {
            page = paginatorUrl.match(/page=(\d+)/);
            page = page ? parseInt(page[1]) : 1;
        }

        let filter = [];

        return new Promise((resolve, reject) => {
            http.post('locations/list', {
                page,
                search: search,
                length: pageLength,
                order: [{'column': orderColumn, 'dir': orderAsc ? 'asc' : 'desc'}],
                filter,
                "paginate": paginate
            }).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },

    fetchLocationGroups(paginatorUrl, search, pageLength, orderColumn, orderAsc) {
        let page = 1;
        if (paginatorUrl) {
            page = paginatorUrl.match(/page=(\d+)/);
            page = page ? parseInt(page[1]) : 1;
        }

        let filter = [];

        return new Promise((resolve, reject) => {
            http.post('locations/groups/list', {
                page,
                search: search,
                length: pageLength,
                order: [{'column': orderColumn, 'dir': orderAsc ? 'asc' : 'desc'}],
                filter,
            }).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },

    updateRecords(locations) {
        return new Promise((resolve, reject) => {
            http.post('locations/save',
                locations
            ).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },

    deleteRecord(id) {
        return new Promise((resolve, reject) => {
            http.delete('locations/' + id).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },
}