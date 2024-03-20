<template>
  <!-- desktop sidebar  -->
  <div
      class="bg-zinc-800 text-light-grey h-screen hidden w-full md:block md:fixed md:top-0 md:bottom-0 md:z-10 md:pt-[68px]"
      :class="[showSideBar ? 'md:w-64' : 'md:w-16']"
  >
    <div class="flex items-center justify-end" :class="{'justify-end': showSideBar, 'justify-center': !showSideBar}">
    <span class="mdi mdi-chevron-left text-3xl text-gray-500 hover:text-gray-50 cursor-pointer"
          v-if="showSideBar" @click="toggleSidebar"></span>
      <span class="mdi mdi-chevron-right text-3xl text-gray-500 hover:text-gray-50 cursor-pointer"
            v-if="!showSideBar" @click="toggleSidebar"></span>
    </div>

    <nav class="relative">

      <SidebarItem to="LiveBroadcast" class="group relative"
                   :class="{['router-link-active router-link-exact-active']: $route.path.match('live-broadcast') !== null}">
        <div class="w-10 h-10 text-gray-200 hover:text-gray-50 flex items-center justify-center">
          <span class="mdi mdi-microphone text-3xl"></span>
        </div>
        <span class="group-hover:opacity-100 transition-opacity bg-gray-800 px-3 text-sm text-gray-100 rounded-md absolute opacity-0 translate-x-14 m-4 mx-auto whitespace-nowrap"
              v-if="!showSideBar">
          Živé vysílání
        </span>
        <span class="sidebar-item text-white" :class="[showSideBar ? 'block' : 'hidden']">
          Živé vysílání
        </span>
      </SidebarItem>

      <SidebarItem to="Records" class="group relative"
                   :class="{['router-link-active router-link-exact-active'] : $route.path.match('records') !== null}">
        <div class="w-10 h-10 text-gray-200 hover:text-gray-50 flex items-center justify-center">
          <span class="mdi mdi-album text-3xl"></span>
        </div>
        <span class="group-hover:opacity-100 transition-opacity bg-gray-800 px-3 text-sm text-gray-100 rounded-md absolute opacity-0 translate-x-14 m-4 mx-auto whitespace-nowrap"
              v-if="!showSideBar">
          Záznamy
        </span>
        <span class="sidebar-item ml-2 text-white" :class="[showSideBar ? 'block' : 'hidden']">
          Záznamy
        </span>
      </SidebarItem>


      <SidebarItem to="About" class="group relative"
                   :class="{['router-link-active router-link-exact-active']: $route.path.match('about') !== null}">
        <div class="w-10 h-10 text-gray-200 hover:text-gray-50 flex items-center justify-center">
          <span class="mdi  mdi-information text-3xl"></span>
        </div>
        <span class="group-hover:opacity-100 transition-opacity bg-gray-800 px-3 text-sm text-gray-100 rounded-md absolute opacity-0 translate-x-14 m-4 mx-auto whitespace-nowrap"
              v-if="!showSideBar">
          O aplikaci
        </span>
        <span class="sidebar-item ml-2 text-white" :class="[showSideBar ? 'block' : 'hidden']">
          O aplikaci
        </span>
      </SidebarItem>

    </nav>
  </div>
  <!-- /desktop sidebar -->

  <!-- mobile side bar -->
  <MobileSidebar/>
  <!-- /mobile side bar -->
</template>

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

</script>