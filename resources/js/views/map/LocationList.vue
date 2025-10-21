<script setup>
import {onMounted, reactive, ref, watch} from "vue";
import {useToast} from "vue-toastification";
import LocationService from "../../services/LocationService.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import ModalDialog from "../../components/modals/ModalDialog.vue";
import CreateEditLocation from "../../components/modals/CreateEditLocation.vue";
import {locationStore} from "../../store/locationStore.js";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";

const componentLabels = {
  RECEIVER: 'Přijímač',
  CHARGER: 'Nabíječ',
  BIDIRECTIONAL: 'Obousměr',
  ECOTECH: 'Ekotechnika',
  CURRENT_LOOP: 'Proudová smyčka',
  BAT_REP_TEST: 'BAT+REP Test',
  DIGITAL_INTERFACE: 'Digitální interface',
  DIGITAL_BIDIRECTIONAL: 'Digitální obousměr',
};

const locations = ref([]);
const locationGroups = ref([]);
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
  updateLocationGroups();
}, {deep: true});

function updateLocationGroups() {
  locationGroups.value = locationStoreInfo.locationGroups;
}

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
  locations.value = [...locations.value].sort((a, b) => {
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
    locationGroups: locationGroups.value,
    location: {...location}
  });
  reveal();
  onConfirm((updatedLocation) => {
    delete updatedLocation.location_group;
    delete updatedLocation.assigned_location_groups;
    LocationService.updateRecords(updatedLocation).then(() => {
      toast.success('Záznam byl úspěšně upraven');
      emitter.emit('refetchLocations');
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se upravit záznam');
    });
  });
}

function deleteLocation(id) {
  const foundLocation = locationStoreInfo.locations.find(location => location.id === id);
  console.log(foundLocation)
  const {reveal, onConfirm} = createConfirmDialog(ModalDialog, {
    title: 'Opravdu si přejete smazat místo?',
    message: `Místo ${foundLocation.name} bude trvale smazáno. Tato akce je nevratná.`
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
  <Box label="Seznam míst">
    <template #header>
      <Input v-model="search.value" icon="mdi-magnify" :eraseable="true" placeholder="Hledat" data-class="input-bordered input-sm"/>
    </template>

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
            <th @click="orderBy('modbus_address')">
              <div class="flex items-center cursor-pointer underline">
                Modbus adresa
                <span v-if="orderColumn === 'modbus_address'">
                  <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th>Adresa obousměru</th>
            <th>Privátní adresa</th>
            <th>
              <div class="flex items-center">
                Pozice
              </div>
            </th>
            <th>Součásti</th>
            <th>Další lokality</th>
            <th @click="orderBy('status')">
              <div class="flex items-center cursor-pointer underline">
                Stav
                <span v-if="orderColumn === 'status'">
                  <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
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
                  <div v-if="location.location_group" class="text-xs text-secondary">{{ location.location_group.name }}</div>
                </div>
              </div>
            </td>
            <td>
              {{ location.type === 'CENTRAL' ? 'Centrála' : 'Hnízdo' }}
            </td>
            <td>
              {{ typeof location.modbus_address === 'number' ? location.modbus_address : (location.modbus_address ?? '-') }}
            </td>
            <td>
              {{ typeof location.bidirectional_address === 'number' ? location.bidirectional_address : (location.bidirectional_address ?? '-') }}
            </td>
            <td>
              {{ typeof location.private_receiver_address === 'number' ? location.private_receiver_address : (location.private_receiver_address ?? '-') }}
            </td>
            <td>
              <span class="text-xs spacing">
                {{ location.latitude.toFixed(8) }}<br/>{{ location.longitude.toFixed(8) }}
              </span>
            </td>
            <td class="text-xs">
              <template v-if="Array.isArray(location.components) && location.components.length">
                {{ location.components.map(component => componentLabels[component] ?? component).join(', ') }}
              </template>
              <template v-else>—</template>
            </td>
            <td class="text-xs">
              <template v-if="Array.isArray(location.assigned_location_groups) && location.assigned_location_groups.length">
                {{ location.assigned_location_groups.map(group => group.name).join(', ') }}
              </template>
              <template v-else>—</template>
            </td>
            <td>
              <div class="flex items-center gap-2">
                <span class="mdi mdi-circle text-xs" :class="{
                  'text-green-500': location.status === 'OK',
                  'text-orange-500': location.status === 'WARNING',
                  'text-red-500': location.status === 'ERROR',
                  'text-blue-500': location.status === 'UNKNOWN'
                }"></span>
                <span class="text-sm">{{ location.status_label ?? location.status ?? 'Neznámé' }}</span>
              </div>
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
    </div>

  </Box>
</template>

<style scoped>
</style>
