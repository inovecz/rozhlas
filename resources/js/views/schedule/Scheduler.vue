<script setup>
import ScheduleList from "./ScheduleList.vue";
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Plán vysílání</h1>
    <div class="content flex flex-col space-y-4">
      <ScheduleList title="Naplánováno" type="planned"/>
      <ScheduleList title="Archív" type="archive"/>
    </div>
  </div>
</template>