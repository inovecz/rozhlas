<script setup>

import {onMounted, reactive, ref, watch} from "vue";
import {durationToTime, formatBytes, formatDate} from "../../helper.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import ModalDialog from "../../components/modals/ModalDialog.vue";
import {useToast} from "vue-toastification";
import {useDataTables} from "../../utils/datatablesTrait.js";
import Box from "../../components/custom/Box.vue";
import Select from "../../components/forms/Select.vue";
import Input from "../../components/forms/Input.vue";
import Button from "../../components/forms/Button.vue";

const records = ref([]);
const playingId = ref(null);
const recordsCache = [];
const typeFilter = reactive({value: 'ALL'})
const toast = useToast();

const {fetchRecords, orderAsc, orderBy, orderColumn, pageLength, search} = useDataTables(fetchRecordings, 'created_at', 5, false);

onMounted(() => {
  fetchRecords();
});

function fetchRecordings(paginatorUrl) {
  let page = 1;
  if (paginatorUrl) {
    page = paginatorUrl.match(/page=(\d+)/);
    page = page ? parseInt(page[1]) : 1;
  }

  let filter = [];
  if (typeFilter.value !== 'ALL') {
    filter.push({'column': 'subtype', 'value': typeFilter.value});
  }

  http.post('records/list', {
    type: 'RECORDING',
    page,
    search: search.value,
    length: pageLength.value,
    order: [{'column': orderColumn.value, 'dir': orderAsc.value ? 'asc' : 'desc'}],
    filter
  }).then(response => {
    records.value = response.data;
  }).catch(error => {
    console.error(error);
  });
}

watch(typeFilter, () => fetchRecords());

function playRecord(id) {

  if (playingId.value !== null) {
    const playPauseButton = document.getElementById('playPauseButton-' + playingId.value);
    const audioPlayer = document.getElementById('audioPlayer-' + playingId.value);
    playPauseButton.innerHTML = '<span class="mdi mdi-play text-emerald-500 text-xl"></span>';
    audioPlayer.pause();
  }

  if (playingId.value !== id || playingId.value === null) {
    const playPauseButton = document.getElementById('playPauseButton-' + id);
    playPauseButton.innerHTML = '<span class="mdi mdi-loading mdi-spin text-gray-500 text-xl"></span>';
    getRecordRaw(id).then(({rawAudio, mime}) => {
      playingId.value = id;
      const audioOutputDevice = JSON.parse(localStorage.getItem('audioOutputDevice')) ?? 'default';
      const audioBlob = new Blob([rawAudio], {type: mime});

      playPauseButton.innerHTML = '<span class="mdi mdi-pause text-gray-500 text-xl"></span>';
      const audioPlayer = document.getElementById('audioPlayer-' + id);
      audioPlayer.src = URL.createObjectURL(audioBlob);
      audioPlayer.setSinkId(audioOutputDevice.id);
      audioPlayer.play();
    });
  } else {
    playingId.value = null;
  }
}

async function getRecordRaw(id) {
  try {
    if (recordsCache[id]) {
      return recordsCache[id];
    } else {
      const response = await http.get(`records/${id}/get-blob`, {responseType: 'arraybuffer'});
      const rawAudioObject = {rawAudio: response.data, mime: response.headers['content-type']};
      recordsCache[id] = rawAudioObject;
      return rawAudioObject;
    }
  } catch (error) {
    throw error;
  }
}

function deleteRecord(id) {
  const {reveal, onConfirm, onCancel} = createConfirmDialog(ModalDialog, {
    title: 'Opravdu si přejete smazat záznam?',
    message: 'Tato akce je nevratná, dojde k trvalému smazání nahrávky.'
  });
  reveal();
  onConfirm(() => {
    http.delete(`records/${id}`).then(() => {
      toast.success('Nahrávka byla úspěšně smazána.')
      fetchRecords();
    }).catch(error => {
      toast.error('Nahrávku se nepodařilo smazat.')
      console.error(error);
    });
  });
}

function renameRecord(id) {
  const {reveal, onConfirm, onCancel} = createConfirmDialog(ModalDialog, {
    title: 'Přejmenování nahrávky',
    message: 'Zadejte nový název nahrávky:',
    useInput: records.value.data.filter(record => record.id === id)[0].name,
  });
  reveal();
  onConfirm((newName) => {
    http.put(`records/${id}/rename`, {name: newName}).then(() => {
      toast.success('Nahrávka byla úspěšně přejmenována.');
      fetchRecords();
    }).catch(error => {
      toast.error('Nahrávku se nepodařilo přejmenovat.');
      console.error(error);
    });
  });
}

