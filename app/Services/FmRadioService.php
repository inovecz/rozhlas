<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\PythonClient;

class FmRadioService extends Service
{
    public function __construct(private readonly PythonClient $client = new PythonClient())
    {
        parent::__construct();
    }

    public function getFrequency(): array
    {
        $response = $this->client->readFrequency();
        $data = $response['json']['data'] ?? $response['json'] ?? [];

        return [
            'frequency' => $data['frequency'] ?? null,
            'python' => $response,
        ];
    }

    public function setFrequency(int $frequency): array
    {
        $response = $this->client->setFrequency($frequency);

        return [
            'frequency' => $frequency,
            'python' => $response,
        ];
    }
}
