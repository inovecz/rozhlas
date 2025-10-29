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

    public function getFrequency(?int $unitId = null): array
    {
        $response = $this->client->readFrequency($unitId);
        $data = $response['json']['data'] ?? $response['json'] ?? [];

        return [
            'frequency' => $data['frequency'] ?? null,
            'python' => $response,
        ];
    }

    public function setFrequency(float $frequency, ?int $unitId = null): array
    {
        $frequencyHz = (int) round($frequency);
        $response = $this->client->setFrequency($frequencyHz, $unitId);

        return [
            'frequency' => $frequencyHz,
            'python' => $response,
        ];
    }
}
