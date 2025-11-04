<script setup>
import {computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch} from "vue";
import {useToast} from "vue-toastification";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Button from "../../components/forms/Button.vue";
import Input from "../../components/forms/Input.vue";
import LiveBroadcastService from "../../services/LiveBroadcastService.js";
import LocationService from "../../services/LocationService.js";
import SettingsService from "../../services/SettingsService.js";
import RecordSelectDialog from "../../components/modals/RecordSelectDialog.vue";
import {formatDate} from "../../helper.js";

const toast = useToast();

const INPUT_OPTIONS = [
  {id: "microphone", label: "Mikrofon"},
  {id: "central_file", label: "Soubor v ústředně"},
  {id: "fm_radio", label: "FM vstup"},
  {id: "input_1", label: "Vstup 1"},
  {id: "input_2", label: "Vstup 2"},
  {id: "system_audio", label: "Systémový zvuk"},
];

const INPUT_DEFINITIONS = {
  microphone: {mixer: "mic", volume: {group: "inputs", id: "mic_capture"}},
  central_file: {mixer: "system", volume: {group: "inputs", id: "system_capture"}},
  fm_radio: {mixer: "fm", volume: {group: "inputs", id: "fm_capture"}},
  input_1: {mixer: "line1", volume: {group: "inputs", id: "mic_capture"}},
  input_2: {mixer: "line2", volume: {group: "inputs", id: "fm_capture"}},
  system_audio: {mixer: "system", volume: {group: "inputs", id: "system_capture"}},
};

const volumeSlider = Object.freeze({min: 0, max: 100, step: 1});

const status = ref({session: null, status: null, device: null});
const loading = ref(false);
const statusLoading = ref(false);
const syncingForm = ref(false);

const locationGroups = ref([]);
const nests = ref([]);
const fmInfo = ref(null);

const form = reactive({
  input: INPUT_OPTIONS[0].id,
  volume: 50,
  playlistItems: [],
  selectedLocationGroups: [],
  selectedNests: [],
  fmFrequency: "",
});

const audioPreview = ref(null);
const playlistAudioCache = new Map();
let volumeUpdateHandle = null;

const isStreaming = computed(() => status.value?.session?.status === "running");

const currentInputDefinition = computed(() => INPUT_DEFINITIONS[form.input] ?? null);
const currentMixerAlias = computed(() => currentInputDefinition.value?.mixer ?? null);

const showPlaylistControls = computed(() => form.input === "central_file");
const showFmControls = computed(() => form.input === "fm_radio");
const selectedPlaylistItem = computed(() => form.playlistItems[0] ?? null);

const locationOptions = computed(() => Array.isArray(locationGroups.value) ? locationGroups.value : []);
const nestOptions = computed(() => Array.isArray(nests.value) ? nests.value : []);

const requestedRoute = computed(() => ensureNumericArray(status.value?.session?.requestedRoute));
const appliedRoute = computed(() => ensureNumericArray(status.value?.session?.route));
const appliedZones = computed(() => ensureNumericArray(status.value?.session?.zones));

const locationNameMap = computed(() => new Map(locationOptions.value.map(group => [Number(group.id), group.name])));
const nestNameMap = computed(() => new Map(nestOptions.value.map(nest => [Number(nest.id), nest.name])));

const locationDisplayNames = computed(() => {
  const sessionLocations = ensureNumericArray(status.value?.session?.locations);
  return sessionLocations.map(id => locationNameMap.value.get(id) ?? `ID ${id}`);
});

const nestDisplayNames = computed(() => {
  const sessionNests = ensureNumericArray(status.value?.session?.nests);
  return sessionNests.map(id => nestNameMap.value.get(id) ?? `ID ${id}`);
});

const missingTargets = computed(() => {
  const missing = status.value?.session?.options?._missing ?? {};
  return {
    locationGroups: ensureNumericArray(missing.location_groups).map(id => locationNameMap.value.get(id) ?? `ID ${id}`),
    locations: ensureArray(missing.locations),
    nests: ensureNumericArray(missing.nests).map(id => nestNameMap.value.get(id) ?? `ID ${id}`),
    locationGroupsMissingAddress: ensureNumericArray(missing.location_groups_missing_address).map(id => locationNameMap.value.get(id) ?? `ID ${id}`),
    zonesOverflow: ensureArray(missing.zones_overflow),
  };
});

