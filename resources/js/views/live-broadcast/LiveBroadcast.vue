<script setup>

import {ref} from "vue";

const recording = ref(false);

let aCtx;
let analyser;
let microphone;

const startStream = () => {
  const recordButton = document.getElementById('record');
  if (recordButton.innerText === 'Zahájit vysílání') {
    recordButton.innerText = 'Zastavit vysílání';
    startRecording();
  } else {
    recordButton.innerText = 'Zahájit vysílání';
    stopRecording();
  }
}

const startRecording = () => {
  recording.value = true;
  navigator.getUserMedia = navigator.getUserMedia || navigator.webkitGetUserMedia || navigator.mozGetUserMedia;
  if (navigator.getUserMedia) {

    navigator.getUserMedia(
        {audio: true},
        function (stream) {
          aCtx = new AudioContext();
          analyser = aCtx.createAnalyser();
          microphone = aCtx.createMediaStreamSource(stream);
          const destination = aCtx.destination;
          microphone.connect(destination);
          analyser.connect(aCtx.destination);
        },
        function () { }
    );
  }
}

const stopRecording = () => {
  recording.value = false;
  microphone.disconnect();
  aCtx.close();
}

</script>

<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3 text-primary">Živé vysílání</h1>
    <div class="content flex flex-col space-y-4">
      <div class="component-box">
        <button id="record" @click="startStream" class="py-4 px-5 text-2xl text-gray-50 border w-full rounded tracking-wide"
                :class="{'bg-rose-500 hover:bg-rose-600 border-rose-700': recording, 'bg-emerald-500 hover:bg-emerald-600 border-emerald-700': !recording}">Zahájit vysílání
        </button>
        <video autoplay ref="broadcaster" class="hidden"></video>
      </div>
    </div>
  </div>
</template>