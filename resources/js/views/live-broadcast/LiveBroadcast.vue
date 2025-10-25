<script setup>
import {computed, nextTick, onMounted, reactive, ref, watch} from "vue";
import {useToast} from "vue-toastification";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import Input from "../../components/forms/Input.vue";
import Textarea from "../../components/forms/Textarea.vue";
import Button from "../../components/forms/Button.vue";
import LiveBroadcastService from "../../services/LiveBroadcastService.js";
import LocationService from "../../services/LocationService.js";
import SettingsService from "../../services/SettingsService.js";
import RecordSelectDialog from "../../components/modals/RecordSelectDialog.vue";
import {formatDate} from "../../helper.js";
import VolumeService from "../../services/VolumeService.js";

const toast = useToast();

const sources = ref([]);
const locationGroups = ref([]);
const nests = ref([]);
const status = ref({session: null, status: null, device: null});
const loading = ref(false);
const fmInfo = ref(null);
const syncingForm = ref(false);
const liveUpdateInProgress = ref(false);

const form = reactive({
  source: '',
  routeText: '',
  selectedLocationGroups: [],
  selectedNests: [],
  note: '',
  playlistItems: [],
  fmFrequency: ''
});

const audioOutputDevices = ref([]);
const initialAudioOutput = JSON.parse(localStorage.getItem('audioOutputDevice') ?? 'null');
const selectedAudioOutputId = ref(initialAudioOutput?.id ?? 'default');
const sourceLabelMap = computed(() => {
  const map = new Map();
  sources.value.forEach((source) => {
    if (source?.id) {
      map.set(source.id, source.label ?? source.id);
    }
  });
  return map;
});

const isStreaming = computed(() => status.value?.session?.status === 'running');
const showPlaylistControls = computed(() => form.source === 'central_file');
const showFmInfo = computed(() => form.source === 'fm_radio');
const hasNestsDefined = computed(() => nests.value.length > 0);
const volumeGroups = ref([]);
const volumeLoading = ref(false);
const volumeSaving = reactive({});
const volumeSlider = {
  min: 0,
  max: 100,
  step: 1,
};
const sourceInputChannelMap = ref({
  microphone: 'input_1',
  central_file: 'file_playback',
  pc_webrtc: 'pc_webrtc',
  input_2: 'input_2',
  input_3: 'input_3',
  input_4: 'input_4',
  input_5: 'input_5',
  input_6: 'input_6',
  input_7: 'input_7',
  input_8: 'input_8',
  fm_radio: 'fm_radio',
  control_box: 'control_box',
});
const hardwareAudioDevices = ref({
  playback_devices: [],
  capture_devices: [],
  pulse: {
    sinks: [],
    sources: []
  },
  pulse_controls: [],
  timestamp: null,
});
const nextScheduledBroadcast = computed(() => {
  const schedule = status.value?.next_schedule ?? null;
  if (!schedule) {
    return null;
  }
  const scheduledAt = schedule.scheduled_at ? new Date(schedule.scheduled_at) : null;
  return {
    ...schedule,
    scheduledAt,
    scheduledAtFormatted: scheduledAt ? formatDate(scheduledAt, 'd.m.Y H:i') : null,
  };
});

const sessionSourceLabel = computed(() => {
  const sourceId = status.value?.session?.source ?? null;
  if (!sourceId) {
    return null;
  }
  return sourceLabelMap.value.get(sourceId) ?? sourceId;
});

const selectedSourceLabel = computed(() => {
  if (!form.source) {
    return null;
  }
  return sourceLabelMap.value.get(form.source) ?? form.source;
});

