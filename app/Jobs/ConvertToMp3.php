<?php

declare(strict_types=1);

namespace App\Jobs;

use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use Illuminate\Bus\Queueable;
use App\Models\File as FileModel;
use Illuminate\Support\Facades\File;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class ConvertToMp3 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected const MIME = 'audio/mpeg';
    protected const EXTENSION = 'mp3';

    public function __construct(protected FileModel $recording)
    {
    }

    public function handle(): void
    {
        $ffmpeg = FFMpeg::create();
        $formatMp3 = new Mp3();
        $originalPath = 'app/'.$this->recording->getStoragePath();
        $audio = $ffmpeg->open(storage_path($originalPath));
        $mp3Path = str($originalPath)->beforeLast('.')->append('.'.self::EXTENSION)->toString();
        $audio->save($formatMp3, storage_path($mp3Path));
        $size = filesize(storage_path($mp3Path));
        $this->recording->update([
            'mime_type' => self::MIME,
            'extension' => self::EXTENSION,
            'size' => $size,
        ]);
        File::delete(storage_path($originalPath));
    }
}
