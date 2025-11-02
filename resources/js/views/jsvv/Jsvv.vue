<script setup>
import {computed, nextTick, onMounted, onBeforeUnmount, reactive, ref, watch} from "vue";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import ModalDialog from "../../components/modals/ModalDialog.vue";
import {useToast} from "vue-toastification";
import router from "../../router.js";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Button from "../../components/forms/Button.vue";
import Checkbox from "../../components/forms/Checkbox.vue";
import Input from "../../components/forms/Input.vue";
import JsvvAlarmService from "../../services/JsvvAlarmService.js";
import JsvvSequenceService from "../../services/JsvvSequenceService.js";
import SettingsService from "../../services/SettingsService.js";
import LiveBroadcastService from "../../services/LiveBroadcastService.js";
import {durationToTime, moveItemDown, moveItemUp} from "../../helper.js";
import {JSVV_BUTTON_DEFAULTS} from "../../constants/jsvvDefaults.js";

const toast = useToast();

const jsvvAlarms = ref([]);
const jsvvAudios = ref([]);
const loadingQuick = ref(false);
const sendingCustom = ref(false);
const stopInProgress = ref(false);
const fmInfo = ref(null);
const fmLoading = ref(false);
const fmPreviewLoading = ref(false);
const fmPreviewActive = ref(false);

const playbackControls = reactive({
  useFmRadio: false,
  fmFrequency: '',
});

const quickAlarmsDefinition = JSVV_BUTTON_DEFAULTS.map((item) => ({
  button: item.button,
  label: item.label,
  defaultSequence: item.sequence,
  steps: item.steps,
}));

const DEFAULT_JSVV_DURATIONS = (() => {
  const fallback = {verbal: 12, siren: 60, fallback: 10};
  if (typeof window !== 'undefined') {
    const candidates = [
      window.jsvvDefaultDurations,
      window.APP_JSVV_DEFAULTS,
      window.appConfig?.jsvv?.defaultDurations,
    ];
    for (const candidate of candidates) {
      if (!candidate || typeof candidate !== 'object') {
        continue;
      }
      const verbal = Number(candidate.verbal ?? candidate.VERBAL);
      const siren = Number(candidate.siren ?? candidate.SIREN);
      const fallbackValue = Number(candidate.fallback ?? candidate.FALLBACK ?? candidate.default);
      return Object.freeze({
        verbal: Number.isFinite(verbal) && verbal > 0 ? verbal : fallback.verbal,
        siren: Number.isFinite(siren) && siren > 0 ? siren : fallback.siren,
        fallback: Number.isFinite(fallbackValue) && fallbackValue > 0 ? fallbackValue : fallback.fallback,
      });
    }
  }
  return Object.freeze(fallback);
})();

const showCustomBuilder = ref(false);
const customSequence = ref([]);
const builderFilters = reactive({
  search: '',
});

onMounted(async () => {
  await Promise.all([fetchJsvvAlarms(), fetchJsvvAudios()]);
  await loadFmSettings();
});

onBeforeUnmount(() => {
  if (fmPreviewActive.value) {
    stopFmPreview();
  }
});

async function loadFmSettings() {
  fmLoading.value = true;
  try {
    const response = await SettingsService.fetchFMSettings();
    fmInfo.value = response;
    const frequency = response?.frequency ?? response?.frequency_mhz ?? null;
    if (!playbackControls.fmFrequency && frequency !== null && frequency !== '') {
      playbackControls.fmFrequency = String(frequency);
    }
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst frekvenci FM rádia');
  } finally {
    fmLoading.value = false;
  }
}

watch(showCustomBuilder, (visible) => {
  if (!visible) {
    builderFilters.search = '';
  } else {
    nextTick(() => {
      if (typeof document !== 'undefined') {
        document.getElementById('builder-search')?.focus();
      }
    });
  }
});

watch(
  () => playbackControls.fmFrequency,
  (value) => {
    if (typeof value !== 'string') {
      return;
    }
    const trimmed = value.trim();
    const sanitized = trimmed.replace(',', '.').replace(/[^0-9.]/g, '');
    if (sanitized !== value) {
      playbackControls.fmFrequency = sanitized;
    }
  }
);

