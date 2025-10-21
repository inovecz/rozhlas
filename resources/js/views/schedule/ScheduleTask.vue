<script setup>
import {computed, onMounted, reactive, ref, watch} from "vue";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import RecordSelectDialog from "../../components/modals/RecordSelectDialog.vue";
import {durationToTime, formatDate, moveItemDown, moveItemUp} from "../../helper.js";
import router from "../../router.js";
import {useToast} from "vue-toastification";
import {useRoute} from "vue-router";
import ScheduleService from "../../services/ScheduleService.js";
import Button from "../../components/forms/Button.vue";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";
import Checkbox from "../../components/forms/Checkbox.vue";
import Select from "../../components/forms/Select.vue";
import CustomFormControl from "../../components/forms/CustomFormControl.vue";

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

const errorBag = ref({});

const selectedRecordings = ref({
  INTRO: null,
  OPENING: null,
  COMMON: [],
  CLOSING: null,
  OUTRO: null,
});

const repeatSettings = reactive({
  count: 1,
  intervalValue: 1,
  intervalUnit: 'minutes',
  weekday: 'monday',
});

const repeatIntervalUnitOptions = [
  {value: 'minutes', label: 'Minuty'},
  {value: 'hours', label: 'Hodiny'},
  {value: 'days', label: 'Dny'},
  {value: 'weekday', label: 'Vybraný den v týdnu'},
  {value: 'months', label: 'Měsíce'},
  {value: 'first_weekday_month', label: 'První daný den v měsíci'},
  {value: 'years', label: 'Roky'},
];

const weekdayOptions = [
  {value: 'monday', label: 'Pondělí'},
  {value: 'tuesday', label: 'Úterý'},
  {value: 'wednesday', label: 'Středa'},
  {value: 'thursday', label: 'Čtvrtek'},
  {value: 'friday', label: 'Pátek'},
  {value: 'saturday', label: 'Sobota'},
  {value: 'sunday', label: 'Neděle'},
];

const numericIntervalUnits = ['minutes', 'hours', 'days', 'months', 'years'];

const parseDuration = (value) => Number.parseInt(value ?? 0, 10) || 0;

const baseDuration = computed(() => {
  let duration = 0;
  const records = selectedRecordings.value;

  if (records.INTRO) {
    duration += parseDuration(records.INTRO.metadata?.duration);
  }
  if (records.OPENING) {
    duration += parseDuration(records.OPENING.metadata?.duration);
  }
  if (Array.isArray(records.COMMON)) {
    records.COMMON.forEach((item) => {
      duration += parseDuration(item.metadata?.duration);
    });
  }
  if (records.CLOSING) {
    duration += parseDuration(records.CLOSING.metadata?.duration);
  }
  if (records.OUTRO) {
    duration += parseDuration(records.OUTRO.metadata?.duration);
  }

  return duration;
});

const repeatCountValue = computed(() => {
  if (!scheduleRepeat.value) {
    return 1;
  }
  return Math.max(1, Number(repeatSettings.count) || 1);
});

const requiresNumericInterval = computed(() => scheduleRepeat.value && numericIntervalUnits.includes(repeatSettings.intervalUnit));

const shouldShowWeekdayPicker = computed(() => scheduleRepeat.value && ['weekday', 'first_weekday_month'].includes(repeatSettings.intervalUnit));

const intervalSeconds = computed(() => {
  if (!scheduleRepeat.value) {
    return 0;
  }

  const unit = repeatSettings.intervalUnit;
  const value = Number(repeatSettings.intervalValue) || 0;

  switch (unit) {
    case 'minutes':
      return value * 60;
    case 'hours':
      return value * 3600;
    case 'days':
      return value * 86400;
    case 'months':
      return value * 30 * 86400;
    case 'years':
      return value * 365 * 86400;
    default:
      return 0;
  }
});

