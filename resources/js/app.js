import {createPinia} from 'pinia';
import {createApp} from "vue";
import './bootstrap';

//Component import
import AppComponent from './App.vue';
import router from "./router.js";
import '@mdi/font/css/materialdesignicons.css'
import piniaPluginPersistedState from "pinia-plugin-persistedstate"
import mitt from 'mitt'
import * as ConfirmDialog from 'vuejs-confirm-dialog';
import debounce from 'lodash.debounce';

//define
const emitter = mitt();
window.emitter = emitter;
const app = createApp({});
const pinia = createPinia()
pinia.use(piniaPluginPersistedState)

window.debounce = debounce;

//define as component
app.component("app-component", AppComponent);

//use package
app.use(pinia);
app.use(router);
app.use(ConfirmDialog);

app.mount("#app");