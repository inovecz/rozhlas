<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
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
        DB::connection()->getPdo()->sqliteCreateCollation('UTF8', function ($a, $b) {
            $collator = new \Collator('cs_CZ.UTF-8');
            $collator->setAttribute(\Collator::CASE_LEVEL, \Collator::ON);
            return $collator->compare($a, $b);
        });
    }
}
