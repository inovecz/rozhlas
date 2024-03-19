import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';


let baseUrl;

if (window.location.host.includes('rozhlas.lan')) {
    baseUrl = 'http://rozhlas.lan/api';
} else {
    baseUrl = 'https://production-url.cz/api';
}

if (localStorage.getItem('token')) {
    window.axios.defaults.headers.common['Authorization'] = 'Bearer ' + localStorage.getItem('token');
}

let myAxios = window.axios.create({
    baseURL: baseUrl,
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    }
});

myAxios.interceptors.response.use(
    response => response,
    error => {
        if (error.response.status === 401) {
            localStorage.removeItem('token');
            axios.defaults.headers.common['Authorization'] = '';
            window.location.replace('/login');
        }
        return Promise.reject(error);
    }
);

window.http = myAxios;