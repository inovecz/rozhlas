<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('streaming-channel.{streamId}', function ($user) {
    return ['id' => $user->id, 'name' => $user->name];
});

Broadcast::channel('stream-signal-channel.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
