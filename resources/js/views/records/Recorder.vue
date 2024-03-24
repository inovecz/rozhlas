<script setup>
import {computed, ref} from "vue";

const selectedCodec = ref([])
const echoCancellation = ref(false)
const recording = ref(false)
const playing = ref(false)
const recordedBlobs = ref();
const recordButton = ref();
const playButton = ref();
const saveButton = ref();
const saveModal = ref();
const recordNameInput = ref();
const audioPlayer = ref();

let recordingStartedTime;
let recordingStoppedTime;

const recordName = ref('');
const canSave = computed(() => recordName.value === '');

const containers = [
  {'container': 'webm', 'label': 'WebM', 'extension': '.webm'},
  {'container': 'ogg', 'label': 'Ogg', 'extension': '.ogg'},
  {'container': 'mp4', 'label': 'Mp4', 'extension': '.mp4'},
  {'container': 'x-matroska', 'label': 'X-Matroska', 'extension': '.mkv'},
  {'container': '3gpp', 'label': '3GPP', 'extension': '.3gp'},
  {'container': '3gpp2', 'label': '3GPP2', 'extension': '.3g2'},
  {'container': '3gp2', 'label': '3gp2', 'extension': '.3g2'},
  {'container': 'quicktime', 'label': 'Quicktime', 'extension': '.mov'},
  {'container': 'mpeg', 'label': 'MPEG', 'extension': '.mpg'},
  {'container': 'aac', 'label': 'AAC', 'extension': '.aac'},
  {'container': 'flac', 'label': 'FLAC', 'extension': '.flac'},
  {'container': 'wav', 'label': 'WAV', 'extension': '.wav'},
];

const codecs = [
  {'codec': 'vp9', 'label': 'VP9'},
  {'codec': 'vp8', 'label': 'VP8'},
  {'codec': 'avc1', 'label': 'AVC1'},
  {'codec': 'av1', 'label': 'AV1'},
  {'codec': 'h265', 'label': 'H265'},
  {'codec': 'h.265', 'label': 'H.265'},
  {'codec': 'h264', 'label': 'H264'},
  {'codec': 'h.264', 'label': 'H.264'},
  {'codec': 'opus', 'label': 'Opus'},
  {'codec': 'pcm', 'label': 'PCM (bez komprese)'},
  {'codec': 'aac', 'label': 'AAC'},
  {'codec': 'mpeg', 'label': 'MPEG'},
  {'codec': 'mp4a', 'label': 'Mp4A'},
];

let mediaRecorder;

// <editor-fold desc="Region: COMPUTED">
const supportedCodecs = computed(() => {
  const supportedContainers = containers.map(container => ({
    codec: `audio/${container.container}`,
    label: `${container.label}`
  })).filter(({codec}) => MediaRecorder.isTypeSupported(codec));

  const supportedCodecs = supportedContainers.flatMap(audio => codecs.map(codec => ({
    value: `${audio.codec};codecs=${codec.codec}`,
    label: `${audio.label} - ${codec.label}`
  }))).filter(({value}) => MediaRecorder.isTypeSupported(value));

  selectedCodec.value = supportedCodecs[0].value;

  return supportedCodecs;
});
// </editor-fold desc="Region: COMPUTED">

// <editor-fold desc="Region: RECORDING">
function record() {
  if (recording.value) {
    stopRecording();
  } else {
    startRecordingProcess();
  }
}

async function startRecordingProcess() {
  const selectedAudioInputDevice = computed(() => JSON.parse(localStorage.getItem('audioInputDevice')) ?? 'default');

  const constraints = {
    audio: {
      deviceId: selectedAudioInputDevice.value.id,
      echoCancellation: {exact: echoCancellation.value},
    }
  };
  initRecording(constraints).then(() => {
    startRecording();
  });
}

async function startRecording() {
  const options = {selectedCodec};
  recordedBlobs.value = [];
  try {
    recordingStartedTime = new Date();
    mediaRecorder = new MediaRecorder(window.stream, options);
  } catch (e) {
    console.error('Exception while creating MediaRecorder:', e);
    document.getElementById('error-message').innerHTML = `Exception while creating MediaRecorder: ${e.toString()}`;
    return;
  }

  mediaRecorder.ondataavailable = handleDataAvailable;
  mediaRecorder.start(); // collect 10ms of data
}

function handleDataAvailable(event) {
  if (event.data && event.data.size > 0) {
    recordedBlobs.value.push(event.data);
  }
}

async function initRecording(constraints) {
  try {
    const stream = await navigator.mediaDevices.getUserMedia(constraints);
    handleSuccess(stream);
  } catch (e) {
    console.error('navigator.getUserMedia error:', e);
    document.getElementById('error-message').innerHTML = `navigator.getUserMedia error:${e.toString()}`;
  }
}

function handleSuccess(stream) {
  recording.value = true;
  recordButton.value.innerHTML = '<span class="mdi mdi-record text-rose-500"></span>Zastavit záznam';
  window.stream = stream;
}

async function stopRecording() {
  mediaRecorder.stop();
  recordingStoppedTime = new Date();
  recording.value = false;
  window.stream.getTracks().forEach(track => track.stop());
  recordButton.value.innerHTML = '<span class="mdi mdi-record text-rose-500"></span>Nový záznam';
}

