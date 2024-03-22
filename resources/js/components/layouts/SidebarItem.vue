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
      class="flex items-center gap-2 px-3 py-2 hover:bg-light-gold transition duration-200 cursor-pointer"
  >
    <slot></slot>
  </router-link>
</template>

<style scoped>
.router-link-active,
.router-link-exact-active {
  background: #4c4c54;
}
</style>