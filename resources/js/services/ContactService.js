export default {
    fetchContacts(paginatorUrl, search, pageLength, orderColumn, orderAsc, filter) {
        let page = 1;
        if (paginatorUrl) {
            page = paginatorUrl.match(/page=(\d+)/);
            page = page ? parseInt(page[1]) : 1;
        }

        return new Promise((resolve, reject) => {
            http.post('contacts/list', {
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

    getAllContacts(scope = null) {
        let queryParams = '';
        if (scope) {
            queryParams = '?scope=' + (Array.isArray(scope) ? scope.join(',') : scope);
        }

        return new Promise((resolve, reject) => {
            http.get('contacts' + queryParams).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },

    saveContact(contact) {
        return new Promise((resolve, reject) => {
            http.post('contacts' + (contact.id ? '/' + contact.id : ''), contact).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject(error.response.data);
            });
        });
    },

    deleteContact(id) {
        return new Promise((resolve, reject) => {
            http.delete('contacts/' + id).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject(error.response.data);
            });
        });
    },

    fetchContactGroups(paginatorUrl, search, pageLength, orderColumn, orderAsc) {
        let page = 1;
        if (paginatorUrl) {
            page = paginatorUrl.match(/page=(\d+)/);
            page = page ? parseInt(page[1]) : 1;
        }

        let filter = [];
        return new Promise((resolve, reject) => {
            http.post('contacts/groups/list', {
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

    getAllContactGroups(scope = null) {
        let queryParams = '';
        if (scope) {
            queryParams = '?scope=' + (Array.isArray(scope) ? scope.join(',') : scope);
        }
        return new Promise((resolve, reject) => {
            http.get('contacts/groups' + queryParams).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    },

    saveContactGroup(contactGroup) {
        return new Promise((resolve, reject) => {
            http.post('contacts/groups' + (contactGroup.id ? '/' + contactGroup.id : ''), contactGroup).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject(error.response.data);
            });
        });
    },

    deleteContactGroup(id) {
        return new Promise((resolve, reject) => {
            http.delete('contacts/groups/' + id).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject(error.response.data);
            });
        });
    },
}