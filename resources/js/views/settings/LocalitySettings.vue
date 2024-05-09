<script setup>

import {useToast} from "vue-toastification";
import {useDataTables} from "../../utils/datatablesTrait.js";
import LocationService from "../../services/LocationService.js";
import {ref} from "vue";

const localities = ref([]);
const toast = useToast();
const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchLocalities, 'name', 5);

function fetchLocalities(paginatorUrl = null) {
  LocationService.fetchLocationGroups(paginatorUrl, search.value, pageLength.value, orderColumn.value, orderAsc.value).then(response => {
    localities.value = response;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst data');
  });
}
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Nastavení lokalit</h1>
    <div class="content flex flex-col space-y-4">
      <div class="component-box">
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>