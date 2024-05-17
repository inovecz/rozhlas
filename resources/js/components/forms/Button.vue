<script setup>
const props = defineProps({
  label: {
    type: String,
    default: null
  },
  dataClass: {
    type: String,
    default: 'btn-primary'
  },
  size: {
    validator: (value, props) => ['lg', 'md', 'sm', 'xs'].includes(value),
    default: 'md'
  },
  type: {
    type: String,
    default: 'button'
  },
  icon: {
    type: String,
    default: null
  },
  disabled: {
    type: Boolean,
    default: false
  },
  routeTo: {
    type: String,
    default: null
  },
});
</script>

<template>
  <router-link v-if="routeTo" :to="{ name: routeTo }">
    <button :type="type" class="btn" :class="[dataClass, {'btn-lg': size === 'lg', 'btn-sm': size === 'sm', 'btn-xs': size === 'xs', 'btn-square': icon && !label}]"
            :disabled="disabled">
      <span v-if="icon" class="mdi" :class="[icon, {'text-xl': !label}]"></span>
      <span v-if="label">{{ label }}</span>
    </button>
  </router-link>
  <button v-else :type="type" class="btn" :class="[dataClass, {'btn-lg': size === 'lg', 'btn-md': size === 'md', 'btn-sm': size === 'sm', 'btn-xs': size === 'xs', 'btn-square': icon && !label}]"
          :disabled="disabled">
    <span v-if="icon" class="mdi" :class="[icon, {'text-xl': !label}]"></span>
    <span v-if="label">{{ label }}</span>
  </button>
</template>

<style scoped>

</style>