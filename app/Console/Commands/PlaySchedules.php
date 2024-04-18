<?php

declare(strict_types=1);

namespace App\Console\Commands;

use FFMpeg\FFMpeg;
use App\Models\Schedule;
use FFMpeg\Format\Audio\Mp3;
use Illuminate\Console\Command;

class PlaySchedules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:play-schedules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        //$broadcast = Schedule::where('scheduled_at', now()->setSecond(0))->first();
        $broadcast = Schedule::find(6);
        if ($broadcast) {
            $ffmpeg = FFMpeg::create();
            $formatMp3 = new Mp3();
            if ($intro = $broadcast->intro) {
                $audio = $ffmpeg->open(storage_path('app/'.$intro->getStoragePath()));
                //$formatMp3->on('progress', $this->showTranscodeProgress());
                $mp3Path = str($intro->getStoragePath())->beforeLast('.')->append('.mp3');
                $audio->save($formatMp3, storage_path('app/'.$mp3Path));
                exec('afplay "'.storage_path('app/'.$mp3Path).'"');
            }
            if ($opening = $broadcast->opening) {
                exec('afplay "'.storage_path('app/'.$opening->getStoragePath()).'"');
            }
            if ($commons = $broadcast->commons) {
                foreach ($commons as $common) {
                    exec('afplay "'.storage_path('app/'.$common->getStoragePath()).'"');
                }
            }
            if ($closing = $broadcast->closing) {
                exec('afplay "'.storage_path('app/'.$closing->getStoragePath()).'"');
            }
            if ($outro = $broadcast->outro) {
                exec('afplay "'.storage_path('app/'.$outro->getStoragePath()).'"');
            }
        }
    }
}
