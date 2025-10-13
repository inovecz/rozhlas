<script setup>

import {ref} from "vue";
import PageContent from "../../components/custom/PageContent.vue";
import Box from "../../components/custom/Box.vue";
import LiveBroadcastService from "../../services/LiveBroadcastService.js";

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

    LiveBroadcastService.startBroadcast().then(response => {

    }).catch(error => {
      console.error(error);
      stopRecording();
    });
  }
}

const stopRecording = () => {
  recording.value = false;
  microphone.disconnect();
  aCtx.close();

  LiveBroadcastService.stopBroadcast().then(response => {

  }).catch(error => {
    console.error(error);
  });
}

</script>

<template>
  <PageContent label="Živé vysílání">
    <Box label="">
      <button id="record" @click="startStream" class="py-4 px-5 text-2xl text-gray-50 border w-full rounded tracking-wide"
              :class="{'bg-rose-500 hover:bg-rose-600 border-rose-700': recording, 'bg-emerald-500 hover:bg-emerald-600 border-emerald-700': !recording}">Zahájit vysílání
      </button>
      <video autoplay ref="broadcaster" class="hidden"></video>
    </Box>
  </PageContent>
</template>