const selectionMetadata = computed(() => {
  const session = status.value?.session ?? {};
  const selection = session.options?._selection ?? {};
  const resolved = session.applied ?? session.options?._resolved ?? {};
  const labels = session.labels ?? session.options?._labels ?? {};

  const toArray = (value) => (Array.isArray(value) ? value : []);
  const locationSummaries = Array.isArray(labels?.locations) ? labels.locations : [];
  const nestSummaries = Array.isArray(labels?.nests) ? labels.nests : [];

  const nestAddresses = (() => {
    const resolvedAddresses = toArray(resolved?.nestAddresses);
    if (resolvedAddresses.length > 0) {
      return resolvedAddresses.map(Number);
    }
    return nestSummaries
      .map((item) => item?.modbus_address)
      .filter((value) => Number.isFinite(value))
      .map(Number);
  })();

  return {
    route: toArray(selection?.route),
    locationIds: toArray(selection?.locations ?? session.locations ?? session.zones),
    nestIds: toArray(selection?.nests ?? session.nests),
    resolvedRoute: toArray(resolved?.route ?? session.route),
    resolvedZones: toArray(resolved?.zones ?? session.zones),
    nestAddresses,
    locationSummaries,
    nestSummaries,
    groupAddresses: toArray(resolved?.groupAddresses),
  };
});

const requestedRoute = computed(() => selectionMetadata.value.route);
const appliedRoute = computed(() => selectionMetadata.value.resolvedRoute);
const appliedZones = computed(() => selectionMetadata.value.resolvedZones);

const locationDisplayNames = computed(() => {
  const {locationSummaries, locationIds} = selectionMetadata.value;
  const labelled = locationSummaries
    .map((item) => {
      if (!item || typeof item.name !== 'string') {
        return null;
      }
      const address = item.modbus_group_address ?? null;
      if (Number.isFinite(address)) {
        return `${item.name} (sdílená adresa ${address})`;
      }
      return item.name;
    })
    .filter((name) => typeof name === 'string' && name.trim().length > 0);

  if (labelled.length > 0) {
    return labelled;
  }

  if (locationIds.length === 0) {
    return [];
  }

  const map = new Map(locationGroups.value.map((group) => [Number(group.id), group.name]));
  return locationIds.map((id) => map.get(Number(id)) ?? `ID ${id}`);
});

const nestDisplayNames = computed(() => {
  const {nestSummaries, nestIds, nestAddresses} = selectionMetadata.value;

  const labelled = nestSummaries
    .map((item) => item?.name)
    .filter((name) => typeof name === 'string' && name.trim().length > 0);

  if (labelled.length > 0) {
    return labelled;
  }

  if (nestIds.length > 0) {
    const map = new Map(nests.value.map((nest) => [Number(nest.id), nest.name ?? `ID ${nest.id}`]));
    return nestIds.map((id) => map.get(Number(id)) ?? `ID ${id}`);
  }

  if (nestAddresses.length > 0) {
    return nestAddresses.map((address) => `#${address}`);
  }

  return [];
});

const missingTargets = computed(() => {
  const session = status.value?.session ?? {};
  const missing = session.options?._missing ?? {};
  const groupMap = new Map((locationGroups.value ?? []).map((group) => [Number(group.id), group.name]));

  return {
    locationGroups: Array.isArray(missing?.location_groups)
        ? missing.location_groups.map((id) => groupMap.get(Number(id)) ?? `ID ${id}`)
        : [],
    locations: Array.isArray(missing?.locations) ? missing.locations : [],
    nests: Array.isArray(missing?.nests) ? missing.nests : [],
    zonesOverflow: Array.isArray(missing?.zones_overflow) ? missing.zones_overflow : [],
    locationGroupsMissingAddress: Array.isArray(missing?.location_groups_missing_address)
        ? missing.location_groups_missing_address.map((id) => groupMap.get(Number(id)) ?? `ID ${id}`)
        : [],
  };
});

onMounted(async () => {
  await Promise.all([
    loadSources(),
    loadLocationGroups(),
    loadNests(),
    loadStatus(),
    loadHardwareAudioDevices(),
    loadVolumeLevels()
  ]);
});

watch(() => form.source, async (newSource, oldSource) => {
  if (syncingForm.value) {
    return;
  }

  if (newSource === 'fm_radio') {
    await loadFmFrequency();
  }

  if (newSource === 'central_file' && form.playlistItems.length === 0) {
    await nextTick();
    pickPlaylist();
  }

  if (isStreaming.value && newSource !== oldSource) {
    if (liveUpdateInProgress.value) {
      return;
    }
    if (newSource === 'central_file' && form.playlistItems.length === 0) {
      return;
    }
    await applyLiveUpdate();
  }
});

