<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NestStatusService;
use App\Settings\TwoWayCommSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\SignalableCommandInterface;

class PollNestStatus extends Command implements SignalableCommandInterface
{
    protected $signature = 'two-way:nest-status-monitor {--once : Execute a single poll immediately} {--force : Ignore scheduling constraints}' ;

    protected $description = 'Periodically polls nest status via two-way communication settings.';

    private bool $shouldExit = false;

    public function handle(NestStatusService $service): int
    {
        $force = (bool) $this->option('force');
        $once = (bool) $this->option('once') || $force;

        if ($once) {
            $result = $service->poll(true);
            $this->outputSummary($result);
            return self::SUCCESS;
        }

        $this->info('Starting two-way nest status monitor...');

        while (!$this->shouldExit) {
            /** @var TwoWayCommSettings $settings */
            $settings = app(TwoWayCommSettings::class);

            if (!$settings->nestStatusAutoUpdate) {
                $this->line('Two-way nest auto update disabled; sleeping for 60 seconds.');
                $this->sleepSeconds(60);
                continue;
            }

            $nextRun = $service->determineNextRun();
            $now = Carbon::now();
            $diff = $now->diffInSeconds($nextRun, false);

            if ($diff <= 0) {
                $result = $service->poll();
                $this->outputSummary($result);
                $this->sleepSeconds(5);
                continue;
            }

            $this->sleepSeconds(min(300, max(5, $diff)));
        }

        $this->info('Nest status monitor stopped.');
        return self::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return [SIGINT, SIGTERM];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->shouldExit = true;
        return 0;
    }

    private function outputSummary(array $result): void
    {
        $updated = (int) ($result['updated'] ?? 0);
        $failures = $result['failures'] ?? [];
        $failureCount = is_array($failures) ? count($failures) : 0;

        $this->line(sprintf('Nest status poll completed: updated=%d, failures=%d', $updated, $failureCount));

        if ($failureCount > 0) {
            Log::warning('Nest status poll completed with failures.', [
                'failures' => $failures,
            ]);
        }
    }

    private function sleepSeconds(int $seconds): void
    {
        $remaining = $seconds;
        while ($remaining > 0 && !$this->shouldExit) {
            $chunk = min(5, $remaining);
            sleep($chunk);
            $remaining -= $chunk;
        }
    }
}
