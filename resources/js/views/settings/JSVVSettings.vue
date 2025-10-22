<script setup>
import {computed, onMounted, reactive, ref} from "vue";
import {useToast} from "vue-toastification";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import RecordSelectDialog from "../../components/modals/RecordSelectDialog.vue";
import SettingsService from "../../services/SettingsService.js";
import LocationService from "../../services/LocationService.js";
import JsvvAlarmService from "../../services/JsvvAlarmService.js";
import Box from "../../components/custom/Box.vue";
import PageContent from "../../components/custom/PageContent.vue";
import Select from "../../components/forms/Select.vue";
import Input from "../../components/forms/Input.vue";
import Textarea from "../../components/forms/Textarea.vue";
import Checkbox from "../../components/forms/Checkbox.vue";
import Button from "../../components/forms/Button.vue";
import VueMultiselect from "vue-multiselect";
import {moveItemDown, moveItemUp} from "../../helper.js";
import {Dialog, DialogPanel, DialogTitle, TransitionChild, TransitionRoot} from "@headlessui/vue";
import {JSVV_BUTTON_DEFAULTS} from "../../constants/jsvvDefaults.js";

const toast = useToast();

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

const desktopButtonsDefinition = JSVV_BUTTON_DEFAULTS.map((item) => ({
  button: item.button,
  label: item.label,
  defaultSequence: item.sequence,
}));

const mobileButtonsDefinition = [
  {button: 0, label: '0 (mobil)', defaultSequence: ''},
  ...desktopButtonsDefinition,
  {button: 9, label: '9 (mobil)', defaultSequence: ''},
];

const desktopButtons = ref([]);
const mobileButtons = ref([]);
const savingDesktop = ref(false);
const savingMobile = ref(false);
const savingAudios = ref(false);

const jsvvSettings = ref({
  locationGroupId: null,
  allowSms: false,
  smsContacts: [],
  smsMessage: '',
  allowAlarmSms: false,
  alarmSmsContacts: [],
  alarmSmsMessage: '',
  allowEmail: false,
  emailContacts: [],
  emailSubject: '',
  emailMessage: '',
});

const errorBag = reactive({
  smsContacts: null,
  smsMessage: null,
  alarmSmsContacts: null,
  alarmSmsMessage: null,
  emailContacts: null,
  emailSubject: null,
  emailMessage: null,
});

const builderState = reactive({
  open: false,
  selectedItems: [],
  target: null,
  mode: 'desktop',
  maxLength: 4,
});

onMounted(async () => {
  await Promise.all([
    loadLocationGroups(),
    loadSettings(),
    loadAlarms(),
    loadAudios(),
  ]);
});

async function loadLocationGroups() {
  try {
    const response = await LocationService.getAllLocationGroups('select');
    locationGroups.value = [{id: null, name: 'General (všechny lokality)'}, ...response];
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst lokality');
  }
}

async function loadSettings() {
  try {
    const response = await SettingsService.fetchJsvvSettings();
    jsvvSettings.value = {
      locationGroupId: response.locationGroupId ?? null,
      allowSms: response.allowSms ?? false,
      smsContacts: response.smsContacts ?? [],
      smsMessage: response.smsMessage ?? '',
      allowAlarmSms: response.allowAlarmSms ?? false,
      alarmSmsContacts: response.alarmSmsContacts ?? [],
      alarmSmsMessage: response.alarmSmsMessage ?? '',
      allowEmail: response.allowEmail ?? false,
      emailContacts: response.emailContacts ?? [],
      emailSubject: response.emailSubject ?? '',
      emailMessage: response.emailMessage ?? '',
    };
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst nastavení JSVV');
  }
}

