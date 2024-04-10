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
    <div class="border border-accent/50 rounded-md px-5 py-2">

    </div>
  </div>
</template>