const sessionSourceLabel = computed(() => {
  const sourceId = status.value?.session?.source ?? null;
  const match = INPUT_OPTIONS.find(option => option.id === sourceId);
  return match?.label ?? (sourceId ?? "Neznámý vstup");
});

const sessionStartedAt = computed(() => {
  const started = status.value?.session?.started_at ?? null;
  return started ? formatDate(started, "d.m.Y H:i:s") : "–";
});

const sessionNote = computed(() => status.value?.session?.options?.note ?? null);

const volumeLabel = computed(() => `${Math.round(clampVolume(form.volume))} %`);

watch(() => form.input, async (newValue, oldValue) => {
  if (syncingForm.value) {
    return;
  }

  const previousVolume = form.volume;
  stopPreview();

  if (newValue === "fm_radio" && !form.fmFrequency) {
    await loadFmFrequency();
  }

  try {
    const revertInput = typeof oldValue === "string" && INPUT_DEFINITIONS[oldValue] ? oldValue : null;
    await applySourceUpdate({silent: true, revertInput, revertVolume: previousVolume});
    if (isStreaming.value) {
      toast.success("Vstup živého vysílání byl přepnut.");
    } else {
      toast.success("Vstup ústředny byl nastaven.");
    }
  } catch (error) {
    console.error(error);
    toast.error("Nepodařilo se přepnout vstup.");
  }
});

watch(selectedPlaylistItem, () => {
  stopPreview();
});

const ensureArray = (value) => Array.isArray(value) ? value : [];
const ensureNumericArray = (value) => ensureArray(value).map(item => Number(item)).filter(Number.isFinite);

const clampVolume = (value) => {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) {
    return volumeSlider.min;
  }
  return Math.min(volumeSlider.max, Math.max(volumeSlider.min, numeric));
};

const findInputLabel = (id) => INPUT_OPTIONS.find(option => option.id === id)?.label ?? id;

const loadStatus = async () => {
  statusLoading.value = true;
  try {
    const response = await LiveBroadcastService.getStatus();
    status.value = response ?? {};

    const session = response?.session ?? {};
    syncingForm.value = true;
    if (session.source && INPUT_DEFINITIONS[session.source]) {
      form.input = session.source;
    }
    form.selectedLocationGroups = ensureNumericArray(session.locations).map(id => String(id));
    form.selectedNests = ensureNumericArray(session.nests).map(id => String(id));
    syncingForm.value = false;

    const volumeOption = session.options?.volume;
    const numericVolume = Number(volumeOption);
    if (Number.isFinite(numericVolume)) {
      form.volume = clampVolume(numericVolume);
    }

    if (form.input === "fm_radio") {
      await loadFmFrequency();
    }

  } catch (error) {
    console.error(error);
    toast.error("Nepodařilo se načíst stav vysílání.");
  } finally {
    syncingForm.value = false;
    statusLoading.value = false;
  }
};

const loadLocationGroups = async () => {
  try {
    const response = await LocationService.getAllLocationGroups();
    locationGroups.value = Array.isArray(response) ? response : (response?.data ?? []);
  } catch (error) {
    console.error(error);
    toast.error("Nepodařilo se načíst lokality.");
  }
};

const loadNests = async () => {
  try {
    const response = await LocationService.fetchNests();
    nests.value = Array.isArray(response) ? response : [];
  } catch (error) {
    console.error(error);
    toast.error("Nepodařilo se načíst hnízda.");
  }
};

const loadFmFrequency = async () => {
  try {
    const response = await SettingsService.fetchFMSettings();
    fmInfo.value = response ?? null;
    const value = response?.frequency ?? "";
    form.fmFrequency = formatFrequency(value);
  } catch (error) {
    console.error(error);
    toast.error("Nepodařilo se načíst frekvenci FM.");
  }
};

const saveFmFrequency = async () => {
  try {
    const numeric = parseFrequency(form.fmFrequency);
    if (!Number.isFinite(numeric) || numeric <= 0) {
      toast.error("Zadejte platnou frekvenci FM.");
      return;
    }
    await SettingsService.saveFMSettings({frequency: numeric});
    toast.success("Frekvence FM byla uložena.");
    await loadFmFrequency();
  } catch (error) {
    console.error(error);
    toast.error("Nepodařilo se uložit frekvenci FM.");
  }
};

