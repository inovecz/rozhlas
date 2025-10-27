<?php

namespace App\Providers;

use App\Events\JsvvMessageReceived;
use App\Listeners\CoordinateControlChannel;
use App\Services\ControlChannelProcessManager;
use App\Services\ControlChannelTransport;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ControlChannelTransport::class, function ($app): ControlChannelTransport {
            /** @var Repository $config */
            $config = $app->make(Repository::class);
            $channelConfig = $config->get('control_channel', []);

            $endpoint = $this->normaliseEndpoint($channelConfig['endpoint'] ?? 'unix:///var/run/jsvv-control.sock');

            $processManager = new ControlChannelProcessManager(
                $endpoint,
                $channelConfig['worker_python'] ?? env('CONTROL_CHANNEL_WORKER_PYTHON', env('PYTHON_BINARY', 'python3')),
                $channelConfig['worker_script'] ?? base_path('python-client/daemons/control_channel_worker.py'),
                base_path(),
                $channelConfig['worker_log'] ?? storage_path('logs/daemons/control_channel_worker.log'),
                (int) ($channelConfig['startup_timeout_ms'] ?? 3000),
                $channelConfig['worker_env'] ?? [],
                filter_var($channelConfig['auto_start'] ?? true, FILTER_VALIDATE_BOOLEAN)
            );

            return new ControlChannelTransport(
                $endpoint,
                (int) ($channelConfig['timeout_ms'] ?? 500),
                (int) ($channelConfig['retry_attempts'] ?? 3),
                (int) ($channelConfig['handshake_timeout_ms'] ?? 150),
                $processManager,
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(
            JsvvMessageReceived::class,
            CoordinateControlChannel::class,
        );

        DB::connection()->getPdo()->sqliteCreateCollation('UTF8', function ($a, $b) {
            $collator = new \Collator('cs_CZ.UTF-8');
            $collator->setAttribute(\Collator::CASE_LEVEL, \Collator::ON);
            return $collator->compare($a, $b);
        });
    }

    private function normaliseEndpoint(string $endpoint): string
    {
        if (!str_starts_with($endpoint, 'unix://')) {
            return $endpoint;
        }

        $path = substr($endpoint, strlen('unix://'));
        if ($path === '') {
            return $endpoint;
        }

        if ($path[0] === DIRECTORY_SEPARATOR) {
            return $endpoint;
        }

        return 'unix://' . base_path($path);
    }
}
