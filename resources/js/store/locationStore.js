import {defineStore} from 'pinia';

export const locationStore = defineStore('locationStore', {
    state: () => ({
        locations: [],
    }),
    getters: {},
    actions: {},
    persist: false,
});