export default {
    fetchLogs(paginatorUrl, search, pageLength, orderColumn, orderAsc) {
        let page = 1;
        if (paginatorUrl) {
            page = paginatorUrl.match(/page=(\d+)/);
            page = page ? parseInt(page[1]) : 1;
        }

        let filter = [];

        return new Promise((resolve, reject) => {
            http.post('logs/list', {
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
    }
}