<script setup>
const props = defineProps({
  label: {
    type: String,
    default: 'PageContent label'
  },
  backRoute: {
    type: String,
    default: null
  }
});
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">
      <router-link v-if="backRoute" :to="{ name: backRoute }">
        <span class="mdi mdi-chevron-left"></span>
        <span v-html="label"/>
      </router-link>
      <span v-if="!backRoute" v-html="label"/>
    </h1>
    <div class="content flex flex-col space-y-4 pb-10">
      <slot/>
    </div>
  </div>
</template>

<style scoped>
</style>