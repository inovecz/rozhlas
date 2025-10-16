<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\JsonResponse;

class LiveBroadcastService extends Service
{
    public function startBroadcast(): array|false
    {
        $output = [];
        $returnVar = 0;

        // Run script in background
        //exec('python3 '.storage_path('scripts/live_broadcast.py').' --start > /dev/null 2>&1 &', $output, $returnVar);

        // Run script and wait for it to finish
        // exec('python3 '.storage_path('scripts/live_broadcast.py').' --start', $output, $returnVar);
        exec('python3 "' . base_path('../bezdratovy-rozhlas-test-modbus/examples.py') . '" start-stream',$output, $returnVar);

        return $output;
        // return $returnVar !== 0 ? false : $output;
    }

    public function stopBroadcast(): array|false
    {
        $output = [];
        $returnVar = 0;

        // Run script in background
        //exec('python3 '.storage_path('scripts/live_broadcast.py').' --stop > /dev/null 2>&1 &', $output, $returnVar);

        // Run script and wait for it to finish
        // exec('python3 '.storage_path('scripts/live_broadcast.py').' --stop', $output, $returnVar);
        exec('python3 "' . base_path('../bezdratovy-rozhlas-test-modbus/examples.py') . '" stop-stream',$output, $returnVar);

        return $returnVar !== 0 ? false : $output;
    }

    public function getResponse(): JsonResponse
    {
        return match ($this->getStatus()) {
            'OK' => $this->setResponseMessage('response.ok'),
            'NOK' => $this->setResponseMessage('response.nok', 400),
            default => $this->notSpecifiedError(),
        };
    }
}
