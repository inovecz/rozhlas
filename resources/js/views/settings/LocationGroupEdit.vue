<script setup>
import {computed, onMounted, ref} from "vue";
import LocationService from "../../services/LocationService.js";
import {useToast} from "vue-toastification";
import {useRoute} from "vue-router";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import RecordSelectDialog from "../../components/modals/RecordSelectDialog.vue";
import router from "../../router.js";

const route = useRoute();
const editingLocationGroupId = ref(route.params.id);
const playingId = ref(null);
const recordsCache = [];
const timingList = [
  {name: 'total', label: 'Celková délka vysílání'},
  {name: 'ptt', label: 'Vysílačka (ptt)'},
  {name: 'subtone', label: 'Subtón'},
  {name: 'file', label: 'Zvukový soubor'},
  {name: 'output_1', label: 'Výstup 1'},
  {name: 'output_2', label: 'Výstup 2'},
  {name: 'output_3', label: 'Výstup 3'},
  {name: 'output_4', label: 'Výstup 4'},
  {name: 'output_5', label: 'Výstup 5'},
  {name: 'output_6', label: 'Výstup 6'},
  {name: 'output_7', label: 'Výstup 7'},
  {name: 'output_8', label: 'Výstup 8'},
  {name: 'output_9', label: 'Výstup 9'},
  {name: 'output_10', label: 'Výstup 10'},
  {name: 'output_11', label: 'Výstup 11'},
  {name: 'relay_1', label: 'Relé 1'},
  {name: 'relay_2', label: 'Relé 2'},
  {name: 'relay_3', label: 'Relé 3'},
  {name: 'relay_4', label: 'Relé 4'},
  {name: 'relay_5', label: 'Relé 5'},
];
const locationGroup = ref({
  id: null,
  name: '',
  subtone_type: 'NONE',
  subtone_data: {listen: [], record: []},
  init_audio: null,
  exit_audio: null,
  // timing is an object with keys from timingList array and values start: 0, end:0
  timing: timingList.reduce((acc, timingRecord) => {
    acc[timingRecord.name] = {start: null, end: null};
    return acc;
  }, {}),
});
const toast = useToast();
const subtoneTypes = ['NONE', 'A16', 'CTCSS_38', 'CTCSS_39', 'CTCSS_47', 'CTCSS_38N', 'CTCSS_32', 'CTCSS_EIA', 'CTCSS_ALINCO', 'CTCSS_MOTOROLA', 'DCS'];

onMounted(() => {
  if (editingLocationGroupId.value) {
    LocationService.getLocationGroup(editingLocationGroupId.value).then(response => {
      locationGroup.value = response;
    }).catch(error => {
      console.error(error);
      toast.error('Nepodařilo se načíst data');
    });
  }
})

