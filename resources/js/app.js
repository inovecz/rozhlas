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
import Toast, {POSITION, useToast} from "vue-toastification";
import "vue-toastification/dist/index.css";
import VueDatePicker from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css'
import {Vue3SlideUpDown} from "vue3-slide-up-down";

//define
const emitter = mitt();
window.emitter = emitter;
const app = createApp({});
const pinia = createPinia()
pinia.use(piniaPluginPersistedState)

window.debounce = debounce;

//define as component
app.component("app-component", AppComponent);
app.component('VueDatePicker', VueDatePicker);
app.component('Vue3SlideUpDown', Vue3SlideUpDown);

//use package
app.use(pinia);
app.use(router);
app.use(ConfirmDialog);

function updateToastMessage(message) {
    // Matches strings like "my message (5 times)"
    // and increases the number by 1 every time

    const rgx = /(.*\()(\d+)(x\))$/g;
    if (message.match(rgx)) {
        const number = parseInt(message.replace(rgx, '$2')) + 1;
        return message.replace(rgx, `$1${number}$3`);
    }
    return `${message} (2x)`;
}

app.use(Toast, {
    position: POSITION.BOTTOM_RIGHT,
    timeout: 3000,
    filterBeforeCreate: (toast, toasts) => {
        const $toast = useToast();
        // Find an existing toast with the same content
        const existingToast = toasts.find((t) =>
            t.content.match(`^${toast.content}(\(\d+ x\))?`)
        );
        if (existingToast) {
            // Update existing toast
            $toast.update(existingToast.id, {
                content: updateToastMessage(existingToast.content),
            });
            // Returning false discards the new toast
            return false;
        }
        // If no matching toast exist, create it
        return toast;
    },
});
app.mount("#app");