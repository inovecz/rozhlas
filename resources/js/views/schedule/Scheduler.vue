<script setup>
import {ref} from "vue";

const selectedTab = ref(1);

function selectTab(tab) {
  selectedTab.value = tab;
}
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Plán vysílání</h1>
    <div class="content flex flex-col space-y-4">
      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Naplánováno
          </div>
        </div>
      </div>
    </div>
  </div>
</template>