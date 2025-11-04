<script setup>
import {computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch} from "vue";
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
import AudioService from "../../services/AudioService.js";

const toast = useToast();

const sources = ref([]);
const locationGroups = ref([]);
const nests = ref([]);
const status = ref({session: null, status: null, device: null});
const loading = ref(false);
const fmInfo = ref(null);
const syncingForm = ref(false);
const liveUpdateInProgress = ref(false);
const mixerStatus = ref({});
const mixerInputs = ref([]);
const mixerOutputs = ref([]);
const mixerLoading = ref(false);
const mixerSyncing = ref(false);
const mixerInputUpdating = ref(false);
const selectedMixerInputId = ref('');

const DEFAULT_FORCED_AUDIO_OUTPUT_ID = 'lineout';
const rawForcedOutput = (import.meta.env.VITE_FORCE_AUDIO_OUTPUT ?? DEFAULT_FORCED_AUDIO_OUTPUT_ID).toString().trim();
const FORCE_AUDIO_OUTPUT_DISABLED_VALUES = new Set(['false', '0', 'off', 'none', 'no', 'disabled']);
const FORCE_AUDIO_OUTPUT_ENABLED = rawForcedOutput !== '' && !FORCE_AUDIO_OUTPUT_DISABLED_VALUES.has(rawForcedOutput.toLowerCase());
const FORCED_AUDIO_OUTPUT_ID = FORCE_AUDIO_OUTPUT_ENABLED ? rawForcedOutput : '';
const FORCED_AUDIO_OUTPUT_LABEL = import.meta.env.VITE_FORCE_AUDIO_OUTPUT_LABEL ?? 'Line Out';
const FORCED_MIXER_OUTPUT_ID = FORCED_AUDIO_OUTPUT_ID;

const selectedMixerOutputId = ref(FORCED_MIXER_OUTPUT_ID || '');

const form = reactive({
  source: 'system_audio',
  routeText: '',
  selectedLocationGroups: [],
  selectedNests: [],
  note: '',
  playlistItems: [],
  introJingle: null,
  outroJingle: null,
  fmFrequency: ''
});

const audioInputDevices = ref([]);
const initialAudioInput = JSON.parse(localStorage.getItem('audioInputDevice') ?? 'null');
const selectedAudioInputId = ref(initialAudioInput?.id ?? 'default');

const audioOutputDevices = ref([]);
const initialAudioOutput = JSON.parse(localStorage.getItem('audioOutputDevice') ?? 'null');
const selectedAudioOutputId = ref(
  FORCE_AUDIO_OUTPUT_ENABLED && FORCED_AUDIO_OUTPUT_ID
    ? FORCED_AUDIO_OUTPUT_ID
    : (initialAudioOutput?.id ?? 'default')
);
const localAudioPlayer = ref(null);
const localPlayback = reactive({
  queue: [],
  index: -1,
  playing: false,
  busy: false,
});
const playlistAudioCache = new Map();
const selectedSourceOptionId = ref('');
const sourceLabelMap = computed(() => {
  const map = new Map();
  sources.value.forEach((source) => {
    if (source?.id) {
      map.set(source.id, source.label ?? source.id);
    }
  });
  return map;
});

const combinedSourceOptions = computed(() => {
  const list = [];
  const baseSources = Array.isArray(sources.value) ? sources.value : [];
  const audioInputs = Array.isArray(audioInputDevices.value) ? audioInputDevices.value : [];

  const findFirstInputId = (predicate) => {
    const entry = audioInputs.find((input) => predicate(input?.id ?? '', input));
    return entry?.id ?? null;
  };

  const findPulseMonitorId = () => findFirstInputId((id) => id.includes('.monitor'));
  const findPulseSourceId = () => findFirstInputId((id) => id.startsWith('pulse:') && !id.includes('.monitor'));
  const findAlsaCaptureId = () => findFirstInputId((id) => id.startsWith('alsa:'));

  const resolvePreferredInputId = (sourceId) => {
    if (!hardwareSourceIds.has(sourceId)) {
      return null;
    }
    switch (sourceId) {
      case 'system_audio':
      case 'gsm':
        return findPulseMonitorId() ?? findPulseSourceId() ?? findAlsaCaptureId();
      case 'fm_radio':
      case 'input_1':
      case 'input_2':
        return findAlsaCaptureId() ?? findPulseSourceId() ?? findPulseMonitorId();
      default:
        return findPulseSourceId() ?? findAlsaCaptureId() ?? findPulseMonitorId();
    }
  };

  baseSources.forEach((source) => {
    if (!source || typeof source.id !== 'string' || source.id.length === 0) {
      return;
    }
    const baseLabel = source.label ?? source.id;
    const available = source.available !== false;
    const unavailableReason = source.unavailable_reason ?? null;

    if (hardwareSourceIds.has(source.id)) {
      const preferredId = resolvePreferredInputId(source.id);
      const fallbackInputId = preferredId && audioInputs.some(input => input?.id === preferredId)
        ? preferredId
        : 'default';
      list.push({
        id: buildSourceOptionId(source.id, fallbackInputId),
        sourceId: source.id,
        audioInputId: fallbackInputId,
        label: baseLabel,
        available,
        unavailableReason,
      });
    } else {
      list.push({
        id: buildSourceOptionId(source.id, null),
        sourceId: source.id,
        audioInputId: null,
        label: baseLabel,
        available,
        unavailableReason,
      });
    }
  });

  return list;
});

const hasPlaylistSelection = computed(() => {
  return (Array.isArray(form.playlistItems) && form.playlistItems.length > 0)
    || form.introJingle !== null
    || form.outroJingle !== null;
});

const recordingStatusLabel = computed(() => {
  if (recordingState.active) {
    return 'Záznam právě probíhá. Ukončete jej před spuštěním dalšího.';
  }
  const info = recordingState.info;
  if (info?.ended_at) {
    const endedAt = new Date(info.ended_at);
    if (!Number.isNaN(endedAt.getTime())) {
      return `Poslední záznam dokončen ${formatDate(endedAt, 'd.m.Y H:i:s')}`;
    }
  }
  if (info?.path) {
    return `Poslední soubor: ${info.path}`;
  }
  return 'Záznam neběží.';
});

const routingEnabled = computed(() => mixerStatus.value?.enabled !== false);