const openPlaylistDialog = () => {
  const {reveal, onConfirm} = createConfirmDialog(RecordSelectDialog, {
    title: "Vyberte nahrávku",
    typeFilter: "ALL",
    multiple: false,
  });
  reveal();
  onConfirm((selection) => {
    const item = Array.isArray(selection) ? selection[0] : selection;
    form.playlistItems = item ? [item] : [];
    if (item) {
      form.input = "central_file";
    }
  });
};

const clearPlaylist = () => {
  stopPreview();
  form.playlistItems = [];
};

const fetchRecordingAudio = async (item) => {
  const recordingId = item?.id ?? item?.recording_id ?? item?.file_id ?? null;
  if (!recordingId) {
    throw new Error("Neplatná nahrávka.");
  }
  if (playlistAudioCache.has(recordingId)) {
    return playlistAudioCache.get(recordingId);
  }
  if (!window.http) {
    throw new Error("HTTP klient není dostupný.");
  }

  const response = await window.http.get(`records/${recordingId}/get-blob`, {responseType: "arraybuffer"});
  const mime = response.headers?.["content-type"] ?? "audio/mpeg";
  const blob = new Blob([response.data], {type: mime});
  const url = URL.createObjectURL(blob);
  const result = {url, mime};
  playlistAudioCache.set(recordingId, result);
  return result;
};

const playSelectedFile = async () => {
  const item = selectedPlaylistItem.value;
  if (!item) {
    toast.error("Nejprve vyberte soubor k přehrání.");
    return;
  }

  try {
    const audioData = await fetchRecordingAudio(item);
    const element = audioPreview.value;
    if (!element) {
      return;
    }
    element.pause();
    element.src = audioData.url;
    element.load();
    await nextTick();
    await element.play();
  } catch (error) {
    console.error(error);
    toast.error("Nepodařilo se přehrát vybraný soubor.");
  }
};

const stopPreview = () => {
  const element = audioPreview.value;
  if (!element) {
    return;
  }
  element.pause();
  element.currentTime = 0;
  element.removeAttribute("src");
  element.load();
};

const handlePreviewEnded = () => {
  stopPreview();
};

const applySourceUpdate = async ({silent = false, revertInput = null, revertVolume = null} = {}) => {
  const alias = currentMixerAlias.value;
  if (!alias) {
    throw new Error("Pro zvolený vstup není nakonfigurován ALSA profil.");
  }

  if (!isStreaming.value) {
    return;
  }

  const payload = {
    identifier: alias,
    source: form.input,
    volume: clampVolume(form.volume),
  };

  try {
    await LiveBroadcastService.selectLiveSource(payload);
    if (!silent) {
      toast.success(isStreaming.value ? "Vstup a hlasitost byly aktualizovány." : "Vstup a hlasitost byly nastaveny.");
    }
  } catch (error) {
    if (revertInput && INPUT_DEFINITIONS[revertInput]) {
      syncingForm.value = true;
      form.input = revertInput;
      syncingForm.value = false;
    }
    if (revertVolume !== null) {
      form.volume = clampVolume(revertVolume);
    }
    throw error;
  }
};

const handleVolumeInput = (value) => {
  const previousVolume = form.volume;
  form.volume = clampVolume(value);
  scheduleVolumeUpdate(previousVolume);
};

const scheduleVolumeUpdate = (previousVolume) => {
  if (volumeUpdateHandle) {
    clearTimeout(volumeUpdateHandle);
  }
  if (!isStreaming.value) {
    return;
  }
  volumeUpdateHandle = setTimeout(async () => {
    try {
      await applySourceUpdate({silent: true});
      if (isStreaming.value) {
        toast.success("Hlasitost byla aktualizována.");
      }
    } catch (error) {
      console.error(error);
      toast.error("Nepodařilo se nastavit hlasitost.");
      if (previousVolume !== undefined) {
        form.volume = clampVolume(previousVolume);
      }
    }
  }, 200);
};