const totalDurationSeconds = computed(() => {
  const base = baseDuration.value;
  if (!scheduleRepeat.value) {
    return base;
  }

  const count = repeatCountValue.value;
  if (count <= 1) {
    return base;
  }

  const interval = Math.max(0, intervalSeconds.value);
  return base * count + interval * (count - 1);
});

const totalDurationLabel = computed(() => durationToTime(totalDurationSeconds.value));

watch(() => repeatSettings.intervalUnit, (unit) => {
  if (numericIntervalUnits.includes(unit)) {
    if (!repeatSettings.intervalValue || repeatSettings.intervalValue < 1) {
      repeatSettings.intervalValue = 1;
    }
  } else if (!['weekday', 'first_weekday_month'].includes(unit)) {
    repeatSettings.intervalValue = 1;
  }

  if (['weekday', 'first_weekday_month'].includes(unit) && !repeatSettings.weekday) {
    repeatSettings.weekday = 'monday';
  }
});

watch(
    () => [scheduleDate.value, totalDurationSeconds.value],
    () => {
      if (scheduleDate.value) {
        checkTimeConflict();
      }
    },
    {immediate: true}
);
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
      repeatSettings.count = schedule.repeat_count ?? 1;
      repeatSettings.intervalValue = schedule.repeat_interval_value ?? 1;
      repeatSettings.intervalUnit = schedule.repeat_interval_unit ?? 'minutes';
      repeatSettings.weekday = schedule.repeat_interval_meta?.weekday ?? repeatSettings.weekday;
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

const cantSave = computed(() => {
  errorBag.value = {};
  let retVal = false;
  if (scheduleTitle.value.length < 3) {
    errorBag.value.scheduleTitle = 'Název úkolu musí mít alespoň 3 znaky';
    retVal = true;
  } else {
    errorBag.value.scheduleTitle = null;
  }
  if (scheduleDate.value === null) {
    errorBag.value.scheduleDate = 'Vyberte termín úkolu';
    retVal = true;
  } else {
    errorBag.value.scheduleDate = null;
  }
  if (selectedRecordings.value.COMMON.length === 0) {
    errorBag.value.selectedRecordings = 'Vyberte alespoň jedno hlášení';
    retVal = true;
  }
  else {
    errorBag.value.selectedRecordings = null;
  }

  if (scheduleRepeat.value) {
    const count = Math.floor(Number(repeatSettings.count));
    if (!count || count < 1) {
      errorBag.value.repeatCount = 'Počet opakování musí být kladné číslo';
      retVal = true;
    } else {
      errorBag.value.repeatCount = null;
    }

    if (requiresNumericInterval.value) {
      const intervalValue = Math.floor(Number(repeatSettings.intervalValue));
      if (!intervalValue || intervalValue < 1) {
        errorBag.value.repeatIntervalValue = 'Interval musí být kladné číslo';
        retVal = true;
      } else {
        errorBag.value.repeatIntervalValue = null;
      }
    } else {
      errorBag.value.repeatIntervalValue = null;
    }

    if (shouldShowWeekdayPicker.value && !repeatSettings.weekday) {
      errorBag.value.repeatIntervalWeekday = 'Vyberte den v týdnu';
      retVal = true;
    } else {
      errorBag.value.repeatIntervalWeekday = null;
    }
  } else {
    errorBag.value.repeatCount = null;
    errorBag.value.repeatIntervalValue = null;
    errorBag.value.repeatIntervalWeekday = null;
  }
  return retVal
});

