<script setup>
const model = defineModel();

const props = defineProps({
  label: {
    type: String,
    default: ''
  },
  dataClass: {
    type: String,
    default: 'checkbox-primary'
  },
  error: {
    type: String,
    default: ''
  }
});
</script>

<template>
  <div class="form-control">
    <label class="label cursor-pointer">
      <span class="label-text" v-html="label"/>
      <input v-model="model" type="checkbox" checked="checked" :class="'checkbox ' + dataClass + (error ? ' checkbox-error' : '')"/>
    </label>
  </div>
</template>

<style scoped>
</style>