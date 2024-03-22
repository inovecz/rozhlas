<script setup>
import {computed, ref} from 'vue';
import {basicStore} from '../../store/basicStore';
import Sidebar from "./Sidebar.vue";
import router from "../../routes/index.js";
import {getAudioInputDevices, getAudioOutputDevices} from "../../helper.js";

const basicStoreInfo = basicStore();

const selectedAudioInputDevice = computed(() => JSON.parse(localStorage.getItem('audioInputDevice')) ?? 'default');
const selectedAudioOutputDevice = computed(() => JSON.parse(localStorage.getItem('audioOutputDevice')) ?? 'default');

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

const fetchAudioInputDevices = computed(() => {
  getAudioInputDevices().then((devices) => {
    audioInputDevices.value = devices;
  });
});
const audioInputDevices = ref(fetchAudioInputDevices.value || []);
const audioInputSelect = ref();

const fetchAudioOutputDevices = computed(() => {
  getAudioOutputDevices().then((devices) => {
    audioOutputDevices.value = devices;
    persistDefaultOutput();
  });
});
const audioOutputDevices = ref(fetchAudioOutputDevices.value || []);
const audioOutputSelect = ref();

function persistDefaultInput() {
  let id = audioInputSelect.value.value === '' ? 'default' : audioInputSelect.value.value;
  let index = audioInputSelect.value.selectedIndex === -1 ? 0 : audioInputSelect.value.selectedIndex;
  const label = audioInputSelect.value.options[index].text;
  localStorage.setItem('audioInputDevice', JSON.stringify({id, label}));
}

function persistDefaultOutput() {
  let id = audioOutputSelect.value.value === '' ? 'default' : audioOutputSelect.value.value;
  let index = audioOutputSelect.value.selectedIndex === -1 ? 0 : audioOutputSelect.value.selectedIndex;
  const label = audioOutputSelect.value.options[index].text;
  localStorage.setItem('audioOutputDevice', JSON.stringify({id, label}));
}
</script>

<template>
  <div class="relative min-h-screen">
    <Sidebar/>
    <!--<editor-fold desc="NAVBAR">-->
    <div class="bg-base-300 py-4 px-4 text-light-grey flex justify-between items-center fixed left-0 right-0 z-10">

      <span class="mdi mdi-menu text-3xl text-gray-100 cursor-pointer md:hidden" @click="toggleSidebar"></span>

      <div class="cursor-pointer">
        <router-link :to="{ name: 'LiveBroadcast' }">
          <div class="flex space-x-3">
            <span class="mdi mdi-broadcast text-3xl"></span>
            <span class="text-3xl font-light">Sarrah IV</span>
          </div>
        </router-link>
      </div>
      <div class="flex gap-4">

        <div class="flex items-center gap-4">
          <span class="cursor-pointer" title="Representative">
            {{ username }}
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
    <!--</editor-fold desc="NAVBAR">-->

    <!--<editor-fold desc="MAIN SECTION">-->
    <section class="relative pt-[70px] md:pl-[256px]"
             :class="[showSideBar ? '' : 'md:pl-[66px]']">
      <router-view></router-view>
    </section>
    <!--</editor-fold desc="MAIN SECTION">-->

    <!--<editor-fold desc="FOOTER">-->
    <footer class="bg-base-300 text-light-grey py-2 px-4 text-center fixed bottom-0 left-0 right-0 z-10">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
        <div class="flex items-center justify-center">
          <span class="mdi mdi-microphone text-xl"></span>
          <select ref="audioInputSelect" @change="persistDefaultInput()" class="select select-ghost select-sm w-full max-w-xs">
            <option v-if="audioInputDevices.length === 0" disabled value="">Default</option>
            <option v-for="audioInput in audioInputDevices" :value="audioInput.id" :selected="audioInput.id === selectedAudioInputDevice.id">
              {{ audioInput.label }}
            </option>
          </select>
        </div>
        <div class="flex items-center justify-center">
          <span class="mdi mdi-volume-medium text-xl"></span>
          <select ref="audioOutputSelect" @change="persistDefaultOutput()" class="select select-ghost select-sm w-full max-w-xs">
            <option v-if="audioOutputDevices.length === 0" disabled value="">Default</option>
            <option v-for="audioOutput in audioOutputDevices" :value="audioOutput.id" :selected="audioOutput.id === selectedAudioOutputDevice.id">
              {{ audioOutput.label }}
            </option>
          </select>
        </div>
      </div>
    </footer>
    <!--</editor-fold desc="FOOTER">-->
  </div>
</template>

<style scoped>
.dropdown:hover .dropdown-menu {
  display: block;
}
</style>