watch(
  () => playbackControls.useFmRadio,
  (enabled) => {
    if (enabled && !playbackControls.fmFrequency && fmInfo.value?.frequency) {
      playbackControls.fmFrequency = String(fmInfo.value.frequency);
    }
    if (!enabled && fmPreviewActive.value) {
      stopFmPreview();
    }
  }
);

function fetchJsvvAlarms() {
  return JsvvAlarmService.fetchJsvvAlarms()
      .then((response) => {
        jsvvAlarms.value = response.data;
      })
      .catch((error) => {
        console.error(error);
        toast.error('Nepodařilo se načíst poplachy');
      });
}

function fetchJsvvAudios() {
  return JsvvAlarmService.getJsvvAudios()
      .then((response) => {
        jsvvAudios.value = response;
      })
      .catch((error) => {
        console.error(error);
        toast.error('Nepodařilo se načíst zvukové zdroje JSVV');
      });
}

const audioMap = computed(() => {
  const map = new Map();
  jsvvAudios.value.forEach((audio) => {
    const key = String(audio.symbol ?? '').trim().toUpperCase();
    if (key) {
      map.set(key, audio);
    }
  });
  return map;
});

function normaliseSymbol(symbol) {
  return String(symbol ?? '').trim().toUpperCase();
}

function resolveAudioForSymbol(symbol) {
  const key = normaliseSymbol(symbol);
  if (!key) {
    return undefined;
  }
  return audioMap.value.get(key);
}

function extractSlot(candidate) {
  if (candidate == null) {
    return null;
  }
  if (typeof candidate === 'number' && Number.isFinite(candidate)) {
    return candidate;
  }
  if (typeof candidate === 'string') {
    const match = candidate.match(/(\d+)/);
    if (match) {
      const value = Number.parseInt(match[1], 10);
      return Number.isNaN(value) ? null : value;
    }
  }
  return null;
}

function resolveSlotForSymbol(symbol, audio) {
  const normalized = normaliseSymbol(symbol);
  if (!normalized) {
    return null;
  }
  if (/^\d+$/.test(normalized)) {
    return Number.parseInt(normalized, 10);
  }
  const candidates = [];
  if (audio) {
    if (typeof audio.slot !== 'undefined') {
      candidates.push(audio.slot);
    }
    if (audio.file) {
      candidates.push(audio.file.metadata?.slot, audio.file.name, audio.file.filename);
    }
    candidates.push(audio.name);
  }
  candidates.push(symbol);
  for (const candidate of candidates) {
    const resolved = extractSlot(candidate);
    if (resolved != null) {
      return resolved;
    }
  }
  return null;
}

function normaliseCategory(category, audio) {
  const source = category ?? audio?.group ?? 'VERBAL';
  return String(source).trim().toLowerCase() === 'siren' ? 'siren' : 'verbal';
}

function normaliseRepeat(value) {
  const parsed = Number.parseInt(value ?? 1, 10);
  if (Number.isNaN(parsed) || parsed <= 0) {
    return 1;
  }
  return parsed;
}

function buildRequestItem(raw) {
  const symbol = raw?.symbol ?? raw?.slot ?? raw;
  const normalizedSymbol = normaliseSymbol(symbol);
  if (!normalizedSymbol) {
    throw new Error('Sekvence obsahuje prázdný symbol.');
  }
  const audio = resolveAudioForSymbol(normalizedSymbol);
  const slot = resolveSlotForSymbol(normalizedSymbol, audio);
  if (slot == null) {
    throw new Error(`Symbol ${normalizedSymbol} není přiřazen k žádnému slotu JSVV.`);
  }
  const category = normaliseCategory(raw?.category, audio);
  const repeat = normaliseRepeat(raw?.repeat);
  const item = {
    slot,
    category,
    repeat,
    symbol: normalizedSymbol,
  };
  const voice = raw?.voice ?? audio?.voice ?? audio?.default_voice;
  if (voice && category !== 'siren') {
    item.voice = voice;
  }
  return item;
}

function resolveDefaultDuration(category) {
  if (category === 'siren') {
    return DEFAULT_JSVV_DURATIONS.siren;
  }
  if (category === 'verbal') {
    return DEFAULT_JSVV_DURATIONS.verbal;
  }
  return DEFAULT_JSVV_DURATIONS.fallback;
}

