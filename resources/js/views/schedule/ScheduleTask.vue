<script setup>
import {computed, onMounted, ref, watch} from "vue";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import RecordSelectDialog from "../../components/modals/RecordSelectDialog.vue";
import {durationToTime, formatDate, moveItemDown, moveItemUp} from "../../helper.js";
import router from "../../router.js";
import {useToast} from "vue-toastification";
import {useRoute} from "vue-router";
import ScheduleService from "../../services/ScheduleService.js";

let initialDate = new Date();
initialDate.setDate(initialDate.getDate() + 1);
initialDate.setHours(12);
initialDate.setMinutes(0);


const playingId = ref(null);
const recordsCache = [];
const toast = useToast();
const route = useRoute();

const editingScheduleId = ref(route.params.id);

const scheduleTitle = ref('');
const scheduleDate = ref(formatDate(initialDate, 'Y-m-d H:i'));
const scheduleRepeat = ref(false);
const totalDuration = ref(0);

const errorBag = ref({});

const selectedRecordings = ref({
  INTRO: null,
  OPENING: null,
  COMMON: [],
  CLOSING: null,
  OUTRO: null,
});
watch([selectedRecordings, scheduleRepeat], () => {
  let duration = 0;
  for (const key in selectedRecordings.value) {
    if (Array.isArray(selectedRecordings.value[key])) {
      selectedRecordings.value[key].forEach(item => {
        duration += parseInt(item.metadata.duration);
        if (key === 'COMMON' && scheduleRepeat.value) {
          duration += parseInt(item.metadata.duration);
        }
      });
    } else {
      duration += parseInt(selectedRecordings.value[key]?.metadata?.duration ?? 0);
    }
  }

  totalDuration.value = duration;
  checkTimeConflict();
}, {deep: true});

onMounted(() => {
  if (editingScheduleId.value) {
    http.get('/schedules/' + editingScheduleId.value).then(response => {
      const schedule = response.data['data'];
      scheduleTitle.value = schedule.title;
      scheduleDate.value = formatDate(new Date(schedule.scheduled_at), 'Y-m-d H:i');
      scheduleRepeat.value = schedule.is_repeating;
      selectedRecordings.value.INTRO = schedule.intro;
      selectedRecordings.value.OPENING = schedule.opening;
      selectedRecordings.value.COMMON = schedule.commons;
      selectedRecordings.value.CLOSING = schedule.closing;
      selectedRecordings.value.OUTRO = schedule.outro;
    }).catch(error => {
      console.error(error);
    });
  }
});

