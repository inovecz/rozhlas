<script setup>
import LocationOverview from "./LocationOverview.vue";
import LocationList from "./LocationList.vue";
import LocationService from "../../services/LocationService.js";
import {useToast} from "vue-toastification";
import {onMounted} from "vue";
import {locationStore} from "../../store/locationStore";
import {useDataTables} from "../../utils/datatablesTrait.js";

const toast = useToast();
const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchLocations, 'scheduled_at');

const locationStoreInfo = locationStore();

onMounted(() => {
  fetchLocations();
});

emitter.on('refetchLocations', () => {
  fetchLocations();
});

function fetchLocations(paginatorUrl) {
  LocationService.fetchRecords(false, paginatorUrl, search.value, pageLength.value, orderColumn.value, orderAsc.value).then(response => {
    locationStoreInfo.locations = response.data;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst seznam lokalit');
  });
}
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Mapa</h1>
    <div class="content flex flex-col space-y-4">
      <LocationOverview/>
      <LocationList/>
    </div>
  </div>
</template>

<style scoped>

</style>