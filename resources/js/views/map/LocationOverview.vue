<script setup>
import "leaflet/dist/leaflet.css"
import "leaflet-extra-markers/dist/css/leaflet.extra-markers.min.css"
import * as L from "leaflet";
import "leaflet-extra-markers/dist/js/leaflet.extra-markers.js";
import {LMap, LMarker, LPopup, LTileLayer} from "@vue-leaflet/vue-leaflet"
import {ref, watch} from "vue";
import {useToast} from "vue-toastification";
import LocationService from "../../services/LocationService.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import CreateEditLocation from "../../components/modals/CreateEditLocation.vue";
import {generateRandomString} from "../../helper.js";
import {locationStore} from "../../store/locationStore.js";
import Box from "../../components/custom/Box.vue";

const map = ref();
const zoom = ref(18);
const center = ref([49.454, 17.978]);
const dragAndDrop = ref(false);
const toggleDranAndDropButton = ref();
const locationStoreInfo = locationStore();
const locationGroups = ref([]);
const toast = useToast();

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

emitter.on('locateOnMap', (center) => {
  center.value = center;
  map.value.leafletObject.setView(new L.LatLng(center.value[0], center.value[1]), 18)
});

watch(locationStoreInfo, () => {
  updateLocationGroups();
}, {deep: true});

function updateLocationGroups() {
  locationGroups.value = locationStoreInfo.locationGroups;
}

const statusColors = {
  OK: 'green',
  WARNING: 'orange',
  ERROR: 'red',
  UNKNOWN: 'pink',
};

const centralIcon = L.ExtraMarkers.icon({
  icon: 'mdi-volume-high',
  markerColor: 'orange',
  shape: 'square',
  prefix: 'mdi',
  iconColor: 'white'
});

const newIcon = L.ExtraMarkers.icon({
  icon: 'mdi-plus',
  markerColor: 'green',
  shape: 'square',
  prefix: 'mdi',
  iconColor: 'white'
});

const nestIconCache = new Map();

const getNestIcon = (status) => {
  const key = status ?? 'UNKNOWN';
  if (nestIconCache.has(key)) {
    return nestIconCache.get(key);
  }
  const markerColor = statusColors[key] ?? statusColors.UNKNOWN;
  const icon = L.ExtraMarkers.icon({
    icon: 'mdi-broadcast',
    markerColor,
    shape: 'square',
    prefix: 'mdi',
    iconColor: markerColor === 'white' ? 'black' : 'white'
  });
  nestIconCache.set(key, icon);
  return icon;
};

const getMarkerIcon = (location) => {
  if (location.isNew) {
    return newIcon;
  }
  if (location.type === 'CENTRAL') {
    return centralIcon;
  }
  const status = location.status ?? 'UNKNOWN';
  return getNestIcon(status);
};

function toggleDragAndDrop(value) {
  if (dragAndDrop.value && value !== true) {
    const updatedLocations = locationStoreInfo.locations.filter((location) => location.updated);
    if (updatedLocations.length > 0) {
      // remove isNew flag from updated locations
      updatedLocations.forEach((location) => {
        delete location.isNew;
        delete location.hash;
      });
      LocationService.updateRecords(updatedLocations).then(response => {
        toast.success('Místa byla úspěšně uložena');
        emitter.emit('refetchLocations');
      }).catch(error => {
        toast.error('Místa se nepodařilo uložit');
        console.error(error);
      });
    } else {
      toast.info('Nebyla provedena žádná změna v rozmístění míst');
    }
    toggleDranAndDropButton.value.innerText = "Upravit rozmístění";
  } else {
    toggleDranAndDropButton.value.innerText = "Uložit";
  }
  dragAndDrop.value = !dragAndDrop.value;
}

function locationPositionUpdated(event, locationId) {
  const location = event.target;
  if (location.dragging.enabled()) {
    const position = location.getLatLng();
    locationStoreInfo.locations.forEach((location) => {
      if (location.hash === locationId) {
        location.latitude = position.lat;
        location.longitude = position.lng;
        location.updated = true;
      } else if (location.id === locationId) {
        location.latitude = position.lat;
        location.longitude = position.lng;
        location.updated = true;
      }
    });
  }
}

function addLocationMarker() {
  const newLocation = {
    id: null,
    name: 'Nové místo',
    location_group_id: null,
    latitude: center.value[0],
    longitude: center.value[1],
    type: 'NEST',
    is_active: true,
    modbus_address: null,
    bidirectional_address: null,
    private_receiver_address: null,
    components: [],
    status: 'OK',
    status_label: 'V pořádku',
    location_group_ids: [],
    assigned_location_groups: [],
    updated: true,
    isNew: true,
    hash: generateRandomString(16)
  };

  const {reveal, onConfirm} = createConfirmDialog(CreateEditLocation, {
    locationGroups: locationGroups.value,
    location: newLocation,
  });
  reveal();
  onConfirm((location) => {
    locationStoreInfo.locations.push(location);
    if (!dragAndDrop.value) {
      toggleDragAndDrop(true);
    }
  });
}

function setNewCenter() {
  const newCenter = map.value.leafletObject.getCenter();
  center.value = [newCenter.lat, newCenter.lng]
}

function filterList(locationId) {
  if (!locationId) {
    return;
  }
  if (!dragAndDrop.value) {
    emitter.emit('filterListById', locationId);
  }
}
</script>

<template>
  <Box label="Přehled míst">
    <template #header>
      <button @click="addLocationMarker" class="btn btn-sm btn-primary">Přidat místo</button>
      <button @click="toggleDragAndDrop" ref="toggleDranAndDropButton" class="btn btn-sm" :class="dragAndDrop === true ? 'btn-secondary' : 'btn-primary'">Upravit rozmístění</button>
    </template>

    <div id="map" class="w-full h-96">
      <l-map ref="map" :zoom="zoom" :center="center" :use-global-leaflet="false" @mouseup="setNewCenter" class="z-10">
        <l-tile-layer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                      layer-type="base"
                      name="OpenStreetMap"/>
        <l-marker
            v-if="locationStoreInfo.locations?.length > 0"
            v-for="location in locationStoreInfo.locations"
            :key="location.id ?? location.hash"
            :draggable="dragAndDrop"
            @click="filterList(location.id)"
            @mouseup="locationPositionUpdated($event, location.isNew ? location.hash : location.id)"
            :lat-lng="[location.latitude, location.longitude]"
            :icon="getMarkerIcon(location)">
          <L-popup>
            <div class="space-y-1 text-sm">
              <div class="font-semibold">{{ location.name }}</div>
              <div v-if="location.status_label" class="flex items-center gap-1">
                <span class="mdi mdi-circle" :class="{
                  'text-green-500': location.status === 'OK',
                  'text-orange-500': location.status === 'WARNING',
                  'text-red-500': location.status === 'ERROR',
                  'text-pink-500': location.status === 'UNKNOWN'
                }"></span>
                <span>{{ location.status_label }}</span>
              </div>
              <div v-if="Array.isArray(location.components) && location.components.length" class="text-xs text-gray-500">
                Součásti: {{ location.components.map(component => componentLabels[component] ?? component).join(', ') }}
              </div>
            </div>
          </L-popup>
        </l-marker>
      </l-map>
    </div>
  </Box>

</template>

<style scoped>
</style>
