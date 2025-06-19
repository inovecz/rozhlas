<script setup>
import {computed} from 'vue';
import {basicStore} from '../../store/basicStore';
import SidebarItem from "./SidebarItem.vue";
import {Vue3SlideUpDown} from "vue3-slide-up-down";

const basicStoreInfo = basicStore();

const toggleSidebar = () => {
  basicStoreInfo.showSideBar = !basicStoreInfo.showSideBar;
}

const showSideBar = computed(() => basicStoreInfo.showSideBar)

const props = defineProps({
  sidebarItems: {
    type: Array,
    required: true
  }
})
</script>

<template>
  <!-- mobile side bar -->
  <div class="fixed bg-base-300 h-screen w-full z-20 md:hidden  transition-all duration-500 ease-in-out"
       :class="[showSideBar ? '-top-[1px] sm:70px block' : '-top-[1000px]']">
    <nav class="mt-[80px]">
      <div v-for="item in props.sidebarItems" :key="item.name">
        <SidebarItem :to="item.to"
                     :class="{['router-link-active router-link-exact-active text-primary-content']: $route.path.match(item.active) !== null}">
          <div @click="toggleSidebar" class="flex justify-start items-center gap-3 w-full">
            <div class="w-10 h-10 text-base-content group-hover:text-secondary hover:text-gray-50 flex items-center justify-center">
              <span :class="['mdi', item.icon, 'text-3xl']"></span>
            </div>
            <span class="sidebar-item text-base-content group-hover:text-secondary">{{ item.name }}</span>
          </div>
          <button v-if="item.submenu && showSideBar" @click.prevent="item.submenuVisible = !item.submenuVisible" class="btn btn-ghost btn-square">
            <span :class="item.submenuVisible ? 'mdi-chevron-up' : 'mdi-chevron-down'" class="mdi text-lg"></span>
          </button>
        </SidebarItem>
        <Vue3SlideUpDown v-model="item.submenuVisible" :duration="250">
          <div v-if="item.submenu && item.submenuVisible" :class="[showSideBar ? 'ml-8 border-l-2 border-slate-600' : '']">
            <div v-for="subItem in item.submenu" :key="subItem.name">
              <SidebarItem :to="subItem.to"
                           :class="{['router-link-active router-link-exact-active text-primary-content']: $route.path.match(subItem.active) !== null}">
                <span class="group-hover:opacity-100 transition-opacity bg-secondary px-3 text-sm text-secondary-content rounded-md absolute opacity-0 translate-x-14 m-4 mx-auto whitespace-nowrap"
                      v-if="!showSideBar">
                  {{ subItem.name }}
                </span>
                <div class="flex items-center sidebar-item group-hover:text-secondary" :class="[showSideBar ? 'block' : 'hidden']">
                  <div :class="['mdi', subItem.icon, 'text-xl', 'mr-3']"></div>
                  <div>{{ subItem.name }}</div>
                </div>
              </SidebarItem>
            </div>
          </div>
        </Vue3SlideUpDown>
      </div>
    </nav>
  </div>
  <!-- /mobile side bar -->
</template>