<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\File;
use App\Jobs\ConvertToMp3;
use Illuminate\Console\Command;

class ConvertAudioFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:convert-audio-files';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $audioFiles = File::where('mime_type', 'audio/webm')->get();
        foreach ($audioFiles as $audioFile) {
            ConvertToMp3::dispatch($audioFile);
        }
    }
}
