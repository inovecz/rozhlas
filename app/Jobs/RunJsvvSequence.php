<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\JsvvSequenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunJsvvSequence implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(JsvvSequenceService $sequenceService): void
    {
        $sequenceService->processQueuedSequences();
    }
}
