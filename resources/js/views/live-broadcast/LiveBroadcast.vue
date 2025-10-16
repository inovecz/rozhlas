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

const systemFileInput = ref(null);

const toast = useToast();

const sources = ref([]);
const locations = ref([]);
const status = ref({session: null, status: null, device: null});
const loading = ref(false);
const fmInfo = ref(null);

const form = reactive({
  source: '',
  routeText: '',
  selectedLocations: [],
  note: '',
  playlistItems: [],
  uploadedFile: null,
  systemAudioFile: null,
  fmFrequency: ''
});

const isStreaming = computed(() => status.value?.session?.status === 'running');
const showPlaylistControls = computed(() => form.source === 'recorded_playlist');
const showUploadedFileControl = computed(() => form.source === 'uploaded_file');
const showFmInfo = computed(() => form.source === 'fm_radio');
const showSystemAudioControls = computed(() => form.source === 'system_audio');

onMounted(async () => {
  await Promise.all([loadSources(), loadLocations(), loadStatus()]);
});

watch(() => form.source, async (newSource) => {
  if (newSource === 'fm_radio') {
    await loadFmFrequency();
  }
  if (newSource === 'recorded_playlist' && form.playlistItems.length === 0) {
    await nextTick();
    pickPlaylist();
  }
  if (newSource === 'uploaded_file' && !form.uploadedFile) {
    await nextTick();
    pickUploadedFile();
  }
  if (newSource === 'system_audio' && !form.systemAudioFile) {
    await nextTick();
    triggerSystemFilePicker();
  }
  if (newSource !== 'system_audio') {
    form.systemAudioFile = null;
  }
});

const loadSources = async () => {
  try {
    sources.value = await LiveBroadcastService.getSources();
    if (!form.source && sources.value.length > 0) {
      form.source = sources.value[0].id;
    }
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst dostupné zdroje');
  }
};

const loadLocations = async () => {
  try {
    const response = await LocationService.getAllLocationGroups();
    locations.value = Array.isArray(response) ? response : (response?.data ?? []);
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst lokality');
  }
};

