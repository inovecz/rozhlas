<script setup>

import {onMounted, ref} from "vue";
import {dtToTime, durationToTime, formatBytes} from "../../helper.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import ModalDialog from "../../components/modals/ModalDialog.vue";

const records = ref([]);
const playingId = ref(null);
const recordsCache = [];
let orderColumn = 'created_at';
let orderAsc = false;

onMounted(() => {
  fetchRecords();
});


function fetchRecords() {
  http.post('records/list', {
    type: 'RECORD',
    order: [{'column': orderColumn, 'dir': orderAsc ? 'asc' : 'desc'}]
  }).then(response => {
    records.value = response.data.data;
  }).catch(error => {
    console.error(error);
  });
}

function orderBy(column) {
  if (orderColumn === column) {
    orderAsc = !orderAsc;
  } else {
    orderColumn = column;
    orderAsc = true;
  }
  fetchRecords();
}

function playRecord(id) {

  if (playingId.value !== null) {
    const playPauseButton = document.getElementById('playPauseButton-' + playingId.value);
    const audioPlayer = document.getElementById('audioPlayer-' + playingId.value);
    playPauseButton.innerHTML = '<span class="mdi mdi-play text-emerald-500 text-xl"></span>';
    audioPlayer.pause();
  }

  if (playingId.value !== id || playingId.value === null) {
    getRecordRaw(id).then(({rawAudio, mime}) => {
      playingId.value = id;
      const audioOutputDevice = JSON.parse(localStorage.getItem('audioOutputDevice')) ?? 'default';
      const audioBlob = new Blob([rawAudio], {type: mime});
      const playPauseButton = document.getElementById('playPauseButton-' + id);
      playPauseButton.innerHTML = '<span class="mdi mdi-pause text-gray-500 text-xl"></span>';
      const audioPlayer = document.getElementById('audioPlayer-' + id);
      audioPlayer.src = URL.createObjectURL(audioBlob);
      audioPlayer.setSinkId(audioOutputDevice.id);
      audioPlayer.play();
    });
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
      fetchRecords();
    }).catch(error => {
      console.error(error);
    });
  });
}

emitter.on('recordSaved', () => {
  fetchRecords();
});
</script>

<template>
  <div class="border border-secondary/50 rounded-md px-5 py-2">
    <div class="text-xl text-primary mb-4 mt-3 px-1">
      Seznam nahrávek
    </div>
    <div class="overflow-x-auto">
      <table class="table table-pin-cols">
        <!-- head -->
        <thead>
          <tr>
            <th @click="orderBy('name')">
              <div class="flex items-center cursor-pointer">
                Název
                <span v-if="orderColumn === 'name'">
                <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
              </span>
              </div>
            </th>
            <th @click="orderBy('created_at')">
              <div class="flex items-center cursor-pointer">
                Nahráno
                <span v-if="orderColumn === 'created_at'">
                <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
              </span>
              </div>
            </th>
            <th @click="orderBy('size')">
              <div class="flex items-center cursor-pointer">
                Velikost
                <span v-if="orderColumn === 'size'">
                <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
              </span>
              </div>
            </th>
            <th @click="orderBy('metadata->duration')">
              <div class="flex items-center cursor-pointer">
                Délka
                <span v-if="orderColumn === 'metadata->duration'">
                <span v-if="orderAsc" class="mdi mdi-triangle-small-up text-lg"></span>
                <span v-if="!orderAsc" class="mdi mdi-triangle-small-down text-lg"></span>
              </span>
              </div>
            </th>
            <th class="text-right">Akce</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="record in records" :key="record.id" class="hover">
            <td>
              <div class="flex items-center gap-3">
                <div>
                  <div class="font-bold">{{ record.name }}</div>
                </div>
              </div>
            </td>
            <td>
              {{ dtToTime(record.created_at) }}
            </td>
            <td>
              {{ formatBytes(record.size, 1) }}
            </td>
            <td>
              {{ durationToTime(record.metadata.duration) }}
            </td>
            <td class="flex space-x-2 justify-end">
              <button :id="'playPauseButton-'+record.id" @click="playRecord(record.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
              <audio :id="'audioPlayer-'+record.id" @ended="playRecord(record.id)"></audio>
              <button @click="deleteRecord(record.id)"><span class="mdi mdi-trash-can text-red-500 text-xl"></span></button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
