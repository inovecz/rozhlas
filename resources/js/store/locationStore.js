import {defineStore} from 'pinia';

export const locationStore = defineStore('basicStore', {
    state: () => ({
        locations: [],
    }),
    getters: {},
    actions: {},
    persist: false,
});