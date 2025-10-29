<?php

use App\Console\Commands\Audio\ApplyPresetCommand;
use App\Console\Commands\Audio\SetInputCommand;
use App\Console\Commands\Audio\SetOutputCommand;
use App\Console\Commands\Audio\SetVolumeCommand;
use App\Console\Commands\InstallApplication;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withCommands([
        ApplyPresetCommand::class,
        InstallApplication::class,
        SetInputCommand::class,
        SetOutputCommand::class,
        SetVolumeCommand::class,
    ])
    ->create();
