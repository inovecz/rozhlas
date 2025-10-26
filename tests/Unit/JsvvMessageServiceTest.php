<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JsvvMessageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class JsvvMessageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_creates_message_and_uses_cache(): void
    {
        config([
            'jsvv.dedup.cache_store' => 'array',
            'jsvv.dedup.ttl' => 120,
        ]);

        Cache::store('array')->flush();

        $service = new JsvvMessageService();

        $payload = $this->buildPayload();

        $first = $service->ingest($payload);
        $this->assertFalse($first['duplicate']);
        $this->assertNotNull($first['message']);

        $second = $service->ingest($payload);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['message']->id, $second['message']->id);
    }

    private function buildPayload(): array
    {
        return [
            'networkId' => 1,
            'vycId' => 1,
            'kppsAddress' => '0x0001',
            'operatorId' => 10,
            'type' => 'ACTIVATION',
            'command' => 'SIREN_SIGNAL',
            'params' => ['signalType' => 1],
            'priority' => 'P2',
            'timestamp' => now()->timestamp,
            'rawMessage' => 'SIREN 1',
        ];
    }
}