const buildStartPayload = () => {
  const options = {};

  if (form.input === "central_file") {
    if (form.playlistItems.length === 0) {
      throw new Error("Nejprve vyberte soubor v ústředně.");
    }
    const playlistItems = form.playlistItems
      .map(item => serializePlaylistItem(item))
      .filter(Boolean);
    if (playlistItems.length === 0) {
      throw new Error("Vybraný soubor nelze zpracovat.");
    }
    options.playlist = playlistItems;
  }

  if (form.input === "fm_radio" && form.fmFrequency) {
    const numericFrequency = parseFrequency(form.fmFrequency);
    if (!Number.isFinite(numericFrequency) || numericFrequency <= 0) {
      throw new Error("Zadejte platnou frekvenci FM.");
    }
    options.frequency = numericFrequency;
  }

  options.volume = clampVolume(form.volume);

  const payload = {
    source: form.input,
    route: [],
    locations: ensureNumericArray(form.selectedLocationGroups),
    nests: ensureNumericArray(form.selectedNests),
    options,
  };

  const mixerAlias = currentMixerAlias.value;
  if (mixerAlias) {
    payload.mixer = {
      identifier: mixerAlias,
      source: form.input,
      volume: clampVolume(form.volume),
    };
  }

  return payload;
};

const startStream = async () => {
  if (form.input === "central_file" && form.playlistItems.length === 0) {
    toast.error("Pro vysílání ze souboru musíte vybrat nahrávku.");
    return;
  }

  loading.value = true;
  try {
    const payload = buildStartPayload();
    await LiveBroadcastService.startBroadcast(payload);
    await loadStatus();
    toast.success("Vysílání bylo spuštěno.");
  } catch (error) {
    console.error(error);
    const message = error?.response?.data?.message ?? error?.message ?? "Nepodařilo se spustit vysílání.";
    toast.error(message);
  } finally {
    loading.value = false;
  }
};

const stopStream = async () => {
  loading.value = true;
  try {
    await LiveBroadcastService.stopBroadcast("frontend_stop");
    await loadStatus();
    toast.info("Vysílání bylo zastaveno.");
  } catch (error) {
    console.error(error);
    toast.error("Nepodařilo se zastavit vysílání.");
  } finally {
    loading.value = false;
  }
};

const updateActiveStream = async () => {
  if (!isStreaming.value) {
    toast.info("Žádné vysílání neběží. Použijte tlačítko Spustit vysílání.");
    return;
  }

  try {
    const payload = buildStartPayload();
    await LiveBroadcastService.startBroadcast(payload);
    toast.success("Živé vysílání bylo aktualizováno.");
  } catch (error) {
    console.error(error);
    toast.error("Nepodařilo se aktualizovat vysílání.");
  }
};

const serializePlaylistItem = (item) => {
  if (!item) {
    return null;
  }
  const id = item.id ?? item.recording_id ?? item.file_id ?? null;
  if (!id) {
    return null;
  }

  const metadata = {...(item.metadata ?? {})};
  const duration =
    item.duration_seconds ??
    item.durationSeconds ??
    metadata.duration ??
    metadata.duration_seconds ??
    null;
  const gain = item.gain ?? metadata.gain ?? null;
  const path = item.path ?? metadata.path ?? metadata.file?.path ?? null;
  const storagePath = item.storage_path ?? metadata.storage_path ?? metadata.file?.storage_path ?? null;
  const filename = item.filename ?? metadata.filename ?? metadata.file?.filename ?? null;
  const extension = item.extension ?? metadata.extension ?? metadata.file?.extension ?? null;
  const mimeType = item.mime_type ?? item.mimeType ?? metadata.mimeType ?? null;

  const result = {
    id: String(id),
    title: resolveRecordingLabel(item),
  };

  if (duration !== null) {
    result.durationSeconds = Number(duration);
  }
  if (gain !== null) {
    result.gain = Number(gain);
  }
  if (path) {
    result.path = path;
  }
  if (storagePath) {
    result.storage_path = storagePath;
  }
  if (filename) {
    result.filename = filename;
  }
  if (extension) {
    result.extension = extension;
  }
  if (mimeType) {
    result.mimeType = mimeType;
  }
  if (Object.keys(metadata).length > 0) {
    result.metadata = metadata;
  }

  return result;
};

const resolveRecordingLabel = (item) => {
  if (!item) {
    return "";
  }
  return (
    item.name ??
    item.title ??
    item.original_name ??
    item.filename ??
    (item.id ? `ID ${item.id}` : "Soubor")
  );
};

onMounted(async () => {
  await Promise.all([
    loadLocationGroups(),
    loadNests(),
  ]);
  await loadStatus();
  if (form.input === "fm_radio") {
    await loadFmFrequency();
  }
});

onBeforeUnmount(() => {
  stopPreview();
  if (volumeUpdateHandle) {
    clearTimeout(volumeUpdateHandle);
  }
  playlistAudioCache.forEach((value) => {
    if (value?.url) {
      URL.revokeObjectURL(value.url);
    }
  });
  playlistAudioCache.clear();
});

