<script setup>
import {computed, onMounted, ref} from "vue";
import LocationService from "../../services/LocationService.js";
import {useToast} from "vue-toastification";
import {useRoute} from "vue-router";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import RecordSelectDialog from "../../components/modals/RecordSelectDialog.vue";
import router from "../../router.js";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";
import Checkbox from "../../components/forms/Checkbox.vue";
import Select from "../../components/forms/Select.vue";
import CustomFormControl from "../../components/forms/CustomFormControl.vue";
import Button from "../../components/forms/Button.vue";

const route = useRoute();
const errorBag = ref({});
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
  is_hidden: false,
  subtone_type: 'NONE',
  subtone_data: {listen: [], record: []},
  init_audio: null,
  exit_audio: null,
  modbus_group_address: null,
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
  errorBag.value = {};
  let retVal = false;
  if (locationGroup.value.name.length < 3) {
    errorBag.value.name = 'Název musí mít alespoň 3 znaky';
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
  <PageContent :label="locationGroup.id ? 'Úprava lokality' : 'Nová lokalita'" back-route="LocationGroupsSettings">
    <Box label="Nastavení lokality">
      <Input v-model="locationGroup.name" label="Název lokality:" placeholder="Zadejte název (min. 3 znaky)" :error="errorBag?.name"/>
      <Checkbox v-model="locationGroup.is_hidden" label="Skrytá lokalita"/>
      <Input v-model="locationGroup.modbus_group_address"
             label="Skupinová Modbus adresa"
             type="number"
             placeholder="Např. 101"
             data-class="input-bordered input-sm"/>
      <Select v-model="locationGroup.subtone_type" label="Typ subtónu:" :options="subtoneTypes"/>

      <CustomFormControl label="Nastavení subtónu">
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
                <Input v-model="locationGroup.subtone_data.listen[index - 1]" type="text" data-class="input-bordered input-sm" placeholder=""/>
              </td>
              <td>
                <Input v-model="locationGroup.subtone_data.record[index - 1]" type="text" data-class="input-bordered input-sm" placeholder=""/>
              </td>
            </tr>
          </tbody>
        </table>
      </CustomFormControl>

      <CustomFormControl label="Soubor k přehrání při inicializaci vysílání">
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
      </CustomFormControl>

      <CustomFormControl label="Soubor k přehrání při ukončování vysílání">
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
      </CustomFormControl>

      <CustomFormControl label="Nastavení času sepnutí/rozepnutí spínacích prvků">
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
                <Input v-model="locationGroup.timing[timingRecord.name].start" type="text" placeholder="" data-class="input-bordered input-sm"/>
              </td>
              <td>
                <Input v-model="locationGroup.timing[timingRecord.name].end" type="text" placeholder="" data-class="input-bordered input-sm"/>
              </td>
            </tr>
          </tbody>
        </table>
      </CustomFormControl>

      <div class="flex items-center justify-end space-x-2">
        <Button route-to="LocationGroupsSettings" data-class="btn-ghost" label="Zrušit" size="sm"/>
        <Button icon="mdi-content-save" label="Uložit" size="sm" :disabled="cantSave" @click="saveLocationGroup"/>
      </div>
    </Box>
  </PageContent>
</template>

<style scoped>
</style>