const isStreaming = computed(() => status.value?.session?.status === 'running');
const showPlaylistControls = computed(() => form.source === 'central_file');
const showCentralFilePicker = computed(() => {
  const centralFileInputId = sourceToMixerInputMap.central_file ?? 'file';
  return selectedMixerInputId.value === centralFileInputId;
});
const showFmInfo = computed(() => form.source === 'fm_radio');
const hasNestsDefined = computed(() => Array.isArray(nests.value) && nests.value.length > 0);
const volumeGroups = ref([]);
const volumeLoading = ref(false);
const volumeSaving = reactive({});
const volumeSlider = {
  min: 0,
  max: 100,
  step: 1,
};

const recordingState = reactive({
  active: false,
  info: null,
});
const recordingBusy = ref(false);

const hardwareSourceIds = new Set([
  'microphone',
  'system_audio',
  'gsm',
  'fm_radio',
  'input_1',
  'input_2',
]);

const sourceToMixerInputMap = {
  microphone: 'mic',
  central_file: 'file',
  system_audio: 'system',
  fm_radio: 'fm',
  gsm: 'gsm',
  input_1: 'input_1',
  input_2: 'input_2',
};

const ALSAMIXER_INPUT_ALIAS = {
  mic: 'mic',
  system: 'system',
  file: 'system',
  input_1: 'line1',
  line1: 'line1',
  input_2: 'line2',
  line2: 'line2',
};
const ALSAMIXER_SUPPORTED_INPUTS = new Set(Object.keys(ALSAMIXER_INPUT_ALIAS));

const resolveAlsamixerIdentifierForInput = (inputId) => {
  if (typeof inputId !== 'string' || inputId.trim() === '') {
    return null;
  }
  const normalized = inputId.trim();
  return ALSAMIXER_INPUT_ALIAS[normalized] ?? null;
};

const resolveAlsamixerIdentifierForSource = (sourceId) => {
  if (typeof sourceId !== 'string' || sourceId.trim() === '') {
    return null;
  }
  const identifier = sourceToMixerInputMap[sourceId];
  if (!identifier) {
    return null;
  }
  return resolveAlsamixerIdentifierForInput(identifier);
};

const primaryInputIds = ['mic', 'file', 'fm', 'input_1', 'input_2', 'gsm', 'system'];
const primaryMixerOptions = computed(() => primaryInputIds
  .map((identifier) => {
    const item = mixerInputs.value.find((entry) => entry.id === identifier);
    if (!item) {
      return null;
    }
    return {
      id: item.id,
      label: item.label ?? item.id,
    };
  })
  .filter(Boolean)
);

const secondaryMixerOptions = computed(() => []);

const parseSimpleMixerControl = (entry) => {
  if (typeof entry !== 'string') {
    return null;
  }
  const trimmed = entry.trim();
  if (trimmed.length === 0) {
    return null;
  }

  const match = trimmed.match(/^Simple mixer control '([^']+)'(?:,(\d+))?/);
  if (!match) {
    return {
      name: trimmed,
      index: null,
      raw: trimmed,
      label: trimmed,
    };
  }

  const name = match[1]?.trim() ?? '';
  const indexValue = match[2] !== undefined ? Number(match[2]) : null;
  const index = Number.isFinite(indexValue) ? indexValue : null;

  if (!name) {
    return {
      name: trimmed,
      index: null,
      raw: trimmed,
      label: trimmed,
    };
  }

  return {
    name,
    index,
    raw: trimmed,
    label: name,
  };
};

const toNumericId = (value) => {
  if (value === null || value === undefined) {
    return null;
  }
  const parsed = Number.parseInt(value, 10);
  return Number.isNaN(parsed) ? null : parsed;
};

const buildSourceOptionId = (sourceId, audioInputId = null) => {
  if (!sourceId) {
    return '';
  }
  if (!hardwareSourceIds.has(sourceId)) {
    return sourceId;
  }
  const normalized = audioInputId && audioInputId !== '' ? audioInputId : 'default';
  return `${sourceId}::${normalized}`;
};

const parseSourceOptionId = (identifier) => {
  if (typeof identifier !== 'string' || identifier.length === 0) {
    return {sourceId: '', audioInputId: null};
  }
  if (!identifier.includes('::')) {
    return {
      sourceId: identifier,
      audioInputId: hardwareSourceIds.has(identifier) ? 'default' : null,
    };
  }
  const [rawSourceId, rawAudioId] = identifier.split('::');
  const sourceId = rawSourceId ?? '';
  const normalizedAudio = rawAudioId && rawAudioId !== '' ? rawAudioId : 'default';
  return {
    sourceId,
    audioInputId: hardwareSourceIds.has(sourceId) ? normalizedAudio : null,
  };
};

const resolveRecordingLabel = (item) => {
  if (!item) {
    return '';
  }
  return item.name
    ?? item.title
    ?? item.original_name
    ?? item.filename
    ?? item.recording_id
    ?? (item.id ? `ID ${item.id}` : 'Neznámá nahrávka');
};

const getOrderedPlaylistItems = () => {
  const ordered = [];
  if (form.introJingle) {
    ordered.push({...form.introJingle, __role: 'intro'});
  }
  if (Array.isArray(form.playlistItems)) {
    form.playlistItems.forEach((item) => {
      if (item) {
        ordered.push({...item, __role: 'main'});
      }
    });
  }
  if (form.outroJingle) {
    ordered.push({...form.outroJingle, __role: 'outro'});
  }
  return ordered;
};

const sourceInputChannelMap = ref({
  microphone: 'capture_level',
  system_audio: 'capture_level',
  central_file: 'capture_level',
  fm_radio: 'capture_level',
  gsm: 'capture_level',
  input_1: 'capture_level',
  input_2: 'capture_level',
});
const sourceOutputChannelMap = ref({});
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

const normaliseMixerItems = (list) => {
  if (!Array.isArray(list)) {
    return [];
  }
  return list
    .filter(item => item && typeof item.id === 'string' && item.id.length > 0)
    .filter(item => item.available !== false)
    .map(item => ({
      id: item.id,
      label: item.label ?? item.id,
      device: item.device ?? null,
    }));
};

