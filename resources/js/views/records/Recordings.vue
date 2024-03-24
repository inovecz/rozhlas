<script setup>
import Recorder from "./Recorder.vue";
import RecordList from "./RecordList.vue";
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Záznamy</h1>
    <div class="content flex flex-col space-y-4">
      <Recorder/>
      <RecordList/>
    </div>
  </div>
</template>