emitter.on('recordSaved', () => {
  fetchRecords();
});
</script>

<template>
  <Box label="Seznam nahrávek">
    <template #header>
      <Select v-model="typeFilter.value" :options="[
        {value: 'ALL', label: 'Vše'},
        {value: 'COMMON', label: 'Běžné hlášení'},
        {value: 'OPENING', label: 'Úvodní slovo'},
        {value: 'CLOSING', label: 'Závěrečné slovo'},
        {value: 'INTRO', label: 'Úvodní znělka'},
        {value: 'OUTRO', label: 'Závěrečná znělka'},
        {value: 'OTHER', label: 'Ostatní'},
      ]" data-class="select-bordered select-sm"/>
      <Input v-model="search.value" icon="mdi-magnify" :eraseable="true" placeholder="Hledat" data-class="input-bordered input-sm"/>
    </template>

    <div class="overflow-x-auto">
      <table class="table">
        <!-- head -->
        <thead>
          <tr>
            <th @click="orderBy('name')">
              <div class="flex items-center cursor-pointer">
                Název
                <span v-if="orderColumn.value === 'name'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('subtype')">
              <div class="flex items-center cursor-pointer">
                Typ
                <span v-if="orderColumn.value === 'subtype'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('created_at')">
              <div class="flex items-center cursor-pointer">
                Nahráno
                <span v-if="orderColumn.value === 'created_at'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('size')">
              <div class="flex items-center cursor-pointer">
                Velikost
                <span v-if="orderColumn.value === 'size'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th @click="orderBy('metadata->duration')">
              <div class="flex items-center cursor-pointer">
                Délka
                <span v-if="orderColumn.value === 'metadata->duration'">
                  <span v-if="orderAsc.value" class="mdi mdi-triangle-small-up text-lg"></span>
                  <span v-if="!orderAsc.value" class="mdi mdi-triangle-small-down text-lg"></span>
                </span>
              </div>
            </th>
            <th class="text-right">Akce</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="record in records.data" :key="record.id" class="hover">
            <td>
              <div class="flex items-center gap-3">
                <div>
                  <div class="font-bold">{{ record.name }}</div>
                </div>
              </div>
            </td>
            <td>
              {{ record.subtype_translated }}
            </td>
            <td>
              {{ formatDate(new Date(record.created_at), 'd.m.Y H:i:s') }}
            </td>
            <td>
              {{ formatBytes(record.size, 1) }}
            </td>
            <td>
              {{ durationToTime(record.metadata.duration) }}
            </td>
            <td class="flex gap-2 justify-end items-center pt-4">
              <button @click="renameRecord(record.id)"><span class="mdi mdi-rename text-primary text-xl"></span></button>
              <button :id="'playPauseButton-'+record.id" @click="playRecord(record.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
              <audio :id="'audioPlayer-'+record.id" @ended="playRecord(record.id)"></audio>
              <button @click="deleteRecord(record.id)"><span class="mdi mdi-trash-can text-red-500 text-xl"></span></button>
            </td>
          </tr>
        </tbody>
      </table>
      <div v-if="records?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
      <div class="flex justify-between items-center py-2 px-1">
        <div>
          <select v-model="pageLength.value" @change="fetchRecords()" class="select select-sm select-bordered w-full max-w-xs">
            <option value="5">5</option>
            <option value="10">10</option>
            <option value="25">25</option>
            <option value="50">50</option>
          </select>
        </div>
        <div v-if="records?.meta?.last_page > 1">
          <div class="flex justify-center items-center">
            <div class="join join-horizontal">
              <template v-for="page in records?.meta?.links">
                <button @click="fetchRecords(page.url)" :disabled="page.url === null" class="btn btn-sm join-item" :class="{['btn-primary']: page.active}">{{ page.label }}</button>
              </template>
            </div>
          </div>
        </div>
      </div>
    </div>

  </Box>
</template>

<style scoped>
@keyframes spin-animation {
  to {
    -webkit-transform: rotate(360deg);
    transform: rotate(360deg);
  }
}

.spinner:before {
  display: block;
  transform-origin: center center;
  -webkit-backface-visibility: hidden;
  -webkit-animation: spin-animation 2s linear infinite;
  animation: spin-animation 2s linear infinite;
}
</style>