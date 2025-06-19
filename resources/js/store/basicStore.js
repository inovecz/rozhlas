import {defineStore} from 'pinia';

export const basicStore = defineStore('basicStore', {
    state: () => ({
        showSideBar: false,
        loggedUser: {},
    }),
    getters: {},
    actions: {},
    persist: true,
});