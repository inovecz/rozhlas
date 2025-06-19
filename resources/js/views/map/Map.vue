<script setup>
import LocationOverview from "./LocationOverview.vue";
import LocationList from "./LocationList.vue";
import LocationService from "../../services/LocationService.js";
import {useToast} from "vue-toastification";
import {onMounted} from "vue";
import {locationStore} from "../../store/locationStore";
import {useDataTables} from "../../utils/datatablesTrait.js";
import PageContent from "../../components/custom/PageContent.vue";

const toast = useToast();
const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchLocations, 'scheduled_at');

const locationStoreInfo = locationStore();

onMounted(() => {
  fetchLocations();
  getAllLocationGroups();
});

emitter.on('refetchLocations', () => {
  fetchLocations();
});

function fetchLocations(paginatorUrl) {
  LocationService.fetchRecords(false, paginatorUrl, search.value, pageLength.value, orderColumn.value, orderAsc.value).then(response => {
    locationStoreInfo.locations = response.data;
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst seznam míst');
  });
}

function getAllLocationGroups() {
  LocationService.getAllLocationGroups('select').then(response => {
    locationStoreInfo.locationGroups = [{id: null, name: 'Nepřiřazeno'}, ...response];
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se načíst seznam lokací');
  });
}
</script>

<template>
  <PageContent label="Mapa">
    <LocationOverview/>
    <LocationList/>
  </PageContent>
</template>

<style scoped>
</style>