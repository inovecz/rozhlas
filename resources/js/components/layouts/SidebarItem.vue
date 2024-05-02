<script setup>

const props = defineProps({
  to: {
    type: String,
    required: true,
    default: "LiveBroadcast"
  }
});
</script>

<template>
  <router-link
      :to="{ name: to }"
      class="flex items-center justify-between gap-2 px-3 py-2 hover:bg-primary hover:text-primary-content transition duration-200 cursor-pointer border-b-2 border-b-base-300"
  >
    <slot></slot>
  </router-link>
</template>

<style scoped>
.router-link-active,
.router-link-exact-active {
  @apply bg-slate-600;
}
</style>