function extractDurationSeconds(metadata) {
  if (!metadata || typeof metadata !== 'object') {
    return null;
  }
  const keys = ['duration_seconds', 'durationSeconds', 'duration', 'length'];
  for (const key of keys) {
    const value = metadata[key];
    if (typeof value === 'number' && Number.isFinite(value) && value > 0) {
      return value;
    }
    if (typeof value === 'string') {
      const numeric = Number.parseFloat(value);
      if (Number.isFinite(numeric) && numeric > 0) {
        return numeric;
      }
    }
  }
  return null;
}

function estimateSequenceDuration(sequenceItems) {
  if (!Array.isArray(sequenceItems) || sequenceItems.length === 0) {
    return null;
  }
  let total = 0;
  let hasValue = false;

  for (const entry of sequenceItems) {
    if (!entry) {
      continue;
    }
    const repeat = normaliseRepeat(entry.repeat);
    const audio = resolveAudioForSymbol(entry.symbol);
    const category = normaliseCategory(entry.category, audio);
    const metadata = audio?.file?.metadata ?? audio?.metadata ?? null;
    let duration = extractDurationSeconds(metadata);
    if (duration == null || !Number.isFinite(duration) || duration <= 0) {
      if (audio?.type === 'SOURCE') {
        return null;
      }
      duration = resolveDefaultDuration(category);
    }
    if (duration == null || !Number.isFinite(duration) || duration <= 0) {
      return null;
    }
    total += duration * repeat;
    hasValue = true;
  }

  return hasValue ? total : null;
}

const groupedAudios = computed(() => {
  const order = ['SIREN', 'GONG', 'VERBAL', 'AUDIO'];
  const groupLabels = {
    SIREN: 'Sirény',
    GONG: 'Gongy',
    VERBAL: 'Verbální informace',
    AUDIO: 'Audiovstupy',
  };
  const map = new Map(order.map((key) => [key, {key, label: groupLabels[key], items: []}]));
  jsvvAudios.value.forEach((audio) => {
    const entry = map.get(audio.group);
    if (entry) {
      entry.items.push({
        symbol: audio.symbol,
        name: audio.name,
        group: audio.group,
        groupLabel: entry.label,
      });
    }
  });
  return order
      .map((key) => map.get(key))
      .filter((entry) => entry && entry.items.length > 0);
});

const filteredGroupedAudios = computed(() => {
  const query = builderFilters.search.trim().toLowerCase();
  if (!query) {
    return groupedAudios.value;
  }
  return groupedAudios.value
      .map((group) => ({
        ...group,
        items: group.items.filter((item) =>
            item.name.toLowerCase().includes(query) ||
            item.symbol.toLowerCase().includes(query)
        ),
      }))
      .filter((group) => group.items.length > 0);
});

const alarmByButton = computed(() => {
  const map = new Map();
  jsvvAlarms.value.forEach((alarm) => {
    if (alarm.button != null) {
      map.set(Number(alarm.button), alarm);
    }
  });
  return map;
});

const quickAlarms = computed(() => quickAlarmsDefinition.map((definition) => {
  const alarm = alarmByButton.value.get(definition.button) ?? null;
  const sequence = (alarm?.sequence || definition.defaultSequence || '').toUpperCase();

  let sequenceItems = [];
  try {
    if (alarm) {
      sequenceItems = buildSequenceItems(alarm);
    } else if (sequence) {
      sequenceItems = buildSequenceFromSymbols(sequence);
    }
  } catch (error) {
    console.debug('Nepodařilo se sestavit sekvenci pro tlačítko JSVV', {
      button: definition.button,
      error,
    });
    sequenceItems = [];
  }

  let durationSeconds = null;
  const backendDuration = alarm?.estimated_duration_seconds;
  if (typeof backendDuration === 'number' && Number.isFinite(backendDuration) && backendDuration > 0) {
    durationSeconds = backendDuration;
  } else if (backendDuration != null) {
    const numeric = Number(backendDuration);
    if (Number.isFinite(numeric) && numeric > 0) {
      durationSeconds = numeric;
    }
  }

  if (durationSeconds == null) {
    const estimated = estimateSequenceDuration(sequenceItems);
    if (estimated != null && Number.isFinite(estimated) && estimated > 0) {
      durationSeconds = estimated;
    }
  }

  const durationLabel = durationSeconds != null
    ? durationToTime(Math.round(durationSeconds))
    : 'N/A';

  return {
    ...definition,
    alarm,
    sequence,
    sequenceLabel: sequence || 'Nenastaveno',
    durationSeconds,
    durationLabel,
  };
}));