const refreshMixerStatus = async (silent = false, statusOverride = null) => {
  mixerLoading.value = true;
  try {
    let statusPayload = statusOverride ?? await AudioService.status();
    statusPayload = statusPayload ?? {};

    let inputs = normaliseMixerItems(statusPayload?.inputs);
    let outputs = normaliseMixerItems(statusPayload?.outputs);

    const routingActive = statusPayload?.enabled !== false;

    const shouldForceOutput = routingActive && FORCE_AUDIO_OUTPUT_ENABLED && !!FORCED_MIXER_OUTPUT_ID;
    const forcedOutputId = shouldForceOutput && outputs.some(item => item.id === FORCED_MIXER_OUTPUT_ID)
      ? FORCED_MIXER_OUTPUT_ID
      : '';

    const currentOutputId = statusPayload?.current?.output?.id ?? '';
    if (shouldForceOutput && forcedOutputId && forcedOutputId !== currentOutputId) {
      try {
        const updatedStatus = await AudioService.setOutput(forcedOutputId);
        if (updatedStatus) {
          statusPayload = updatedStatus;
          inputs = normaliseMixerItems(updatedStatus?.inputs);
          outputs = normaliseMixerItems(updatedStatus?.outputs);
        }
      } catch (setError) {
        console.error('Failed to enforce mixer output', setError);
        if (!silent) {
          toast.error('Nepodařilo se nastavit výstup ústředny.');
        }
      }
    }

    mixerStatus.value = statusPayload;
    mixerInputs.value = inputs;
    mixerOutputs.value = outputs;

    mixerSyncing.value = true;

    const currentInputId = statusPayload?.current?.input?.id ?? '';

    const resolvePreferredInput = (currentId, availableList, previousSelection) => {
      const hasCurrent = currentId && availableList.some(item => item.id === currentId);
      const hasPrevious = previousSelection && availableList.some(item => item.id === previousSelection);

      if (mixerInputUpdating.value) {
        if (hasPrevious) {
          return previousSelection;
        }
        if (hasCurrent) {
          return currentId;
        }
        return availableList[0]?.id ?? '';
      }

      if (hasCurrent) {
        return currentId;
      }
      if (hasPrevious) {
        return previousSelection;
      }
      return availableList[0]?.id ?? '';
    };

    const desiredInput = resolvePreferredInput(currentInputId, inputs, selectedMixerInputId.value);
    if (desiredInput !== selectedMixerInputId.value) {
      selectedMixerInputId.value = desiredInput;
    }

    if (shouldForceOutput && forcedOutputId && forcedOutputId !== selectedMixerOutputId.value) {
      selectedMixerOutputId.value = forcedOutputId;
    } else if (!shouldForceOutput && currentOutputId && currentOutputId !== selectedMixerOutputId.value) {
      selectedMixerOutputId.value = currentOutputId;
    } else if (!routingActive && selectedMixerOutputId.value !== '') {
      selectedMixerOutputId.value = '';
    }

    mixerSyncing.value = false;
  } catch (error) {
    console.error('Failed to load mixer status', error);
    if (!silent) {
      toast.error('Nepodařilo se načíst nastavení audio směrování');
    }
    mixerSyncing.value = false;
  } finally {
    mixerLoading.value = false;
  }
};

const sendMixerUpdateForInput = async (identifier, {silent = false, revertTo = null} = {}) => {
  const resolved = resolveAlsamixerIdentifierForInput(identifier);
  if (!resolved) {
    return {skipped: true};
  }
  if (mixerInputUpdating.value) {
    return {skipped: true};
  }
  if (!isStreaming.value) {
    return {skipped: true};
  }

  mixerInputUpdating.value = true;
  try {
    const response = await LiveBroadcastService.selectLiveSource({identifier: resolved, source: identifier});
    const updatedStatus = response?.mixer ?? null;
    await refreshMixerStatus(true, updatedStatus ?? null);
    if (!silent) {
      toast.success('Vstup ústředny byl přepnut.');
    }
    return {skipped: false, success: true, response};
  } catch (error) {
    console.error('Failed to switch audio input', error);
    if (!silent) {
      toast.error('Nepodařilo se přepnout audio vstup.');
    }
    if (typeof revertTo === 'string' && revertTo !== '') {
      mixerSyncing.value = true;
      selectedMixerInputId.value = revertTo;
      mixerSyncing.value = false;
    }
    throw error;
  } finally {
    mixerInputUpdating.value = false;
  }
};

const sendMixerUpdateForSource = async (sourceId, options = {}) => {
  const identifier = resolveAlsamixerIdentifierForSource(sourceId);
  if (!identifier) {
    return {skipped: true};
  }
  return sendMixerUpdateForInput(identifier, options);
};

onMounted(async () => {
  await Promise.all([
    loadSources(),
    loadLocationGroups(),
    loadNests(),
    loadStatus(),
    loadVolumeLevels()
  ]);
});

onBeforeUnmount(() => {
  stopLocalPlayback();
});

