export default {
    fetchRecords(paginatorUrl, search, pageLength, orderColumn, orderAsc) {
        let page = 1;
        if (paginatorUrl) {
            page = paginatorUrl.match(/page=(\d+)/);
            page = page ? parseInt(page[1]) : 1;
        }

        let filter = [];

        return new Promise((resolve, reject) => {
            http.post('users/list', {
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

    saveUser(user) {
        return new Promise((resolve, reject) => {
            console.log(user);
            http.post('users' + (user.id ? '/' + user.id : ''), user).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject(error.response.data);
            });
        });
    },

    deleteUser(id) {
        return new Promise((resolve, reject) => {
            http.delete('users/' + id).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject(error.response.data);
            });
        });
    }
}