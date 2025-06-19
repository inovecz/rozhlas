<script setup>

import {computed, onMounted, ref} from "vue";
import SettingsService from "../../services/SettingsService.js";
import LocationService from "../../services/LocationService.js";
import {useToast} from "vue-toastification";
import JsvvAlarmService from "../../services/JsvvAlarmService.js";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import RecordSelectDialog from "../../components/modals/RecordSelectDialog.vue";
import VueMultiselect from "vue-multiselect";
import Input from "../../components/forms/Input.vue";
import Textarea from "../../components/forms/Textarea.vue";
import CustomFormControl from "../../components/forms/CustomFormControl.vue";
import Checkbox from "../../components/forms/Checkbox.vue";
import Select from "../../components/forms/Select.vue";
import Box from "../../components/custom/Box.vue";
import PageContent from "../../components/custom/PageContent.vue";
import Button from "../../components/forms/Button.vue";

const toast = useToast();
const playingId = ref(null);
const recordsCache = [];
const locationGroups = ref([]);
const jsvvAudios = ref([]);
const errorBag = ref({});
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
  LocationService.getAllLocationGroups('select').then((response) => {
    locationGroups.value = [{id: null, name: 'Nepřiřazeno'}, ...response];
  });
  SettingsService.fetchJsvvSettings().then((response) => {
    jsvvSettings.value = response;
  });
  JsvvAlarmService.getJsvvAudios().then((response) => {
    jsvvAudios.value = response;
  });
});