async function loadAlarms() {
  try {
    const response = await JsvvAlarmService.fetchJsvvAlarms();
    const alarms = response.data ?? [];
    const byId = new Map(alarms.map((alarm) => [alarm.id, alarm]));
    const byButton = new Map(alarms.filter((alarm) => alarm.button != null).map((alarm) => [Number(alarm.button), alarm]));
    const byMobileButton = new Map(alarms.filter((alarm) => alarm.mobile_button != null).map((alarm) => [Number(alarm.mobile_button), alarm]));

    desktopButtons.value = desktopButtonsDefinition.map((definition) => {
      const alarm = byButton.get(definition.button) ?? null;
      const sequence = (alarm?.sequence || definition.defaultSequence || '').toUpperCase();
      return {
        ...definition,
        sequence,
        alarm,
      };
    });

    mobileButtons.value = mobileButtonsDefinition.map((definition) => {
      const alarm = byMobileButton.get(definition.button) ?? null;
      const sequence = (alarm?.sequence || definition.defaultSequence || '').toUpperCase();
      return {
        ...definition,
        sequence,
        alarm,
      };
    });
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst konfiguraci tlačítek JSVV');
  }
}

async function loadAudios() {
  try {
    jsvvAudios.value = await JsvvAlarmService.getJsvvAudios();
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst zvuky JSVV');
  }
}

async function saveLocationSettings() {
  try {
    await SettingsService.saveJsvvSettings({
      locationGroupId: jsvvSettings.value.locationGroupId,
    });
    toast.success('Nastavení lokality bylo uloženo');
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se uložit nastavení lokality');
  }
}

function openSequenceBuilder(row, mode) {
  builderState.open = true;
  builderState.mode = mode;
  builderState.target = row;
  builderState.selectedItems = [];

  const sequence = (row.sequence ?? '').split('').filter(Boolean).slice(0, builderState.maxLength);
  sequence.forEach((symbol) => {
    const audio = audioMap.value.get(symbol);
    builderState.selectedItems.push({
      symbol,
      label: audio?.name ?? `Symbol ${symbol}`,
      group: audio?.group ?? null,
      groupLabel: audio?.group_label ?? '',
    });
  });
}

function closeSequenceBuilder() {
  builderState.open = false;
  builderState.selectedItems = [];
  builderState.target = null;
}

const audioMap = computed(() => {
  const map = new Map();
  jsvvAudios.value.forEach((audio) => {
    map.set(String(audio.symbol), audio);
  });
  return map;
});

const groupedBuilderAudios = computed(() => {
  const order = ['SIREN', 'GONG', 'VERBAL', 'AUDIO'];
  const groupLabels = {
    SIREN: 'Sirény',
    GONG: 'Gongy',
    VERBAL: 'Verbální informace',
    AUDIO: 'Audiovstupy',
  };
  return order
      .map((group) => ({
        group,
        label: groupLabels[group],
        items: jsvvAudios.value
            .filter((audio) => audio.group === group)
            .map((audio) => ({
              symbol: audio.symbol,
              name: audio.name,
              group: audio.group,
              groupLabel: groupLabels[group],
            })),
      }))
      .filter((group) => group.items.length > 0);
});

function addSymbolToBuilder(item) {
  if (builderState.selectedItems.length >= builderState.maxLength) {
    toast.warning(`Sekvence může obsahovat maximálně ${builderState.maxLength} symboly.`);
    return;
  }
  builderState.selectedItems.push({...item});
}

function removeSymbolFromBuilder(index) {
  builderState.selectedItems.splice(index, 1);
}

function moveSymbolUp(index) {
  if (index <= 0) {
    return;
  }
  moveItemUp(builderState.selectedItems, index);
  builderState.selectedItems = [...builderState.selectedItems];
}

function moveSymbolDown(index) {
  if (index >= builderState.selectedItems.length - 1) {
    return;
  }
  moveItemDown(builderState.selectedItems, index);
  builderState.selectedItems = [...builderState.selectedItems];
}

function confirmSequenceBuilder() {
  if (!builderState.target) {
    closeSequenceBuilder();
    return;
  }
  const sequence = builderState.selectedItems.map((item) => item.symbol).join('').slice(0, builderState.maxLength);
  builderState.target.sequence = sequence;
  closeSequenceBuilder();
}

