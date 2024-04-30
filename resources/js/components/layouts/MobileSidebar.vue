<script setup>
import {computed} from 'vue';
import {basicStore} from '../../store/basicStore';
import SidebarItem from "./SidebarItem.vue";

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
  <div class="fixed bg-zinc-800 h-screen w-full z-10 md:hidden  transition-all duration-500 ease-in-out"
       :class="[showSideBar ? '-top-[1px] sm:70px block' : '-top-[900px]']">
    <nav class="mt-[80px]">
      <SidebarItem v-for="item in props.sidebarItems" :key="item.name" :to="item.to"
                   :class="{['router-link-active router-link-exact-active text-primary-content']: $route.path.match(item.active) !== null}"
                   @click="toggleSidebar">
        <div class="w-10 h-10 text-gray-200 group-hover:text-secondary hover:text-gray-50 flex items-center justify-center">
          <span :class="['mdi', item.icon, 'text-3xl']"></span>
        </div>
        <span class="sidebar-item text-gray-100 group-hover:text-secondary">{{ item.name }}</span>
      </SidebarItem>
    </nav>
  </div>
  <!-- /mobile side bar -->
</template>