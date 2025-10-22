<?php

namespace App\Providers;

use App\Events\JsvvMessageReceived;
use App\Listeners\CoordinateControlChannel;
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
        //
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
