<template>
  <router-view/>
  <DialogsWrapper/>
</template>

<script setup>
import {basicStore} from './store/basicStore';

const basicStoreApi = basicStore();
</script>