const loadSources = async () => {
  try {
    const response = await LiveBroadcastService.getSources();
    const list = Array.isArray(response) ? response : (response?.sources ?? []);
    const normalised = list
      .map((item) => ({
        id: item?.id ?? null,
        label: item?.label ?? item?.id ?? '',
        available: item?.available !== false,
        unavailable_reason: item?.unavailable_reason ?? null,
      }))
      .filter(item => typeof item.id === 'string' && item.id.length > 0);

    sources.value = normalised;

    const ensureDefaultSource = () => {
      const preferred = sources.value.find(source => source.id === 'pc_webrtc' && source.available);
      const firstAvailable = sources.value.find(source => source.available);
      const fallback = preferred ?? firstAvailable ?? sources.value[0] ?? null;
      if (fallback) {
        form.source = fallback.id;
      } else {
        form.source = '';
      }
    };

    if (!form.source) {
      ensureDefaultSource();
      return;
    }

    const selected = sources.value.find(source => source.id === form.source);
    if (!selected || selected.available === false) {
      if (!isStreaming.value) {
        ensureDefaultSource();
      }
    }
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst dostupné zdroje');
  }
};

const loadLocationGroups = async () => {
  try {
    const response = await LocationService.getAllLocationGroups();
    locationGroups.value = Array.isArray(response) ? response : (response?.data ?? []);
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst lokality');
  }
};

const loadNests = async () => {
  try {
    nests.value = await LocationService.fetchNests();
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst hnízda');
  }
};

const buildHardwareOutputList = (devices) => {
  const outputs = [{id: 'default', label: 'Výchozí systém'}];
  const sinks = Array.isArray(devices?.pulse?.sinks) ? devices.pulse.sinks : [];
  sinks.forEach((sink) => {
    if (sink?.name) {
      outputs.push({
        id: `pulse:${sink.name}`,
        label: sink?.pretty_name ?? sink?.description ?? `PulseAudio ${sink.name}`,
      });
    }
  });

  const playbackDevices = Array.isArray(devices?.playback_devices) ? devices.playback_devices : [];
  playbackDevices.forEach((device) => {
    const card = Number.isFinite(device?.card) ? Number(device.card) : null;
    const dev = Number.isFinite(device?.device) ? Number(device.device) : null;
    const id = card !== null && dev !== null ? `alsa:${card}:${dev}` : null;
    if (id) {
      const cardLabel = device?.card_description ?? device?.card_name ?? `Karta ${card}`;
      const devLabel = device?.device_description ?? device?.device_name ?? `Zařízení ${dev}`;
      outputs.push({
        id,
        label: `${cardLabel} • ${devLabel}`,
      });
    }
  });

  const unique = [];
  const seen = new Set();
  outputs.forEach((item) => {
    if (item?.id && !seen.has(item.id)) {
      seen.add(item.id);
      unique.push(item);
    }
  });
  return unique;
};

const loadHardwareAudioDevices = async (silent = false) => {
  try {
    const devices = await LiveBroadcastService.getAudioDevices();
    hardwareAudioDevices.value = devices;
    const outputs = buildHardwareOutputList(devices);
    audioOutputDevices.value = outputs;
    if (!outputs.some(device => device.id === selectedAudioOutputId.value)) {
      selectedAudioOutputId.value = outputs[0]?.id ?? 'default';
    }
  } catch (error) {
    console.error('Failed to detect audio hardware', error);
    if (!silent) {
      toast.error('Nepodařilo se načíst hardware audio zařízení');
    }
  }
};

