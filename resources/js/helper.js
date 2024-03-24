export const getPermissions = () => {
    // Older browsers might not implement mediaDevices at all, so we set an empty object first
    if (navigator.mediaDevices === undefined) {
        navigator.mediaDevices = {};
    }

    // Some browsers partially implement media devices. We can't just assign an object
    // with getUserMedia as it would overwrite existing properties.
    // Here, we will just add the getUserMedia property if it's missing.
    if (navigator.mediaDevices.getUserMedia === undefined) {
        navigator.mediaDevices.getUserMedia = function (constraints) {
            // First get ahold of the legacy getUserMedia, if present
            const getUserMedia =
                navigator.webkitGetUserMedia || navigator.mozGetUserMedia;

            // Some browsers just don't implement it - return a rejected promise with an error
            // to keep a consistent interface
            if (!getUserMedia) {
                return Promise.reject(
                    new Error("getUserMedia is not implemented in this browser")
                );
            }

            // Otherwise, wrap the call to the old navigator.getUserMedia with a Promise
            return new Promise((resolve, reject) => {
                getUserMedia.call(navigator, constraints, resolve, reject);
            });
        };
    }
    navigator.mediaDevices.getUserMedia =
        navigator.mediaDevices.getUserMedia ||
        navigator.webkitGetUserMedia ||
        navigator.mozGetUserMedia;

    return new Promise((resolve, reject) => {
        navigator.mediaDevices.getUserMedia({video: true, audio: true}).then(stream => {
            resolve(stream);
        }).catch(err => {
            reject(err);
            //   throw new Error(`Unable to fetch stream ${err}`);
        });
    });
};

export const getAudioInputDevices = () => {
    return new Promise((resolve, reject) => {
        navigator.mediaDevices.enumerateDevices().then(devices => {
            resolve(devices.filter(device => device.kind === 'audioinput').map(({deviceId, label}) => ({id: deviceId, label})));
        }).catch(err => {
            reject(err);
        });
    });
}

export const getAudioOutputDevices = () => {
    return new Promise((resolve, reject) => {
        navigator.mediaDevices.enumerateDevices().then(devices => {
            resolve(devices.filter(device => device.kind === 'audiooutput').map(({deviceId, label}) => ({id: deviceId, label})));
        }).catch(err => {
            reject(err);
        });
    });
}

export const dtToTime = (dt) => {
    const date = new Date(dt);
    return date.toLocaleDateString('cs-CZ') + ' ' + date.toLocaleTimeString('cs-CZ');
}

export const durationToTime = (duration) => {
    const hours = Math.floor(duration / 3600);
    const minutes = Math.floor((duration % 3600) / 60);
    const seconds = Math.floor(duration % 60);
    const formattedMinutes = String(minutes).padStart(2, '0');
    const formattedSeconds = String(seconds).padStart(2, '0');
    return `${hours}:${formattedMinutes}:${formattedSeconds}`;
}

// format bytes as human-readable text
export const formatBytes = (bytes, decimals = 2) => {
    if (bytes === 0) return '0 Bytes';

    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

    const i = Math.floor(Math.log(bytes) / Math.log(k));
    const formattedBytes = (bytes / Math.pow(k, i)).toFixed(decimals);

    return `${formattedBytes} ${sizes[i]}`;
}