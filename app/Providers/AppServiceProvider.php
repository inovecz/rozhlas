<?php

namespace App\Providers;

use App\Events\JsvvMessageReceived;
use App\Listeners\CoordinateControlChannel;
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

            return new ControlChannelTransport(
                $channelConfig['endpoint'] ?? 'unix:///var/run/jsvv-control.sock',
                (int) ($channelConfig['timeout_ms'] ?? 500),
                (int) ($channelConfig['retry_attempts'] ?? 3),
                (int) ($channelConfig['handshake_timeout_ms'] ?? 150),
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
}