const hasCustomSequence = computed(() => customSequence.value.length > 0);

function openCustomBuilder() {
  showCustomBuilder.value = true;
}

function closeCustomBuilder() {
  showCustomBuilder.value = false;
  clearCustomSequence();
}

function manageAlarm(alarm) {
  if (!alarm?.id) {
    toast.error('Tento poplach není nakonfigurován.');
    return;
  }
  router.push({name: 'EditJSVV', params: {id: alarm.id}});
}

async function stopJsvvPlayback() {
  if (stopInProgress.value) {
    return;
  }
  stopInProgress.value = true;
  try {
    const response = await JsvvSequenceService.sendCommand({type: 'STOP', payload: {reason: 'ui_stop_button'}});
    const status = response?.status ?? response?.sequence?.status ?? 'stopped';
    if (status === 'busy') {
      toast.warning('Sekvencer právě zpracovává poplach, zkuste to znovu.');
    } else {
      toast.success('Vysílání JSVV bylo ukončeno.');
    }
  } catch (error) {
    console.error(error);
    const message = error?.response?.data?.message ?? error?.message ?? 'Nepodařilo se odeslat příkaz STOP.';
    toast.error(message);
  } finally {
    stopInProgress.value = false;
  }
}

function expandSequenceDefinition(rawSequence, fallbackSymbol) {
  if (Array.isArray(rawSequence)) {
    return rawSequence;
  }
  if (typeof rawSequence === 'string') {
    const trimmed = rawSequence.trim();
    if (!trimmed) {
      return [fallbackSymbol];
    }
    try {
      const parsed = JSON.parse(trimmed);
      if (Array.isArray(parsed)) {
        return parsed;
      }
    } catch (error) {
      // Not a JSON array; fall back to symbol string.
    }
    return trimmed
        .split('')
        .map((symbol) => symbol.trim())
        .filter((symbol) => symbol.length > 0);
  }
  if (rawSequence == null) {
    return [fallbackSymbol];
  }
  return [rawSequence];
}

function buildSequenceItems(alarm) {
  const rawSequence = alarm.sequence_json || alarm.sequence;
  const fallbackSymbol = alarm.slot ?? alarm.button ?? alarm.id ?? 1;
  const entries = expandSequenceDefinition(rawSequence, fallbackSymbol);
  return entries.map((entry) => buildRequestItem(entry));
}

const buildSequenceFromSymbols = (sequenceString) => {
  if (!sequenceString) {
    return [];
  }
  return sequenceString
      .toString()
      .split('')
      .map((symbol) => symbol.trim())
      .filter((symbol) => symbol.length > 0)
      .map((symbol) => buildRequestItem(symbol));
};

function notifySequenceTrigger(result) {
  const status = result?.status ?? null;
  const position = Number(result?.queue_position ?? result?.queuePosition ?? 0);
  if (status === 'not_found') {
    toast.error('Sekvenci se nepodařilo najít.');
    return;
  }
  if (status === 'failed') {
    const message = result?.error_message ?? 'Poplach se nepodařilo spustit.';
    toast.error(message);
    return;
  }
  if (status === 'running') {
    toast.success('Poplach byl spuštěn.');
    return;
  }
  if (status === 'queued') {
    if (Number.isFinite(position) && position > 1) {
      toast.success(`Poplach byl zařazen do fronty JSVV (pozice ${position}).`);
    } else {
      toast.success('Poplach byl zařazen do fronty JSVV.');
    }
    return;
  }
  toast.success('Požadavek na poplach byl přijat.');
}

function buildSequenceOptions(base = {}) {
  const options = {...base};
  if (playbackControls.useFmRadio) {
    options.audioInputId = 'fm';
    options.playbackSource = 'fm_radio';
    if (playbackControls.fmFrequency) {
      options.frequency = playbackControls.fmFrequency;
    }
  }
  return options;
}

function formatFrequencyDisplay(value) {
  if (value === null || value === undefined) {
    return 'Neznámá';
  }
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) {
    return String(value);
  }
  if (numeric > 1000) {
    const mhz = numeric / 1_000_000;
    return `${mhz.toFixed(2)} MHz (${numeric.toFixed(0)} Hz)`;
  }
  return `${numeric.toFixed(2)} MHz`;
}

