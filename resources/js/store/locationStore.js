import {defineStore} from 'pinia';

export const locationStore = defineStore('locationStore', {
    state: () => ({
        locations: [],
        locationGroups: [],
    }),
    getters: {},
    actions: {},
    persist: false,
});