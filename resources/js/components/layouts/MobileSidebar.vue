<template>
  <!-- mobile side bar -->
  <div class="fixed bg-zinc-800 h-screen w-full z-10 md:hidden  transition-all duration-500 ease-in-out"
       :class="[showSideBar ? '-top-[1px] sm:70px block' : '-top-[900px]']">
    <nav class="mt-[80px]">
      <SidebarItem
          to="LiveBroadcast"
          :class="{
                    ['router-link-active router-link-exact-active']:
                        $route.path.match('live-broadcast') !== null,
                }"
          @click="toggleSidebar">
        <div class="w-10 h-10 text-gray-200 hover:text-gray-50 flex items-center justify-center">
          <span class="mdi mdi-microphone text-3xl"></span>
        </div>
        <span class="sidebar-item text-gray-100">Živé vysílání</span>
      </SidebarItem>

      <SidebarItem to="About"
                   :class="{
                    ['router-link-active router-link-exact-active']:
                        $route.path.match('about') !== null
                    }"
                   @click="toggleSidebar">
        <div class="w-10 h-10 text-gray-200 hover:text-gray-50 flex items-center justify-center">
          <span class="mdi  mdi-information text-3xl"></span>
        </div>
        <span class="sidebar-item text-gray-100">O aplikaci</span>
      </SidebarItem>

    </nav>
  </div>
  <!-- /mobile side bar -->
</template>

<script setup>
import {computed} from 'vue';
import {basicStore} from '../../store/basicStore';
import SidebarItem from "./SidebarItem.vue";

const basicStoreInfo = basicStore();

const toggleSidebar = () => {
  basicStoreInfo.showSideBar = !basicStoreInfo.showSideBar;
}

const showSideBar = computed(() => basicStoreInfo.showSideBar)

</script>