function selectRecording(subtype) {
  const multiple = subtype === 'COMMON';
  const {reveal, onConfirm} = createConfirmDialog(RecordSelectDialog, {
    typeFilter: subtype,
    multiple,
  });
  reveal();
  onConfirm((data) => {
    selectedRecordings.value[subtype] = data;
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

const canSave = computed(() => {
  const noErrors = Object.values(errorBag.value).every(value => value === null);
  return scheduleTitle.value !== '' && scheduleDate.value !== null && selectedRecordings.value.COMMON.length > 0 && noErrors;
});

function checkTimeConflict() {
  ScheduleService.checkTimeConflict(formatDate(new Date(scheduleDate.value), 'Y-m-d H:i'), totalDuration.value, editingScheduleId.value).then(response => {
    console.log(response);
    if (response.message === 'response.time_conflict') {
      errorBag.value.scheduleDate = 'Vybraný termín se překrývá s termínem jiného úkolu';
      toast.error('Vybraný termín se překrývá s termínem jiného úkolu', {});
    } else if (response.message === 'response.no_time_conflict') {
      errorBag.value.scheduleDate = null;
    }
  }).catch(error => {
    console.error(error);
  });
}

checkTimeConflict();

function saveTask() {
  const id = editingScheduleId.value;
  const title = scheduleTitle.value;
  const scheduledAt = scheduleDate.value;
  const isRepeating = scheduleRepeat.value;
  const introId = selectedRecordings.value.INTRO ? selectedRecordings.value.INTRO.id : null;
  const openingId = selectedRecordings.value.OPENING ? selectedRecordings.value.OPENING.id : null;
  const commonIds = selectedRecordings.value.COMMON.map(recording => recording.id);
  const closingId = selectedRecordings.value.CLOSING ? selectedRecordings.value.CLOSING.id : null;
  const outroId = selectedRecordings.value.OUTRO ? selectedRecordings.value.OUTRO.id : null;
  ScheduleService.saveTask(id, title, scheduledAt, isRepeating, introId, openingId, commonIds, closingId, outroId).then(() => {
    toast.success('Úkol byl úspěšně uložen');
    router.push({name: 'Scheduler'});
  }).catch(error => {
    toast.error('Při ukládání úkolu došlo k chybě');
    console.error(error);
  });
}
</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">
      <router-link :to="{ name: 'Scheduler' }">
        <span class="mdi mdi-chevron-left"></span>
      </router-link>
      <span class="color-primary">Nový úkol</span>
    </h1>
    <form @submit.prevent="saveTask">
      <div class="content grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div class="component-box">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between">
            <div class="text-xl text-primary mb-4 mt-3 px-1">
              Obecné informace
            </div>
          </div>
          <div class="flex flex-col gap-4">

            <label class="form-control w-full">
              <div class="label">
                <span class="font-bold">Název</span>
              </div>
              <input v-model="scheduleTitle" type="text" placeholder="Zadejte název úkolu" class="input input-bordered w-full" autofocus/>
            </label>

            <label class="form-control w-full">
              <div class="label">
                <span class="font-bold">Termín vysílání</span>
              </div>
              <!--              <VueDatePicker :input-class-name="'input input-bordered w-full '+(errorBag.scheduleDate ? 'input-error' : '' )"
                                           v-model="scheduleDate"
                                           time-picker-inline
                                           format="dd.MM.yyyy HH:mm"
                                           model-type="yyyy-MM-dd HH:mm"
                                           placeholder="Vyberte termín vysílání"
                                           locale="cs"
                                           :enable-time-picker="true"
                                           minutes-grid-increment="1"
                                           @closed="checkTimeConflict"

                            />-->
              <input type="datetime-local" :class="'input input-bordered w-full '+(errorBag.scheduleDate ? 'input-error' : '' )"
                     v-model="scheduleDate"
                     placeholder="Vyberte termín vysílání"
                     @change="checkTimeConflict">
              <div class="label">
                <span v-if="errorBag.scheduleDate" class="label-text-alt text-red-500"><span class="mdi mdi-alert-circle-outline mr-1"></span>{{ errorBag.scheduleDate }}</span>
              </div>
            </label>

            <div class="form-control">
              <label class="label cursor-pointer">
                <span class="font-bold">Opakovat hlášení</span>
                <input v-model="scheduleRepeat" type="checkbox" :checked="scheduleRepeat" class="checkbox"/>
              </label>
            </div>

          </div>
        </div>

        <div class="component-box">
          <div class="flex flex-col sm:flex-row sm:items-center justify-between">
            <div class="text-xl text-primary mb-4 mt-3 px-1">
              Zvukové soubory (délka vysílání: {{ durationToTime(totalDuration) }})
            </div>
          </div>
          <div class="flex flex-col gap-4">

            <div class="form-control w-full">
              <div class="label">
                <span class="font-bold">Úvodní znělka</span>
              </div>
              <button type="button" v-if="!selectedRecordings.INTRO" class="btn btn-sm btn-primary" @click="selectRecording('INTRO')">Zvolit nahrávku</button>
              <div v-if="selectedRecordings.INTRO" class="flex justify-between items-center pl-3">
                <div>
                  <span>{{ selectedRecordings.INTRO.name }}</span>
                </div>
                <div class="flex gap-2 justify-end items-center">
                  <button type="button" :id="'playPauseButton-'+selectedRecordings.INTRO.id" @click="playRecord(selectedRecordings.INTRO.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
                  <audio :id="'audioPlayer-'+selectedRecordings.INTRO.id" @ended="playRecord(selectedRecordings.INTRO.id)"></audio>
                  <button type="button" @click="selectedRecordings.INTRO = null"><span class="mdi mdi-close text-red-500 text-xl"></span></button>
                </div>
              </div>
            </div>

            <div class="form-control w-full">
              <div class="label">
                <span class="font-bold">Úvodní slovo</span>
              </div>
              <button type="button" v-if="!selectedRecordings.OPENING" class="btn btn-sm btn-primary" @click="selectRecording('OPENING')">Zvolit nahrávku</button>
              <div v-if="selectedRecordings.OPENING" class="flex justify-between items-center pl-3">
                <div>
                  <span>{{ selectedRecordings.OPENING.name }}</span>
                </div>
                <div class="flex gap-2 justify-end items-center">
                  <button type="button" :id="'playPauseButton-'+selectedRecordings.OPENING.id" @click="playRecord(selectedRecordings.OPENING.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
                  <audio :id="'audioPlayer-'+selectedRecordings.OPENING.id" @ended="playRecord(selectedRecordings.OPENING.id)"></audio>
                  <button type="button" @click="selectedRecordings.OPENING = null"><span class="mdi mdi-close text-red-500 text-xl"></span></button>
                </div>
              </div>
            </div>

            <div class="form-control w-full">
              <div class="label">
                <span class="font-bold">Hlášení</span>
              </div>
              <button type="button" v-if="selectedRecordings.COMMON.length === 0" class="btn btn-sm btn-primary" @click="selectRecording('COMMON')">Zvolit nahrávky</button>
              <div v-if="selectedRecordings.COMMON.length > 0">
                <div v-for="(commonRecording, index) in selectedRecordings.COMMON" :key="commonRecording.id" class="flex justify-between items-center pl-3">
                  <div class="flex items-center gap-2">
                    <button type="button" v-if="selectedRecordings.COMMON.length > 1 && index !== 0" @click="moveItemUp(selectedRecordings.COMMON, index)"><span class="mdi mdi-chevron-up"></span></button>
                    <button type="button" v-if="selectedRecordings.COMMON.length > 1 && index === 0" class="opacity-0" disabled><span class="mdi mdi-chevron-up"></span></button>
                    <button type="button" v-if="selectedRecordings.COMMON.length > 1 && index !== selectedRecordings.COMMON.length - 1" @click="moveItemDown(selectedRecordings.COMMON, index)"><span class="mdi mdi-chevron-down"></span></button>
                    <button type="button" v-if="selectedRecordings.COMMON.length > 1 && index === selectedRecordings.COMMON.length - 1" class="opacity-0" disabled><span class="mdi mdi-chevron-down"></span></button>
                    <span>{{ commonRecording.name }}</span>
                  </div>
                  <div class="flex gap-2 justify-end items-center">
                    <button type="button" :id="'playPauseButton-'+commonRecording.id" @click="playRecord(commonRecording.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
                    <audio :id="'audioPlayer-'+commonRecording.id" @ended="playRecord(commonRecording.id)"></audio>
                    <button type="button" @click="selectedRecordings.COMMON.splice(selectedRecordings.COMMON.findIndex(recording => recording.id === commonRecording.id),1)"><span class="mdi mdi-close text-red-500 text-xl"></span></button>
                  </div>
                </div>
              </div>
            </div>

            <div class="form-control w-full">
              <div class="label">
                <span class="font-bold">Závěrečné slovo</span>
              </div>
              <button type="button" v-if="!selectedRecordings.CLOSING" class="btn btn-sm btn-primary" @click="selectRecording('CLOSING')">Zvolit nahrávku</button>
              <div v-if="selectedRecordings.CLOSING" class="flex justify-between items-center pl-3">
                <div>
                  <span>{{ selectedRecordings.CLOSING.name }}</span>
                </div>
                <div class="flex gap-2 justify-end items-center">
                  <button type="button" :id="'playPauseButton-'+selectedRecordings.CLOSING.id" @click="playRecord(selectedRecordings.CLOSING.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
                  <audio :id="'audioPlayer-'+selectedRecordings.CLOSING.id" @ended="playRecord(selectedRecordings.CLOSING.id)"></audio>
                  <button type="button" @click="selectedRecordings.CLOSING = null"><span class="mdi mdi-close text-red-500 text-xl"></span></button>
                </div>
              </div>
            </div>

            <div class="form-control w-full">
              <div class="label">
                <span class="font-bold">Závěrečná znělka</span>
              </div>
              <button type="button" v-if="!selectedRecordings.OUTRO" class="btn btn-sm btn-primary" @click="selectRecording('OUTRO')">Zvolit nahrávku</button>
              <div v-if="selectedRecordings.OUTRO" class="flex justify-between items-center pl-3">
                <div>
                  <span>{{ selectedRecordings.OUTRO.name }}</span>
                </div>
                <div class="flex gap-2 justify-end items-center">
                  <button type="button" :id="'playPauseButton-'+selectedRecordings.OUTRO.id" @click="playRecord(selectedRecordings.OUTRO.id)"><span class="mdi mdi-play text-emerald-500 text-xl"></span></button>
                  <audio :id="'audioPlayer-'+selectedRecordings.OUTRO.id" @ended="playRecord(selectedRecordings.OUTRO.id)"></audio>
                  <button type="button" @click="selectedRecordings.OUTRO = null"><span class="mdi mdi-close text-red-500 text-xl"></span></button>
                </div>
              </div>
            </div>

          </div>
        </div>

        <div class="lg:col-span-2 flex items-center justify-end space-x-5">
          <router-link :to="{ name: 'Scheduler' }">
            <button class="underline">Zrušit</button>
          </router-link>
          <button type="submit" class="btn btn-sm btn-primary" :disabled="!canSave">Uložit</button>
        </div>
      </div>
    </form>
  </div>
</template>

<style scoped>

</style>