<script setup>
const model = defineModel();

const props = defineProps({
  label: {
    type: String,
    default: ''
  },
  dataClass: {
    type: String,
    default: 'textarea-bordered h-24'
  },
  placeholder: {
    type: String,
    default: ''
  },
  error: {
    type: String,
    default: ''
  }
});
</script>

<template>
  <label :class="'form-control ' + (label ? 'relative mb-4' : '')">
    <div v-if="label" class="label">
      <span :class="'label-text' + (error ? ' text-error' : '')" v-html="label"/>
    </div>
    <textarea v-model="model" :class="'textarea w-full ' + dataClass + (error ? ' textarea-error' : '')" :placeholder="placeholder"/>
    <div v-if="label && error" class="absolute -bottom-5 right-0">
      <span class="text-xxs text-error">{{ error }}</span>
    </div>
  </label>
</template>

<style scoped>
</style>