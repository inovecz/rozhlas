import {defineStore} from 'pinia';

export const contactGroupStore = defineStore('contactGroupStore', {
    state: () => ({
        contactGroups: [],
    }),
    getters: {},
    actions: {},
    persist: false,
});