const loadStatus = async () => {
  try {
    await loadHardwareAudioDevices(true);
    const response = await LiveBroadcastService.getStatus();
    syncingForm.value = true;
    status.value = response;

    const session = response?.session ?? {};
    const selection = session.options?._selection ?? {};
    const ensureArray = (value) => Array.isArray(value) ? value : [];
    const routeSelection = ensureArray(selection.route ?? session.requestedRoute ?? session.route);
    const locationSelection = ensureArray(selection.locations ?? session.locations ?? session.zones);
    const nestSelection = ensureArray(selection.nests ?? session.nests);

    form.routeText = routeSelection.length > 0 ? routeSelection.join(', ') : '';
    form.selectedLocationGroups = locationSelection.map(value => String(value));
    form.selectedNests = nestSelection.map(value => String(value));

    if (session.source) {
      form.source = session.source;
      if (session.source === 'fm_radio') {
        await loadFmFrequency();
      }
    }

    const optionBag = session.options ?? {};
    if (typeof optionBag.note === 'string') {
      form.note = optionBag.note;
    }

    const audioOutputId = optionBag.audioOutputId ?? optionBag.audio_output_id ?? selectedAudioOutputId.value;
    if (audioOutputId) {
      selectedAudioOutputId.value = audioOutputId;
    }
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst stav vysílání');
  } finally {
    syncingForm.value = false;
  }
};

const loadFmFrequency = async () => {
  try {
    const response = await SettingsService.fetchFMSettings();
    form.fmFrequency = response?.frequency ?? '';
    fmInfo.value = response;
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst frekvenci FM rádia');
  }
};

const persistAudioOutput = () => {
  const device = audioOutputDevices.value.find(output => output.id === selectedAudioOutputId.value);
  const payload = {
    id: selectedAudioOutputId.value,
    label: device?.label ?? 'Výchozí systém',
  };
  localStorage.setItem('audioOutputDevice', JSON.stringify(payload));
};

if (!initialAudioOutput) {
  persistAudioOutput();
}

watch(selectedAudioOutputId, async (newValue, oldValue) => {
  persistAudioOutput();
  if (syncingForm.value) {
    return;
  }
  if (!isStreaming.value) {
    return;
  }
  if (liveUpdateInProgress.value) {
    return;
  }
  if (newValue === oldValue) {
    return;
  }
  await applyLiveUpdate();
});

const parseNumericList = (value) => {
  if (!value) {
    return [];
  }
  return value
      .split(',')
      .map(item => item.trim())
      .filter(item => item !== '')
      .map(item => Number(item))
      .filter(Number.isFinite);
};

watch(
  () => form.playlistItems.map(item => item.id ?? item),
  async (newValue, oldValue) => {
    if (syncingForm.value) {
      return;
    }
    if (!isStreaming.value) {
      return;
    }
    if (form.source !== 'central_file') {
      return;
    }
    if (form.playlistItems.length === 0) {
      return;
    }
    if (liveUpdateInProgress.value) {
      return;
    }
    await applyLiveUpdate();
  }
);

const makeVolumeKey = (groupId, itemId) => `${groupId}:${itemId}`;

const loadVolumeLevels = async (silent = false) => {
  volumeLoading.value = true;
  try {
    const response = await VolumeService.fetchLiveLevels();
    const groups = Array.isArray(response?.groups) ? response.groups : [];
    volumeGroups.value = groups;
    if (response?.sourceChannels && typeof response.sourceChannels === 'object') {
      sourceInputChannelMap.value = {
        ...sourceInputChannelMap.value,
        ...response.sourceChannels
      };
    }
  } catch (error) {
    console.error(error);
    if (!silent) {
      toast.error('Nepodařilo se načíst nastavení hlasitosti');
    }
  } finally {
    volumeLoading.value = false;
  }
};

const updateVolumeLevel = async (groupId, itemId, value) => {
  const key = makeVolumeKey(groupId, itemId);
  volumeSaving[key] = true;
  try {
    const response = await VolumeService.applyRuntimeLevel({group: groupId, id: itemId, value});
    const updatedItem = response?.item ?? null;
    if (updatedItem) {
      const group = volumeGroups.value.find(entry => entry.id === groupId);
      if (group) {
        const index = group.items.findIndex(entry => entry.id === itemId);
        if (index !== -1) {
          group.items[index] = {...group.items[index], ...updatedItem};
        }
      }
    }
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se uložit změnu hlasitosti');
    throw error;
  } finally {
    delete volumeSaving[key];
  }
};

