<script setup>

const model = defineModel();

const props = defineProps({
  label: {
    type: String,
    default: ''
  },
  type: {
    type: String,
    default: 'text'
  },
  dataClass: {
    type: String,
    default: 'input-bordered'
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
  },
  icon: {
    type: String,
    default: null
  },
  eraseable: {
    type: Boolean,
    default: false
  },
  badge: {
    type: String,
    default: ''
  },
  autocompleteOff: {
    type: Boolean,
    default: false
  },
  autofocus: {
    type: Boolean,
    default: false
  }
});
</script>

<template>
  <label class="form-control" :class="{'relative mb-4' : label}">
    <div v-if="label" class="label">
      <span :class="'label-text' + (error ? ' text-error' : '')" v-html="label"/>
    </div>
    <label class="input w-full flex items-center"
           :class="[dataClass, {'input-lg': size === 'lg', 'input-md': size === 'md', 'input-sm': size === 'sm', 'input-xs': size === 'xs', 'input-error': error, 'gap-2': icon}]">
      <span v-if="icon" class="mdi" :class="icon"></span>
      <input v-model="model" :type="type" class="grow" :placeholder="placeholder" :autocomplete="autocompleteOff ? 'off' : null" :autofocus="autofocus ? true : null"/>
      <span v-if="eraseable" @click="() => {model = null}" class="mdi mdi-eraser text-secondary opacity-70 cursor-pointer"></span>
      <span v-if="badge" class="badge badge-info">{{ badge }}</span>
    </label>
    <div v-if="label && error" class="absolute -bottom-5 right-0">
      <span class="text-xxs text-error">{{ error }}</span>
    </div>
  </label>
</template>

<style scoped>
</style>