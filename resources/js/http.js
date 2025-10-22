import axios from 'axios';

window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';


const protocol = window.location.protocol === 'https:' ? 'https:' : 'http:';
const hostname = window.location.hostname || '127.0.0.1';
const defaultPort = 8001;
const envApiUrl = (typeof import.meta !== 'undefined' && import.meta.env && import.meta.env.VITE_API_URL)
    ? import.meta.env.VITE_API_URL
    : null;

const baseUrl = envApiUrl ?? `${protocol}//${hostname}:${defaultPort}/api`;

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
