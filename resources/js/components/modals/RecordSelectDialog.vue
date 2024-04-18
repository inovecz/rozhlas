<script setup>

import {onMounted, reactive, ref, watch} from "vue";
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot} from "@headlessui/vue";
import {durationToTime} from "../../helper.js";

const props = defineProps(['typeFilter', 'multiple']);

const isOpen = ref(true)

const records = ref([]);
const playingId = ref(null);
const recordsCache = [];

const selectedRecordings = ref([]);
let orderColumn = 'created_at';
let orderAsc = false;
const pageLength = ref(5)
const search = reactive({value: null})
watch(search, debounce(() => {
  fetchRecords();
}, 500));

const emit = defineEmits(['confirm', 'cancel']);

onMounted(() => {
  fetchRecords();
});


function fetchRecords(paginatorUrl) {
  let page = 1;
  if (paginatorUrl) {
    page = paginatorUrl.match(/page=(\d+)/);
    page = page ? parseInt(page[1]) : 1;
  }

  let filter = [];
  if (props.typeFilter.value !== 'ALL') {
    filter.push({'column': 'subtype', 'value': props.typeFilter});
  }

  http.post('records/list', {
    type: 'RECORDING',
    page,
    search: search.value,
    length: pageLength.value,
    order: [{'column': orderColumn, 'dir': orderAsc ? 'asc' : 'desc'}],
    filter
  }).then(response => {
    records.value = response.data;
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

const closeModalWith = (value) => {
  isOpen.value = false;
  setTimeout(() => {
    if (value === 'confirm') {
      let data = [];
      if (props.multiple) {
        selectedRecordings.value.forEach(recording => {
          data.push(records.value.data.find(record => record.id === recording));
        });
      } else {
        data = records.value.data.find(record => record.id === selectedRecordings.value);
      }
      emit('confirm', data);
    } else {
      emit('cancel');
    }
  }, 300);
}
</script>

<template>
  <TransitionRoot appear :show="isOpen" as="template">
    <Dialog as="div" @close="closeModalWith('cancel')" class="relative z-10">
      <TransitionChild
          as="template"
          enter="duration-300 ease-out"
          enter-from="opacity-0"
          enter-to="opacity-100"
          leave="duration-200 ease-in"
          leave-from="opacity-100"
          leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black/25 backdrop-blur-sm"/>
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 text-center">
          <TransitionChild
              as="template"
              enter="duration-300 ease-out"
              enter-from="opacity-0 scale-95"
              enter-to="opacity-100 scale-100"
              leave="duration-200 ease-in"
              leave-from="opacity-100 scale-100"
              leave-to="opacity-0 scale-95">
            <DialogPanel class="w-full max-w-xl flex flex-col gap-6 transform overflow-hidden rounded-2xl glass p-6 text-left align-middle shadow-xl transition-all">
              <DialogTitle as="h3" class="flex flex-row  justify-between text-lg font-medium leading-6 text-primary">
                <div>
                  Výběr nahrávky
                </div>
                <label class="input input-sm input-bordered flex items-center gap-2">
                  <input v-model="search.value" type="text" class="grow" placeholder="Hledat"/>
                  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="w-4 h-4 opacity-70">
                    <path fill-rule="evenodd" d="M9.965 11.026a5 5 0 1 1 1.06-1.06l2.755 2.754a.75.75 0 1 1-1.06 1.06l-2.755-2.754ZM10.5 7a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0Z" clip-rule="evenodd"/>
                  </svg>
                </label>
              </DialogTitle>

              <div class="flex flex-col gap-3">

                <table class="table">
                  <!-- head -->
                  <thead>
                    <tr>
                      <th></th>
                      <th @click="orderBy('name')">
                        <div class="flex items-center cursor-pointer">
                          Název
                          <span v-if="orderColumn === 'name'">
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
                    <tr v-for="record in records.data" :key="record.id" class="hover">
                      <th>
                        <input v-if="!props.multiple" v-model="selectedRecordings" type="radio" :value="record.id" class="radio"/>
                        <input v-if="props.multiple" v-model="selectedRecordings" type="checkbox" :value="record.id" class="checkbox"/>
                      </th>
                      <td>
                        <div class="flex items-center gap-3">
                          <div>
                            <div class="font-bold">{{ record.name }}</div>
                          </div>
                        </div>
                      </td>
                      <td>
                        {{ durationToTime(record.metadata.duration) }}
                      </td>
                      <td class="text-right">
                        <button :id="'playPauseButton-'+record.id" @click="playRecord(record.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
                        <audio :id="'audioPlayer-'+record.id" @ended="playRecord(record.id)"></audio>
                      </td>
                    </tr>
                  </tbody>
                </table>
                <div v-if="records?.data?.length === 0" class="text-center py-2">Nebyla nalezena žádná data</div>
                <div class="flex justify-between items-center py-2 px-1">
                  <div>
                    <select v-model="pageLength" @change="fetchRecords()" class="select select-sm select-bordered w-full max-w-xs">
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

              <div class="flex items-center justify-end space-x-5">
                <button class="underline" @click="closeModalWith('cancel')">Zrušit</button>
                <button class="btn btn-sm btn-primary" @click="closeModalWith('confirm')">Potvrdit</button>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>

<style scoped>

</style>