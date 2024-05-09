<script setup>
import "leaflet/dist/leaflet.css"
import "leaflet-extra-markers/dist/css/leaflet.extra-markers.min.css"
import * as L from "leaflet";
import "leaflet-extra-markers/dist/js/leaflet.extra-markers.js";
import {LMap, LMarker, LPopup, LTileLayer} from "@vue-leaflet/vue-leaflet"
import {ref} from "vue";
import {useToast} from "vue-toastification";
import LocationService from "../../services/LocationService.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import CreateEditLocation from "../../components/modals/CreateEditLocation.vue";
import {generateRandomString} from "../../helper.js";
import {locationStore} from "../../store/locationStore.js";

const map = ref();
const zoom = ref(18);
const center = ref([49.454, 17.978]);
const dragAndDrop = ref(false);
const toggleDranAndDropButton = ref();

const locationStoreInfo = locationStore();

emitter.on('locateOnMap', (center) => {
  center.value = center;
  map.value.leafletObject.setView(new L.LatLng(center.value[0], center.value[1]), 18)
});

const icons = {
  NEST: L.ExtraMarkers.icon({
    icon: 'mdi-broadcast',
    markerColor: 'white',
    shape: 'square',
    prefix: 'mdi',
    iconColor: 'black'
  }),
  CENTRAL: L.ExtraMarkers.icon({
    icon: 'mdi-volume-high',
    markerColor: 'orange',
    shape: 'square',
    prefix: 'mdi',
    iconColor: 'white'
  }),
  NEW: L.ExtraMarkers.icon({
    icon: 'mdi-plus',
    markerColor: 'green',
    shape: 'square',
    prefix: 'mdi',
    iconColor: 'white'
  })
};

const toast = useToast();


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
        emitter.emit('refetchLocationsList');
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
    latitude: center.value[0],
    longitude: center.value[1],
    type: 'NEST',
    is_active: true,
    updated: true,
    isNew: true,
    hash: generateRandomString(16)
  };

  const {reveal, onConfirm} = createConfirmDialog(CreateEditLocation, {
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
  if (!dragAndDrop.value) {
    emitter.emit('filterListById', locationId);
  }
}
</script>

<template>
  <div class="component-box">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between">
      <div class="text-xl text-primary mb-4 mt-3 px-1">
        Přehled míst
      </div>
      <div class="space-x-2">
        <button @click="addLocationMarker" class="btn btn-sm btn-primary">Přidat místo</button>
        <button @click="toggleDragAndDrop" ref="toggleDranAndDropButton" class="btn btn-sm" :class="dragAndDrop === true ? 'btn-secondary' : 'btn-primary'">Upravit rozmístění</button>
      </div>
    </div>

    <div id="map" class="w-full h-96">
      <!--        <iframe class="w-full min-h-96" src="https://www.openstreetmap.org/export/embed.html?bbox=17.97043561935425%2C49.45124805196347%2C17.98269867897034%2C49.45675780335221&amp;layer=mapnik" style="border: 1px solid black"></iframe>-->
      <l-map ref="map" :zoom="zoom" :center="center" :use-global-leaflet="false" @mouseup="setNewCenter" class="z-10">
        <l-tile-layer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                      layer-type="base"
                      name="OpenStreetMap"/>
        <l-marker v-if="locationStoreInfo.locations?.length > 0" v-for="location in locationStoreInfo.locations" :key="location.id" :draggable="dragAndDrop" @click="filterList(location.id)" @mouseup="locationPositionUpdated($event, location.isNew ? location.hash : location.id)" :lat-lng="[location.latitude, location.longitude]" :icon="location.isNew ? icons.NEW : icons[location.type]">
          <L-popup>
            {{ location.name }}
          </L-popup>
        </l-marker>
      </l-map>
    </div>
  </div>
</template>

<style scoped>

</style>