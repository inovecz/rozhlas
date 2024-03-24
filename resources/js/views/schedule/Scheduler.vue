<script setup>
import {Tab, TabGroup, TabList, TabPanel, TabPanels} from "@headlessui/vue";
import {ref} from "vue";

const selectedTab = ref(1);

function selectTab(tab) {
  selectedTab.value = tab;
}
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Plán vysílání</h1>
    <div class="border border-secondary/50 rounded-md px-5 py-2">
      <div class="content flex flex-col space-y-4">
        <TabGroup>
          <TabList class="border-0 border-b border-b-accent mb-2 flex justify-start">
            <Tab @click="selectTab(1)" class="border border-b-2 border-transparent hover:border-b-accent focus:ring-0 focus:ring-offset-0 px-2" :class="{'text-bold text-primary': selectedTab.value === 1}">Plán</Tab>
            <Tab @click="selectTab(2)" class="border border-b-2 border-transparent hover:border-b-accent focus:ring-0 focus:ring-offset-0 px-2" :class="{'text-bold text-primary': selectedTab.value === 2}">Archív</Tab>
          </TabList>
          <TabPanels>
            <TabPanel>Content 1</TabPanel>
            <TabPanel>Content 2</TabPanel>
          </TabPanels>
        </TabGroup>
      </div>
    </div>
  </div>
</template>