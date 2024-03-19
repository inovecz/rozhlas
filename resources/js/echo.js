import Echo from 'laravel-echo';

import Pusher from 'pusher-js';

window.Pusher = Pusher;

let global = window;

window.Echo = new Echo({
    broadcaster: 'pusher',
    //key: import.meta.env.PUSHER_APP_KEY,
    //cluster: import.meta.env.PUSHER_APP_CLUSTER,
    key: '0b053ad8162384c6ea24',
    cluster: 'eu',
    forceTLS: true,
});