async function saveButtonSettings(type) {
  const rows = type === 'desktop' ? desktopButtons.value : mobileButtons.value;
  if (rows.length === 0) {
    return;
  }
  const savingFlag = type === 'desktop' ? savingDesktop : savingMobile;
  savingFlag.value = true;

  try {
    for (const row of rows) {
      const sanitizedSequence = (row.sequence ?? '')
          .toString()
          .toUpperCase()
          .replace(/[^0-9A-Z]/g, '')
          .slice(0, builderState.maxLength);
      row.sequence = sanitizedSequence;

      const payload = {
        id: row.alarm?.id ?? null,
        name: row.label,
        sequence: sanitizedSequence || null,
        button: type === 'desktop' ? row.button : row.alarm?.button ?? null,
        mobile_button: type === 'mobile' ? row.button : row.alarm?.mobile_button ?? null,
      };

      await JsvvAlarmService.saveJsvvAlarm(payload);
    }

    toast.success('Nastavení tlačítek bylo uloženo');
    await loadAlarms();
  } catch (error) {
    console.error(error);
    toast.error('Nastavení tlačítek se nepodařilo uložit');
  } finally {
    savingFlag.value = false;
  }
}

const cantSaveSms = computed(() => {
  errorBag.smsContacts = null;
  errorBag.smsMessage = null;
  if (!jsvvSettings.value.allowSms) {
    return false;
  }
  let hasError = false;
  if (!jsvvSettings.value.smsContacts?.length) {
    errorBag.smsContacts = 'Musíte zadat alespoň jedno telefonní číslo';
    hasError = true;
  }
  if (!jsvvSettings.value.smsMessage?.trim()) {
    errorBag.smsMessage = 'Musíte zadat text SMS zprávy';
    hasError = true;
  }
  return hasError;
});

const cantSaveAlarmSms = computed(() => {
  errorBag.alarmSmsContacts = null;
  errorBag.alarmSmsMessage = null;
  if (!jsvvSettings.value.allowAlarmSms) {
    return false;
  }
  let hasError = false;
  if (!jsvvSettings.value.alarmSmsContacts?.length) {
    errorBag.alarmSmsContacts = 'Musíte zadat alespoň jedno telefonní číslo';
    hasError = true;
  }
  if (!jsvvSettings.value.alarmSmsMessage?.trim()) {
    errorBag.alarmSmsMessage = 'Musíte zadat text SMS zprávy';
    hasError = true;
  }
  return hasError;
});

const cantSaveEmail = computed(() => {
  errorBag.emailContacts = null;
  errorBag.emailSubject = null;
  errorBag.emailMessage = null;
  if (!jsvvSettings.value.allowEmail) {
    return false;
  }
  let hasError = false;
  if (!jsvvSettings.value.emailContacts?.length) {
    errorBag.emailContacts = 'Musíte zadat alespoň jeden e-mail';
    hasError = true;
  }
  if (!jsvvSettings.value.emailSubject?.trim()) {
    errorBag.emailSubject = 'Musíte zadat předmět e-mailu';
    hasError = true;
  }
  if (!jsvvSettings.value.emailMessage?.trim()) {
    errorBag.emailMessage = 'Musíte zadat text e-mailu';
    hasError = true;
  }
  return hasError;
});

async function saveSmsSettings() {
  if (cantSaveSms.value) {
    toast.error('Zkontrolujte prosím nastavení SMS');
    return;
  }
  try {
    await SettingsService.saveJsvvSettings({
      allowSms: jsvvSettings.value.allowSms,
      smsContacts: jsvvSettings.value.smsContacts,
      smsMessage: jsvvSettings.value.smsMessage,
    });
    toast.success('SMS nastavení bylo uloženo');
  } catch (error) {
    console.error(error);
    toast.error('Nastavení SMS se nepodařilo uložit');
  }
}