function saveJsvvSettings(scope = null) {
  let settingsToSave = {};
  if (scope === 'sms') {
    settingsToSave = {
      allowSms: jsvvSettings.value.allowSms,
      smsContacts: jsvvSettings.value.smsContacts,
      smsMessage: jsvvSettings.value.smsMessage,
    };
  } else if (scope === 'email') {
    settingsToSave = {
      allowEmail: jsvvSettings.value.allowEmail,
      emailContacts: jsvvSettings.value.emailContacts,
      emailSubject: jsvvSettings.value.emailSubject,
      emailMessage: jsvvSettings.value.emailMessage,
    };
  } else {
    settingsToSave = {
      locationGroupId: jsvvSettings.value.locationGroupId,
    };
  }
  SettingsService.saveJsvvSettings(settingsToSave).then(() => {
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

function addPhoneContact(newContact) {
  const phoneRegex = /^(\+(?:\d{1,3}))?(\s|-)?((?:\d{2,3})|\(\d{2,3}\))(?:\s|-)?(\d{3})(?:\s|-)?(\d{3})$/;
  if (!phoneRegex.test(newContact)) {
    toast.error('Zadané číslo není ve správném formátu');
    return;
  }
  jsvvSettings.value.smsContacts.push(newContact);
}

function addEmailContact(newContact) {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!emailRegex.test(newContact)) {
    toast.error('Zadaný e-mail není ve správném formátu');
    return;
  }
  jsvvSettings.value.emailContacts.push(newContact);
}

const cantSaveSms = computed(() => {
  delete errorBag.value.smsContacts;
  delete errorBag.value.smsMessage;
  let retVal = false;
  if (jsvvSettings.value.allowSms === true) {
    if (jsvvSettings.value.smsContacts.length === 0) {
      retVal = true;
      errorBag.value.smsContacts = 'Musíte zadat alespoň jedno telefonní číslo';
    }
    if (!jsvvSettings.value.smsMessage) {
      retVal = true;
      errorBag.value.smsMessage = 'Musíte zadat text SMS zprávy';
    }
  }
  return retVal;
});

const cantSaveEmail = computed(() => {
  delete errorBag.value.emailContacts;
  delete errorBag.value.emailSubject;
  delete errorBag.value.emailMessage;
  let retVal = false;
  if (jsvvSettings.value.allowEmail) {
    if (jsvvSettings.value.emailContacts.length === 0) {
      retVal = true;
      errorBag.value.emailContacts = 'Musíte zadat alespoň jeden e-mailový kontakt';
    }
    if (!jsvvSettings.value.emailSubject) {
      retVal = true;
      errorBag.value.emailSubject = 'Musíte zadat předmět e-mailu';
    }
    if (!jsvvSettings.value.emailMessage) {
      retVal = true;
      errorBag.value.emailMessage = 'Musíte zadat text e-mailu';
    }
  }
  return retVal;
});
</script>

<template>
  <PageContent label="Nastavení JSVV">

    <Box label="Nastavení lokality">
      <Select v-model="jsvvSettings.locationGroupId" label="Zvolte lokalitu" option-key="id" option-label="name" :options="locationGroups" data-class="select-bordered w-full"/>

      <div class="flex justify-end">
        <Button @click="saveJsvvSettings" icon="mdi-content-save" label="Uložit" size="sm"/>
      </div>
    </Box>

    <Box label="Nastavení zvuků">
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
              <Input v-model="jsvvAudio.name" placeholder="" data-class="input-bordered input-sm"/>
            </td>
            <td>
              <Select v-if="jsvvAudio.type === 'SOURCE'" v-model="jsvvAudio.source" option-key="value" model-key="source" option-label="label" :options="audioSources" data-class="select-bordered select-sm w-full"/>
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

      <div class="flex justify-end">
        <Button @click="saveJsvvAudioSettings" icon="mdi-content-save" label="Uložit" size="sm"/>
      </div>
    </Box>

    <Box label="Nastavení SMS">
      <Checkbox v-model="jsvvSettings.allowSms" label="Povolit odesílání informační SMS při zahájení vysílání poplachu JSVV"/>

      <CustomFormControl label="Telefonní čísla" :error="errorBag.smsContacts">
        <VueMultiselect v-model="jsvvSettings.smsContacts" :options="[]" :multiple="true"
                        :class="{'error' : errorBag.smsContacts}"
                        :close-on-select="false" :showNoOptions="false" :taggable="true" @tag="addEmailContact"
                        placeholder="Přidat příjemce" tagPlaceholder="Přidat číslo"/>
      </CustomFormControl>

      <Textarea v-model="jsvvSettings.smsMessage" placeholder="Zde napište zprávu, která bude rozeslána příjemcům ze seznamu" label="Text zprávy" :error="errorBag.smsMessage"/>

      <div class="flex justify-end">
        <Button @click="saveJsvvSettings('sms')" icon="mdi-content-save" label="Uložit" size="sm" :disabled="cantSaveSms"/>
      </div>
    </Box>

    <Box label="Nastavení e-mailu">
      <Checkbox v-model="jsvvSettings.allowEmail" label="Povolit odesílání informačního e-mailu při zahájení vysílání poplachu JSVV"/>

      <CustomFormControl label="E-maily" :error="errorBag.emailContacts">
        <VueMultiselect v-model="jsvvSettings.emailContacts" :options="[]" :multiple="true"
                        :class="{'error' : errorBag.emailContacts}"
                        :close-on-select="false" :showNoOptions="false" :taggable="true" @tag="addEmailContact"
                        placeholder="Přidat příjemce" tagPlaceholder="Přidat e-mail"/>
      </CustomFormControl>

      <Input v-model="jsvvSettings.emailSubject" placeholder="Napiště předmět e-mailu" label="Předmět e-mailu" :error="errorBag.emailSubject"/>

      <Textarea v-model="jsvvSettings.emailMessage" placeholder="Zde napište zprávu, která bude rozeslána příjemcům ze seznamu" label="Text zprávy" :error="errorBag.emailMessage"/>

      <div class="flex justify-end">
        <Button @click="saveJsvvSettings('email')" icon="mdi-content-save" label="Uložit" size="sm" :disabled="cantSaveEmail"/>
      </div>
    </Box>

  </PageContent>
</template>

<style scoped>
</style>