function selectRecording(subtype) {
  const {reveal, onConfirm} = createConfirmDialog(RecordSelectDialog, {
    typeFilter: subtype === 'init_audio' ? 'INTRO' : 'OUTRO',
    multiple: false,
  });
  reveal();
  onConfirm((data) => {
    locationGroup.value[subtype] = data;
  });
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

const cantSave = computed(() => {
  let retVal = false;
  if (locationGroup.value.name.length < 3) {
    retVal = true;
  }
  return retVal;
});

function saveLocationGroup() {
  LocationService.saveLocationGroup(locationGroup.value).then(() => {
    toast.success('Lokalita byla úspěšně uložena');
    router.push({name: 'LocationGroupsSettings'});
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se uložit lokalitu');
  });
}
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">{{ locationGroup.id ? 'Úprava lokality' : 'Nová lokalita' }}</h1>
    <div class="content flex flex-col space-y-4">

      <div class="component-box">
        <div class="flex flex-col gap-4">
          <div class="flex flex-col gap-2">
            <div class="text-sm text-base-content">
              Název
            </div>
            <div>
              <input v-model="locationGroup.name" type="text" placeholder="Zadejte název (min. 3 znaky)" class="input input-bordered w-full"/>
            </div>
          </div>

          <div class="form-control">
            <label class="label cursor-pointer">
              <span class="label-text">Skrytá lokalita</span>
              <input v-model="locationGroup.is_hidden" type="checkbox" checked="checked" class="checkbox checkbox-primary"/>
            </label>
          </div>

          <div class="flex flex-col gap-2">
            <div class="text-sm text-base-content">
              Typ subtónu
            </div>
            <div>
              <select v-model="locationGroup.subtone_type" class="select select-bordered w-full">
                <option v-for="subtoneType of subtoneTypes" :key="subtoneType" :value="subtoneType" :selected="locationGroup.subtone_type === subtoneType">{{ subtoneType }}</option>
              </select>
            </div>
          </div>

          <div class="flex flex-col gap-2">
            <div class="text-sm text-base-content">
              Nastavení subtónu
            </div>
            <table class="border-separate border-spacing-2">
              <thead>
                <tr>
                  <td class="text-sm">Subtón</td>
                  <td class="text-sm">Subtón (záznam)</td>
                </tr>
              </thead>
              <tbody>
                <tr v-for="index in 5" :key="index">
                  <td>
                    <input v-model="locationGroup.subtone_data.listen[index - 1]" type="text" placeholder="" class="input input-sm input-bordered w-full"/>
                  </td>
                  <td>
                    <input v-model="locationGroup.subtone_data.record[index - 1]" type="text" placeholder="" class="input input-sm input-bordered w-full"/>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="form-control w-full">
            <div class="text-sm text-base-content">
              <span>Soubor k přehrání při inicializaci vysílání</span>
            </div>
            <button type="button" v-if="!locationGroup.init_audio?.id" class="btn btn-sm btn-primary" @click="selectRecording('init_audio')">Zvolit nahrávku</button>
            <div v-if="locationGroup.init_audio?.id" class="flex justify-between items-center pl-3">
              <div>
                <span>{{ locationGroup.init_audio.name }}</span>
              </div>
              <div class="flex gap-2 justify-end items-center">
                <button type="button" :id="'playPauseButton-'+locationGroup.init_audio.id" @click="playRecord(locationGroup.init_audio.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
                <audio :id="'audioPlayer-'+locationGroup.init_audio.id" @ended="playRecord(locationGroup.init_audio.id)"></audio>
                <button type="button" @click="locationGroup.init_audio = null"><span class="mdi mdi-close text-red-500 text-xl"></span></button>
              </div>
            </div>
          </div>

          <div class="form-control w-full">
            <div class="text-sm text-base-content">
              <span>Soubor k přehrání při ukončování vysílání</span>
            </div>
            <button type="button" v-if="!locationGroup.exit_audio?.id" class="btn btn-sm btn-primary" @click="selectRecording('exit_audio')">Zvolit nahrávku</button>
            <div v-if="locationGroup.exit_audio?.id" class="flex justify-between items-center pl-3">
              <div>
                <span>{{ locationGroup.exit_audio.name }}</span>
              </div>
              <div class="flex gap-2 justify-end items-center">
                <button type="button" :id="'playPauseButton-'+locationGroup.exit_audio.id" @click="playRecord(locationGroup.exit_audio.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
                <audio :id="'audioPlayer-'+locationGroup.exit_audio.id" @ended="playRecord(locationGroup.exit_audio.id)"></audio>
                <button type="button" @click="locationGroup.exit_audio = null"><span class="mdi mdi-close text-red-500 text-xl"></span></button>
              </div>
            </div>
          </div>

          <div class="flex flex-col gap-2">
            <div class="text-sm text-base-content">
              Nastavení času sepnutí/rozepnutí spínacích prvků
            </div>
            <table class="border-separate border-spacing-2">
              <thead>
                <tr>
                  <td></td>
                  <td class="text-sm">Zahajování vysílání [ms]</td>
                  <td class="text-sm">Ukončování vysílání [ms]</td>
                </tr>
              </thead>
              <tbody>
                <tr v-if="locationGroup.timing" v-for="timingRecord in timingList" :key="timingRecord.name">
                  <td>{{ timingRecord.label }}</td>
                  <td>
                    <input v-model="locationGroup.timing[timingRecord.name].start" type="text" placeholder="" class="input input-sm input-bordered w-full"/>
                  </td>
                  <td>
                    <input v-model="locationGroup.timing[timingRecord.name].end" type="text" placeholder="" class="input input-sm input-bordered w-full"/>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="lg:col-span-2 flex items-center justify-end space-x-5">
            <router-link :to="{ name: 'LocationGroupsSettings' }">
              <button class="underline">Zrušit</button>
            </router-link>
            <button @click="saveLocationGroup" class="btn btn-sm btn-primary" :disabled="cantSave">Uložit</button>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<style scoped>

</style>