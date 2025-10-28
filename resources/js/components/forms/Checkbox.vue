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
  },
  disabled: {
    type: Boolean,
    default: false
  }
});
</script>

<template>
  <div class="form-control">
    <label class="label cursor-pointer">
      <span class="label-text" v-html="label"/>
      <input v-model="model"
             type="checkbox"
             checked="checked"
             :disabled="disabled"
             :class="'checkbox ' + dataClass + (error ? ' checkbox-error' : '') + (disabled ? ' opacity-60 cursor-not-allowed' : '')"/>
    </label>
  </div>
</template>

<style scoped>
</style>