</script>

<template>
  <PageContent label="Živé vysílání">
    <audio ref="audioPreview" class="hidden" @ended="handlePreviewEnded"></audio>

    <div class="space-y-6">
      <div class="w-full">
        <Button
          class="w-full flex flex-col items-start gap-2 p-5 text-left border border-primary rounded-lg shadow-lg bg-primary text-white hover:bg-primary/90 transition"
          :disabled="loading"
          :variant="isStreaming ? 'danger' : 'primary'"
          :icon="isStreaming ? 'mdi-stop' : 'mdi-play-circle'"
          @click="isStreaming ? stopStream() : startStream()"
        >
          <span class="text-lg font-semibold">
            {{ isStreaming ? "Zastavit vysílání" : "Spustit vysílání" }}
          </span>
          <span class="text-sm opacity-80">
            {{ isStreaming ? "Aktuálně běží živé vysílání" : "Kliknutím spustíte vysílání do vybraných lokalit" }}
          </span>
        </Button>
      </div>

      <Box label="Aktuální stav vysílání">
        <div v-if="statusLoading" class="text-sm text-gray-500">Načítám aktuální stav…</div>
        <div v-else>
          <div v-if="isStreaming && status.session" class="grid gap-2 text-sm text-gray-700 md:grid-cols-2">
            <div><strong>Zdroj:</strong> {{ sessionSourceLabel }}</div>
            <div><strong>Start:</strong> {{ sessionStartedAt }}</div>
            <div><strong>Zóny:</strong> {{ appliedZones.length ? appliedZones.join(', ') : '-' }}</div>
            <div><strong>Route (požadováno):</strong> {{ requestedRoute.length ? requestedRoute.join(', ') : '-' }}</div>
            <div><strong>Route (aplikováno):</strong> {{ appliedRoute.length ? appliedRoute.join(', ') : '-' }}</div>
            <div><strong>Lokality:</strong> {{ locationDisplayNames.length ? locationDisplayNames.join(', ') : '-' }}</div>
            <div><strong>Hnízda:</strong> {{ nestDisplayNames.length ? nestDisplayNames.join(', ') : '-' }}</div>
            <div v-if="sessionNote"><strong>Poznámka:</strong> {{ sessionNote }}</div>
          </div>
          <div v-else class="text-sm text-gray-500">Žádná relace není aktivní.</div>
        </div>
        <div class="mt-4 flex flex-wrap gap-3">
          <Button variant="secondary" :disabled="statusLoading" icon="mdi-refresh" @click="loadStatus">
            Aktualizovat stav
          </Button>
          <Button v-if="isStreaming" variant="ghost" :disabled="loading" icon="mdi-update" @click="updateActiveStream">
            Aktualizovat běžící vysílání
          </Button>
        </div>
      </Box>

      <div class="grid gap-6 md:grid-cols-2">
        <Box label="Zdroje a obsah">
          <div class="space-y-6">
            <div class="space-y-2">
              <span class="block text-sm font-semibold text-gray-700">Výběr vstupu</span>
              <div class="flex flex-col gap-2">
                <label
                  v-for="option in INPUT_OPTIONS"
                  :key="option.id"
                  class="flex items-center gap-2 rounded border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm transition hover:border-primary"
                >
                  <input
                    type="radio"
                    class="form-radio text-primary focus:ring-primary"
                    :value="option.id"
                    v-model="form.input"
                    :disabled="loading"
                  >
                  <span>{{ option.label }}</span>
                </label>
              </div>
            </div>

            <div v-if="showPlaylistControls" class="space-y-3">
              <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-700">Soubor v ústředně</span>
                <div class="flex items-center gap-2">
                  <Button size="xs" icon="mdi-playlist-plus" @click="openPlaylistDialog">Vybrat soubor</Button>
                  <Button
                    v-if="selectedPlaylistItem"
                    size="xs"
                    variant="ghost"
                    icon="mdi-trash-can"
                    @click="clearPlaylist"
                  >
                    Odebrat
                  </Button>
                </div>
              </div>

              <div v-if="!selectedPlaylistItem" class="text-xs text-gray-500">
                Zatím není vybrána žádná nahrávka.
              </div>

              <div v-else class="rounded border border-gray-200 bg-gray-50 px-3 py-2 text-sm">
                <div class="font-medium text-gray-800">{{ resolveRecordingLabel(selectedPlaylistItem) }}</div>
                <div class="mt-2 flex gap-2">
                  <Button size="xs" icon="mdi-play" @click="playSelectedFile">Přehrát</Button>
                  <Button size="xs" variant="ghost" icon="mdi-stop" @click="stopPreview">Zastavit</Button>
                </div>
              </div>
            </div>

            <div v-if="showFmControls" class="space-y-3">
              <Input
                v-model="form.fmFrequency"
                label="FM frekvence (MHz)"
                type="text"
                placeholder="např. 99,5"
              />
              <div class="flex gap-2">
                <Button size="xs" icon="mdi-content-save" @click="saveFmFrequency">Uložit frekvenci</Button>
                <Button size="xs" variant="ghost" icon="mdi-refresh" @click="loadFmFrequency">Načíst aktuální</Button>
              </div>
            </div>

            <div class="space-y-2">
              <span class="block text-sm font-semibold text-gray-700">Hlasitost ({{ volumeLabel }})</span>
              <input
                class="range range-sm w-full"
                type="range"
                :min="volumeSlider.min"
                :max="volumeSlider.max"
                :step="volumeSlider.step"
                :value="form.volume"
                @input="handleVolumeInput($event.target.value)"
              >
              <div class="flex justify-between text-xs text-gray-500">
                <span>0%</span>
                <span>100%</span>
              </div>
            </div>
          </div>
        </Box>

        <Box label="Cílové lokality a hnízda">
          <div class="space-y-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Lokality</label>
              <select
                v-model="form.selectedLocationGroups"
                multiple
                class="form-select w-full h-32 border border-gray-300 rounded-md bg-white focus:border-primary focus:ring focus:ring-primary focus:ring-opacity-40"
              >
                <option v-for="group in locationOptions" :key="group.id" :value="String(group.id)">
                  {{ group.name }}{{ group.modbus_group_address ? ` (adresa ${group.modbus_group_address})` : '' }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Hnízda</label>
              <select
                v-model="form.selectedNests"
                multiple
                class="form-select w-full h-32 border border-gray-300 rounded-md bg-white focus:border-primary focus:ring focus:ring-primary focus:ring-opacity-40"
              >
                <option v-for="nest in nestOptions" :key="nest.id" :value="String(nest.id)">
                  {{ nest.name }}{{ nest.modbus_address ? ` (adresa ${nest.modbus_address})` : '' }}
                </option>
              </select>
              <p class="text-xs text-gray-500 mt-1">
                Vybraná hnízda budou přidána mezi cílové zóny vysílání.
              </p>
            </div>

            <div
              v-if="
                missingTargets.locationGroups.length ||
                missingTargets.locations.length ||
                missingTargets.nests.length ||
                missingTargets.locationGroupsMissingAddress.length ||
                missingTargets.zonesOverflow.length
              "
              class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-3 space-y-1"
            >
              <p class="font-semibold">Chybějící cíle:</p>
              <ul class="list-disc list-inside space-y-1">
                <li v-if="missingTargets.locationGroups.length">Skupiny: {{ missingTargets.locationGroups.join(', ') }}</li>
                <li v-if="missingTargets.locations.length">Lokality bez adresy: {{ missingTargets.locations.join(', ') }}</li>
                <li v-if="missingTargets.nests.length">Hnízda bez adresy: {{ missingTargets.nests.join(', ') }}</li>
                <li v-if="missingTargets.locationGroupsMissingAddress.length">Sdílená adresa chybí u: {{ missingTargets.locationGroupsMissingAddress.join(', ') }}</li>
                <li v-if="missingTargets.zonesOverflow.length">Překročena kapacita zón: {{ missingTargets.zonesOverflow.join(', ') }}</li>
              </ul>
            </div>
          </div>
        </Box>
      </div>
    </div>
  </PageContent>
</template>
const formatFrequency = (value) => {
  if (value === null || value === undefined || value === "") {
    return "";
  }
  const numeric = parseFloat(String(value).replace(",", "."));
  if (!Number.isFinite(numeric)) {
    return String(value);
  }
  return numeric.toString().replace(".", ",");
};

const parseFrequency = (value) => {
  if (value === null || value === undefined) {
    return NaN;
  }
  const normalized = String(value).trim().replace(/\s+/g, "").replace(",", ".");
  return Number.parseFloat(normalized);
};
