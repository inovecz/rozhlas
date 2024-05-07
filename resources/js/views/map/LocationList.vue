<script setup>
import {onMounted, reactive, ref, watch} from "vue";
import {useToast} from "vue-toastification";
import LocationService from "../../services/LocationService.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import ModalDialog from "../../components/modals/ModalDialog.vue";
import CreateEditLocation from "../../components/modals/CreateEditLocation.vue";
import {locationStore} from "../../store/locationStore.js";

const locations = ref([]);
let orderColumn = 'id';
let orderAsc = false;
const search = reactive({value: null});
const toast = useToast();


const locationStoreInfo = locationStore();

onMounted(() => {
  filterLocations();
});

watch(search, debounce(() => {
  filterLocations();
}, 300));

emitter.on('filterListById', (id) => {
  search.value = `id:${id}`;
  filterLocations();
});

watch(locationStoreInfo, () => {
  filterLocations();
});

function filterLocations() {
  if (search.value !== null && search.value !== '') {
    if (search.value.startsWith('id:')) {
      locations.value = locationStoreInfo.locations.filter(location => {
        return location.id.toString().includes(search.value.replace('id:', ''));
      });
      return;
    }
    locations.value = locationStoreInfo.locations.filter(location => {
      return location.name.toLowerCase().includes(search.value.toLowerCase());
    });
  } else {
    locations.value = locationStoreInfo.locations;
  }
  // order
  locations.value = locations.value.sort((a, b) => {
    if (orderAsc) {
      return a[orderColumn] > b[orderColumn] ? 1 : -1;
    } else {
      return a[orderColumn] < b[orderColumn] ? 1 : -1;
    }
  });

}

function orderBy(column) {
  if (orderColumn === column) {
    orderAsc = !orderAsc;
  } else {
    orderColumn = column;
    orderAsc = true;
  }
  filterLocations();
}

function locateOnMap(locationId) {
  const location = locationStoreInfo.locations.find(location => location.id === locationId);
  const center = [location.latitude, location.longitude];
  emitter.emit('locateOnMap', center);
}

function editLocation(id) {
  const location = locationStoreInfo.locations.find(location => location.id === id);
  const {reveal, onConfirm} = createConfirmDialog(CreateEditLocation, {
    location: location
  });
  reveal();
  onConfirm((location) => {
    LocationService.updateRecords(location).then(() => {
      toast.success('Záznam byl úspěšně upraven');
      emitter.emit('refetchLocations');
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se upravit záznam');
    });
  });
}

function deleteLocation(id) {
  const {reveal, onConfirm} = createConfirmDialog(ModalDialog, {
    title: 'Opravdu si přejete smazat lokaci?',
    message: 'Tato akce je nevratná, dojde k trvalému smazání lokace.'
  });
  reveal();
  onConfirm(() => {
    LocationService.deleteRecord(id).then(() => {
      toast.success('Záznam byl úspěšně smazán');
      emitter.emit('refetchLocations');
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se smazat záznam');
    });
  });
}
</script>

<template>
  <div class="component-box">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between">
      <div class="text-xl text-primary mb-4 mt-3 px-1">
        Seznam lokalit
      </div>
      <label class="input input-sm input-bordered flex items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 opacity-70">
          <path fill-rule="evenodd" d="M9.965 11.026a5 5 0 1 1 1.06-1.06l2.755 2.754a.75.75 0 1 1-1.06 1.06l-2.755-2.754ZM10.5 7a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Z" clip-rule="evenodd"/>
        </svg>
        <input v-model="search.value" type="text" class="grow" placeholder="Hledat"/>
        <svg @click="() => {search.value = null}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4 opacity-70 cursor-pointer">
          <path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z" fill="currentColor"></path>
        </svg>
      </label>
    </div>
    <div class="overflow-x-auto">
      <table class="table">
        <!-- head -->
        <thead>
          <tr>
            <th @click="orderBy('name')">
              <div class="flex items-center cursor-pointer underline">
                Název
                <span v-if="orderColumn === 'name'">
                  <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('type')">
              <div class="flex items-center cursor-pointer underline">
                Typ
                <span v-if="orderColumn === 'type'">
                  <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th>
              <div class="flex items-center">
                Pozice
              </div>
            </th>
            <th @click="orderBy('is_active')">
              <div class="flex items-center cursor-pointer underline">
                Aktivní
                <span v-if="orderColumn === 'is_active'">
                  <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th class="text-right">Akce</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="locations.length > 0" v-for="location in locations" :key="location.id" class="hover">
            <td>
              <div class="flex items-center gap-3">
                <div>
                  <div class="font-bold">{{ location.name }}</div>
                </div>
              </div>
            </td>
            <td>
              {{ location.type === 'CENTRAL' ? 'Centrála' : 'Hnízdo' }}
            </td>
            <td>
              <span class="text-xs spacing">
                {{ location.latitude.toFixed(8) }}<br/>{{ location.longitude.toFixed(8) }}
              </span>
            </td>
            <td>
              {{ location.is_active ? 'Ano' : 'Ne' }}
            </td>
            <td class="flex gap-2 justify-end items-center pt-4">
              <button @click="locateOnMap(location.id)"><span class="mdi mdi-target text-primary text-xl"></span></button>
              <button @click="editLocation(location.id)"><span class="mdi mdi-rename text-primary text-xl"></span></button>
              <button @click="deleteLocation(location.id)"><span class="mdi mdi-trash-can text-red-500 text-xl"></span></button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="locations?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
      <!--      <div class="flex justify-between items-center py-2 px-1">
              <div>
                <select v-model="pageLength" @change="fetchLocations()" class="select select-sm select-bordered w-full max-w-xs">
                  <option value="5">5</option>
                  <option value="10">10</option>
                  <option value="25">25</option>
                  <option value="50">50</option>
                </select>
              </div>
              <div v-if="locations?.meta?.last_page > 1">
                <div class="flex justify-center items-center">
                  <div class="join join-horizontal">
                    <template v-for="page in locations?.meta?.links">
                      <button @click="fetchRecords(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
                    </template>
                  </div>
                </div>
              </div>
            </div>-->
    </div>
  </div>
</template>

<style scoped>

</style>