// </editor-fold desc="Region: RECORDING">

// <editor-fold desc="Region: PLAY / PAUSE">
function playPauseRecorded() {
  if (playing.value) {
    playButton.value.innerHTML = '<span class="mdi mdi-play text-emerald-500"></span>Přehrát';
    playing.value = false;
    audioPlayer.value.src = null;
    audioPlayer.value.srcObject = null;
    audioPlayer.value.setSinkId('default');
    audioPlayer.value.pause();
  } else {
    const audioOutputDevice = JSON.parse(localStorage.getItem('audioOutputDevice')) ?? 'default';
    playing.value = true;
    playButton.value.innerHTML = '<span class="mdi mdi-pause text-gray-500"></span>Zastavit';
    const mimeType = selectedCodec.value.split(';', 1)[0];
    const blob = new Blob(recordedBlobs.value, {type: mimeType});
    audioPlayer.value.src = null;
    audioPlayer.value.srcObject = null;
    audioPlayer.value.src = window.URL.createObjectURL(blob);
    audioPlayer.value.controls = false;
    audioPlayer.value.setSinkId(audioOutputDevice.id);
    audioPlayer.value.play();
  }
}

// </editor-fold desc="Region: PLAY / PAUSE">

// <editor-fold desc="Region: SAVE RECORD">
function showSaveModal() {
  recordName.value = 'Nahrávka ' + new Date().toLocaleString('cs-CZ');
  saveModal.value.showModal();
}

async function saveRecord() {
  saveModal.value.close();
  const mimeType = selectedCodec.value.split(';', 1)[0];
  const container = selectedCodec.value.split(';', 1)[0].split('/')[1];
  const extension = containers.find(c => c.container === container).extension;

  const blob = new Blob(recordedBlobs.value, {type: mimeType});
  console.log(recordedBlobs.value);

  const formData = new FormData();
  formData.append('file', blob, 'recording' + extension);
  formData.append('type', 'RECORD');
  formData.append('name', recordName.value);
  formData.append('metadata[duration]', Math.round((recordingStoppedTime.getTime() - recordingStartedTime.getTime()) / 1000));
  http.post('/upload', formData, {
    headers: {
      'Content-Type': 'multipart/form-data'
    }
  }).then(response => {
    emitter.emit('recordSaved');
    recordedBlobs.value = undefined;
    console.log(response);
  }).catch(error => {
    console.log(error);
  });
}

// </editor-fold desc="Region: SAVE RECORD">
</script>

<template>
  <div class="border border-secondary/50 rounded-md px-5 py-2">
    <div class="text-xl text-primary mb-4 mt-3 px-1">
      Nahrání záznamu
    </div>
    <div class="flex justify-between">
      <button ref="recordButton" @click="record()" class="btn btn-sm" :class="recording ? 'btn-error' : 'btn-success'"><span class="mdi mdi-record text-rose-500"></span>Nový záznam</button>
      <div class="flex space-x-2">
        <button ref="playButton" @click="playPauseRecorded()" class="btn btn-sm btn-primary" :disabled="(recording || recordedBlobs === undefined)"><span class="mdi mdi-play"></span>Přehrát</button>
        <button ref="saveButton" @click="showSaveModal()" class="btn btn-sm btn-primary" :disabled="(recording || recordedBlobs === undefined)"><span class="mdi mdi-content-save"></span>Uložit</button>
      </div>
      <dialog ref="saveModal" class="modal">
        <div class="modal-box">
          <form method="dialog">
            <button class="btn btn-sm btn-circle btn-ghost absolute right-2 top-2">✕</button>
          </form>
          <h3 class="font-bold text-lg">Zadejte název pod kterým bude nahrávka uložena</h3>
          <div class="my-3">
            <input ref="recordNameInput" v-model="recordName" type="text" placeholder="Zadejte název nahrávky" class="input input-bordered w-full"/>
          </div>
          <div class="modal-action">
            <button @click="saveRecord()" type="button" class="btn btn-primary" :disabled="canSave"><span class="mdi mdi-content-save"></span>Uložit</button>
          </div>
        </div>
      </dialog>
    </div>
    <div>
      <span id="error-message"></span>
    </div>
    <div>
      <div class="form-control">
        <label class="label cursor-pointer">
          <span class="label-text">Formát záznamu:</span>
          <select v-model="selectedCodec" class="select select-bordered w-full max-w-xs" :disabled="recording">
            <option v-if="supportedCodecs.length === 0" disabled value="">Nenalezen žádný kodek</option>
            <option v-for="codec in supportedCodecs" :value="codec.value">
              {{ codec.label }}
            </option>
          </select>
        </label>
      </div>
      <div class="form-control">
        <label class="label cursor-pointer">
          <span class="label-text">Potlačení ozvěny</span>
          <input v-model="echoCancellation" type="checkbox" checked="checked" class="checkbox" :disabled="recording"/>
        </label>
      </div>
    </div>
    <div>
      <audio ref="audioPlayer" @ended="playPauseRecorded()"></audio>
    </div>
  </div>
</template>