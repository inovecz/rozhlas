<template>
  <div class="relative min-h-screen">
    <Sidebar/>
    <!-- navbar -->
    <div class="bg-zinc-800 py-4 px-4 text-light-grey flex justify-between items-center fixed left-0 right-0 z-10">

      <span class="mdi mdi-menu text-3xl text-gray-100 cursor-pointer md:hidden" @click="toggleSidebar"></span>

      <div class="cursor-pointer">
        <router-link :to="{ name: 'LiveBroadcast' }">
          <div class="flex space-x-3">
            <span class="mdi mdi-broadcast text-3xl text-white"></span>
            <span class="text-white text-3xl font-light">Sarrah IV</span>
          </div>
        </router-link>
      </div>
      <div class="flex gap-4">

        <div class="flex items-center gap-4">
          <span class="cursor-pointer font-renner_medium text-white" title="Representative">
            {{ username }}
          </span>
        </div>
        <div class="dropdown inline-block relative">
          <span class="mdi mdi-account-circle text-3xl text-white"></span>
          <ul class="dropdown-menu min-w-max absolute right-0 hidden text-gray-700 pt-5">
            <li class="bg-gray-200 hover:bg-gray-400 py-2 px-4 block whitespace-no-wrap cursor-pointer"
                @click="logout">
              Odhlásit se
            </li>
          </ul>
        </div>
      </div>
    </div>
    <!-- navbar -->
    <!-- main section -->
    <section class="relative pt-[70px] md:pl-[256px]"
             :class="[showSideBar ? '' : 'md:pl-[66px]']">
      <router-view></router-view>
    </section>
    <!-- /main section -->

    <!-- Footer Section -->

    <!-- End Footer Section -->
  </div>
</template>

<script setup>
import {computed} from 'vue';
import {basicStore} from '../../store/basicStore';
import Sidebar from "./Sidebar.vue";
import router from "../../routes/index.js";

const basicStoreInfo = basicStore();
const loggedUser = computed(() => basicStoreInfo.loggedUser);

const toggleSidebar = () => {
  basicStoreInfo.showSideBar = !basicStoreInfo.showSideBar;
}

const username = computed(() => localStorage.getItem('username'));

const logout = () => {
  http.post('/auth/logout').then(response => {
    localStorage.removeItem('token');
    localStorage.removeItem('username');
    router.push('/login')
  }).catch(error => {
    console.log(error);
  });
}

const showSideBar = computed(() => basicStoreInfo.showSideBar)

</script>

<style scoped>
.dropdown:hover .dropdown-menu {
  display: block;
}

</style>