<script setup>

const model = defineModel();

const props = defineProps({
  label: {
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
  <label class="form-control relative mb-4">
    <div class="label">
      <span :class="'label-text' + (error ? ' text-error' : '')" v-html="label"/>
    </div>
    <slot/>
    <div v-if="error" class="absolute -bottom-5 right-0">
      <span class="text-xxs text-error">{{ error }}</span>
    </div>
  </label>
</template>

<style scoped>
</style>