async function startFmPreview() {
  if (fmPreviewLoading.value || fmPreviewActive.value) {
    return;
  }
  if (!playbackControls.fmFrequency) {
    toast.warning('Zadejte frekvenci FM rádia.');
    return;
  }

  fmPreviewLoading.value = true;
  try {
    const parsed = Number(playbackControls.fmFrequency);
    const frequency = Number.isFinite(parsed) ? parsed : playbackControls.fmFrequency;
    const options = {
      frequency,
      playbackSource: 'fm_radio',
      audioInputId: 'fm',
    };
    await LiveBroadcastService.startBroadcast({
      source: 'fm_radio',
      route: [],
      locations: [],
      nests: [],
      options,
    });
    fmPreviewActive.value = true;
    toast.success('FM stream byl spuštěn.');
  } catch (error) {
    console.error(error);
    const message = error?.response?.data?.message ?? 'Nepodařilo se spustit FM stream.';
    toast.error(message);
  } finally {
    fmPreviewLoading.value = false;
  }
}

async function stopFmPreview() {
  if (fmPreviewLoading.value || !fmPreviewActive.value) {
    return;
  }
  fmPreviewLoading.value = true;
  try {
    await LiveBroadcastService.stopBroadcast('fm_preview_stop');
    toast.info('FM stream byl zastaven.');
  } catch (error) {
    console.error(error);
    const message = error?.response?.data?.message ?? 'Nepodařilo se zastavit FM stream.';
    toast.error(message);
  } finally {
    fmPreviewLoading.value = false;
    fmPreviewActive.value = false;
  }
}

async function triggerQuickAlarm(definition) {
  if (loadingQuick.value) {
    return;
  }
  const alarm = definition.alarm;
  const sequenceString = alarm?.sequence || definition.sequence || definition.defaultSequence;
  if (!sequenceString) {
    toast.error('Pro tento poplach není definována sekvence.');
    return;
  }
  const {reveal, onConfirm} = createConfirmDialog(ModalDialog, {
    title: definition.label,
    message: 'Alarm bude odeslán do ústředny.',
  });
  reveal();
  onConfirm(async () => {
      loadingQuick.value = true;
      try {
        const items = alarm ? buildSequenceItems(alarm) : buildSequenceFromSymbols(sequenceString);
        const sequence = await JsvvSequenceService.planSequence(items, buildSequenceOptions({
          priority: alarm?.priority ?? 'P2',
          zones: alarm?.zones ?? [],
        }));
        const sequenceId = sequence?.id ?? sequence?.sequence?.id;
        if (!sequenceId) {
          throw new Error('Sequence ID missing');
        }
        const triggerResult = await JsvvSequenceService.triggerSequence(sequenceId);
      notifySequenceTrigger(triggerResult);
    } catch (error) {
      console.error(error);
      toast.error(error?.message ?? 'Nepodařilo se odeslat alarm');
    } finally {
      loadingQuick.value = false;
    }
  });
}

function addCustomItem(item) {
  customSequence.value.push({
    uid: `${item.symbol}-${Date.now()}-${Math.random()}`,
    symbol: item.symbol,
    label: item.name,
    group: item.group,
    groupLabel: item.groupLabel,
  });
}

function removeCustomItem(index) {
  customSequence.value.splice(index, 1);
}

function moveCustomItemUp(index) {
  if (index <= 0) {
    return;
  }
  moveItemUp(customSequence.value, index);
  customSequence.value = [...customSequence.value];
}

function moveCustomItemDown(index) {
  if (index >= customSequence.value.length - 1) {
    return;
  }
  moveItemDown(customSequence.value, index);
  customSequence.value = [...customSequence.value];
}

function clearCustomSequence() {
  customSequence.value = [];
}