function checkTimeConflict() {
  if (!scheduleDate.value) {
    return;
  }

  const datetime = formatDate(new Date(scheduleDate.value), 'Y-m-d H:i');
  ScheduleService.checkTimeConflict(datetime, totalDurationSeconds.value, editingScheduleId.value).then(response => {
    if (response.message === 'response.time_conflict') {
      errorBag.value.scheduleDate = 'Vybraný termín se překrývá s termínem jiného úkolu';
      toast.error('Vybraný termín se překrývá s termínem jiného úkolu', {});
    } else if (response.message === 'response.no_time_conflict') {
      errorBag.value.scheduleDate = null;
    }
  }).catch(error => {
    console.error('Nelze ověřit časový konflikt');
  });
}

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
  let repeatCount = null;
  let repeatIntervalValue = null;
  let repeatIntervalUnit = null;
  let repeatIntervalMeta = null;

  if (isRepeating) {
    repeatCount = Math.max(1, Number(repeatSettings.count) || 1);
    repeatIntervalUnit = repeatSettings.intervalUnit;

    if (requiresNumericInterval.value) {
      repeatIntervalValue = Math.max(1, Number(repeatSettings.intervalValue) || 1);
    }

    if (shouldShowWeekdayPicker.value) {
      repeatIntervalMeta = {
        weekday: repeatSettings.weekday,
      };
    }
  }

  ScheduleService.saveTask(
      id,
      title,
      scheduledAt,
      isRepeating,
      introId,
      openingId,
      commonIds,
      closingId,
      outroId,
      repeatCount,
      repeatIntervalValue,
      repeatIntervalUnit,
      repeatIntervalMeta
  ).then(() => {
    toast.success('Úkol byl úspěšně uložen');
    router.push({name: 'Scheduler'});
  }).catch(error => {
    toast.error('Při ukládání úkolu došlo k chybě');
    console.error(error);
  });
}
</script>

<template>
  <PageContent :label="editingScheduleId ? 'Úprava úkolu' : 'Nový úkol'" back-route="Scheduler">
    <form @submit.prevent="saveTask" class="space-y-4">
      <Box label="Obecné informace">
        <Input v-model="scheduleTitle" label="Název" placeholder="Zadejte název úkolu" autofocus :error="errorBag?.scheduleTitle"/>
        <Input v-model="scheduleDate" type="datetime-local" label="Termín vysílání" @change="checkTimeConflict" :error="errorBag?.scheduleDate"/>
        <Checkbox v-model="scheduleRepeat" label="Opakovat hlášení"/>
        <div v-if="scheduleRepeat" class="grid gap-4 md:grid-cols-2">
          <Input
              v-model.number="repeatSettings.count"
              type="number"
              min="1"
              step="1"
              label="Počet opakování"
              :error="errorBag?.repeatCount"/>
          <div class="grid gap-2 md:grid-cols-[1.5fr,2fr]">
            <Input
                v-if="requiresNumericInterval"
                v-model.number="repeatSettings.intervalValue"
                type="number"
                min="1"
                step="1"
                label="Interval"
                :error="errorBag?.repeatIntervalValue"/>
            <div v-else class="hidden md:block"/>
            <Select
                v-model="repeatSettings.intervalUnit"
                :options="repeatIntervalUnitOptions"
                label="Typ intervalu"
                size="sm"/>
          </div>
          <Select
              v-if="shouldShowWeekdayPicker"
              v-model="repeatSettings.weekday"
              :options="weekdayOptions"
              label="Den v týdnu"
              size="sm"
              :error="errorBag?.repeatIntervalWeekday"/>
        </div>
      </Box>

      <Box :label="'Zvukové soubory (délka vysílání: ' + totalDurationLabel + ')'">
        <CustomFormControl label="Úvodní znělka">
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
        </CustomFormControl>

        <CustomFormControl label="Úvodní slovo">
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
        </CustomFormControl>

        <CustomFormControl label="Hlášení" :error="errorBag?.selectedRecordings">
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
        </CustomFormControl>

        <CustomFormControl label="Závěrečné slovo">
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
        </CustomFormControl>

        <CustomFormControl label="Závěrečná znělka">
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
        </CustomFormControl>
      </Box>

      <div class="flex items-center justify-end space-x-2">
        <Button route-to="Scheduler" data-class="btn-ghost" label="Zrušit" size="sm"/>
        <Button type="submit" icon="mdi-content-save" label="Uložit" size="sm" :disabled="cantSave"/>
      </div>
    </form>
  </PageContent>
</template>

<style scoped>
</style>
