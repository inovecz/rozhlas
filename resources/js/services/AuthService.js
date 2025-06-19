export default {
    login(username, password) {
        return new Promise((resolve, reject) => {
            http.post('/auth/login', {
                username,
                password
            }).then(response => {
                resolve(response.data);
            }).catch(error => {
                reject([]);
            });
        });
    }
}