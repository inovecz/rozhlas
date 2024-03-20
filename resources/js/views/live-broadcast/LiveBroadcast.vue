<template>
  <div class="px-5 py-5">
    <h1 class="text-3xl mb-3">Živé vysílání</h1>
    <div class="content">
      <button id="record" @click="startStream" class="py-4 px-5 text-2xl text-gray-50 border w-full rounded tracking-wide"
              :class="{'bg-rose-500 hover:bg-rose-600 border-rose-700': recording, 'bg-emerald-500 hover:bg-emerald-600 border-emerald-700': !recording}">Zahájit vysílání
      </button>
      <!--      <p v-if="isVisibleLink" class="my-5">
              Share the following streaming link: {{ streamLink }}
            </p>-->
      <video autoplay ref="broadcaster"></video>
    </div>
  </div>
</template>

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

    console.log('startRecording');
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
        function () { console.log("Error 003.")}
    );
  }
}

const stopRecording = () => {
  recording.value = false;
  console.log('stopRecording')
  microphone.disconnect();
  aCtx.close();
}

</script>

<!--<script>
import Peer from "simple-peer";
import {basicStore} from "../../store/basicStore.js";

export default {
  name: "Broadcaster",
  props: [
    "auth_user_id",
    "env",
    "turn_url",
    "turn_username",
    "turn_credential",
  ],
  data() {
    return {
      basicStoreInfo: basicStore(),
      isVisibleLink: false,
      streamingPresenceChannel: null,
      streamingUsers: [],
      currentlyContactedUser: null,
      allPeers: {}, // this will hold all dynamically created peers using the 'ID' of users who just joined as keys
    };
  },
  computed: {
    streamId() {
      // you can improve streamId generation code. As long as we include the
      // broadcaster's user id, we are assured of getting unique streamiing link everytime.
      // the current code just generates a fixed streaming link for a particular user.
      return `${this.basicStoreInfo?.loggedUser?.id}12acde2`;
    },
    streamLink() {
      // just a quick fix. can be improved by setting the app_url
      if (this.env === "production") {
        return `https://production-url.cz/streaming/${this.streamId}`;
      } else {
        return `http://rozhlas.lan/streaming/${this.streamId}`;
      }
    },
  },
  methods: {
    async startStream() {
      // microphone and camera permissions
      //const stream = await getPermissions();
      this.$refs.broadcaster.srcObject = await navigator.mediaDevices.getUserMedia({
        audio: true,
      });
      this.initializeStreamingChannel();
      this.initializeSignalAnswerChannel(); // a private channel where the broadcaster listens to incoming signalling answer
      this.isVisibleLink = true;
    },
    peerCreator(stream, user, signalCallback) {
      let peer;
      return {
        create: () => {
          peer = new Peer({
            initiator: true,
            trickle: false,
            stream: stream,
            config: {
              iceServers: [
                {
                  urls: "stun:stun.stunprotocol.org",
                },
                {
                  urls: this.turn_url,
                  username: this.turn_username,
                  credential: this.turn_credential,
                },
              ],
            },
          });
        },
        getPeer: () => peer,
        initEvents: () => {
          peer.on("signal", (data) => {
            // send offer over here.
            signalCallback(data, user);
          });
          peer.on("stream", (stream) => {
            console.log("onStream");
          });
          peer.on("track", (track, stream) => {
            console.log("onTrack");
          });
          peer.on("connect", () => {
            console.log("Broadcaster Peer connected");
          });
          peer.on("close", () => {
            console.log("Broadcaster Peer closed");
          });
          peer.on("error", (err) => {
            console.log("handle error gracefully");
          });
        },
      };
    },
    initializeStreamingChannel() {
      this.streamingPresenceChannel = window.Echo.join(
          `streaming-channel.${this.streamId}`
      );
      this.streamingPresenceChannel.here((users) => {
        this.streamingUsers = users;
      });
      this.streamingPresenceChannel.joining((user) => {
        console.log("New User", user);
        // if this new user is not already on the call, send your stream offer
        const joiningUserIndex = this.streamingUsers.findIndex(
            (data) => data.id === user.id
        );
        if (joiningUserIndex < 0) {
          this.streamingUsers.push(user);
          // A new user just joined the channel so signal that user
          this.currentlyContactedUser = user.id;
          this.$set(
              this.allPeers,
              `${user.id}`,
              this.peerCreator(
                  this.$refs.broadcaster.srcObject,
                  user,
                  this.signalCallback
              )
          );
          // Create Peer
          this.allPeers[user.id].create();
          // Initialize Events
          this.allPeers[user.id].initEvents();
        }
      });
      this.streamingPresenceChannel.leaving((user) => {
        console.log(user.name, "Left");
        // destroy peer
        this.allPeers[user.id].getPeer().destroy();
        // delete peer object
        delete this.allPeers[user.id];
        // if one leaving is the broadcaster set streamingUsers to empty array
        if (user.id === this.auth_user_id) {
          this.streamingUsers = [];
        } else {
          // remove from streamingUsers array
          const leavingUserIndex = this.streamingUsers.findIndex(
              (data) => data.id === user.id
          );
          this.streamingUsers.splice(leavingUserIndex, 1);
        }
      });
    },
    initializeSignalAnswerChannel() {
      window.Echo.private(`stream-signal-channel.${this.auth_user_id}`).listen(
          "StreamAnswer",
          ({data}) => {
            console.log("Signal Answer from private channel");
            if (data.answer.renegotiate) {
              console.log("renegotating");
            }
            if (data.answer.sdp) {
              const updatedSignal = {
                ...data.answer,
                sdp: `${data.answer.sdp}\n`,
              };
              this.allPeers[this.currentlyContactedUser].getPeer().signal(updatedSignal);
            }
          }
      );
    },
    signalCallback(offer, user) {
      axios.post("/stream-offer", {
        broadcaster: this.auth_user_id,
        receiver: user,
        offer,
      }).then((res) => {
        console.log(res);
      }).catch((err) => {
        console.log(err);
      });
    },
  },
};
</script>-->