async function sendCustomSequence() {
  if (!hasCustomSequence.value || sendingCustom.value) {
    toast.warning('Sestavte nejprve vlastní poplach.');
    return;
  }
  let items;
  try {
    items = customSequence.value.map((entry) =>
        buildRequestItem({slot: entry.symbol, category: entry.group, repeat: 1})
    );
  } catch (error) {
    console.error(error);
    toast.error(error?.message ?? 'Sekvenci se nepodařilo připravit.');
    return;
  }
  sendingCustom.value = true;
  try {
    const options = buildSequenceOptions({});
    const commandResult = await JsvvSequenceService.sendCommand({
      type: 'SEQUENCE',
      payload: {
        ...options,
        steps: items,
      }
    });
    notifySequenceTrigger(commandResult?.sequence ?? commandResult);
    customSequence.value = [];
  } catch (error) {
    console.error(error);
    toast.error(error?.message ?? 'Nepodařilo se odeslat vlastní poplach');
  } finally {
    sendingCustom.value = false;
  }
}

function openProtocol() {
  router.push({name: 'Log'});
}
</script>

<template>
  <PageContent label="Poplach JSVV">
    <div class="space-y-6">
      <div class="flex flex-wrap gap-3">
        <Button icon="mdi-format-list-bulleted" variant="secondary" size="sm" @click="openProtocol">
          Protokol JSVV
        </Button>
        <Button icon="mdi-cog" size="sm" route-to="JSVVSettings">
          Nastavení JSVV
        </Button>
        <Button
            icon="mdi-bullhorn-variant"
            size="sm"
            :disabled="showCustomBuilder"
            @click="openCustomBuilder">
          Vlastní poplach
        </Button>
        <Button
            icon="mdi-stop-circle"
            size="sm"
            variant="danger"
            :disabled="stopInProgress"
            @click="stopJsvvPlayback">
          Ukončit JSVV
        </Button>
      </div>

      <Box label="Nastavení přehrávání">
        <div class="space-y-4">
          <Checkbox v-model="playbackControls.useFmRadio" label="Přehrávat poplach přes FM rádio"/>
          <div
              v-if="playbackControls.useFmRadio"
              class="grid gap-4 sm:grid-cols-[minmax(0,220px),1fr] items-start">
            <Input
                v-model="playbackControls.fmFrequency"
                label="Frekvence rádia"
                placeholder="Např.: 104.3"
                badge="MHz"
                data-class="input-bordered input-sm"
            />
            <div class="text-xs text-gray-500 space-y-1">
              <div v-if="fmLoading">
                Načítám aktuální nastavení FM přijímače…
              </div>
              <div v-else-if="fmInfo?.frequency">
                Aktuální frekvence přijímače: <span class="font-semibold">{{ formatFrequencyDisplay(fmInfo.frequency) }}</span>
              </div>
              <div v-else>
                Zadejte požadovanou frekvenci v&nbsp;MHz. Pokud pole ponecháte prázdné, použije se výchozí nastavení přijímače.
              </div>
              <div class="flex flex-wrap gap-2 pt-2">
                <Button
                    v-if="!fmPreviewActive"
                    icon="mdi-play"
                    size="sm"
                    :disabled="fmPreviewLoading"
                    @click="startFmPreview">
                  Spustit prehravani
                </Button>
                <Button
                    v-else
                    icon="mdi-stop"
                    size="sm"
                    variant="danger"
                    :disabled="fmPreviewLoading"
                    @click="stopFmPreview">
                  Zastavit FM stream
                </Button>
              </div>
            </div>
          </div>
        </div>
      </Box>

      <Box label="Tlačítka JSVV">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <div
              v-for="definition in quickAlarms"
              :key="definition.button"
              class="border border-gray-200 rounded-lg bg-white shadow-sm p-4 flex flex-col gap-3">
            <div class="flex items-start justify-between gap-2">
              <div>
                <div class="text-xs text-gray-500">Tlačítko {{ definition.button }}</div>
                <div class="font-semibold text-gray-800">{{ definition.label }}</div>
                <div class="text-xs text-gray-500 mt-1">
                  Sekvence: <span class="font-medium">{{ definition.sequenceLabel }}</span>
                </div>
                <div class="text-xs text-gray-500 mt-1">
                  Délka: <span class="font-medium">{{ definition.durationLabel }}</span>
                </div>
                <ul v-if="definition.steps?.length" class="mt-2 text-xs text-gray-500 space-y-1 list-disc list-inside">
                  <li v-for="(step, stepIndex) in definition.steps" :key="stepIndex">{{ step }}</li>
                </ul>
              </div>
              <Button
                  icon="mdi-cog"
                  size="xs"
                  variant="ghost"
                  :disabled="!definition.alarm"
                  @click="manageAlarm(definition.alarm)">
                Nastavit
              </Button>
            </div>
            <Button
                icon="mdi-bullhorn"
                variant="primary"
                :disabled="loadingQuick || !definition.sequence"
                @click="triggerQuickAlarm(definition)">
              Spustit poplach
            </Button>
          </div>
        </div>
      </Box>

      <Box v-if="showCustomBuilder" label="Vlastní poplach">
        <div class="grid gap-6 lg:grid-cols-2">
          <div class="space-y-4">
            <div class="flex flex-col gap-2">
              <label class="text-sm font-medium text-gray-700" for="builder-search">Vyhledávání</label>
              <input
                  id="builder-search"
                  v-model="builderFilters.search"
                  type="text"
                  class="input input-bordered w-full"
                  placeholder="Hledat v seznamu zvuků"/>
            </div>
            <div class="space-y-4 max-h-[520px] overflow-y-auto pr-2">
              <div
                  v-for="group in filteredGroupedAudios"
                  :key="group.key"
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
                      @click="addCustomItem(item)">
                    <div>
                      <div class="font-medium text-gray-800">{{ item.name }}</div>
                      <div class="text-xs text-gray-500">Symbol: {{ item.symbol }}</div>
                    </div>
                    <span class="mdi mdi-plus text-primary text-xl"></span>
                  </button>
                </div>
              </div>
              <div v-if="filteredGroupedAudios.length === 0" class="text-sm text-gray-500">
                Žádné zvuky neodpovídají hledání.
              </div>
            </div>
          </div>

          <div class="flex flex-col gap-4">
            <div class="flex items-center justify-between">
              <div>
                <div class="text-sm  font-semibold text-gray-700">Sestava poplachu</div>
                <div class="text-xs text-gray-500">Kliknutím na zvuky vlevo je přidáte do této sekvence.</div>
              </div>
              <Button
                  size="xs"
                  variant="ghost"
                  icon="mdi-delete"
                  :disabled="!hasCustomSequence || sendingCustom"
                  @click="clearCustomSequence">
                Vyčistit
              </Button>
            </div>

            <div class="border border-dashed border-gray-300 rounded-lg min-h-[200px] p-3 space-y-2">
              <div v-if="!hasCustomSequence" class="text-sm text-gray-500">
                Dosud nebyly přidány žádné položky. Vyberte zvuk z panelu vlevo.
              </div>
              <div
                  v-for="(item, index) in customSequence"
                  :key="item.uid"
                  class="flex items-center justify-between gap-2 bg-white border border-gray-200 rounded px-3 py-2 shadow-sm">
                <div>
                  <div class="font-medium text-gray-800">{{ item.label }}</div>
                  <div class="text-xs text-gray-500">Symbol: {{ item.symbol }} · {{ item.groupLabel }}</div>
                </div>
                <div class="flex items-center gap-1">
                  <button
                      class="btn btn-xs btn-square"
                      :disabled="index === 0"
                      @click="moveCustomItemUp(index)">
                    <span class="mdi mdi-chevron-up"></span>
                  </button>
                  <button
                      class="btn btn-xs btn-square"
                      :disabled="index === customSequence.length - 1"
                      @click="moveCustomItemDown(index)">
                    <span class="mdi mdi-chevron-down"></span>
                  </button>
                  <button
                      class="btn btn-xs btn-square btn-error"
                      @click="removeCustomItem(index)">
                    <span class="mdi mdi-close"></span>
                  </button>
                </div>
              </div>
            </div>

            <div class="flex flex-wrap gap-3">
              <Button
                  icon="mdi-bullhorn-variant"
                  :disabled="!hasCustomSequence || sendingCustom"
                  @click="sendCustomSequence">
                Odeslat vlastní poplach
              </Button>
              <Button
                  icon="mdi-playlist-plus"
                  variant="ghost"
                  :disabled="!hasCustomSequence"
                  @click="() => toast.info('Sekvenci lze uložit v nastavení JSVV.')">
                Uložit jako předvolbu
              </Button>
              <Button
                  icon="mdi-close"
                  variant="ghost"
                  :disabled="sendingCustom"
                  @click="closeCustomBuilder">
                Zavřít
              </Button>
            </div>
          </div>
        </div>
      </Box>
    </div>
  </PageContent>
</template>
