<script setup>
const model = defineModel();

const props = defineProps({
  label: {
    type: String,
    default: ''
  },
  options: {
    type: Array,
    default: () => []
  },
  optionKey: {
    type: String,
    default: 'value'
  },
  modelKey: {
    type: String,
    default: null
  },
  optionLabel: {
    type: String,
    default: 'label'
  },
  dataClass: {
    type: String,
    default: 'select-bordered'
  },
  size: {
    validator: (value, props) => ['lg', 'md', 'sm', 'xs'].includes(value),
    default: 'md'
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

function containsOnlyBasicTypes(arr) {
  const basicTypes = ['string', 'number', 'boolean', 'undefined'];
  return arr.every(item => basicTypes.includes(typeof item) || item === null);
}
</script>

<template>
  <label :class="'form-control ' + (label ? 'relative mb-4' : '')">
    <div v-if="label" class="label">
      <span :class="'label-text' + (error ? ' text-error' : '')" v-html="label"/>
    </div>
    <select v-model="model"
            :class="['select w-full', dataClass, {'select-lg': size === 'lg', 'select-md': size === 'md', 'select-sm': size === 'sm', 'select-xs': size === 'xs', 'select-error': error}]">
      <option v-if="!containsOnlyBasicTypes(options)" v-for="option of options" :key="option[optionKey]" :value="option[optionKey]" :selected="option[optionKey] === (modelKey ? model[modelKey] : model)">{{ option[optionLabel] }}</option>
      <option v-if="containsOnlyBasicTypes(options)" v-for="option of options" :key="option" :value="option" :selected="option === model">{{ option }}</option>
    </select>
    <div v-if="label && error" class="absolute -bottom-5 right-0">
      <span class="text-xxs text-error">{{ error }}</span>
    </div>
  </label>
</template>

<style scoped>
</style>