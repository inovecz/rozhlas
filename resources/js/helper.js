import {jwtDecode} from "jwt-decode";

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

export const getAudioInputDevices = async () => {
    const mediaDevices = navigator.mediaDevices;
    if (!mediaDevices || typeof mediaDevices.enumerateDevices !== 'function') {
        console.warn("Media device enumeration is not supported in this browser");
        return [];
    }

    let permissionStream = null;
    try {
        if (typeof mediaDevices.getUserMedia === 'function') {
            try {
                permissionStream = await mediaDevices.getUserMedia({audio: true});
            } catch (permissionError) {
                console.warn("Microphone permission not granted; device labels may be limited.", permissionError);
            }
        }

        const devices = await mediaDevices.enumerateDevices();
        return devices
            .filter(device => device.kind === "audioinput")
            .map(({deviceId, label}, index) => ({
                id: deviceId || `input-${index}`,
                label: (label && label.trim()) || `Mikrofon ${index + 1}`,
            }));
    } catch (err) {
        console.error("Error while fetching audio inputs:", err);
        return [];
    } finally {
        if (permissionStream) {
            permissionStream.getTracks().forEach(track => track.stop());
        }
    }
}

export const getAudioOutputDevices = async () => {
    const mediaDevices = navigator.mediaDevices;
    if (!mediaDevices || typeof mediaDevices.enumerateDevices !== 'function') {
        console.warn("Media device enumeration is not supported in this browser");
        return [];
    }

    const enumerateOutputs = async () => {
        const devices = await mediaDevices.enumerateDevices();
        return devices.filter(device => device.kind === "audiooutput");
    };

    const mapOutputs = (outputs) => outputs.map(({deviceId, label}, index) => {
        const trimmedLabel = label && label.trim();
        const isDefault = deviceId === "default";
        return {
            id: deviceId || (isDefault ? "default" : `output-${index}`),
            label: trimmedLabel || (isDefault ? "Výchozí systém" : `Audio výstup ${index + 1}`),
        };
    });

    try {
        let outputs = await enumerateOutputs();
        const hasLabels = outputs.some(device => device.label && device.label.trim().length > 0);

        if (!hasLabels && typeof mediaDevices.getUserMedia === 'function') {
            let permissionStream;
            try {
                permissionStream = await mediaDevices.getUserMedia({audio: true});
                outputs = await enumerateOutputs();
            } catch (permissionError) {
                console.warn("Microphone permission not granted; audio output labels may be limited.", permissionError);
            } finally {
                if (permissionStream) {
                    permissionStream.getTracks().forEach(track => track.stop());
                }
            }
        }

        return mapOutputs(outputs);
    } catch (err) {
        console.error("Error while fetching audio outputs:", err);
        return [];
    }
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

export const isBase64 = (str) => {
    // Regular expression to check if the string is a base64 string
    const base64Regex = /^(data:)?(.*?);(?:.*?),(.*)$/;
    return base64Regex.test(str);
}

export const moveItemUp = (arr, index) => {
    if (index > 0 && index < arr.length) {
        const temp = arr[index - 1];
        arr[index - 1] = arr[index];
        arr[index] = temp;
    }
    return arr;
}

export const moveItemDown = (arr, index) => {
    if (index >= 0 && index < arr.length - 1) {
        const temp = arr[index + 1];
        arr[index + 1] = arr[index];
        arr[index] = temp;
    }
    return arr;
}

export const formatDate = (date, format) => {
    //if (format === 'Y-m-d H:i:s') {
    //    return date.toISOString().slice(0, 19).replace('T', ' ');
    //} else if (format === 'Y-m-d H:i') {
    //    return date.toISOString().slice(0, 16).replace('T', ' ');
    //} else if (format === 'Y-m-d') {
    //    return date.toISOString().slice(0, 10);
    //}

    // Format output string by replacing placeholders with date values. Available placeholders are: Y, m, d, H, i, s
    return format.replace(/Y|m|d|H|i|s/g, (match) => {
        switch (match) {
            case 'Y':
                return date.getFullYear();
            case 'm':
                return String(date.getMonth() + 1).padStart(2, '0');
            case 'd':
                return String(date.getDate()).padStart(2, '0');
            case 'H':
                return String(date.getHours()).padStart(2, '0');
            case 'i':
                return String(date.getMinutes()).padStart(2, '0');
            case 's':
                return String(date.getSeconds()).padStart(2, '0');
            default:
                return match;
        }
    });
}

export const generateRandomString = (length) => {
    const characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < length; i++) {
        result += characters.charAt(Math.floor(Math.random() * characters.length));
    }
    return result;
}

export const getLoggedUsername = () => {
    return jwtDecode(localStorage.getItem('token')).username;
}

export const getLoggedUserId = () => {
    return jwtDecode(localStorage.getItem('token')).user_id;
}