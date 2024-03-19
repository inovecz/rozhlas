<template>
  <router-link
      :to="{ name: to }"
      class="flex items-center gap-2 px-3 py-2 hover:bg-light-gold transition duration-200 cursor-pointer"
  >
    <slot></slot>
  </router-link>
</template>

<script>
export default {
  props: {
    to: {
      required: true,
      type: String,
      default: "LiveBroadcast",
    },
  },
  computed: {
    routeName() {
      return this.$route.name;
    },
  },
};
</script>

<style scoped>
.router-link-active,
.router-link-exact-active {
  background: #4c4c54;
}
</style>