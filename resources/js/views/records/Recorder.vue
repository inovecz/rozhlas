<script setup>
import {computed, onBeforeUnmount, onMounted, reactive, ref} from "vue";
import {useToast} from "vue-toastification";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import Button from "../../components/forms/Button.vue";
import Box from "../../components/custom/Box.vue";
import Textarea from "../../components/forms/Textarea.vue";
import RecordSaveDialog from "../../components/modals/RecordSaveDialog.vue";
import RecordList from "./RecordList.vue";
import LiveBroadcastService from "../../services/LiveBroadcastService.js";
import VolumeService from "../../services/VolumeService.js";
import RecordingService from "../../services/RecordingService.js";
import {durationToTime, formatBytes} from "../../helper.js";

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

const form = reactive({
  source: '',
  note: '',
});

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

const recordingStatusLabel = computed(() => (recording.value ? 'Záznam běží' : 'Záznam není aktivní'));

const recordingStatusSubtitle = computed(() => {
  if (recording.value && recordStartedAt.value) {
    return `Nahrávání probíhá od ${recordStartedAt.value.toLocaleString('cs-CZ')}`;
  }
  if (!recording.value && recordStoppedAt.value) {
    return `Poslední záznam ukončen ${recordStoppedAt.value.toLocaleString('cs-CZ')}`;
  }
  return 'Záznam ještě nebyl spuštěn.';
});

const formattedDuration = computed(() => {
  if (recording.value && recordStartedAt.value) {
    return durationToTime(Math.max(recordDurationSeconds.value, 0));
  }
  if (!recording.value && recordDurationSeconds.value > 0) {
    return durationToTime(recordDurationSeconds.value);
  }
  return '00:00';
});

const fileSizeDisplay = computed(() => (recordBlob.value ? formatBytes(recordBlob.value.size) : '–'));

const noteDisplay = computed(() => (form.note && form.note.trim().length > 0 ? form.note.trim() : '—'));

const sourceChannels = computed(() => sourceInputChannelMap.value ?? {});

const currentSourceId = computed(() => form.source || null);

const activeInputItemId = computed(() => {
  const source = currentSourceId.value;
  if (source && sourceChannels.value[source]) {
    return sourceChannels.value[source];
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
    const found = items.find((item) => item.id === targetId);
    if (found) {
      return {groupId: group.id, item: found};
    }
  }
  return null;
});

const currentInputVolumeItem = computed(() => currentVolumeEntry.value?.item ?? null);
const currentInputVolumeGroupId = computed(() => currentVolumeEntry.value?.groupId ?? null);

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

const canTogglePreview = computed(() => !!recordBlob.value && !recording.value && !saving.value);
const canSaveRecording = computed(() => !!recordBlob.value && !recording.value && !saving.value);

const bigButtonIcon = computed(() => (recording.value ? 'mdi-stop' : 'mdi-record-circle'));
const bigButtonLabel = computed(() => (recording.value ? 'Zastavit záznam' : 'Spustit nový záznam'));
const bigButtonVariant = computed(() => (recording.value ? 'danger' : 'primary'));

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

const toggleRecording = async () => {
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
  if (!recordBlob.value || saving.value) {
    toast.warning('Nejprve vytvořte záznam.');
    return;
  }

  const {reveal, onConfirm} = createConfirmDialog(RecordSaveDialog, {});
  reveal();

  onConfirm(async (data) => {
    const {name, subtype} = data;
    const blob = recordBlob.value;
    if (!blob) {
      toast.error('Chybí data nahrávky');
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

    saving.value = true;
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
  await Promise.all([loadSources(), loadVolumeLevels()]);
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
          class="w-full flex flex-col items-start gap-2 p-5 text-left border border-primary rounded-lg shadow-lg bg-primary text-white hover:bg-primary/90 transition"
          @click="toggleRecording">
        <template #default>
          <div class="flex w-full justify-between items-center">
            <div class="space-y-1">
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
