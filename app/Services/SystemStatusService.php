<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\JsvvSequence;
use App\Services\DeviceDiagnosticsService;
use App\Services\StreamOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class SystemStatusService extends Service
{
    private const DAEMON_DEFINITIONS = [
        [
            'name' => 'control_channel_worker',
            'label' => 'Řídicí kanál',
            'category' => 'daemon',
            'pid_file' => 'control_channel_worker.pid',
        ],
        [
            'name' => 'gsm_listener',
            'label' => 'GSM listener',
            'category' => 'daemon',
            'pid_file' => 'gsm_listener.pid',
            'optional_env' => 'GSM_SERIAL_PORT',
        ],
        [
            'name' => 'control_tab_listener',
            'label' => 'Control Tab listener',
            'category' => 'daemon',
            'pid_file' => 'control_tab_listener.pid',
            'optional_env' => 'CONTROL_TAB_SERIAL_PORT',
        ],
        [
            'name' => 'jsvv_listener',
            'label' => 'JSVV listener',
            'category' => 'daemon',
            'pid_file' => 'jsvv_listener.pid',
            'optional_env' => 'JSVV_PORT',
        ],
        [
            'name' => 'backend_server',
            'label' => 'Laravel backend',
            'category' => 'service',
            'pid_file' => 'backend.pid',
            'base_path' => 'run',
        ],
        [
            'name' => 'vite_dev',
            'label' => 'Vite dev server',
            'category' => 'service',
            'pid_file' => 'vite-dev.pid',
            'base_path' => 'run',
        ],
        [
            'name' => 'alarm_monitor',
            'label' => 'Monitor alarm bufferu',
            'category' => 'service',
            'pid_file' => 'alarm-monitor.pid',
            'base_path' => 'run',
        ],
        [
            'name' => 'queue_worker',
            'label' => 'Queue worker',
            'category' => 'service',
            'pid_file' => 'queue-worker.pid',
            'base_path' => 'run',
        ],
        [
            'name' => 'two_way_monitor',
            'label' => 'Monitor obousměrné komunikace',
            'category' => 'service',
            'pid_file' => 'two-way-monitor.pid',
            'base_path' => 'run',
        ],
    ];

    public function overview(): array
    {
        $daemons = $this->collectProcessStatuses();

        $jobsPending = $this->safeTableCount('jobs');
        $jobsFailed = $this->safeTableCount('failed_jobs');

        $sequenceCounts = JsvvSequence::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $jsvvCompletedToday = JsvvSequence::query()
            ->where('status', 'completed')
            ->whereDate('created_at', now()->toDateString())
            ->count();

        $lastCompleted = JsvvSequence::query()
            ->where('status', 'completed')
            ->latest('completed_at')
            ->first();

        $orchestrator = new StreamOrchestrator();
        $broadcastDetails = $orchestrator->getStatusDetails();
        $currentSession = $this->resolveCurrentSession($broadcastDetails['session'] ?? null);

        $diagnosticsService = new DeviceDiagnosticsService();
        $diagnostics = $diagnosticsService->overview(refresh: true, triggerNotifications: true);

        return [
            'timestamp' => now()->toIso8601String(),
            'daemons' => $daemons,
            'queues' => [
                'pending_jobs' => $jobsPending,
                'failed_jobs' => $jobsFailed,
                'jsvv' => [
                    'planned' => (int) ($sequenceCounts['planned'] ?? 0),
                    'queued' => (int) ($sequenceCounts['queued'] ?? 0),
                    'running' => (int) ($sequenceCounts['running'] ?? 0),
                    'completed_today' => $jsvvCompletedToday,
                    'last_completed' => $lastCompleted?->toArray(),
                ],
            ],
            'broadcast' => $currentSession,
            'broadcast_previous' => $currentSession ? null : $this->resolveLastCompletedSession(),
            'broadcast_raw' => $broadcastDetails,
            'diagnostics' => $diagnostics,
        ];
    }

    private function resolveCurrentSession(?array $session): ?array
    {
        if (!$session || ($session['status'] ?? null) !== 'running') {
            return null;
        }
        return $session;
    }

    private function resolveLastCompletedSession(): ?array
    {
        $last = DB::table('broadcast_sessions')
            ->orderByDesc('created_at')
            ->first();

        if (!$last) {
            return null;
        }

        return (array) $last;
    }

    private function collectProcessStatuses(): array
    {
        $items = [];
        foreach (self::DAEMON_DEFINITIONS as $definition) {
            $basePath = $definition['base_path'] ?? 'daemons';
            $pidFile = storage_path(sprintf('logs/%s/%s', $basePath, $definition['pid_file']));

            $status = [
                'name' => $definition['name'],
                'label' => $definition['label'],
                'category' => $definition['category'],
                'pid_file' => $pidFile,
            ];

            $optionalEnv = $definition['optional_env'] ?? null;
            if ($optionalEnv !== null && empty(env($optionalEnv))) {
                $status['status'] = 'disabled';
                $items[] = $status;
                continue;
            }

            if (!File::exists($pidFile)) {
                $status['status'] = 'stopped';
                $items[] = $status;
                continue;
            }

            $pid = (int) trim((string) File::get($pidFile));
            $status['pid'] = $pid > 0 ? $pid : null;

            if ($pid > 0 && $this->isProcessRunning($pid)) {
                $status['status'] = 'running';
            } else {
                $status['status'] = 'not_running';
            }

            $items[] = $status;
        }

        return $items;
    }

    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $process = Process::fromShellCommandline(sprintf('tasklist /FI "PID eq %d"', $pid));
            $process->run();

            return str_contains($process->getOutput(), (string) $pid);
        }

        $process = Process::fromShellCommandline(sprintf('ps -p %d', $pid));
        $process->run();

        return $process->isSuccessful();
    }

    private function safeTableCount(string $table): int
    {
        try {
            return DB::table($table)->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
