<template>
  <div class="flex flex-col h-screen">
    <!-- navbar -->
    <div class="bg-zinc-800 py-4 px-4 text-light-grey flex justify-between items-center fixed left-0 right-0 z-10">
      <div class="flex space-x-3">
        <span class="mdi mdi-broadcast text-3xl text-white"></span>
        <span class="text-white text-3xl font-light">Sarrah IV</span>
      </div>
    </div>

    <router-view/>

    <!-- footer -->

  </div>
</template>
<script setup>
</script>