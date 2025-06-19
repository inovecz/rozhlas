export default {
    fetchMessages(paginatorUrl, search, pageLength, orderColumn, orderAsc, filter) {
        let page = 1;
        if (paginatorUrl) {
            page = paginatorUrl.match(/page=(\d+)/);
            page = page ? parseInt(page[1]) : 1;
        }

        return new Promise((resolve, reject) => {
            http.post('messages/list', {
                page,
                search: search.value,
                length: pageLength.value,
                order: [{'column': orderColumn, 'dir': orderAsc ? 'asc' : 'desc'}],
                filter
            }).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },
}