watch(() => form.source, async (newSource, oldSource) => {
  if (syncingForm.value) {
    return;
  }

  if (newSource === 'fm_radio') {
    await loadFmFrequency();
  }

  const recommended = sourceToMixerInputMap[newSource];
  if (recommended && recommended !== selectedMixerInputId.value && mixerInputs.value.some(item => item.id === recommended)) {
    mixerSyncing.value = true;
    selectedMixerInputId.value = recommended;
    mixerSyncing.value = false;
  }

  if (isStreaming.value && newSource !== oldSource) {
    if (liveUpdateInProgress.value) {
      return;
    }
    if (newSource === 'central_file' && !hasPlaylistSelection.value) {
      return;
    }
    const fallbackMixerId = sourceToMixerInputMap[oldSource] ?? null;
    try {
      await sendMixerUpdateForSource(newSource, {silent: true, revertTo: fallbackMixerId});
    } catch (error) {
      console.warn('Failed to update mixer input after source switch.', error);
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
      const current = sources.value.find(source => source.id === form.source && source.available);
      if (current) {
        return;
      }

      const preferredOrder = ['microphone', 'system_audio', 'central_file'];
      const preferred = preferredOrder
        .map((id) => sources.value.find(source => source.id === id && source.available))
        .find(Boolean);
      const firstAvailable = sources.value.find(source => source.available);
      const fallback = preferred ?? firstAvailable ?? sources.value[0] ?? null;
      form.source = fallback ? fallback.id : '';
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

const pickFallbackSourceId = () => {
  const availableSources = sources.value.filter(source => source?.available !== false);
  const nonCentralSources = availableSources.filter(source => source.id !== 'central_file');
  const candidates = nonCentralSources.length > 0 ? nonCentralSources : availableSources;
  const preferredOrder = ['microphone', 'system_audio', 'gsm', 'fm_radio', 'input_1', 'input_2'];
  for (const preferredId of preferredOrder) {
    const match = candidates.find(source => source.id === preferredId);
    if (match) {
      return match.id;
    }
  }
  return candidates[0]?.id ?? 'system_audio';
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
    const list = await LocationService.fetchNests();
    nests.value = Array.isArray(list) ? list : [];
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst hnízda');
  }
};

const buildHardwareOutputList = (devices) => {
  const outputs = [{id: 'default', label: 'Výchozí systém'}];

  if (FORCE_AUDIO_OUTPUT_ENABLED && FORCED_AUDIO_OUTPUT_ID) {
    outputs.unshift({id: FORCED_AUDIO_OUTPUT_ID, label: FORCED_AUDIO_OUTPUT_LABEL});
  }
  const sinks = Array.isArray(devices?.pulse?.sinks) ? devices.pulse.sinks : [];
  sinks.forEach((sink) => {
    if (sink?.name) {
      outputs.push({
        id: `pulse:${sink.name}`,
        label: sink?.pretty_name ?? sink?.description ?? `PulseAudio ${sink.name}`,
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

const buildHardwareInputList = (devices) => {
  const inputs = [{id: 'default', label: 'Výchozí systém'}];
  const sources = Array.isArray(devices?.pulse?.sources) ? devices.pulse.sources : [];
  sources.forEach((source) => {
    if (source?.name) {
      inputs.push({
        id: `pulse:${source.name}`,
        label: source?.pretty_name ?? source?.description ?? `PulseAudio ${source.name}`,
      });
    }
  });

  const pulseSinks = Array.isArray(devices?.pulse?.sinks) ? devices.pulse.sinks : [];
  pulseSinks.forEach((sink) => {
    if (!sink?.name) {
      return;
    }
    const monitorId = `pulse:${sink.name}.monitor`;
    if (!inputs.some(entry => entry?.id === monitorId)) {
      inputs.push({
        id: monitorId,
        label: 'Systémový výstup',
      });
    }
  });

  const captureDevices = Array.isArray(devices?.capture_devices) ? devices.capture_devices : [];
  captureDevices.forEach((device) => {
    const card = toNumericId(device?.card);
    const dev = toNumericId(device?.device);
    const cardLabel = device?.card_description ?? device?.card_name ?? (card !== null ? `Karta ${card}` : 'ALSA zařízení');
    const id = card !== null && dev !== null ? `alsa:${card}:${dev}` : null;
    if (id) {
      const devLabel = device?.device_description ?? device?.device_name ?? `Zařízení ${dev}`;
      inputs.push({
        id,
        label: `${cardLabel} • ${devLabel}`,
      });
    }
  });

  const unique = [];
  const seen = new Set();
  inputs.forEach((item) => {
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
    const inputs = buildHardwareInputList(devices);
    audioOutputDevices.value = outputs;
    audioInputDevices.value = inputs;
    if (!inputs.some(device => device.id === selectedAudioInputId.value)) {
      selectedAudioInputId.value = inputs[0]?.id ?? 'default';
    }
    const desiredOutput = (() => {
      if (FORCE_AUDIO_OUTPUT_ENABLED && FORCED_AUDIO_OUTPUT_ID) {
        return outputs.find(device => device.id === FORCED_AUDIO_OUTPUT_ID) ?? outputs[0];
      }
      return outputs.find(device => device.id === selectedAudioOutputId.value)
        ?? outputs.find(device => device.id === (initialAudioOutput?.id ?? ''))
        ?? outputs[0];
    })();
    if (desiredOutput) {
      selectedAudioOutputId.value = desiredOutput.id;
      persistAudioOutput();
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

    const audioInputId = optionBag.audioInputId ?? optionBag.audio_input_id ?? selectedAudioInputId.value;
    const audioOutputId = optionBag.audioOutputId ?? optionBag.audio_output_id ?? selectedAudioOutputId.value;
    if (audioInputId) {
      selectedAudioInputId.value = audioInputId;
    }
    const routingActive = routingEnabled.value;
    if (routingActive && FORCE_AUDIO_OUTPUT_ENABLED && FORCED_AUDIO_OUTPUT_ID) {
      selectedAudioOutputId.value = FORCED_AUDIO_OUTPUT_ID;
    } else if (audioOutputId) {
      selectedAudioOutputId.value = audioOutputId;
    }

    await refreshMixerStatus(true);
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

function persistAudioInput() {
  const device = audioInputDevices.value.find(input => input.id === selectedAudioInputId.value);
  const payload = {
    id: selectedAudioInputId.value,
    label: device?.label ?? 'Výchozí systém',
  };
  localStorage.setItem('audioInputDevice', JSON.stringify(payload));
}

function persistAudioOutput() {
  const device = audioOutputDevices.value.find(output => output.id === selectedAudioOutputId.value);
  const routingActive = routingEnabled.value;
  const fallbackLabel = routingActive && FORCE_AUDIO_OUTPUT_ENABLED && FORCED_AUDIO_OUTPUT_ID
    ? FORCED_AUDIO_OUTPUT_LABEL
    : 'Výchozí systém';
  const payload = {
    id: selectedAudioOutputId.value,
    label: device?.label ?? fallbackLabel,
  };
  localStorage.setItem('audioOutputDevice', JSON.stringify(payload));
}

if (!initialAudioInput) {
  persistAudioInput();
}

watch(selectedSourceOptionId, (newValue, oldValue) => {
  if (!newValue || newValue === oldValue) {
    return;
  }
  const {sourceId, audioInputId} = parseSourceOptionId(newValue);
  if (sourceId && sourceId !== form.source) {
    form.source = sourceId;
  }
  if (audioInputId && audioInputId !== selectedAudioInputId.value) {
    selectedAudioInputId.value = audioInputId;
    return;
  }
  if (!audioInputId && hardwareSourceIds.has(sourceId) && selectedAudioInputId.value !== 'default') {
    selectedAudioInputId.value = 'default';
  }
});

watch(
  () => form.playlistItems.length,
  (newLength) => {
    if (syncingForm.value) {
      return;
    }

    if (newLength > 0) {
      if (form.source !== 'central_file') {
        form.source = 'central_file';
      }
      return;
    }

    if (newLength === 0 && form.source === 'central_file' && !isStreaming.value) {
      form.source = pickFallbackSourceId();
    }
  }
);

watch(
  [() => combinedSourceOptions.value, () => form.source, () => selectedAudioInputId.value],
  () => {
    const options = combinedSourceOptions.value;
    if (!Array.isArray(options) || options.length === 0) {
      return;
    }
    const desiredId = buildSourceOptionId(form.source, selectedAudioInputId.value);
    let target = options.find(option => option.id === desiredId);
    if (!target && form.source) {
      target = options.find(option => option.sourceId === form.source);
    }
    if (!target) {
      target = options[0];
    }
    if (target && target.id !== selectedSourceOptionId.value) {
      selectedSourceOptionId.value = target.id;
    }
  },
  {immediate: true}
);

watch(selectedAudioInputId, async (newValue, oldValue) => {
  persistAudioInput();
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
  if (localPlayback.playing && localAudioPlayer.value && typeof localAudioPlayer.value.setSinkId === 'function') {
    try {
      await localAudioPlayer.value.setSinkId(newValue || 'default');
    } catch (error) {
      console.debug('Unable to switch sink for local playback', error);
    }
  }
  await applyLiveUpdate();
});

watch(selectedMixerInputId, async (newValue, oldValue) => {
  if (mixerSyncing.value || mixerInputUpdating.value) {
    return;
  }
  if (!newValue || newValue === oldValue) {
    return;
  }

  if (!routingEnabled.value) {
    selectedMixerInputId.value = newValue;
    return;
  }

  const currentId = mixerStatus.value?.current?.input?.id ?? null;
  const currentAlias = resolveAlsamixerIdentifierForInput(currentId);
  const nextAlias = resolveAlsamixerIdentifierForInput(newValue);
  if (currentAlias && nextAlias && currentAlias === nextAlias) {
    return;
  }

  try {
    await sendMixerUpdateForInput(newValue, {revertTo: oldValue ?? '', silent: false});
  } catch (error) {
    // already handled in helper
  }
});

watch(isStreaming, (value) => {
  if (!value) {
    stopLocalPlayback();
  }
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
    if (!hasPlaylistSelection.value) {
      return;
    }
    if (liveUpdateInProgress.value) {
      return;
    }
    await applyLiveUpdate();
  }
);

watch(
  () => form.introJingle ? form.introJingle.id : null,
  async (newValue, oldValue) => {
    if (newValue === oldValue || syncingForm.value) {
      return;
    }
    if (!isStreaming.value || form.source !== 'central_file' || liveUpdateInProgress.value) {
      return;
    }
    await applyLiveUpdate();
  }
);

watch(
  () => form.outroJingle ? form.outroJingle.id : null,
  async (newValue, oldValue) => {
    if (newValue === oldValue || syncingForm.value) {
      return;
    }
    if (!isStreaming.value || form.source !== 'central_file' || liveUpdateInProgress.value) {
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
    if (response?.sourceOutputChannels && typeof response.sourceOutputChannels === 'object') {
      sourceOutputChannelMap.value = {
        ...sourceOutputChannelMap.value,
        ...response.sourceOutputChannels
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

const activeOutputItemId = computed(() => {
  const source = currentSourceId.value;
  if (source && sourceOutputChannelMap.value?.[source]) {
    return sourceOutputChannelMap.value[source];
  }
  const outputsGroup = volumeGroups.value.find(group => group.id === 'outputs');
  const items = Array.isArray(outputsGroup?.items) ? outputsGroup.items : [];
  if (items.length > 0) {
    return items[0].id;
  }
  return null;
});

const findVolumeEntryById = (itemId) => {
  if (!itemId) {
    return null;
  }
  for (const group of volumeGroups.value) {
    const items = Array.isArray(group?.items) ? group.items : [];
    const found = items.find(item => item.id === itemId);
    if (found) {
      return {groupId: group.id, item: found};
    }
  }
  return null;
};

const currentInputVolumeEntry = computed(() => findVolumeEntryById(activeInputItemId.value));
const currentOutputVolumeEntry = computed(() => findVolumeEntryById(activeOutputItemId.value));

const currentInputVolumeItem = computed(() => currentInputVolumeEntry.value?.item ?? null);
const currentOutputVolumeItem = computed(() => currentOutputVolumeEntry.value?.item ?? null);

const currentInputVolumeSavingKey = computed(() => {
  const entry = currentInputVolumeEntry.value;
  if (!entry) {
    return null;
  }
  return makeVolumeKey(entry.groupId, entry.item.id);
});

const currentOutputVolumeSavingKey = computed(() => {
  const entry = currentOutputVolumeEntry.value;
  if (!entry) {
    return null;
  }
  return makeVolumeKey(entry.groupId, entry.item.id);
});

const isInputVolumeSaving = computed(() => {
  const key = currentInputVolumeSavingKey.value;
  return key ? Boolean(volumeSaving[key]) : false;
});

const isOutputVolumeSaving = computed(() => {
  const key = currentOutputVolumeSavingKey.value;
  return key ? Boolean(volumeSaving[key]) : false;
});

const handleVolumeEntryChange = async (entry) => {
  if (!entry) {
    toast.error('Pro tento zdroj není k dispozici nastavení hlasitosti.');
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

const handleInputVolumeChange = async () => {
  await handleVolumeEntryChange(currentInputVolumeEntry.value);
};

const handleOutputVolumeChange = async () => {
  await handleVolumeEntryChange(currentOutputVolumeEntry.value);
};

const buildStartPayload = () => {
  const options = {};
  if (form.note) {
    options.note = form.note;
  }
  const orderedItems = getOrderedPlaylistItems();
  const resolveMixerAlias = () => {
    const selected = resolveAlsamixerIdentifierForInput(selectedMixerInputId.value);
    if (selected) {
      return selected;
    }
    return resolveAlsamixerIdentifierForSource(form.source);
  };
  const clampInputVolume = (value) => {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
      return null;
    }
    return Math.min(volumeSlider.max, Math.max(volumeSlider.min, numeric));
  };
  if (showPlaylistControls.value && orderedItems.length > 0) {
    const playlistItems = orderedItems
      .map((item) => {
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
        const gapMs = item.gap_ms ?? item.gapMs ?? null;
        const storagePath =
          metadata.storage_path ??
          metadata.file?.storage_path ??
          item.storage_path ??
          item.path ??
          null;
        if (storagePath && metadata.storage_path === undefined) {
          metadata.storage_path = storagePath;
        }

        const path =
          item.path ??
          metadata.path ??
          storagePath ??
          null;
        if (path && metadata.path === undefined) {
          metadata.path = path;
        }

        const filenameBase = item.filename ?? metadata.filename ?? metadata.file?.filename ?? null;
        const rawExtension = item.extension ?? metadata.extension ?? metadata.file?.extension ?? (item.mime_type ? item.mime_type.split('/').pop() : null);
        const normalisedExtension = rawExtension ? rawExtension.replace(/^\./, '').toLowerCase() : null;
        const inferExtFromMime = (mime) => {
          if (!mime) {
            return null;
          }
          if (mime === 'audio/mpeg') {
            return 'mp3';
          }
          if (mime === 'audio/wav' || mime === 'audio/x-wav') {
            return 'wav';
          }
          if (mime === 'audio/ogg') {
            return 'ogg';
          }
          return null;
        };
        const extension = normalisedExtension ?? inferExtFromMime(item.mime_type ?? metadata.mimeType);
        const buildFilename = () => {
          if (!filenameBase) {
            return null;
          }
          if (!extension) {
            return filenameBase;
          }
          return filenameBase.endsWith(`.${extension}`) ? filenameBase : `${filenameBase}.${extension}`;
        };
        const filename = buildFilename();

        const ensureFilePath = (base) => {
          if (!base) {
            return null;
          }
          const trimmed = base.trim();
          if (trimmed === '') {
            return null;
          }
          if (!filename) {
            return trimmed;
          }
          const normalised = trimmed.replace(/[/\\]+$/, '');
          if (normalised.endsWith(`/${filename}`) || normalised === filename) {
            return trimmed.startsWith('/') || normalised === filename ? trimmed : normalised;
          }
          if (normalised.includes('.')) {
            return trimmed;
          }
          return `${normalised}/${filename}`;
        };

        const resolvedStoragePath = ensureFilePath(storagePath);
        const resolvedAbsolutePath = ensureFilePath(path);
        const resolvedMetadataStoragePath = ensureFilePath(metadata.storage_path);
        const resolvedMetadataPath = ensureFilePath(metadata.path);

        if (resolvedMetadataStoragePath) {
          metadata.storage_path = resolvedMetadataStoragePath;
        }
        if (resolvedMetadataPath) {
          metadata.path = resolvedMetadataPath;
        }
        if (filename && !metadata.filename) {
          metadata.filename = filename;
        }
        if (extension && !metadata.extension) {
          metadata.extension = extension;
        }

        if (metadata.role === undefined && item.__role) {
          metadata.role = item.__role;
        }

        const result = {
          id: String(id),
          title: resolveRecordingLabel(item),
          durationSeconds: duration !== null ? Number(duration) : null,
          gain: gain !== null ? Number(gain) : null,
          gapMs: gapMs !== null ? Number(gapMs) : null,
          path: resolvedAbsolutePath ?? path ?? null,
          storage_path: resolvedStoragePath ?? storagePath ?? null,
          filename,
          mimeType: item.mime_type ?? item.mimeType ?? null,
          metadata,
        };

        Object.keys(result).forEach((key) => {
          const value = result[key];
          if (
            value === null ||
            value === undefined ||
            (typeof value === 'string' && value.trim() === '' && key !== 'id')
          ) {
            delete result[key];
          }
        });

        if (result.metadata && Object.keys(result.metadata).length === 0) {
          delete result.metadata;
        }

        return result;
      })
      .filter(Boolean);

    if (playlistItems.length > 0) {
      options.playlist = playlistItems;
    }
  }
  if (showFmInfo.value && form.fmFrequency) {
    options.frequency = form.fmFrequency;
  }
  options.audioInputId = selectedAudioInputId.value || 'default';
  options.audioOutputId = selectedAudioOutputId.value || 'default';

  const manualRoute = parseNumericList(form.routeText);
  const selectedLocationIds = form.selectedLocationGroups.map(value => Number(value)).filter(Number.isFinite);
  const selectedNestIds = form.selectedNests.map(value => Number(value)).filter(Number.isFinite);

  const playlistPresent = options.playlist && options.playlist.length > 0;
  const resolvedSource = playlistPresent
    ? 'central_file'
    : (form.source || 'microphone');

  const payload = {
    source: resolvedSource,
    route: manualRoute,
    locations: selectedLocationIds,
    nests: selectedNestIds,
    options,
  };

  const mixerAlias = resolveMixerAlias();
  if (mixerAlias) {
    const inputVolumeItem = currentInputVolumeItem.value;
    const volume = clampInputVolume(inputVolumeItem?.value ?? null);
    payload.mixer = {
      identifier: mixerAlias,
      source: resolvedSource,
    };
    if (volume !== null) {
      payload.mixer.volume = volume;
    }
  }

  return payload;
};

const fetchRecordingAudio = async (item) => {
  const recordingId = item?.id ?? item?.recording_id ?? item?.file_id ?? null;
  if (!recordingId) {
    throw new Error('Neplatné ID nahrávky.');
  }
  if (playlistAudioCache.has(recordingId)) {
    return playlistAudioCache.get(recordingId);
  }

  if (!window.http) {
    throw new Error('HTTP klient není inicializován.');
  }

  const response = await window.http.get(`records/${recordingId}/get-blob`, {responseType: 'arraybuffer'});
  const mime = response.headers?.['content-type'] ?? 'audio/mpeg';
  const blob = new Blob([response.data], {type: mime});
  const url = URL.createObjectURL(blob);
  const result = {url, mime};
  playlistAudioCache.set(recordingId, result);
  return result;
};

const buildLocalPlaybackQueue = async (items) => {
  const queue = [];
  for (const item of items) {
    const recordingId = item?.id ?? item?.recording_id ?? item?.file_id ?? null;
    if (!recordingId) {
      continue;
    }
    const audio = await fetchRecordingAudio(item);
    queue.push({
      id: String(recordingId),
      label: resolveRecordingLabel(item),
      url: audio.url,
      mime: audio.mime,
      role: item.__role ?? null,
    });
  }
  return queue;
};

const stopLocalPlayback = () => {
  const audioElement = localAudioPlayer.value;
  if (audioElement) {
    audioElement.pause();
    audioElement.currentTime = 0;
    audioElement.src = '';
  }
  localPlayback.queue = [];
  localPlayback.index = -1;
  localPlayback.playing = false;
  localPlayback.busy = false;
};

const playLocalQueueItem = async (index) => {
  const audioElement = localAudioPlayer.value;
  if (!audioElement) {
    return;
  }

  const item = localPlayback.queue[index];
  if (!item) {
    stopLocalPlayback();
    return;
  }

  localPlayback.index = index;

  if (typeof audioElement.setSinkId === 'function' && selectedAudioOutputId.value) {
    try {
      await audioElement.setSinkId(selectedAudioOutputId.value);
    } catch (error) {
      console.debug('setSinkId not supported or failed:', error);
    }
  }

  audioElement.src = item.url;
  audioElement.load();
  await nextTick();
  await audioElement.play();
};

const handleLocalPlaybackEnded = async () => {
  if (!localPlayback.playing) {
    return;
  }
  const nextIndex = localPlayback.index + 1;
  if (nextIndex >= localPlayback.queue.length) {
    stopLocalPlayback();
    return;
  }
  try {
    await playLocalQueueItem(nextIndex);
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se přehrát další položku.');
    stopLocalPlayback();
  }
};

const startFileBroadcast = async () => {
  if (localPlayback.busy) {
    return;
  }
  form.source = 'central_file';
  const orderedItems = getOrderedPlaylistItems();
  const hasQueueItems = orderedItems.length > 0;
  localPlayback.busy = true;
  loading.value = true;
  try {
    stopLocalPlayback();
    let queue = [];
    if (hasQueueItems) {
      queue = await buildLocalPlaybackQueue(orderedItems);
      if (queue.length === 0) {
        throw new Error('Žádné audio není k dispozici.');
      }
    }

    const payload = buildStartPayload();
    await LiveBroadcastService.startBroadcast(payload);

    if (queue.length > 0) {
      localPlayback.queue = queue;
      localPlayback.index = -1;
      localPlayback.playing = true;
      await playLocalQueueItem(0);
    }
    toast.success('Vysílání bylo spuštěno');
    await loadStatus();
  } catch (error) {
    console.error(error);
    stopLocalPlayback();
    const message = error?.response?.data?.message ?? error?.message ?? 'Nepodařilo se spustit vysílání';
    toast.error(message);
  } finally {
    localPlayback.busy = false;
    loading.value = false;
  }
};

const pickIntroJingle = () => {
  const {reveal, onConfirm} = createConfirmDialog(RecordSelectDialog, {
    title: 'Vyberte úvodní znělku',
    typeFilter: 'INTRO',
    multiple: false,
  });
  reveal();
  onConfirm((selection) => {
    const picked = Array.isArray(selection) ? selection[0] : selection;
    form.introJingle = picked ?? null;
    if (picked) {
      form.source = 'central_file';
    }
  });
};

const pickOutroJingle = () => {
  const {reveal, onConfirm} = createConfirmDialog(RecordSelectDialog, {
    title: 'Vyberte závěrečnou znělku',
    typeFilter: 'OUTRO',
    multiple: false,
  });
  reveal();
  onConfirm((selection) => {
    const picked = Array.isArray(selection) ? selection[0] : selection;
    form.outroJingle = picked ?? null;
    if (picked) {
      form.source = 'central_file';
    }
  });
};

const clearIntroJingle = () => {
  form.introJingle = null;
};

const clearOutroJingle = () => {
  form.outroJingle = null;
};

const startStream = async () => {
  if (form.source === 'central_file') {
    await startFileBroadcast();
    return;
  }

  loading.value = true;
  try {
    const payload = buildStartPayload();
    if (!hasPlaylistSelection.value && payload.options?.playlist) {
      delete payload.options.playlist;
    }
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
  stopLocalPlayback();
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
  if (form.source === 'central_file') {
    await startFileBroadcast();
    return;
  }

  liveUpdateInProgress.value = true;
  try {
    const payload = buildStartPayload();
    if (!hasPlaylistSelection.value && payload.options?.playlist) {
      delete payload.options.playlist;
    }
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
const stopFileBroadcast = async () => {
  if (localPlayback.busy) {
    return;
  }
  await stopStream();
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
    form.source = 'central_file';
  });
};

const removePlaylistItem = (index) => {
  form.playlistItems.splice(index, 1);
};

const startRecordingCapture = async () => {
  if (recordingBusy.value) {
    return;
  }
  recordingBusy.value = true;
  try {
    const response = await LiveBroadcastService.startRecording({source: form.source});
    recordingState.active = true;
    recordingState.info = response?.recording ?? null;
    toast.success('Záznam byl spuštěn.');
  } catch (error) {
    console.error('Failed to start capture', error);
    const message = error?.response?.data?.message ?? 'Nepodařilo se spustit záznam.';
    toast.error(message);
  } finally {
    recordingBusy.value = false;
  }
};

const stopRecordingCapture = async () => {
  if (recordingBusy.value) {
    return;
  }
  recordingBusy.value = true;
  try {
    const response = await LiveBroadcastService.stopRecording();
    recordingState.active = false;
    recordingState.info = response?.recording ?? null;
    toast.info('Záznam byl ukončen.');
  } catch (error) {
    console.error('Failed to stop capture', error);
    const message = error?.response?.data?.message ?? 'Nepodařilo se zastavit záznam.';
    toast.error(message);
  } finally {
    recordingBusy.value = false;
  }
};

</script>

<template>
  <PageContent label="Živé vysílání">
    <audio ref="localAudioPlayer" class="hidden" @ended="handleLocalPlaybackEnded"></audio>
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
            <div class="space-y-3">
              <div v-if="primaryMixerOptions.length > 0" class="space-y-1">
                <span class="block text-sm font-medium text-gray-700">Rychlý výběr vstupu</span>
                <div class="flex flex-wrap gap-3">
                  <label
                      v-for="option in primaryMixerOptions"
                      :key="option.id"
                      class="flex items-center gap-2 rounded border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm transition hover:border-primary focus-within:border-primary"
                  >
                    <input
                        type="radio"
                        class="form-radio text-primary focus:ring-primary"
                        :value="option.id"
                        v-model="selectedMixerInputId"
                        :disabled="mixerLoading || mixerInputUpdating"
                    >
                    <span>{{ option.label }}</span>
                  </label>
                </div>
              </div>
              <div v-else class="text-xs text-gray-500">
                Žádné rychlé předvolby vstupu nejsou k dispozici.
              </div>

              <div class="space-y-1 text-xs text-gray-500">
                <p v-if="mixerLoading">Načítám dostupné vstupy…</p>
                <p v-else-if="mixerInputUpdating">Přepínám vstup…</p>
                <p v-else-if="mixerInputs.length === 0">Žádné vstupy nejsou k dispozici.</p>
              </div>
            </div>

            <div v-if="showCentralFilePicker" class="space-y-3">
              <div class="flex justify-between items-center">
                <span class="text-sm font-medium text-gray-700">Soubor v ústředně</span>
                <Button size="xs" icon="mdi-playlist-plus" @click="pickPlaylist">Vybrat soubor</Button>
              </div>
              <div v-if="form.playlistItems.length === 0" class="text-xs text-gray-500">Zatím není vybrána žádná nahrávka.</div>
              <ul v-else class="space-y-2 text-xs">
                <li v-for="(item, index) in form.playlistItems" :key="item.id" class="flex justify-between items-center bg-gray-100 px-2 py-1 rounded">
                  <span>{{ resolveRecordingLabel(item) }}</span>
                  <button class="text-red-500" @click="removePlaylistItem(index)"><span class="mdi mdi-close"></span></button>
                </li>
              </ul>

              <div class="grid gap-3 md:grid-cols-2">
                <div class="space-y-1 text-xs">
                  <div class="flex items-center justify-between">
                    <span class="font-medium text-gray-700">Úvodní znělka</span>
                    <div class="flex items-center gap-1">
                      <Button size="2xs" variant="ghost" icon="mdi-music-note-plus" @click="pickIntroJingle">Vybrat</Button>
                      <button v-if="form.introJingle" class="text-red-500" @click="clearIntroJingle"><span class="mdi mdi-close"></span></button>
                    </div>
                  </div>
                  <div class="text-gray-600">{{ form.introJingle ? resolveRecordingLabel(form.introJingle) : 'Nevybráno' }}</div>
                </div>
                <div class="space-y-1 text-xs">
                  <div class="flex items-center justify-between">
                    <span class="font-medium text-gray-700">Závěrečná znělka</span>
                    <div class="flex items-center gap-1">
                      <Button size="2xs" variant="ghost" icon="mdi-music-note-plus" @click="pickOutroJingle">Vybrat</Button>
                      <button v-if="form.outroJingle" class="text-red-500" @click="clearOutroJingle"><span class="mdi mdi-close"></span></button>
                    </div>
                  </div>
                  <div class="text-gray-600">{{ form.outroJingle ? resolveRecordingLabel(form.outroJingle) : 'Nevybráno' }}</div>
                </div>
              </div>

              <div class="grid gap-2 sm:grid-cols-2">
                <Button
                    variant="primary"
                    icon="mdi-play"
                    :disabled="localPlayback.busy || loading || !hasPlaylistSelection"
                    @click="startFileBroadcast">
                  Přehrát
                </Button>
                <Button
                    variant="ghost"
                    icon="mdi-stop"
                    :disabled="localPlayback.busy || (!localPlayback.playing && !isStreaming)"
                    @click="stopFileBroadcast">
                  Zastavit
                </Button>
              </div>
              <div v-if="localPlayback.playing && localPlayback.queue[localPlayback.index]" class="text-xs text-gray-500">
                Přehrávám: {{ localPlayback.queue[localPlayback.index].label }}
              </div>
            </div>

            <Textarea v-model="form.note" label="Poznámka" rows="2" placeholder="Nepovinné doplňující údaje"/>

            <div class="space-y-2">
              <div class="flex flex-wrap items-center gap-3">
                <Button
                    variant="primary"
                    icon="mdi-record-circle"
                    :disabled="recordingBusy || recordingState.active"
                    @click="startRecordingCapture">
                  Spustit záznam
                </Button>
                <Button
                    variant="ghost"
                    icon="mdi-stop-circle"
                    :disabled="recordingBusy || !recordingState.active"
                    @click="stopRecordingCapture">
                  Ukončit záznam
                </Button>
                <span v-if="recordingBusy" class="text-xs text-gray-500">Zpracovávám…</span>
              </div>
              <div class="text-xs text-gray-500">
                {{ recordingStatusLabel }}
              </div>
            </div>

            <div v-if="showFmInfo" class="space-y-2 text-sm">
              <div><strong>Frekvence FM rádia:</strong> {{ form.fmFrequency || 'Neznámá' }}</div>
              <Button size="xs" icon="mdi-refresh" @click="loadFmFrequency">Aktualizovat frekvenci</Button>
            </div>

            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <span class="text-sm font-semibold text-gray-700">Hlasitost vstupu</span>
                <span v-if="volumeLoading" class="text-xs text-gray-500">Načítám…</span>
              </div>
              <template v-if="!volumeLoading">
                <template v-if="currentInputVolumeItem">
                  <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:min-w-[360px] mt-2">
                    <input
                        v-model.number="currentInputVolumeItem.value"
                        type="range"
                        class="range range-sm w-full sm:w-64 md:w-80"
                        :min="volumeSlider.min"
                        :max="volumeSlider.max"
                        :step="volumeSlider.step"
                        @change="handleInputVolumeChange"
                        :disabled="isInputVolumeSaving"
                    />
                    <div class="flex items-center gap-2 text-sm text-gray-700">
                      <span class="inline-block w-14 text-right">{{ Math.round(Number(currentInputVolumeItem.value)) }} %</span>
                    </div>
                  </div>
                  <div v-if="isInputVolumeSaving" class="text-xs text-gray-500 text-right">Ukládám…</div>
                </template>
                <div v-else class="text-xs text-gray-500 mt-1">Žádný vstup není dostupný.</div>
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

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Hnízda</label>
              <select
                  v-model="form.selectedNests"
                  multiple
                  :disabled="!hasNestsDefined"
                  class="form-select w-full h-32 border border-gray-300 rounded-md bg-white focus:border-primary focus:ring focus:ring-primary focus:ring-opacity-40 disabled:bg-gray-100 disabled:text-gray-500">
                <option v-for="nest in nests" :key="nest.id" :value="String(nest.id)">
                  {{ nest.name }}{{ nest.modbus_address ? ' (adresa ' + nest.modbus_address + ')' : '' }}
                </option>
              </select>
              <p v-if="hasNestsDefined" class="text-xs text-gray-500">Vybraná hnízda se přidají mezi cílové zóny vysílání.</p>
              <p v-else class="text-xs text-gray-500">Žádná hnízda nejsou dostupná pro výběr.</p>
            </div>

            <Input v-if="false" v-model="form.routeText" label="Route (čísla oddělená čárkou)" placeholder="např.: 1,2,3"/>

          </div>
        </Box>
      </div>
    </div>
  </PageContent>
</template>
