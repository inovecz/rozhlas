<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PlayBackendAudio extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:play-backend-audio';

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
        $path = storage_path('app/02 - Piano Sonata No 11 in A Major.mp3');
        $command = 'afplay "'.$path.'"';
        exec($command);
    }
}
