<script setup>
import {computed, onBeforeUnmount, onMounted, reactive, ref, watch} from "vue";
import {useToast} from "vue-toastification";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import Button from "../../components/forms/Button.vue";
import Box from "../../components/custom/Box.vue";
import Textarea from "../../components/forms/Textarea.vue";
import Select from "../../components/forms/Select.vue";
import RecordSaveDialog from "../../components/modals/RecordSaveDialog.vue";
import RecordSelectDialog from "../../components/modals/RecordSelectDialog.vue";
import RecordList from "./RecordList.vue";
import LiveBroadcastService from "../../services/LiveBroadcastService.js";
import VolumeService from "../../services/VolumeService.js";
import RecordingService from "../../services/RecordingService.js";
import AudioService from "../../services/AudioService.js";
import {durationToTime, formatBytes} from "../../helper.js";
import {FILE_SUBTYPE_OPTIONS} from "../../constants/fileSubtypeOptions.js";

const toast = useToast();

const sources = ref([]);
const volumeGroups = ref([]);
const volumeLoading = ref(false);
const volumeSaving = reactive({});
const volumeSlider = {
  min: 0,
  max: 100,
  step: 1,
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
const sourceOutputChannelMap = ref({
  microphone: 'tx_audio',
  system_audio: 'tx_audio',
  central_file: 'tx_audio',
  fm_radio: 'tx_audio',
  gsm: 'tx_audio',
  input_1: 'tx_audio',
  input_2: 'tx_audio',
});

const sourceToMixerInputMap = {
  microphone: 'mic',
  central_file: 'file',
  system_audio: 'system',
  fm_radio: 'fm',
  gsm: 'gsm',
  input_1: 'input_1',
  input_2: 'input_2',
};

const centralFileSelection = ref(null);
const form = reactive({
  source: '',
  note: '',
  subtype: 'COMMON',
});

const isCentralFileSource = computed(() => form.source === 'central_file');

const subtypeOptions = FILE_SUBTYPE_OPTIONS;

const recording = ref(false);
const loading = ref(false);
const saving = ref(false);
const recordStartedAt = ref(null);
const recordStoppedAt = ref(null);
const recordDurationSeconds = ref(0);
const recordBlob = ref(null);
const recordMimeType = ref('audio/webm');
const recordExtension = ref('webm');
const previewUrl = ref(null);
const audioPlayer = ref(null);
const isPreviewPlaying = ref(false);
const audioRoutingEnabled = ref(false);
const availableMixerInputs = ref([]);
const currentMixerInputId = ref('');

const recordedChunks = ref([]);
let mediaRecorderInstance = null;
let mediaStreamRef = null;
let durationInterval = null;

const sourceLabelMap = computed(() => {
  const map = new Map();
  sources.value.forEach((source) => {
    if (source?.id) {
      map.set(source.id, source.label ?? source.id);
    }
  });
  return map;
});

const selectedSourceLabel = computed(() => {
  if (!form.source) {
    return '–';
  }
  return sourceLabelMap.value.get(form.source) ?? form.source;
});

const recordingStatusLabel = computed(() => {
  if (isCentralFileSource.value) {
    return 'Záznam ze souboru';
  }
  return recording.value ? 'Záznam běží' : 'Záznam není aktivní';
});

const recordingStatusSubtitle = computed(() => {
  if (isCentralFileSource.value) {
    return centralFileSelection.value
      ? `Vybraný soubor: ${centralFileSelection.value.name}`
      : 'Vyberte soubor z ústředny pro vytvoření záznamu.';
  }
  if (recording.value && recordStartedAt.value) {
    return `Nahrávání probíhá od ${recordStartedAt.value.toLocaleString('cs-CZ')}`;
  }
  if (!recording.value && recordStoppedAt.value) {
    return `Poslední záznam ukončen ${recordStoppedAt.value.toLocaleString('cs-CZ')}`;
  }
  return 'Záznam ještě nebyl spuštěn.';
});

const formattedDuration = computed(() => {
  if (isCentralFileSource.value && centralFileSelection.value) {
    const duration = centralFileSelection.value.duration_seconds
      ?? centralFileSelection.value.duration
      ?? centralFileSelection.value?.metadata?.duration
      ?? recordDurationSeconds.value;
    return durationToTime(Math.max(Number(duration) || 0, 0));
  }
  if (recording.value && recordStartedAt.value) {
    return durationToTime(Math.max(recordDurationSeconds.value, 0));
  }
  if (!recording.value && recordDurationSeconds.value > 0) {
    return durationToTime(recordDurationSeconds.value);
  }
  return '00:00';
});

const fileSizeDisplay = computed(() => {
  if (isCentralFileSource.value && centralFileSelection.value?.size) {
    return formatBytes(Number(centralFileSelection.value.size));
  }
  return recordBlob.value ? formatBytes(recordBlob.value.size) : '–';
});

const noteDisplay = computed(() => (form.note && form.note.trim().length > 0 ? form.note.trim() : '—'));

const subtypeLabelMap = computed(() => {
  const map = new Map();
  subtypeOptions.forEach((option) => {
    map.set(option.value, option.label);
  });
  return map;
});

const selectedSubtypeLabel = computed(() => {
  if (!form.subtype) {
    return '—';
  }
  return subtypeLabelMap.value.get(form.subtype) ?? form.subtype;
});

const sourceChannels = computed(() => sourceInputChannelMap.value ?? {});

const currentSourceId = computed(() => form.source || null);

const activeInputItemId = computed(() => {
  const source = currentSourceId.value;
  if (source && sourceChannels.value[source]) {
    return sourceChannels.value[source];
  }
  return null;
});

const activeOutputItemId = computed(() => {
  const source = currentSourceId.value;
  if (source && sourceOutputChannelMap.value?.[source]) {
    return sourceOutputChannelMap.value[source];
  }
  return null;
});

const findVolumeEntryById = (itemId) => {
  if (!itemId) {
    return null;
  }
  for (const group of volumeGroups.value) {
    const items = Array.isArray(group?.items) ? group.items : [];
    const found = items.find((item) => item.id === itemId);
    if (found) {
      return {groupId: group.id, item: found};
    }
  }
  return null;
};

const currentVolumeEntry = computed(() => {
  return findVolumeEntryById(activeInputItemId.value) ?? findVolumeEntryById(activeOutputItemId.value);
});

const currentInputVolumeItem = computed(() => currentVolumeEntry.value?.item ?? null);
const currentInputVolumeGroupId = computed(() => currentVolumeEntry.value?.groupId ?? null);

const linkedVolumeEntries = computed(() => {
  const ids = [];
  if (activeInputItemId.value) {
    ids.push(activeInputItemId.value);
  }
  if (activeOutputItemId.value) {
    ids.push(activeOutputItemId.value);
  }
  const seen = new Set();
  const entries = [];
  for (const id of ids) {
    if (seen.has(id)) {
      continue;
    }
    seen.add(id);
    const entry = findVolumeEntryById(id);
    if (entry) {
      entries.push(entry);
    }
  }
  return entries;
});

const currentVolumeSavingKey = computed(() => {
  const groupId = currentInputVolumeGroupId.value;
  const item = currentInputVolumeItem.value;
  if (!groupId || !item) {
    return null;
  }
  return `${groupId}:${item.id}`;
});

const isCurrentVolumeSaving = computed(() => {
  const key = currentVolumeSavingKey.value;
  return key ? Boolean(volumeSaving[key]) : false;
});

const canTogglePreview = computed(() => {
  if (isCentralFileSource.value) {
    return false;
  }
  return !!recordBlob.value && !recording.value && !saving.value;
});

const canSaveRecording = computed(() => {
  if (isCentralFileSource.value) {
    return !!centralFileSelection.value && !saving.value;
  }
  return !!recordBlob.value && !recording.value && !saving.value;
});

const bigButtonIcon = computed(() => {
  if (isCentralFileSource.value) {
    return centralFileSelection.value ? 'mdi-file-replace' : 'mdi-file-music';
  }
  return recording.value ? 'mdi-stop' : 'mdi-record-circle';
});
const bigButtonLabel = computed(() => {
  if (isCentralFileSource.value) {
    return centralFileSelection.value ? 'Změnit soubor' : 'Vybrat soubor';
  }
  return recording.value ? 'Zastavit záznam' : 'Spustit nový záznam';
});
const bigButtonVariant = computed(() => {
  if (isCentralFileSource.value) {
    return 'primary';
  }
  return recording.value ? 'danger' : 'primary';
});

const preferedMimeTypes = [
  {mime: 'audio/webm;codecs=opus', extension: 'webm'},
  {mime: 'audio/webm', extension: 'webm'},
  {mime: 'audio/ogg;codecs=opus', extension: 'ogg'},
  {mime: 'audio/ogg', extension: 'ogg'},
];

const makeVolumeKey = (groupId, itemId) => `${groupId}:${itemId}`;

const pickPreferredMimeType = () => {
  for (const option of preferedMimeTypes) {
    if (MediaRecorder.isTypeSupported(option.mime)) {
      return option;
    }
  }
  return {mime: '', extension: 'webm'};
};

const determineExtension = (mimeType) => {
  if (!mimeType) {
    return 'webm';
  }
  const match = preferedMimeTypes.find((option) => option.mime === mimeType);
  if (match) {
    return match.extension;
  }
  if (mimeType.includes('ogg')) {
    return 'ogg';
  }
  if (mimeType.includes('webm')) {
    return 'webm';
  }
  if (mimeType.includes('wav')) {
    return 'wav';
  }
  return 'webm';
};

const normaliseMixerItems = (list) => {
  if (!Array.isArray(list)) {
    return [];
  }
  return list
    .filter((item) => item && typeof item.id === 'string' && item.id.length > 0)
    .filter((item) => item.available !== false)
    .map((item) => ({
      id: item.id,
      label: item.label ?? item.id,
      device: item.device ?? null,
    }));
};

const loadMixerStatus = async (silent = false) => {
  try {
    const statusPayload = await AudioService.status();
    audioRoutingEnabled.value = Boolean(statusPayload?.enabled);
    availableMixerInputs.value = normaliseMixerItems(statusPayload?.inputs);
    currentMixerInputId.value = statusPayload?.current?.input?.id ?? '';
    return statusPayload;
  } catch (error) {
    audioRoutingEnabled.value = false;
    if (!silent) {
      console.error('Failed to load mixer status for recorder', error);
    } else {
      console.debug('Mixer status unavailable for recorder', error);
    }
    return null;
  }
};

const resolveMixerInputId = (sourceId) => {
  if (!sourceId) {
    return null;
  }
  return sourceToMixerInputMap[sourceId] ?? null;
};

const switchMixerInputForSource = async (sourceId, {silent = false} = {}) => {
  if (!audioRoutingEnabled.value) {
    return;
  }
  if (!sourceId || sourceId === 'central_file') {
    return;
  }
  const targetInputId = resolveMixerInputId(sourceId);
  if (!targetInputId) {
    return;
  }
  if (
    availableMixerInputs.value.length > 0
    && !availableMixerInputs.value.some((item) => item.id === targetInputId)
  ) {
    return;
  }
  if (currentMixerInputId.value === targetInputId) {
    return;
  }

  try {
    await AudioService.setInput(targetInputId);
    await loadMixerStatus(true);
    if (!silent) {
      toast.success('Vstup ústředny byl přepnut pro záznam.');
    }
  } catch (error) {
    console.error('Failed to switch mixer input for recording', error);
    if (!silent) {
      toast.error('Nepodařilo se přepnout vstup ústředny pro záznam.');
    }
  }
};

const loadSources = async () => {
  try {
    const response = await LiveBroadcastService.getSources();
    sources.value = Array.isArray(response) ? response : [];
    if (!form.source && sources.value.length > 0) {
      form.source = sources.value[0].id;
    }
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst dostupné zdroje');
  }
};

const loadVolumeLevels = async (silent = false) => {
  volumeLoading.value = true;
  try {
    const response = await VolumeService.fetchLiveLevels();
    const groups = Array.isArray(response?.groups) ? response.groups : [];
    volumeGroups.value = groups;
    if (response?.sourceChannels && typeof response.sourceChannels === 'object') {
      sourceInputChannelMap.value = {
        ...sourceInputChannelMap.value,
        ...response.sourceChannels,
      };
    }
    if (response?.sourceOutputChannels && typeof response.sourceOutputChannels === 'object') {
      sourceOutputChannelMap.value = {
        ...sourceOutputChannelMap.value,
        ...response.sourceOutputChannels,
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

const extractFileDurationSeconds = (file) => {
  if (!file) {
    return 0;
  }
  const metadata = file.metadata ?? {};
  const rawDuration =
    metadata.duration
    ?? metadata.duration_seconds
    ?? metadata.length
    ?? file.duration_seconds
    ?? file.duration;
  const parsed = Number(rawDuration);
  if (Number.isNaN(parsed) || parsed < 0) {
    return 0;
  }
  return parsed;
};

watch(
  () => form.source,
  async (newValue, oldValue) => {
    if (newValue === 'central_file') {
      resetRecordingState();
    } else {
      centralFileSelection.value = null;
      recordDurationSeconds.value = 0;
    }
    const shouldNotify = oldValue !== undefined && oldValue !== '' && newValue !== oldValue;
    await switchMixerInputForSource(newValue, {silent: !shouldNotify});
  }
);

watch(
  () => centralFileSelection.value,
  (selection) => {
    if (!isCentralFileSource.value) {
      return;
    }
    if (selection) {
      recordDurationSeconds.value = Math.round(extractFileDurationSeconds(selection));
    } else {
      recordDurationSeconds.value = 0;
    }
  }
);

watch(
  () => audioRoutingEnabled.value,
  async (enabled) => {
    if (enabled) {
      await switchMixerInputForSource(form.source, {silent: true});
    }
  }
);

const updateVolumeLevel = async (groupId, itemId, value) => {
  const key = makeVolumeKey(groupId, itemId);
  volumeSaving[key] = true;
  try {
    const response = await VolumeService.applyRuntimeLevel({group: groupId, id: itemId, value});
    const updatedItem = response?.item ?? null;
    if (updatedItem) {
      const group = volumeGroups.value.find((entry) => entry.id === groupId);
      if (group) {
        const index = group.items.findIndex((entry) => entry.id === itemId);
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
    toast.error('Pro tento zdroj není k dispozici nastavení hlasitosti.');
    return;
  }
  const parsed = Number(entry.item.value);
  if (Number.isNaN(parsed)) {
    toast.error('Zadejte platnou číselnou hodnotu');
    return;
  }
  const clamped = Math.min(volumeSlider.max, Math.max(volumeSlider.min, parsed));
  const targets = linkedVolumeEntries.value;
  if (targets.length === 0) {
    toast.error('Pro tento zdroj není k dispozici nastavení hlasitosti.');
    return;
  }
  targets.forEach(({item}) => {
    item.value = clamped;
  });
  try {
    for (const {groupId, item} of targets) {
      await updateVolumeLevel(groupId, item.id, clamped);
    }
  } catch (error) {
    await loadVolumeLevels(true);
  }
};

const startDurationTimer = () => {
  stopDurationTimer();
  if (!recordStartedAt.value) {
    return;
  }
  durationInterval = setInterval(() => {
    if (recordStartedAt.value) {
      recordDurationSeconds.value = Math.max(0, Math.round((Date.now() - recordStartedAt.value.getTime()) / 1000));
    }
  }, 1000);
};

const stopDurationTimer = () => {
  if (durationInterval) {
    clearInterval(durationInterval);
    durationInterval = null;
  }
};

const cleanupStream = () => {
  if (mediaStreamRef) {
    mediaStreamRef.getTracks().forEach((track) => track.stop());
    mediaStreamRef = null;
  }
};

const cleanupPreview = () => {
  if (audioPlayer.value) {
    audioPlayer.value.pause();
    audioPlayer.value.currentTime = 0;
  }
  isPreviewPlaying.value = false;
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
  }
};

const openCentralFilePicker = () => {
  const {reveal, onConfirm} = createConfirmDialog(RecordSelectDialog, {
    title: 'Vyberte soubor z ústředny',
    typeFilter: 'ALL',
    multiple: false,
  });
  reveal();
  onConfirm((selection) => {
    const chosen = Array.isArray(selection) ? selection[0] : selection;
    if (!chosen) {
      return;
    }
    centralFileSelection.value = chosen;
    toast.success('Soubor byl vybrán');
  });
};

const toggleRecording = async () => {
  if (isCentralFileSource.value) {
    openCentralFilePicker();
    return;
  }
  if (recording.value) {
    await stopRecording();
  } else {
    await startRecording();
  }
};

const startRecording = async () => {
  if (recording.value || loading.value) {
    return;
  }
  loading.value = true;
  try {
    await switchMixerInputForSource(form.source, {silent: true});
    const constraints = {
      audio: {
        echoCancellation: false,
      },
    };
    const stream = await navigator.mediaDevices.getUserMedia(constraints);
    mediaStreamRef = stream;
    recordedChunks.value = [];
    cleanupPreview();

    const preferred = pickPreferredMimeType();
    recordMimeType.value = preferred.mime;
    recordExtension.value = preferred.extension;

    try {
      mediaRecorderInstance = preferred.mime
        ? new MediaRecorder(stream, {mimeType: preferred.mime})
        : new MediaRecorder(stream);
    } catch (error) {
      console.warn('Preferred mimeType failed, falling back to default MediaRecorder.', error);
      mediaRecorderInstance = new MediaRecorder(stream);
      recordMimeType.value = mediaRecorderInstance.mimeType || 'audio/webm';
      recordExtension.value = determineExtension(recordMimeType.value);
    }

    mediaRecorderInstance.ondataavailable = (event) => {
      if (event.data && event.data.size > 0) {
        recordedChunks.value.push(event.data);
      }
    };

    mediaRecorderInstance.onstop = () => {
      cleanupStream();
      const chunks = recordedChunks.value;
      if (!chunks || chunks.length === 0) {
        recording.value = false;
        return;
      }
      const blob = new Blob(chunks, {type: recordMimeType.value || 'audio/webm'});
      recordBlob.value = blob;
      const url = URL.createObjectURL(blob);
      if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
      }
      previewUrl.value = url;
      recordStoppedAt.value = new Date();
      if (recordStartedAt.value) {
        recordDurationSeconds.value = Math.max(
          1,
          Math.round((recordStoppedAt.value.getTime() - recordStartedAt.value.getTime()) / 1000),
        );
      }
      recording.value = false;
      toast.success('Záznam byl dokončen');
    };

    mediaRecorderInstance.start(1000);
    recordStartedAt.value = new Date();
    recordStoppedAt.value = null;
    recordDurationSeconds.value = 0;
    startDurationTimer();
    recording.value = true;
    toast.success('Záznam byl spuštěn');
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se spustit nahrávání. Zkontrolujte oprávnění mikrofonu.');
    cleanupStream();
    recording.value = false;
  } finally {
    loading.value = false;
  }
};

const stopRecording = async () => {
  if (!recording.value || !mediaRecorderInstance || loading.value) {
    return;
  }
  loading.value = true;
  stopDurationTimer();
  try {
    mediaRecorderInstance.stop();
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se zastavit nahrávání');
  } finally {
    mediaRecorderInstance = null;
    loading.value = false;
  }
};

const togglePreview = async () => {
  if (!recordBlob.value || !previewUrl.value) {
    toast.warning('Nejprve vytvořte záznam.');
    return;
  }
  if (!audioPlayer.value) {
    return;
  }
  if (isPreviewPlaying.value) {
    audioPlayer.value.pause();
    audioPlayer.value.currentTime = 0;
    isPreviewPlaying.value = false;
    return;
  }

  audioPlayer.value.src = previewUrl.value;
  const audioOutput = JSON.parse(localStorage.getItem('audioOutputDevice') ?? 'null') ?? {id: 'default'};
  if (typeof audioPlayer.value.setSinkId === 'function' && audioOutput.id) {
    try {
      await audioPlayer.value.setSinkId(audioOutput.id);
    } catch (error) {
      console.warn('Failed to set sinkId', error);
    }
  }

  try {
    await audioPlayer.value.play();
    isPreviewPlaying.value = true;
  } catch (error) {
    console.error(error);
    toast.error('Nahrávku se nepodařilo přehrát');
  }
};

const handlePreviewEnded = () => {
  if (audioPlayer.value) {
    audioPlayer.value.currentTime = 0;
  }
  isPreviewPlaying.value = false;
};

const resetRecordingState = () => {
  stopDurationTimer();
  cleanupPreview();
  recordBlob.value = null;
  recordedChunks.value = [];
  recordStartedAt.value = null;
  recordStoppedAt.value = null;
  recordDurationSeconds.value = 0;
};

const saveRecording = () => {
  if (saving.value) {
    return;
  }
  if (isCentralFileSource.value) {
    if (!centralFileSelection.value) {
      toast.warning('Nejprve vyberte soubor z ústředny.');
      return;
    }
  } else if (!recordBlob.value) {
    toast.warning('Nejprve vytvořte záznam.');
    return;
  }

  const dialogOptions = {
    defaultSubtype: form.subtype,
  };
  if (isCentralFileSource.value && centralFileSelection.value?.name) {
    dialogOptions.defaultName = centralFileSelection.value.name;
  }

  const {reveal, onConfirm} = createConfirmDialog(RecordSaveDialog, dialogOptions);
  reveal();

  onConfirm(async ({name, subtype}) => {
    form.subtype = subtype;
    saving.value = true;

    if (isCentralFileSource.value) {
      try {
        const durationSeconds = Math.round(extractFileDurationSeconds(centralFileSelection.value));
        const metadata = {
          source: form.source ?? '',
          note: form.note ?? '',
        };
        if (durationSeconds > 0) {
          metadata.duration = durationSeconds;
        }
        await RecordingService.createFromCentralFile({
          source_file_id: centralFileSelection.value.id,
          name,
          subtype,
          note: form.note ?? '',
          metadata,
        });
        emitter.emit('recordSaved');
        toast.success('Záznam byl vytvořen ze souboru v ústředně');
      } catch (error) {
        console.error(error);
        const message = error?.response?.data?.message ?? 'Nepodařilo se vytvořit záznam ze souboru';
        toast.error(message);
      } finally {
        saving.value = false;
      }
      return;
    }

    const blob = recordBlob.value;
    if (!blob) {
      toast.error('Chybí data nahrávky');
      saving.value = false;
      return;
    }

    const extension = recordExtension.value || determineExtension(recordMimeType.value);
    const formData = new FormData();
    formData.append('file', blob, `recording.${extension}`);
    formData.append('type', 'RECORDING');
    formData.append('subtype', subtype);
    formData.append('name', name);
    formData.append('extension', extension);
    formData.append('metadata[duration]', Math.max(recordDurationSeconds.value, 1));
    formData.append('metadata[source]', form.source ?? '');
    formData.append('metadata[note]', form.note ?? '');

    try {
      await RecordingService.uploadRecording(formData);
      emitter.emit('recordSaved');
      toast.success('Záznam byl uložen');
      resetRecordingState();
    } catch (error) {
      console.error(error);
      toast.error('Nepodařilo se uložit záznam');
    } finally {
      saving.value = false;
    }
  });
};

onMounted(async () => {
  await Promise.all([loadSources(), loadVolumeLevels(), loadMixerStatus(true)]);
  await switchMixerInputForSource(form.source, {silent: true});
});

onBeforeUnmount(() => {
  stopDurationTimer();
  cleanupStream();
  cleanupPreview();
});
</script>

<template>
  <div class="space-y-6">
    <div class="w-full">
      <Button
          :icon="bigButtonIcon"
          :variant="bigButtonVariant"
          :disabled="loading"
          :aria-label="bigButtonLabel"
          :label="bigButtonLabel"
          class="w-full flex flex-col items-start gap-2 p-5 text-left border border-primary rounded-lg shadow-lg bg-primary text-white hover:bg-primary/90 transition"
          @click="toggleRecording">
        <template #default>
            <div class="flex w-full justify-between items-center">
              <div class="space-y-1">
                <div class="text-xs font-semibold uppercase tracking-wide text-white/80">
                  {{ bigButtonLabel }}
                </div>
                <div class="text-lg font-semibold">
                {{ recordingStatusLabel }}
              </div>
              <div class="text-sm opacity-90">
                {{ recordingStatusSubtitle }}
              </div>
            </div>
            <div class="text-sm text-white/80 space-y-1 text-right">
              <div>Zdroj: {{ selectedSourceLabel }}</div>
              <div>Délka: {{ formattedDuration }}</div>
            </div>
          </div>
        </template>
      </Button>
    </div>

    <Box label="Aktuální stav nahrávání">
      <div class="grid gap-4 md:grid-cols-2 text-sm">
        <div class="space-y-2">
          <div><strong>Zdroj:</strong> {{ selectedSourceLabel }}</div>
          <div><strong>Stav:</strong> {{ recording ? 'probíhá' : 'neaktivní' }}</div>
          <div><strong>Začátek:</strong> {{ recordStartedAt ? recordStartedAt.toLocaleString('cs-CZ') : '–' }}</div>
          <div><strong>Konec:</strong> {{ recordStoppedAt ? recordStoppedAt.toLocaleString('cs-CZ') : '–' }}</div>
        </div>
        <div class="space-y-2">
          <div><strong>Délka:</strong> {{ formattedDuration }}</div>
          <div><strong>Velikost souboru:</strong> {{ fileSizeDisplay }}</div>
          <div><strong>Typ nahrávky:</strong> {{ selectedSubtypeLabel }}</div>
          <div><strong>Poznámka:</strong> {{ noteDisplay }}</div>
        </div>
      </div>
    </Box>

    <div class="grid gap-6 lg:grid-cols-2">
      <Box label="Nastavení záznamu">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Zdroj záznamu</label>
            <select
                v-model="form.source"
                class="form-select w-full border border-gray-300 rounded-md bg-white focus:border-primary focus:ring focus:ring-primary focus:ring-opacity-40"
                :disabled="recording">
              <option v-for="source in sources" :key="source.id" :value="source.id">
                {{ source.label }}
              </option>
            </select>
          </div>

          <div class="space-y-2">
            <div class="flex justify-between items-center">
              <span class="text-sm font-medium text-gray-700">
                Hlasitost
                <template v-if="currentInputVolumeItem">
                  – {{ currentInputVolumeItem.label }}
                </template>
              </span>
              <span v-if="volumeLoading" class="text-xs text-gray-500">Načítám…</span>
            </div>
            <template v-if="currentInputVolumeItem">
              <div class="flex flex-col sm:flex-row sm:items-center gap-2 sm:min-w-[360px]">
                <input
                    v-model.number="currentInputVolumeItem.value"
                    type="range"
                    class="range range-sm w-full sm:w-64 md:w-80"
                    :min="volumeSlider.min"
                    :max="volumeSlider.max"
                    :step="volumeSlider.step"
                    @change="handleActiveVolumeChange"
                    :disabled="isCurrentVolumeSaving || recording"
                />
                <div class="flex items-center gap-2 text-sm text-gray-700">
                  <span class="inline-block w-14 text-right">{{ Math.round(Number(currentInputVolumeItem.value)) }} %</span>
                  <span v-if="isCurrentVolumeSaving" class="text-xs text-gray-500">Ukládám…</span>
                </div>
              </div>
            </template>
            <div v-else class="text-xs text-gray-500">
              Pro zvolený zdroj není k dispozici ovládání hlasitosti.
            </div>
          </div>

          <Select
              v-model="form.subtype"
              :options="subtypeOptions"
              label="Typ nahrávky"
              size="sm"
          />

          <Textarea v-model="form.note" label="Poznámka" rows="3" placeholder="Nepovinné poznámky k této nahrávce"/>

          <div class="flex flex-wrap gap-2">
            <Button
                size="sm"
                :variant="isPreviewPlaying ? 'ghost' : 'secondary'"
                icon="mdi-play-circle"
                :disabled="!canTogglePreview"
                @click="togglePreview">
              {{ isPreviewPlaying ? 'Zastavit náhled' : 'Přehrát náhled' }}
            </Button>
            <Button
                size="sm"
                icon="mdi-content-save"
                :disabled="!canSaveRecording"
                @click="saveRecording">
              Uložit záznam
            </Button>
          </div>
          <audio ref="audioPlayer" class="hidden" @ended="handlePreviewEnded"></audio>
        </div>
      </Box>

      <div class="space-y-4">
        <RecordList/>
      </div>
    </div>
  </div>
</template>
