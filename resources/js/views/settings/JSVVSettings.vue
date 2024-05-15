<script setup>

import {onMounted, ref} from "vue";
import SettingsService from "../../services/SettingsService.js";
import LocationService from "../../services/LocationService.js";
import {useToast} from "vue-toastification";
import JsvvAlarmService from "../../services/JsvvAlarmService.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import RecordSelectDialog from "../../components/modals/RecordSelectDialog.vue";

const toast = useToast();
const playingId = ref(null);
const recordsCache = [];
const locationGroups = ref([]);
const jsvvAudios = ref([]);
const audioSources = [
  {value: null, label: 'Nevybráno'},
  {value: 'INPUT_1', label: 'Vstup 1'},
  {value: 'INPUT_2', label: 'Vstup 2'},
  {value: 'INPUT_3', label: 'Vstup 3'},
  {value: 'INPUT_4', label: 'Vstup 4'},
  {value: 'INPUT_5', label: 'Vstup 5'},
  {value: 'INPUT_6', label: 'Vstup 6'},
  {value: 'INPUT_7', label: 'Vstup 7'},
  {value: 'INPUT_8', label: 'Vstup 8'},
  {value: 'FM', label: 'FM rádio'},
  {value: 'MIC', label: 'Mikrofon'},
];

const jsvvSettings = ref({
  locationGroupId: null,
});

onMounted(() => {
  LocationService.getAllLocationGroups().then((response) => {
    locationGroups.value = response;
  });
  SettingsService.fetchJsvvSettings().then((response) => {
    jsvvSettings.value = response;
  });
  JsvvAlarmService.getJsvvAudios().then((response) => {
    jsvvAudios.value = response;
  });
});

function saveJsvvSettings() {
  SettingsService.saveJsvvSettings(jsvvSettings.value).then(() => {
    toast.success('Nastavení JSVV bylo úspěšně uloženo');
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se uložit nastavení JSVV');
  });
}

function saveJsvvAudioSettings() {
  let audios = [...jsvvAudios.value];
  audios.forEach(audio => {
    // if audio.file_id is undefinned make it null
    if (audio.file_id === undefined) {
      audio.file_id = null;
    }
    if (audio.type === 'FILE' && audio.file_id !== audio.file?.id) {
      audio.file_id = audio.file?.id ?? null;
    }
  });
  JsvvAlarmService.saveJsvvAudios(audios).then(() => {
    toast.success('Nastavení zvuků bylo úspěšně uloženo');
    JsvvAlarmService.getJsvvAudios().then((response) => {
      jsvvAudios.value = response;
    });
  }).catch(error => {
    console.error(error);
    toast.error('Nepodařilo se uložit nastavení zvuků');
  });
}

function selectJsvvRecording(jsvvAudioSymbol) {
  const jsvvAudio = jsvvAudios.value.find(jsvvAudio => jsvvAudio.symbol === jsvvAudioSymbol);
  const {reveal, onConfirm} = createConfirmDialog(RecordSelectDialog, {
    typeFilter: 'JSVV',
    multiple: false,
  });
  reveal();
  onConfirm((data) => {
    jsvvAudio.file = data;
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
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Nastavení JSVV</h1>
    <div class="content flex flex-col space-y-4">
      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Nastavení lokality
          </div>
        </div>
        <div class="flex flex-col gap-2">

          <div class="text-sm text-base-content">
            Zvolte lokalitu
          </div>
          <div>
            <select v-model="jsvvSettings.locationGroupId" class="select select-bordered w-full">
              <option :value="null">Nepřiřazeno</option>
              <option v-for="locationGroup of locationGroups" :key="locationGroup.id" :value="locationGroup.id" :selected="locationGroup.id === jsvvSettings.locationGroupId">{{ locationGroup.name }}</option>
            </select>
          </div>

          <div class="flex justify-end space-x-5">
            <button @click="saveJsvvSettings" class="btn btn-sm btn-primary">Uložit</button>
          </div>
        </div>
      </div>

      <div class="component-box">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between">
          <div class="text-xl text-primary mb-4 mt-3 px-1">
            Nastavení zvuků
          </div>
        </div>
        <div class="flex flex-col gap-2">


          <table class="border-separate border-spacing-2">
            <thead>
              <tr>
                <th class="text-left"></th>
                <th class="text-left">Název</th>
                <th class="text-left">Zvukový soubor / vstup</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="jsvvAudios" v-for="jsvvAudio in jsvvAudios" :key="jsvvAudio.symbol">
                <td>{{ jsvvAudio.symbol }}</td>
                <td>
                  <input v-model="jsvvAudio.name" type="text" placeholder="" class="input input-sm input-bordered w-full"/>
                </td>
                <td>
                  <select v-if="jsvvAudio.type === 'SOURCE'" v-model="jsvvAudio.source" class="select select-bordered select-sm w-full">
                    <option v-for="audioSource of audioSources" :key="audioSource.value" :value="audioSource.value" :selected="audioSource.value === jsvvAudio.source">{{ audioSource.label }}</option>
                  </select>

                  <div v-if="jsvvAudio.type === 'FILE'" class="form-control w-full">
                    <button type="button" v-if="!jsvvAudio.file" class="btn btn-sm btn-primary" @click="selectJsvvRecording(jsvvAudio.symbol)">Zvolit nahrávku</button>
                    <div v-if="jsvvAudio.file" class="flex justify-between items-center pl-3">
                      <div>
                        <span>{{ jsvvAudio.file.name }}</span>
                      </div>
                      <div class="flex gap-2 justify-end items-center">
                        <button type="button" :id="'playPauseButton-'+jsvvAudio.file.id" @click="playRecord(jsvvAudio.file.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
                        <audio :id="'audioPlayer-'+jsvvAudio.file.id" @ended="playRecord(jsvvAudio.file.id)"></audio>
                        <button type="button" @click="jsvvAudio.file = null"><span class="mdi mdi-close text-red-500 text-xl"></span></button>
                      </div>
                    </div>
                  </div>


                </td>
              </tr>
            </tbody>
          </table>

          <div class="flex justify-end space-x-5">
            <button @click="saveJsvvAudioSettings" class="btn btn-sm btn-primary">Uložit</button>
          </div>
        </div>
      </div>

    </div>
  </div>
</template>

<style scoped>

</style>