<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Modbus\AlarmDecoder;
use PHPUnit\Framework\TestCase;

class AlarmDecoderTest extends TestCase
{
    public function test_detects_low_battery_alarm(): void
    {
        $decoder = new AlarmDecoder([
            'definitions' => [
                [
                    'code' => 'battery_voltage_low',
                    'label' => 'Slabá baterie',
                    'category' => 'power',
                    'severity' => 'critical',
                    'conditions' => [
                        ['word' => 'battery_voltage_raw', 'operator' => 'lt', 'value' => 1150],
                    ],
                    'metrics' => [
                        'battery_voltage_v' => ['word' => 'battery_voltage_raw', 'scale' => 0.01, 'precision' => 2],
                        'battery_current_a' => ['word' => 'battery_current_raw', 'scale' => 0.01, 'precision' => 2],
                    ],
                    'message_tokens' => [
                        '{alarm}' => 'Slabá baterie',
                        '{voltage}' => '{battery_voltage_v}',
                    ],
                ],
            ],
            'word_aliases' => [
                'battery_voltage_raw' => 2,
                'battery_current_raw' => 3,
            ],
        ]);

        $payload = $decoder->decode([0, 0, 1120, 15, 0, 0, 0, 0]);

        $this->assertTrue($payload['matched']);
        $this->assertSame('battery_voltage_low', $payload['code']);
        $this->assertSame('Slabá baterie', $payload['label']);
        $this->assertSame('critical', $payload['severity']);
        $this->assertSame('11.20', $payload['metrics']['battery_voltage_v']);
        $this->assertSame('0.15', $payload['metrics']['battery_current_a']);
        $this->assertSame('11.20', $payload['placeholders']['{voltage}']);
    }

    public function test_falls_back_to_unknown_alarm(): void
    {
        $decoder = new AlarmDecoder();

        $payload = $decoder->decode([0, 0, 1400, 10, 0, 0, 0, 0]);

        $this->assertFalse($payload['matched']);
        $this->assertSame('unknown', $payload['code']);
        $this->assertArrayHasKey('battery_voltage_v', $payload['metrics']);
    }
}
