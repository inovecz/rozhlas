<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BroadcastSession;
use App\Services\StreamOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class EnforceBroadcastTimeout implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        private readonly string $sessionId,
        private readonly string $source,
        private readonly int $maxSeconds,
        private readonly string $queueName = 'monitoring'
    ) {
        $this->onQueue($this->queueName);
    }

    public function handle(StreamOrchestrator $orchestrator): void
    {
        $cacheKey = $this->cacheKey();
        Cache::forget($cacheKey);

        /** @var BroadcastSession|null $session */
        $session = BroadcastSession::query()->find($this->sessionId);
        if ($session === null || $session->status !== 'running') {
            return;
        }

        if ($session->source !== $this->source) {
            return;
        }

        if ($session->started_at !== null) {
            $elapsed = now()->diffInSeconds($session->started_at, false);
            if ($elapsed < $this->maxSeconds) {
                return;
            }
        }

        $orchestrator->stop('auto_timeout');
    }

    public static function cacheKeyFor(string $sessionId): string
    {
        return sprintf('broadcast:auto_timeout:%s', $sessionId);
    }

    private function cacheKey(): string
    {
        return self::cacheKeyFor($this->sessionId);
    }
}
