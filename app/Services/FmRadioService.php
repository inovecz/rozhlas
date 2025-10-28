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

    public function setFrequency(float $frequency): array
    {
        $frequencyHz = (int) round($frequency);
        $response = $this->client->setFrequency($frequencyHz);

        return [
            'frequency' => $frequencyHz,
            'python' => $response,
        ];
    }
}
