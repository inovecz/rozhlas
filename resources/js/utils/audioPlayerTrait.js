import {ref} from "vue";

export function useAudioPlayer() {
    const playingId = ref(null);
    const recordsCache = [];

    function playRecord(id) {

        if (playingId.value !== null) {
            const playPauseButton = document.getElementById('playPauseButton-' + playingId.value);
            const audioPlayer = document.getElementById('audioPlayer-' + playingId.value);
            playPauseButton.innerHTML = '<span class="mdi mdi-play text-emerald-500 text-xl"></span>';
            audioPlayer.pause();
        }

        if (playingId.value !== id || playingId.value === null) {
            const playPauseButton = document.getElementById('playPauseButton-' + id);
            playPauseButton.innerHTML = '<span class="mdi mdi-loading mdi-spin text-gray-500 text-xl"></span>';
            getRecordRaw(id).then(({rawAudio, mime}) => {
                playingId.value = id;
                const audioOutputDevice = JSON.parse(localStorage.getItem('audioOutputDevice')) ?? 'default';
                const audioBlob = new Blob([rawAudio], {type: mime});

                playPauseButton.innerHTML = '<span class="mdi mdi-pause text-gray-500 text-xl"></span>';
                const audioPlayer = document.getElementById('audioPlayer-' + id);
                audioPlayer.src = URL.createObjectURL(audioBlob);
                audioPlayer.setSinkId(audioOutputDevice.id);
                audioPlayer.play();
            });
        } else {
            playingId.value = null;
        }
    }

    async function getRecordRaw(id) {
        try {
            if (recordsCache[id]) {
                return recordsCache[id];
            } else {
                const response = await http.get(`records/${id}/get-blob`, {responseType: 'arraybuffer'});
                const rawAudioObject = {rawAudio: response.data, mime: response.headers['content-type']};
                recordsCache[id] = rawAudioObject;
                return rawAudioObject;
            }
        } catch (error) {
            throw error;
        }
    }

    return {
        playingId
    };
}