async function saveAlarmSmsSettings() {
  if (cantSaveAlarmSms.value) {
    toast.error('Zkontrolujte prosím nastavení SMS pro alarmy');
    return;
  }
  try {
    await SettingsService.saveJsvvSettings({
      allowAlarmSms: jsvvSettings.value.allowAlarmSms,
      alarmSmsContacts: jsvvSettings.value.alarmSmsContacts,
      alarmSmsMessage: jsvvSettings.value.alarmSmsMessage,
    });
    toast.success('SMS nastavení pro alarmy bylo uloženo');
  } catch (error) {
    console.error(error);
    toast.error('Nastavení SMS pro alarmy se nepodařilo uložit');
  }
}

async function saveEmailSettings() {
  if (cantSaveEmail.value) {
    toast.error('Zkontrolujte prosím nastavení e-mailu');
    return;
  }
  try {
    await SettingsService.saveJsvvSettings({
      allowEmail: jsvvSettings.value.allowEmail,
      emailContacts: jsvvSettings.value.emailContacts,
      emailSubject: jsvvSettings.value.emailSubject,
      emailMessage: jsvvSettings.value.emailMessage,
    });
    toast.success('E-mailové nastavení bylo uloženo');
  } catch (error) {
    console.error(error);
    toast.error('Nastavení e-mailu se nepodařilo uložit');
  }
}

