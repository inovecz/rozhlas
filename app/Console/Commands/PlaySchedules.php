<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Schedule;
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
        $broadcast = Schedule::where('scheduled_at', now()->setSecond(0))->first();
        if ($broadcast) {
            if ($intro = $broadcast->intro) {
                exec('afplay "'.storage_path('app/'.$intro->getStoragePath()).'"');
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
            $broadcast->update(['processed_at' => now()]);
        }
    }
}
