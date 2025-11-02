<?php

use App\Console\Commands\Alarm\PollCommand as AlarmPollCommand;
use App\Console\Commands\Audio\ApplyPresetCommand;
use App\Console\Commands\Audio\SetInputCommand;
use App\Console\Commands\Audio\SetOutputCommand;
use App\Console\Commands\Audio\SetVolumeCommand;
use App\Console\Commands\ControlTab\TestSendCommand as ControlTabTestSendCommand;
use App\Console\Commands\Gsm\TestSendCommand as GsmTestSendCommand;
use App\Console\Commands\InstallApplication;
use App\Console\Commands\Jsvv\HandleCommand as JsvvHandleCommand;
use App\Console\Commands\Jsvv\TestSendCommand as JsvvTestSendCommand;
use App\Console\Commands\Modbus\TestReadCommand as ModbusTestReadCommand;
use App\Console\Commands\Modbus\TestWriteCommand as ModbusTestWriteCommand;
use App\Console\Commands\Port\ExpectCommand as PortExpectCommand;
use App\Console\Commands\Port\SendCommand as PortSendCommand;
use App\Console\Commands\Rf\ReadStatusCommand as RfReadStatusCommand;
use App\Console\Commands\Rf\RxPlayLastCommand as RfRxPlayLastCommand;
use App\Console\Commands\Rf\TxStartCommand as RfTxStartCommand;
use App\Console\Commands\Rf\TxStopCommand as RfTxStopCommand;
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
        RfTxStartCommand::class,
        RfTxStopCommand::class,
        RfRxPlayLastCommand::class,
        RfReadStatusCommand::class,
        JsvvHandleCommand::class,
        JsvvTestSendCommand::class,
        AlarmPollCommand::class,
        PortSendCommand::class,
        PortExpectCommand::class,
        ControlTabTestSendCommand::class,
        GsmTestSendCommand::class,
        ModbusTestReadCommand::class,
        ModbusTestWriteCommand::class,
    ])
    ->create();
