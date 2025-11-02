<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\StartPlannedBroadcast;
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
    protected $description = 'Dispatch planned broadcast jobs for schedules that are due.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $now = now();

        $dueSchedules = Schedule::query()
            ->whereNull('processed_at')
            ->where('scheduled_at', '<=', $now)
            ->orderBy('scheduled_at')
            ->limit(25)
            ->pluck('id');

        foreach ($dueSchedules as $scheduleId) {
            StartPlannedBroadcast::dispatch((int) $scheduleId);
        }
    }
}
