<script setup>
import {computed, ref} from "vue";
import {createConfirmDialog} from "vuejs-confirm-dialog";
import RecordSaveDialog from "../../components/modals/RecordSaveDialog.vue";
import {isBase64} from "../../helper.js";
import {useToast} from "vue-toastification";

const selectedCodec = ref([])
const echoCancellation = ref(false)
const recording = ref(false)
const playing = ref(false)
const recordedBlobs = ref();
const recordButton = ref();
const playButton = ref();
const saveButton = ref();
const audioPlayer = ref();

const toast = useToast();

const uploadedFile = ref({content: null, name: null, type: null, extension: null, duration: null});

let recordingStartedTime;
let recordingStoppedTime;

const containers = [
  {'container': 'webm', 'label': 'WebM', 'extension': 'webm'},
  {'container': 'ogg', 'label': 'Ogg', 'extension': 'ogg'},
  {'container': 'mp4', 'label': 'Mp4', 'extension': 'mp4'},
  {'container': 'x-matroska', 'label': 'X-Matroska', 'extension': 'mkv'},
  {'container': '3gpp', 'label': '3GPP', 'extension': '3gp'},
  {'container': '3gpp2', 'label': '3GPP2', 'extension': '3g2'},
  {'container': '3gp2', 'label': '3gp2', 'extension': '3g2'},
  {'container': 'quicktime', 'label': 'Quicktime', 'extension': 'mov'},
  {'container': 'mpeg', 'label': 'MPEG', 'extension': 'mpg'},
  {'container': 'aac', 'label': 'AAC', 'extension': 'aac'},
  {'container': 'flac', 'label': 'FLAC', 'extension': 'flac'},
  {'container': 'wav', 'label': 'WAV', 'extension': 'wav'},
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
  uploadedFile.value = {content: null, name: null, type: null, extension: null};
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

// <editor-fold desc="Region: UPLOAD FILE">
function uploadFile(event) {
  const file = event.target.files[0];
  if (file) {
    // filename = file.name without extension
    const filename = file.name.split('.').slice(0, -1).join('.');
    uploadedFile.value = {content: null, name: filename, type: file.type, extension: file.name.split('.').pop(), duration: null};
    // save content of uploaded file to recordedBlobs
    const reader = new FileReader();
    reader.readAsDataURL(file)
    reader.onload = function (e) {
      const audioBase64 = e.target.result;
      recordedBlobs.value = [audioBase64];
      uploadedFile.value.content = audioBase64;
      const audio = document.createElement('audio');
      audio.src = URL.createObjectURL(file);
      audio.addEventListener('loadedmetadata', function () {
        uploadedFile.value.duration = audio.duration;
      });
    }
  } else {
    uploadedFile.value = {content: null, name: null, extension: null};
  }
}

// </editor-fold desc="Region: UPLOAD FILE">

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
    audioPlayer.value.srcObject = null;

    if (isBase64(recordedBlobs.value[0])) {
      audioPlayer.value.src = recordedBlobs.value[0];
    } else {
      const source = new Blob(recordedBlobs.value, {type: recordedBlobs.value[0].type});
      audioPlayer.value.src = window.URL.createObjectURL(source);
    }
    audioPlayer.value.controls = false;
    audioPlayer.value.setSinkId(audioOutputDevice.id);
    audioPlayer.value.play();
  }
}

// </editor-fold desc="Region: PLAY / PAUSE">

// <editor-fold desc="Region: SAVE RECORD">
function saveRecord(id) {
  const {reveal, onConfirm, onCancel} = createConfirmDialog(RecordSaveDialog, {
    uploadedFile: uploadedFile
  });
  reveal();
  onConfirm((data) => {
    const {name, subtype} = data;

    let mimeType;
    let extension;
    let blob;
    let duration;

    if (uploadedFile.value.content) {
      mimeType = uploadedFile.value.type;
      extension = uploadedFile.value.extension;
      const base64 = uploadedFile.value.content;
      const byteCharacters = atob(base64.split(',')[1]);
      const byteNumbers = new Array(byteCharacters.length);
      for (let i = 0; i < byteCharacters.length; i++) {
        byteNumbers[i] = byteCharacters.charCodeAt(i);
      }
      const byteArray = new Uint8Array(byteNumbers);
      blob = new Blob([byteArray], {type: mimeType});

      duration = Math.round(uploadedFile.value.duration);
    } else {
      mimeType = selectedCodec.value.split(';', 1)[0];
      const container = selectedCodec.value.split(';', 1)[0].split('/')[1];
      extension = containers.find(c => c.container === container).extension;
      blob = new Blob(recordedBlobs.value, {type: mimeType});
      duration = Math.round((recordingStoppedTime.getTime() - recordingStartedTime.getTime()) / 1000);
    }

    const formData = new FormData();
    formData.append('file', blob, 'recording' + extension);
    formData.append('type', 'RECORDING');
    formData.append('subtype', subtype);
    formData.append('name', name);
    formData.append('extension', extension);
    formData.append('metadata[duration]', duration);


    http.post('/upload', formData, {
      headers: {
        'Content-Type': 'multipart/form-data'
      }
    }).then(response => {
      emitter.emit('recordSaved');
      uploadedFile.value = {content: null, name: null, type: null, extension: null, duration: null};
      recordedBlobs.value = undefined;
      toast.success('Záznam byl úspěšně uložen');
    }).catch(error => {
      toast.error('Nepodařilo se uložit záznam');
    });
  });
}

// </editor-fold desc="Region: SAVE RECORD">
</script>

<template>
  <div class="component-box">
    <div class="text-xl text-primary mb-4 mt-3 px-1">
      Nahrání záznamu
    </div>
    <div class="flex justify-between gap-2">
      <div class="flex gap-2 flex-shrink">
        <button ref="recordButton" @click="record()" class="btn btn-sm" :class="recording ? 'btn-error' : 'btn-success'"><span class="mdi mdi-record text-rose-500"></span>Nový záznam</button>
        <div>
          <label for="uploadFile" class="btn btn-sm join-item">{{ uploadedFile.name ?? 'Nahrát soubor' }}</label>
          <input type="file" title="aaaa" name="uploadfile" accept=".mp3,audio/*" id="uploadFile" class="input input-sm hidden input-bordered w-full max-w-xs join-item" @change="uploadFile"/>
        </div>
      </div>
      <div class="flex gap-2">
        <button ref="playButton" @click="playPauseRecorded()" class="btn btn-sm btn-primary" :disabled="(recording || recordedBlobs === undefined)"><span class="mdi mdi-play"></span>Přehrát</button>
        <button ref="saveButton" @click="saveRecord()" class="btn btn-sm btn-primary" :disabled="(recording || recordedBlobs === undefined)"><span class="mdi mdi-content-save"></span>Uložit</button>
      </div>
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