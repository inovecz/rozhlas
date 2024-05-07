<script setup>
import LocationOverview from "./LocationOverview.vue";
import LocationList from "./LocationList.vue";
import LocationService from "../../services/LocationService.js";
import {useToast} from "vue-toastification";
import {onMounted, reactive, ref} from "vue";
import {locationStore} from "../../store/locationStore";

let orderColumn = 'scheduled_at';
let orderAsc = true;
const pageLength = ref(5);
const search = reactive({value: null});
const toast = useToast();

const locationStoreInfo = locationStore();

onMounted(() => {
  fetchLocations();
});

emitter.on('refetchLocations', () => {
  fetchLocations();
});

function fetchLocations(paginatorUrl) {
  LocationService.fetchRecords(false, paginatorUrl, search.value, pageLength.value, orderColumn, orderAsc).then(response => {
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