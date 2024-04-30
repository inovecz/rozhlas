<script setup>
import SidebarItem from "./SidebarItem.vue";

import {computed, ref} from 'vue';
import {basicStore} from '../../store/basicStore';
import MobileSidebar from "./MobileSidebar.vue";

const basicStoreInfo = basicStore();
const show = ref(true)

const toggleSidebar = () => {
  basicStoreInfo.showSideBar = !basicStoreInfo.showSideBar;
}

const showSideBar = computed(() => basicStoreInfo.showSideBar)

const sidebarItems = [
  {
    name: 'Živé vysílání',
    icon: 'mdi-microphone',
    to: 'LiveBroadcast',
    active: 'live-broadcast'
  }, {
    name: 'Záznamy',
    icon: 'mdi-album',
    to: 'Recordings',
    active: 'recordings'
  }, {
    name: 'Plán vysílání',
    icon: 'mdi-calendar-clock',
    to: 'Scheduler',
    active: 'schedule'
  }, {
    name: 'Mapa',
    icon: 'mdi-map',
    to: 'Map',
    active: 'map'
  }, {
    name: 'Protokoly',
    icon: 'mdi-math-log',
    to: 'Log',
    active: 'log'
  }, {
    name: 'Uživatelé',
    icon: 'mdi-account-group',
    to: 'Users',
    active: 'users'
  }, {
    name: 'O aplikaci',
    icon: 'mdi-information',
    to: 'About',
    active: 'about'
  }
];
</script>

<template>
  <!-- desktop sidebar  -->
  <div
      class="bg-base-300 text-light-grey h-screen hidden w-full md:block md:fixed md:top-0 md:bottom-0 md:z-10 md:pt-[68px]"
      :class="[showSideBar ? 'md:w-64' : 'md:w-16']">
    <div class="flex items-center justify-end" :class="{'justify-end': showSideBar, 'justify-center': !showSideBar}">
    <span class="mdi mdi-chevron-left text-3xl text-gray-500 hover:text-gray-50 cursor-pointer"
          v-if="showSideBar" @click="toggleSidebar"></span>
      <span class="mdi mdi-chevron-right text-3xl text-gray-500 hover:text-gray-50 cursor-pointer"
            v-if="!showSideBar" @click="toggleSidebar"></span>
    </div>

    <nav class="relative">
      <SidebarItem v-for="item in sidebarItems" :key="item.name" :to="item.to"
                   :class="{['router-link-active router-link-exact-active text-primary-content']: $route.path.match(item.active) !== null}">
        <div class="w-10 h-10 group-hover:text-secondary flex items-center justify-center">
          <span :class="['mdi', item.icon, 'text-3xl']"></span>
        </div>
        <span class="group-hover:opacity-100 transition-opacity bg-gray-800 px-3 text-sm text-gray-100 rounded-md absolute opacity-0 translate-x-14 m-4 mx-auto whitespace-nowrap"
              v-if="!showSideBar">
          {{ item.name }}
        </span>
        <span class="sidebar-item group-hover:text-secondary" :class="[showSideBar ? 'block' : 'hidden']">
          {{ item.name }}
        </span>
      </SidebarItem>
    </nav>
  </div>
  <!-- /desktop sidebar -->

  <!-- mobile side bar -->
  <MobileSidebar :sidebar-items="sidebarItems"/>
  <!-- /mobile side bar -->
</template>