const handleActiveVolumeChange = async () => {
  const entry = currentVolumeEntry.value;
  if (!entry) {
    return;
  }
  const {groupId, item} = entry;
  const parsed = Number(item.value);
  if (Number.isNaN(parsed)) {
    toast.error('Zadejte platnou číselnou hodnotu');
    return;
  }
  const clamped = Math.min(volumeSlider.max, Math.max(volumeSlider.min, parsed));
  item.value = clamped;
  try {
    await updateVolumeLevel(groupId, item.id, clamped);
  } catch (error) {
    await loadVolumeLevels(true);
  }
};

const currentSourceId = computed(() => {
  if (isStreaming.value && status.value?.session?.source) {
    return status.value.session.source;
  }
  return form.source || status.value?.session?.source || null;
});

const activeInputItemId = computed(() => {
  const source = currentSourceId.value;
  if (source && sourceInputChannelMap.value?.[source]) {
    return sourceInputChannelMap.value[source];
  }
  return null;
});

const currentVolumeEntry = computed(() => {
  const targetId = activeInputItemId.value;
  if (!targetId) {
    return null;
  }
  for (const group of volumeGroups.value) {
    const items = Array.isArray(group?.items) ? group.items : [];
    const found = items.find(item => item.id === targetId);
    if (found) {
      return {groupId: group.id, item: found};
    }
  }
  return null;
});

const currentInputVolumeGroupId = computed(() => currentVolumeEntry.value?.groupId ?? null);

const currentInputVolumeItem = computed(() => currentVolumeEntry.value?.item ?? null);

const currentVolumeSavingKey = computed(() => {
  const groupId = currentInputVolumeGroupId.value;
  const item = currentInputVolumeItem.value;
  if (!groupId || !item) {
    return null;
  }
  return makeVolumeKey(groupId, item.id);
});

const isCurrentVolumeSaving = computed(() => {
  const key = currentVolumeSavingKey.value;
  return key ? Boolean(volumeSaving[key]) : false;
});

const buildStartPayload = () => {
  const options = {};
  if (form.note) {
    options.note = form.note;
  }
  if (showPlaylistControls.value && form.playlistItems.length > 0) {
    options.playlist = form.playlistItems.map(item => ({
      id: item.id,
      title: item.name ?? item.title ?? item.original_name ?? '',
      durationSeconds: item.duration_seconds ?? item.durationSeconds ?? null,
    }));
  }
  if (showFmInfo.value && form.fmFrequency) {
    options.frequency = form.fmFrequency;
  }
  options.audioOutputId = selectedAudioOutputId.value || 'default';

  const manualRoute = parseNumericList(form.routeText);
  const selectedLocationIds = form.selectedLocationGroups.map(value => Number(value)).filter(Number.isFinite);
  const selectedNestIds = form.selectedNests.map(value => Number(value)).filter(Number.isFinite);

  return {
    source: form.source || 'microphone',
    route: manualRoute,
    locations: selectedLocationIds,
    nests: selectedNestIds,
    options,
  };
};

const startStream = async () => {
  if (showPlaylistControls.value && form.playlistItems.length === 0) {
    toast.warning('Vyberte prosím soubor v ústředně.');
    return;
  }

  loading.value = true;
  try {
    const payload = buildStartPayload();
    await LiveBroadcastService.startBroadcast(payload);
    toast.success('Vysílání bylo spuštěno');
    await loadStatus();
  } catch (error) {
    console.error(error);
    const message = error?.response?.data?.message ?? 'Nepodařilo se spustit vysílání';
    toast.error(message);
  } finally {
    loading.value = false;
  }
};

const stopStream = async () => {
  loading.value = true;
  try {
    await LiveBroadcastService.stopBroadcast('frontend_stop');
    toast.info('Vysílání bylo zastaveno');
    await loadStatus();
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se zastavit vysílání');
  } finally {
    loading.value = false;
  }
};

