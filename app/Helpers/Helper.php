<?php

if (!function_exists('audio_duration')) {
    function audio_duration(string $pathToFile): int
    {
        $ffProbe = \FFMpeg\FFProbe::create();
        return (int) round($ffProbe->format($pathToFile)->get('duration'));
    }
}