async function saveAudioSettings() {
  savingAudios.value = true;
  try {
    const payload = jsvvAudios.value.map((audio) => {
      const entry = {...audio};
      if (entry.type === 'FILE') {
        entry.file_id = entry.file?.id ?? entry.file_id ?? null;
      }
      if (entry.group_label) {
        delete entry.group_label;
      }
      delete entry.file;
      return entry;
    });
    await JsvvAlarmService.saveJsvvAudios(payload);
    toast.success('Nastavení zvuků bylo uloženo');
    await loadAudios();
  } catch (error) {
    console.error(error);
    toast.error('Nastavení zvuků se nepodařilo uložit');
  } finally {
    savingAudios.value = false;
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

function addAlarmPhoneContact(newContact) {
  const phoneRegex = /^(\+(?:\d{1,3}))?(\s|-)?((?:\d{2,3})|\(\d{2,3}\))(?:\s|-)?(\d{3})(?:\s|-)?(\d{3})$/;
  if (!phoneRegex.test(newContact)) {
    toast.error('Zadané číslo není ve správném formátu');
    return;
  }
  jsvvSettings.value.alarmSmsContacts.push(newContact);
}

function addEmailContact(newContact) {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  if (!emailRegex.test(newContact)) {
    toast.error('Zadaný e-mail není ve správném formátu');
    return;
  }
  jsvvSettings.value.emailContacts.push(newContact);
}

function selectJsvvRecording(symbol) {
  const audio = jsvvAudios.value.find((item) => item.symbol === symbol);
  if (!audio) {
    return;
  }
  const {reveal, onConfirm} = createConfirmDialog(RecordSelectDialog, {
    typeFilter: 'JSVV',
    multiple: false,
  });
  reveal();
  onConfirm((data) => {
    audio.file = data;
    audio.file_id = data?.id ?? null;
  });
}

async function playRecord(id) {
  try {
    const response = await window.http.get(`records/${id}/get-blob`, {responseType: 'arraybuffer'});
    const audioBlob = new Blob([response.data], {type: response.headers['content-type']});
    const audio = new Audio(URL.createObjectURL(audioBlob));
    audio.play();
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se přehrát nahrávku');
  }
}
</script>

<template>
  <PageContent label="Nastavení JSVV">
    <div class="space-y-6">
      <Box label="Nastavení tlačítek JSVV">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
            <tr>
              <th>Tlačítko</th>
              <th>Sekvence</th>
              <th class="text-right">Akce</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="row in desktopButtons" :key="'desktop-' + row.button">
              <td class="font-medium">{{ row.label }}</td>
              <td>
                <Input v-model="row.sequence" maxlength="4" data-class="input-bordered input-sm w-full" placeholder="např. 1234"/>
              </td>
              <td class="text-right">
                <div class="flex justify-end gap-2">
                  <Button size="xs" icon="mdi-playlist-edit" variant="ghost" @click="openSequenceBuilder(row, 'desktop')">
                    Vybrat sekvenci
                  </Button>
                </div>
              </td>
            </tr>
            </tbody>
          </table>
        </div>
        <div class="flex justify-end">
          <Button :disabled="savingDesktop" icon="mdi-content-save" label="Uložit tlačítka" size="sm" @click="saveButtonSettings('desktop')"/>
        </div>
      </Box>

      <Box label="Nastavení tlačítek JSVV (mobil)">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
            <tr>
              <th>Tlačítko</th>
              <th>Sekvence</th>
              <th class="text-right">Akce</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="row in mobileButtons" :key="'mobile-' + row.button">
              <td class="font-medium">{{ row.label }}</td>
              <td>
                <Input v-model="row.sequence" maxlength="4" data-class="input-bordered input-sm w-full" placeholder="např. 1234"/>
              </td>
              <td class="text-right">
                <div class="flex justify-end gap-2">
                  <Button size="xs" icon="mdi-playlist-edit" variant="ghost" @click="openSequenceBuilder(row, 'mobile')">
                    Vybrat sekvenci
                  </Button>
                </div>
              </td>
            </tr>
            </tbody>
          </table>
        </div>
        <div class="flex justify-end">
          <Button :disabled="savingMobile" icon="mdi-content-save" label="Uložit mobilní tlačítka" size="sm" @click="saveButtonSettings('mobile')"/>
        </div>
      </Box>

      <Box label="Nastavení zvuků JSVV">
        <div class="overflow-x-auto">
          <table class="table">
            <thead>
            <tr>
              <th>Symbol</th>
              <th>Název</th>
              <th>Zdroj</th>
              <th class="text-right">Akce</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="audio in jsvvAudios" :key="audio.symbol">
              <td class="font-mono">{{ audio.symbol }}</td>
              <td>
                <Input v-model="audio.name" data-class="input-bordered input-sm w-full"/>
              </td>
              <td>
                <div v-if="audio.type === 'SOURCE'">
                  <Select
                      v-model="audio.source"
                      :options="audioSources"
                      label=""
                      option-key="value"
                      option-label="label"
                      data-class="select-bordered select-sm w-full"/>
                </div>
                <div v-else class="flex items-center gap-2">
                  <Button size="xs" variant="ghost" icon="mdi-file-music" @click="selectJsvvRecording(audio.symbol)">
                    Vybrat soubor
                  </Button>
                  <span v-if="audio.file?.name" class="text-xs text-gray-600">{{ audio.file.name }}</span>
                  <span v-else-if="audio.file_id" class="text-xs text-gray-500">Soubor ID: {{ audio.file_id }}</span>
                </div>
              </td>
              <td class="text-right">
                <Button
                    v-if="audio.file_id"
                    size="xs"
                    variant="ghost"
                    icon="mdi-play"
                    @click="playRecord(audio.file_id)">
                  Přehrát
                </Button>
              </td>
            </tr>
            </tbody>
          </table>
        </div>
        <div class="flex justify-end">
          <Button :disabled="savingAudios" icon="mdi-content-save" label="Uložit zvuky" size="sm" @click="saveAudioSettings"/>
        </div>
      </Box>

      <Box label="Nastavení lokality JSVV">
        <Select
            v-model="jsvvSettings.locationGroupId"
            label="Zvolte lokalitu"
            option-key="id"
            option-label="name"
            :options="locationGroups"
            data-class="select-bordered w-full"/>
        <div class="flex justify-end">
          <Button icon="mdi-content-save" label="Uložit lokalitu" size="sm" @click="saveLocationSettings"/>
        </div>
      </Box>

      <Box label="Nastavení informační SMS zprávy">
        <Checkbox v-model="jsvvSettings.allowSms" label="Povolit odesílání informační SMS při zahájení poplachu JSVV"/>

        <div class="space-y-4 mt-3">
          <div>
            <label class="label-text text-sm font-medium">Telefonní čísla</label>
            <VueMultiselect
                v-model="jsvvSettings.smsContacts"
                :options="[]"
                :multiple="true"
                :close-on-select="false"
                :taggable="true"
                @tag="addPhoneContact"
                placeholder="Přidat příjemce"
                tag-placeholder="Přidat číslo"
                :class="{'border border-error rounded-md': errorBag.smsContacts}"/>
            <p v-if="errorBag.smsContacts" class="text-xs text-error mt-1">{{ errorBag.smsContacts }}</p>
          </div>
          <Textarea
              v-model="jsvvSettings.smsMessage"
              placeholder="Zpráva, která bude odeslána příjemcům"
              label="Text zprávy"
              :error="errorBag.smsMessage"/>
        </div>

        <div class="flex justify-end">
          <Button icon="mdi-content-save" label="Uložit SMS nastavení" size="sm" :disabled="cantSaveSms" @click="saveSmsSettings"/>
        </div>
      </Box>

      <Box label="Nastavení SMS upozornění na alarm hnízd">
        <Checkbox v-model="jsvvSettings.allowAlarmSms" label="Povolit odesílání SMS při zařízení/hlídce (např. slabá baterie)"/>

        <div class="space-y-4 mt-3">
          <div>
            <label class="label-text text-sm font-medium">Telefonní čísla</label>
            <VueMultiselect
                v-model="jsvvSettings.alarmSmsContacts"
                :options="[]"
                :multiple="true"
                :close-on-select="false"
                :taggable="true"
                @tag="addAlarmPhoneContact"
                placeholder="Přidat příjemce"
                tag-placeholder="Přidat číslo"
                :class="{'border border-error rounded-md': errorBag.alarmSmsContacts}"/>
            <p v-if="errorBag.alarmSmsContacts" class="text-xs text-error mt-1">{{ errorBag.alarmSmsContacts }}</p>
          </div>
          <Textarea
              v-model="jsvvSettings.alarmSmsMessage"
              placeholder="Zpráva, která bude zaslána při vyhlášení alarmu (dostupné proměnné: {nest}, {repeat}, {data})"
              label="Text zprávy"
              :error="errorBag.alarmSmsMessage"/>
        </div>

        <div class="flex justify-end">
          <Button icon="mdi-content-save" label="Uložit SMS pro alarmy" size="sm" :disabled="cantSaveAlarmSms" @click="saveAlarmSmsSettings"/>
        </div>
      </Box>

      <Box label="Nastavení informačního e-mailu">
        <Checkbox v-model="jsvvSettings.allowEmail" label="Povolit odesílání informačního e-mailu při zahájení poplachu JSVV"/>

        <div class="space-y-4 mt-3">
          <div>
            <label class="label-text text-sm font-medium">E-mailové adresy</label>
            <VueMultiselect
                v-model="jsvvSettings.emailContacts"
                :options="[]"
                :multiple="true"
                :close-on-select="false"
                :taggable="true"
                @tag="addEmailContact"
                placeholder="Přidat příjemce"
                tag-placeholder="Přidat e-mail"
                :class="{'border border-error rounded-md': errorBag.emailContacts}"/>
            <p v-if="errorBag.emailContacts" class="text-xs text-error mt-1">{{ errorBag.emailContacts }}</p>
          </div>
          <Input v-model="jsvvSettings.emailSubject" placeholder="Předmět e-mailu" label="Předmět" :error="errorBag.emailSubject"/>
          <Textarea v-model="jsvvSettings.emailMessage" placeholder="Zpráva, která bude zaslána příjemcům" label="Text zprávy" :error="errorBag.emailMessage"/>
        </div>

        <div class="flex justify-end">
          <Button icon="mdi-content-save" label="Uložit e-mailové nastavení" size="sm" :disabled="cantSaveEmail" @click="saveEmailSettings"/>
        </div>
      </Box>
    </div>

    <TransitionRoot appear :show="builderState.open" as="template">
      <Dialog as="div" class="relative z-30" @close="closeSequenceBuilder">
        <TransitionChild
            as="template"
            enter="duration-300 ease-out"
            enter-from="opacity-0"
            enter-to="opacity-100"
            leave="duration-200 ease-in"
            leave-from="opacity-100"
            leave-to="opacity-0">
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
              <DialogPanel class="w-full max-w-5xl transform overflow-hidden rounded-2xl bg-white p-6 text-left shadow-xl transition-all space-y-6">
                <DialogTitle as="h3" class="text-lg font-semibold text-gray-800">
                  Vybrat sekvenci JSVV (max {{ builderState.maxLength }} symboly)
                </DialogTitle>

                <div class="grid gap-6 lg:grid-cols-2">
                  <div class="space-y-3 max-h-[520px] overflow-y-auto pr-2">
                    <div
                        v-for="group in groupedBuilderAudios"
                        :key="group.group"
                        class="border border-gray-200 rounded-lg">
                      <div class="bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-700">
                        {{ group.label }}
                      </div>
                      <div class="divide-y divide-gray-100">
                        <button
                            v-for="item in group.items"
                            :key="item.symbol"
                            type="button"
                            class="w-full flex justify-between items-center px-3 py-2 text-left hover:bg-primary/5"
                            @click="addSymbolToBuilder(item)">
                          <div>
                            <div class="font-medium text-gray-800">{{ item.name }}</div>
                            <div class="text-xs text-gray-500">Symbol: {{ item.symbol }}</div>
                          </div>
                          <span class="mdi mdi-plus text-primary text-xl"></span>
                        </button>
                      </div>
                    </div>
                  </div>

                  <div class="space-y-3">
                    <div class="flex items-center justify-between">
                      <div class="text-sm font-semibold text-gray-700">Sestava sekvence</div>
                      <span class="text-xs text-gray-500">
                        {{ builderState.selectedItems.length }} / {{ builderState.maxLength }}
                      </span>
                    </div>
                    <div class="border border-dashed border-gray-300 rounded-lg min-h-[200px] p-3 space-y-2">
                      <div v-if="builderState.selectedItems.length === 0" class="text-sm text-gray-500">
                        Klikněte na zvuk vlevo pro přidání do sekvence.
                      </div>
                      <div
                          v-for="(item, index) in builderState.selectedItems"
                          :key="`${item.symbol}-${index}`"
                          class="flex items-center justify-between gap-2 bg-white border border-gray-200 rounded px-3 py-2 shadow-sm">
                        <div>
                          <div class="font-medium text-gray-800">{{ item.label }}</div>
                          <div class="text-xs text-gray-500">Symbol: {{ item.symbol }} · {{ item.groupLabel }}</div>
                        </div>
                        <div class="flex items-center gap-1">
                          <button class="btn btn-xs btn-square" :disabled="index === 0" @click="moveSymbolUp(index)">
                            <span class="mdi mdi-chevron-up"></span>
                          </button>
                          <button class="btn btn-xs btn-square" :disabled="index === builderState.selectedItems.length - 1" @click="moveSymbolDown(index)">
                            <span class="mdi mdi-chevron-down"></span>
                          </button>
                          <button class="btn btn-xs btn-square btn-error" @click="removeSymbolFromBuilder(index)">
                            <span class="mdi mdi-close"></span>
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="flex justify-end gap-2">
                  <Button variant="ghost" icon="mdi-close" label="Zavřít" size="sm" @click="closeSequenceBuilder"/>
                  <Button icon="mdi-check" label="Uložit sekvenci" size="sm" @click="confirmSequenceBuilder"/>
                </div>
              </DialogPanel>
            </TransitionChild>
          </div>
        </div>
      </Dialog>
    </TransitionRoot>
  </PageContent>
</template>

<style scoped>
.multiselect.error .multiselect__tags {
  @apply border border-error;
}
</style>
