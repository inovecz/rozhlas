import {createPinia} from 'pinia';
import {createApp} from "vue";
import './bootstrap';

//Component import
import AppComponent from './App.vue';
import router from "./routes/index.js";
import '@mdi/font/css/materialdesignicons.css'
import piniaPluginPersistedState from "pinia-plugin-persistedstate"

//define
const app = createApp({});
const pinia = createPinia()
pinia.use(piniaPluginPersistedState)

//define as component
app.component("app-component", AppComponent);

//use package
app.use(pinia)
app.use(router)

app.mount("#app");