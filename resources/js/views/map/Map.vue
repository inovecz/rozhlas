<script setup>
import LocationOverview from "./LocationOverview.vue";
import LocationList from "./LocationList.vue";
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Mapa</h1>
    <div class="content flex flex-col space-y-4 pt-6">
      <LocationOverview/>
      <LocationList/>
    </div>
  </div>
</template>

<style scoped>

</style>