const loadStatus = async () => {
  try {
    status.value = await LiveBroadcastService.getStatus();
    const session = status.value?.session ?? {};
    if (Array.isArray(session.route)) {
      form.routeText = session.route.join(', ');
    }
    if (Array.isArray(session.locations ?? session.zones)) {
      form.selectedLocations = (session.locations ?? session.zones).map(value => String(value));
    }
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se načíst stav vysílání');
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

const startStream = async () => {
  loading.value = true;
  try {
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
    if (showUploadedFileControl.value && form.uploadedFile) {
      options.uploadedFileId = form.uploadedFile.id;
    }
    if (showSystemAudioControls.value && form.systemAudioFile) {
      options.systemFileId = form.systemAudioFile.id;
    }
    if (showFmInfo.value && form.fmFrequency) {
      options.frequency = form.fmFrequency;
    }

    if (showSystemAudioControls.value && !form.systemAudioFile) {
      toast.warning('Vyberte prosím soubor pro přehrávání z počítače.');
      loading.value = false;
      return;
    }

    const payload = {
      source: form.source || 'microphone',
      route: parseNumericList(form.routeText),
      locations: form.selectedLocations.map(value => Number(value)).filter(Number.isFinite),
      options,
    };
    await LiveBroadcastService.startBroadcast(payload);
    toast.success('Vysílání bylo spuštěno');
    await loadStatus();
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se spustit vysílání');
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

const pickUploadedFile = () => {
  const {reveal, onConfirm} = createConfirmDialog(RecordSelectDialog, {
    title: 'Vyberte soubor',
    typeFilter: 'FILE',
    multiple: false
  });
  reveal();
  onConfirm((selection) => {
    if (selection) {
      form.uploadedFile = selection;
    }
  });
};

const clearUploadedFile = () => {
  form.uploadedFile = null;
};

const triggerSystemFilePicker = () => {
  systemFileInput.value?.click();
};

const handleSystemFileChange = async (event) => {
  const file = event.target.files?.[0];
  if (!file) {
    return;
  }

  const formData = new FormData();
  const fileName = file.name.replace(/\.[^/.]+$/, '');
  const extensionMatch = file.name.match(/\.([^.]+)$/);
  const extension = extensionMatch ? extensionMatch[1] : '';

  formData.append('file', file);
  formData.append('type', 'COMMON');
  formData.append('name', fileName || 'system-audio');
  if (extension) {
    formData.append('extension', extension);
  }

  try {
    const response = await window.http.post('/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    const data = response.data?.data ?? response.data;
    form.systemAudioFile = data;
    toast.success('Soubor byl nahrán');
  } catch (error) {
    console.error(error);
    toast.error('Nepodařilo se nahrát soubor');
  } finally {
    event.target.value = '';
  }
};

const clearSystemAudioFile = () => {
  form.systemAudioFile = null;
};
</script>

<template>
  <PageContent label="Živé vysílání">
    <div class="grid gap-6 md:grid-cols-2">
      <Box label="Ovládání">
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Zdroj signálu</label>
            <select v-model="form.source" class="form-select w-full">
              <option v-for="source in sources" :key="source.id" :value="source.id">
                {{ source.label }}
              </option>
            </select>
          </div>

          <Input v-model="form.routeText" label="Route (čísla oddělená čárkou)" placeholder="např.: 1,2,3"/>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Lokality</label>
            <select v-model="form.selectedLocations" multiple class="form-select w-full h-32">
              <option v-for="location in locations" :key="location.id" :value="String(location.id)">
                {{ location.name }}
              </option>
            </select>
          </div>

          <Textarea v-model="form.note" label="Poznámka" rows="2" placeholder="Nepovinné doplňující údaje"/>

          <div v-if="showPlaylistControls" class="space-y-2">
            <div class="flex justify-between items-center">
              <span class="text-sm font-medium text-gray-700">Seznam nahrávek</span>
              <Button size="xs" icon="mdi-playlist-plus" @click="pickPlaylist">Vybrat nahrávky</Button>
            </div>
            <div v-if="form.playlistItems.length === 0" class="text-xs text-gray-500">Zatím nebyly vybrány žádné nahrávky.</div>
            <ul v-else class="space-y-2 text-xs">
              <li v-for="(item, index) in form.playlistItems" :key="item.id" class="flex justify-between items-center bg-gray-100 px-2 py-1 rounded">
                <span>{{ item.name ?? item.title ?? item.original_name ?? ('ID ' + item.id) }}</span>
                <button class="text-red-500" @click="removePlaylistItem(index)"><span class="mdi mdi-close"></span></button>
              </li>
            </ul>
          </div>

          <div v-if="showUploadedFileControl" class="space-y-2">
            <div class="flex justify-between items-center">
              <span class="text-sm font-medium text-gray-700">Nahraný soubor</span>
              <Button size="xs" icon="mdi-file-upload" @click="pickUploadedFile">Vybrat soubor</Button>
            </div>
            <div v-if="!form.uploadedFile" class="text-xs text-gray-500">Není vybrán žádný soubor.</div>
            <div v-else class="flex justify-between items-center bg-gray-100 px-2 py-1 rounded text-xs">
              <span>{{ form.uploadedFile.name ?? form.uploadedFile.original_name ?? ('ID ' + form.uploadedFile.id) }}</span>
              <button class="text-red-500" @click="clearUploadedFile"><span class="mdi mdi-close"></span></button>
            </div>
          </div>

          <div v-if="showSystemAudioControls" class="space-y-3">
            <div class="space-y-2 text-xs text-gray-600 bg-blue-50 border border-blue-200 rounded p-3">
              <p class="font-medium text-blue-700">Tip: Soubor z počítače</p>
              <p>
                Ujistěte se, že máte nakonfigurovanou virtuální zvukovou kartu nebo mix
                (např. Loopback / VB-Audio) a že je směrována do vstupu, který ústředna očekává.
                Případně nahrajte soubor, který chcete vysílat.
              </p>
            </div>
            <div class="space-y-2">
              <div class="flex justify-between items-center">
                <span class="text-sm font-medium text-gray-700">Vybraný soubor</span>
                <div class="flex gap-2">
                  <input ref="systemFileInput" type="file" class="hidden" accept="audio/*,.mp3,.wav,.ogg" @change="handleSystemFileChange"/>
                  <Button size="xs" icon="mdi-file-upload" @click="triggerSystemFilePicker">Zvolit soubor</Button>
                  <Button size="xs" variant="ghost" icon="mdi-delete" @click="clearSystemAudioFile" :disabled="!form.systemAudioFile">Odstranit</Button>
                </div>
              </div>
              <div v-if="form.systemAudioFile" class="bg-gray-100 px-2 py-1 rounded text-xs">
                {{ form.systemAudioFile.name ?? form.systemAudioFile.original_name ?? ('ID ' + form.systemAudioFile.id) }}
              </div>
              <div v-else class="text-xs text-gray-500">Zatím není vybrán žádný soubor.</div>
            </div>
          </div>

          <div v-if="showFmInfo" class="space-y-2 text-sm">
            <div><strong>Frekvence FM rádia:</strong> {{ form.fmFrequency || 'Neznámá' }}</div>
            <Button size="xs" icon="mdi-refresh" @click="loadFmFrequency">Aktualizovat frekvenci</Button>
          </div>

          <div class="flex gap-3">
            <Button :disabled="loading || isStreaming" class="flex-1" icon="mdi-play" label="Spustit vysílání" @click="startStream"/>
            <Button :disabled="loading || !isStreaming" class="flex-1" icon="mdi-stop" variant="danger" label="Zastavit vysílání" @click="stopStream"/>
          </div>
          <Button variant="secondary" icon="mdi-refresh" :disabled="loading" @click="loadStatus">Aktualizovat stav</Button>
        </div>
      </Box>

      <Box label="Aktuální stav">
        <div v-if="status.session" class="space-y-2 text-sm">
          <div><strong>ID relace:</strong> {{ status.session.id }}</div>
          <div><strong>Zdroj:</strong> {{ status.session.source }}</div>
          <div><strong>Stav:</strong> {{ status.session.status }}</div>
          <div><strong>Začátek:</strong> {{ status.session.startedAt ?? status.session.started_at }}</div>
          <div v-if="status.session.stoppedAt || status.session.stopped_at"><strong>Konec:</strong> {{ status.session.stoppedAt ?? status.session.stopped_at }}</div>
          <div><strong>Route:</strong> {{ (status.session.route || []).join(', ') || '-' }}</div>
          <div><strong>Lokality:</strong> {{ (status.session.locations || status.session.zones || []).join(', ') || '-' }}</div>
        </div>
        <div v-else class="text-gray-500 text-sm">Žádná relace není aktivní.</div>

        <div class="mt-4">
          <details>
            <summary class="cursor-pointer text-sm text-gray-600">Raw status data</summary>
            <pre class="mt-2 bg-gray-100 p-2 rounded text-xs overflow-auto">{{ status }}</pre>
          </details>
        </div>
      </Box>
    </div>
  </PageContent>
</template>
