<script setup>
const props = defineProps({
  label: {
    type: String,
    default: null
  },
  dataClass: {
    type: String,
    default: ''
  },
});
</script>

<template>
  <div :class="['component-box', dataClass]">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between">
      <div v-if="label" class="text-xl text-primary mb-4 mt-3 px-1" v-html="label"/>
      <div class="flex gap-4">
        <slot name="header"></slot>
      </div>
    </div>
    <div class="flex flex-col gap-2">
      <slot/>
    </div>
  </div>
</template>

<style scoped>
</style>