const applyLiveUpdate = async () => {
  if (liveUpdateInProgress.value) {
    return;
  }
  if (!isStreaming.value) {
    return;
  }

  liveUpdateInProgress.value = true;
  try {
    const payload = buildStartPayload();
    await LiveBroadcastService.startBroadcast(payload);
    await loadStatus();
  } catch (error) {
    console.error(error);
    const message = error?.response?.data?.message ?? 'Nepodařilo se aktualizovat vysílání';
    toast.error(message);
  } finally {
    liveUpdateInProgress.value = false;
  }
};

const pickPlaylist = () => {
  const {reveal, onConfirm} = createConfirmDialog(RecordSelectDialog, {
    title: 'Vyberte nahrávky',
    typeFilter: 'ALL',
    multiple: true
  });
  reveal();
  onConfirm((selection) => {
    form.playlistItems = Array.isArray(selection) ? selection : [selection];
  });
};

const removePlaylistItem = (index) => {
  form.playlistItems.splice(index, 1);
};

</script>

<template>
  <PageContent label="Živé vysílání">
    <div class="space-y-6">
      <div class="w-full">
        <Button
            class="w-full flex flex-col items-start gap-2 p-5 text-left border border-primary rounded-lg shadow-lg bg-primary text-white hover:bg-primary/90 transition"
            :disabled="loading"
            :variant="isStreaming ? 'danger' : 'primary'"
            :icon="isStreaming ? 'mdi-stop' : 'mdi-play-circle'"
            :label="isStreaming ? 'Zastavit aktuální vysílání' : 'Spustit nové vysílání'"
            @click="isStreaming ? stopStream() : startStream()">
          <template #default>
            <div class="flex w-full justify-between items-center">
              <div class="space-y-1">
                <div class="text-lg font-semibold">
                  {{ isStreaming ? 'Vysílání běží' : 'Žádné vysílání není aktivní' }}
                </div>
                <div class="text-sm opacity-90">
                  Zdroj: {{ sessionSourceLabel ?? selectedSourceLabel ?? '–' }}
                </div>
                <div class="text-sm opacity-90" v-if="status.session?.startedAt || status.session?.started_at">
                  Začátek: {{ status.session.startedAt ?? status.session.started_at }}
                </div>
              </div>
              <div class="text-sm text-white/80 space-y-1 text-right">
                <div>Route: {{ appliedRoute.length ? appliedRoute.join(', ') : '-' }}</div>
                <div>Zóny: {{ appliedZones.length ? appliedZones.join(', ') : '-' }}</div>
              </div>
            </div>
          </template>
        </Button>
      </div>

      <Box label="Aktuální stav vysílání">
        <template v-if="isStreaming && status.session">
          <div class="grid gap-4 md:grid-cols-2 text-sm">
            <div class="space-y-2">
              <div><strong>ID relace:</strong> {{ status.session.id }}</div>
              <div><strong>Zdroj:</strong> {{ sessionSourceLabel ?? status.session.source }}</div>
              <div><strong>Stav:</strong> {{ status.session.status }}</div>
              <div><strong>Začátek:</strong> {{ status.session.startedAt ?? status.session.started_at }}</div>
              <div v-if="status.session.stoppedAt || status.session.stopped_at"><strong>Konec:</strong> {{ status.session.stoppedAt ?? status.session.stopped_at }}</div>
            </div>
            <div class="space-y-2">
              <div><strong>Route (požadovaná):</strong> {{ requestedRoute.length ? requestedRoute.join(', ') : '-' }}</div>
              <div><strong>Route (aplikovaná):</strong> {{ appliedRoute.length ? appliedRoute.join(', ') : '-' }}</div>
              <div><strong>Lokality:</strong> {{ locationDisplayNames.length ? locationDisplayNames.join(', ') : '-' }}</div>
              <div><strong>Hnízda:</strong> {{ nestDisplayNames.length ? nestDisplayNames.join(', ') : '-' }}</div>
              <div><strong>Zóny:</strong> {{ appliedZones.length ? appliedZones.join(', ') : '-' }}</div>
            </div>
          </div>
          <div
              v-if="missingTargets.locationGroups.length || missingTargets.locations.length || missingTargets.nests.length || missingTargets.locationGroupsMissingAddress.length || missingTargets.zonesOverflow.length"
              class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
            <p class="font-medium">Upozornění:</p>
            <ul class="list-disc list-inside space-y-1 mt-1">
              <li v-if="missingTargets.locationGroups.length">Skupiny: {{ missingTargets.locationGroups.join(', ') }}</li>
              <li v-if="missingTargets.locations.length">Lokality bez Modbus adresy: {{ missingTargets.locations.join(', ') }}</li>
              <li v-if="missingTargets.nests.length">Hnízda bez Modbus adresy: {{ missingTargets.nests.join(', ') }}</li>
              <li v-if="missingTargets.locationGroupsMissingAddress.length">Lokalitám chybí sdílená adresa: {{ missingTargets.locationGroupsMissingAddress.join(', ') }}</li>
              <li v-if="missingTargets.zonesOverflow.length">Překročena kapacita zón (max 5): {{ missingTargets.zonesOverflow.join(', ') }}</li>
            </ul>
          </div>
        </template>
        <div v-else class="text-gray-500 text-sm">Žádná relace není aktivní.</div>

        <div v-if="isStreaming && status.session" class="mt-4">
          <details>
            <summary class="cursor-pointer text-sm text-gray-600">Raw data</summary>
            <pre class="mt-2 bg-gray-100 p-2 rounded text-xs overflow-auto">{{ status }}</pre>
          </details>
        </div>

        <div class="mt-6">
          <h3 class="text-sm font-semibold text-gray-700">Příští vysílání</h3>
          <div v-if="nextScheduledBroadcast" class="mt-2 rounded border border-gray-200 bg-gray-50 p-3 text-sm space-y-1">
            <div class="font-medium text-gray-900">{{ nextScheduledBroadcast.title }}</div>
            <div class="text-gray-600">
              Naplánováno na {{ nextScheduledBroadcast.scheduledAtFormatted ?? '–' }}
            </div>
            <RouterLink
                :to="{name: 'EditSchedule', params: {id: nextScheduledBroadcast.id}}"
                class="text-primary text-xs font-medium underline">
              Otevřít v plánu vysílání
            </RouterLink>
          </div>
          <div v-else class="mt-2 text-xs text-gray-500">
            Žádné další vysílání není naplánované.
          </div>
        </div>

        <div class="mt-3 flex gap-3">
          <Button variant="secondary" icon="mdi-refresh" :disabled="loading" @click="loadStatus">
            Aktualizovat stav
          </Button>
          <Button v-if="isStreaming" variant="ghost" icon="mdi-stop-circle-outline" :disabled="loading" @click="stopStream">
            Vynutit stop
          </Button>
        </div>
      </Box>

      <div class="grid gap-6 md:grid-cols-2">
        <Box label="Zdroje a obsah">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Zdroj signálu</label>
              <select
                  v-model="form.source"
                  class="form-select w-full border border-gray-300 rounded-md bg-white focus:border-primary focus:ring focus:ring-primary focus:ring-opacity-40">
                <option v-for="source in sources" :key="source.id" :value="source.id" :disabled="source.available === false && source.id !== form.source" :title="source.available === false ? (source.unavailable_reason ?? 'Zdroj není dostupný') : ''">
                  {{ source.label }}{{ source.available === false && source.unavailable_reason ? ' – ' + source.unavailable_reason : (source.available === false ? ' – Nedostupný' : '') }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Výstup zvuku</label>
              <select
                  v-model="selectedAudioOutputId"
                  class="form-select w-full border border-gray-300 rounded-md bg-white focus:border-primary focus:ring focus:ring-primary focus:ring-opacity-40">
                <option v-for="output in audioOutputDevices" :key="output.id" :value="output.id">
                  {{ output.label }}
                </option>
              </select>
            </div>

            <Textarea v-model="form.note" label="Poznámka" rows="2" placeholder="Nepovinné doplňující údaje"/>

            <div v-if="showPlaylistControls" class="space-y-2">
              <div class="flex justify-between items-center">
                <span class="text-sm font-medium text-gray-700">Soubor v ústředně</span>
                <Button size="xs" icon="mdi-playlist-plus" @click="pickPlaylist">Vybrat soubor</Button>
              </div>
              <div v-if="form.playlistItems.length === 0" class="text-xs text-gray-500">Zatím není vybrán žádný soubor.</div>
              <ul v-else class="space-y-2 text-xs">
                <li v-for="(item, index) in form.playlistItems" :key="item.id" class="flex justify-between items-center bg-gray-100 px-2 py-1 rounded">
                  <span>{{ item.name ?? item.title ?? item.original_name ?? ('ID ' + item.id) }}</span>
                  <button class="text-red-500" @click="removePlaylistItem(index)"><span class="mdi mdi-close"></span></button>
                </li>
              </ul>
            </div>

            <div v-if="showFmInfo" class="space-y-2 text-sm">
              <div><strong>Frekvence FM rádia:</strong> {{ form.fmFrequency || 'Neznámá' }}</div>
              <Button size="xs" icon="mdi-refresh" @click="loadFmFrequency">Aktualizovat frekvenci</Button>
            </div>

            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-700">
                  Ovládání hlasitosti
                  <template v-if="currentInputVolumeItem">
                    – {{ currentInputVolumeItem.label }}
                  </template>
                </span>
                <span v-if="volumeLoading" class="text-xs text-gray-500">Načítám…</span>
              </div>
              <template v-if="!volumeLoading">
                <div v-if="currentInputVolumeItem" class="space-y-2">
                  <div class="text-xs text-gray-500">
                    Výchozí hodnota: {{ Math.round(currentInputVolumeItem.default) }} %
                  </div>
                  <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:min-w-[360px]">
                    <input
                        v-model.number="currentInputVolumeItem.value"
                        type="range"
                        class="range range-sm w-full sm:w-64 md:w-80"
                        :min="volumeSlider.min"
                        :max="volumeSlider.max"
                        :step="volumeSlider.step"
                        @change="handleActiveVolumeChange"
                        :disabled="isCurrentVolumeSaving"
                    />
                    <div class="flex items-center gap-2 text-sm text-gray-700">
                      <span class="inline-block w-14 text-right">{{ Math.round(Number(currentInputVolumeItem.value)) }} %</span>
                      <span v-if="isCurrentVolumeSaving" class="text-xs text-gray-500">Ukládám…</span>
                    </div>
                  </div>
                </div>
                <div v-else class="text-xs text-gray-500">Žádný aktivní vstup není dostupný.</div>
              </template>
            </div>
          </div>
        </Box>

        <Box label="Cílové lokality a hnízda">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Lokality</label>
              <select
                  v-model="form.selectedLocationGroups"
                  multiple
                  class="form-select w-full h-32 border border-gray-300 rounded-md bg-white focus:border-primary focus:ring focus:ring-primary focus:ring-opacity-40">
                <option v-for="location in locationGroups" :key="location.id" :value="String(location.id)">
                  {{ location.name }}{{ location.modbus_group_address ? ' (adresa ' + location.modbus_group_address + ')' : '' }}
                </option>
              </select>
            </div>

            <div v-if="hasNestsDefined">
              <label class="block text-sm font-medium text-gray-700 mb-1">Hnízda</label>
              <select
                  v-model="form.selectedNests"
                  multiple
                  class="form-select w-full h-32 border border-gray-300 rounded-md bg-white focus:border-primary focus:ring focus:ring-primary focus:ring-opacity-40">
                <option v-for="nest in nests" :key="nest.id" :value="String(nest.id)">
                  {{ nest.name }}{{ nest.modbus_address ? ' (adresa ' + nest.modbus_address + ')' : '' }}
                </option>
              </select>
              <p class="text-xs text-gray-500">Vybraná hnízda se přidají mezi cílové zóny vysílání.</p>
            </div>

            <Input v-if="false" v-model="form.routeText" label="Route (čísla oddělená čárkou)" placeholder="např.: 1,2,3"/>

          </div>
        </Box>
      </div>
    </div>
  </PageContent>
</template>
