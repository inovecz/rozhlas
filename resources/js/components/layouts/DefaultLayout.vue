<script setup>
import {computed, ref} from 'vue';
import {basicStore} from '../../store/basicStore';
import Sidebar from "./Sidebar.vue";
import router from "../../router.js";
import {getLoggedUsername} from "../../helper.js";
import {useToast} from "vue-toastification";

const basicStoreInfo = basicStore();
const isDark = ref(localStorage.getItem('theme') === 'dark' || false);
const toast = useToast();

const setTheme = () => {
  localStorage.setItem('theme', isDark.value ? 'dark' : 'light');
  document.querySelector('html').setAttribute('data-theme', isDark.value ? 'dark' : 'light');
}

const toggleSidebar = () => {
  basicStoreInfo.showSideBar = !basicStoreInfo.showSideBar;
}

const logout = () => {
  http.post('/auth/logout').then(response => {
    localStorage.removeItem('token');
    router.push('/login')
  }).catch(() => {
    toast.error('Odhlášení se nezdařilo');
  });
}

const showSideBar = computed(() => basicStoreInfo.showSideBar)

</script>

<template>
  <div class="relative min-h-screen">
    <Sidebar/>
    <!--<editor-fold desc="NAVBAR">-->
    <div class="bg-base-300 py-4 px-4 text-light-grey flex justify-between items-center fixed left-0 right-0 z-20">

      <span class="mdi mdi-menu text-3xl text-base-content cursor-pointer md:hidden" @click="toggleSidebar"></span>

      <div class="cursor-pointer">
        <router-link :to="{ name: 'LiveBroadcast' }">
          <div class="flex space-x-3">
            <span class="mdi mdi-broadcast text-3xl"></span>
            <span class="text-3xl font-light">Sarah V</span>
          </div>
        </router-link>
      </div>
      <div class="flex gap-8">
        <label class="swap swap-rotate">

          <input v-model="isDark" @change="setTheme()" type="checkbox" class="theme-controller"/>

          <svg class="swap-off fill-current w-6 h-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path
                d="M5.64,17l-.71.71a1,1,0,0,0,0,1.41,1,1,0,0,0,1.41,0l.71-.71A1,1,0,0,0,5.64,17ZM5,12a1,1,0,0,0-1-1H3a1,1,0,0,0,0,2H4A1,1,0,0,0,5,12Zm7-7a1,1,0,0,0,1-1V3a1,1,0,0,0-2,0V4A1,1,0,0,0,12,5ZM5.64,7.05a1,1,0,0,0,.7.29,1,1,0,0,0,.71-.29,1,1,0,0,0,0-1.41l-.71-.71A1,1,0,0,0,4.93,6.34Zm12,.29a1,1,0,0,0,.7-.29l.71-.71a1,1,0,1,0-1.41-1.41L17,5.64a1,1,0,0,0,0,1.41A1,1,0,0,0,17.66,7.34ZM21,11H20a1,1,0,0,0,0,2h1a1,1,0,0,0,0-2Zm-9,8a1,1,0,0,0-1,1v1a1,1,0,0,0,2,0V20A1,1,0,0,0,12,19ZM18.36,17A1,1,0,0,0,17,18.36l.71.71a1,1,0,0,0,1.41,0,1,1,0,0,0,0-1.41ZM12,6.5A5.5,5.5,0,1,0,17.5,12,5.51,5.51,0,0,0,12,6.5Zm0,9A3.5,3.5,0,1,1,15.5,12,3.5,3.5,0,0,1,12,15.5Z"/>
          </svg>

          <svg class="swap-on fill-current w-6 h-6" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path d="M21.64,13a1,1,0,0,0-1.05-.14,8.05,8.05,0,0,1-3.37.73A8.15,8.15,0,0,1,9.08,5.49a8.59,8.59,0,0,1,.25-2A1,1,0,0,0,8,2.36,10.14,10.14,0,1,0,22,14.05,1,1,0,0,0,21.64,13Zm-9.5,6.69A8.14,8.14,0,0,1,7.08,5.22v.27A10.15,10.15,0,0,0,17.22,15.63a9.79,9.79,0,0,0,2.1-.22A8.11,8.11,0,0,1,12.14,19.73Z"/>
          </svg>

        </label>
        <div class="flex gap-4">

          <div class="flex items-center gap-4">
            <span class="cursor-pointer" title="Representative">
              {{ getLoggedUsername() }}
            </span>
          </div>
          <div class="dropdown inline-block relative">
            <span class="mdi mdi-account-circle text-3xl"></span>
            <ul class="dropdown-menu min-w-max absolute right-0 hidden text-gray-700 pt-5">
              <li class="bg-gray-200 hover:bg-gray-400 py-2 px-4 block whitespace-no-wrap cursor-pointer"
                  @click="logout">
                Odhlásit se
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <!--</editor-fold desc="NAVBAR">-->

    <!--<editor-fold desc="MAIN SECTION">-->
    <section class="relative pt-[70px] md:pl-[256px] mb-10"
             :class="[showSideBar ? '' : 'md:pl-[66px]']">
      <router-view></router-view>
    </section>
    <!--</editor-fold desc="MAIN SECTION">-->
  </div>
</template>

<style scoped>
.dropdown:hover .dropdown-menu {
  display: block;
}
</style>
