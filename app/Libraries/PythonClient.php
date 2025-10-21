<?php

declare(strict_types=1);

namespace App\Libraries;

use App\Enums\ModbusRegister;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

class PythonClient
{
    private const DEFAULT_BINARY = 'python3';
    private const DEFAULT_MODBUS_SCRIPT = 'modbus_control.py';
    private const DEFAULT_JSVV_SCRIPT = 'jsvv_control.py';

    private string $pythonBinary;
    private string $scriptsRoot;
    private string $modbusScript;
    private string $jsvvScript;

    public function __construct(
        ?string $pythonBinary = null,
        ?string $scriptsRoot = null,
        ?string $modbusScript = null,
        ?string $jsvvScript = null,
    ) {
        $this->pythonBinary = $pythonBinary ?? (string) env('PYTHON_BINARY', self::DEFAULT_BINARY);
        $this->scriptsRoot = $scriptsRoot ?? base_path('python-client');
        $this->modbusScript = $modbusScript ?? (string) env('MODBUS_SCRIPT', self::DEFAULT_MODBUS_SCRIPT);
        $this->jsvvScript = $jsvvScript ?? (string) env('JSVV_SCRIPT', self::DEFAULT_JSVV_SCRIPT);
    }

    /**
     * -----------------------------------------------------------------
     * Modbus helpers
     * -----------------------------------------------------------------
     */
    public function startStream(?array $route = null, ?array $zones = null, ?float $timeout = null): array
    {
        return $this->runModbus('start-stream', [
            'route' => $route,
            'zones' => $zones,
        ], $timeout);
    }

    public function stopStream(?float $timeout = null): array
    {
        return $this->runModbus('stop-stream', [], $timeout);
    }

    public function getDeviceInfo(): array
    {
        return $this->runModbus('device-info');
    }

    public function getStatusRegisters(): array
    {
        return $this->runModbus('status');
    }

    public function getModbusDefaults(): array
    {
        return $this->runModbus('defaults');
    }

    public function probe(int|string|null $register = null, ?int $unitId = null): array
    {
        $options = [];
        if ($register !== null) {
            $options['register'] = $register;
        }
        if ($unitId !== null) {
            $options['unit-id'] = $unitId;
        }

        return $this->runModbus('probe', $options);
    }

    public function readRegister(int|string $address, int $count = 1, ?int $unitId = null): array
    {
        return $this->runModbus('read-register', [
            'address' => $address,
            'count' => $count,
            'unit-id' => $unitId,
        ]);
    }

    public function writeRegister(int|string $address, int $value, ?int $unitId = null): array
    {
        return $this->runModbus('write-register', [
            'address' => $address,
            'value' => $value,
            'unit-id' => $unitId,
        ]);
    }

    public function writeRegisters(int|string $address, array $values, ?int $unitId = null): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Values array must contain at least one element.');
        }

        return $this->runModbus('write-registers', [
            'address' => $address,
            'values' => $values,
            'unit-id' => $unitId,
        ]);
    }

    public function readBlock(string $name): array
    {
        return $this->runModbus('read-block', ['name' => $name]);
    }

    public function writeBlock(string $name, array $values): array
    {
        if ($values === []) {
            throw new InvalidArgumentException('Values array must contain at least one element.');
        }

        return $this->runModbus('write-block', [
            'name' => $name,
            'values' => $values,
        ]);
    }

    public function readFrequency(?int $unitId = null): array
    {
        return $this->runModbus('read-frequency', [
            'unit-id' => $unitId,
        ]);
    }

    public function setFrequency(int $value, ?int $unitId = null): array
    {
        return $this->runModbus('set-frequency', [
            'value' => $value,
            'unit-id' => $unitId,
        ]);
    }

    public function configureRoute(?array $addresses = null, ?int $unitId = null): array
    {
        $options = ['unit-id' => $unitId];
        if ($addresses !== null) {
            $options['addresses'] = $addresses;
        }

        return $this->runModbus('set-route', $options);
    }

    public function configureDestinationZones(?array $zones = null, ?int $unitId = null): array
    {
        $options = ['unit-id' => $unitId];
        if ($zones !== null) {
            $options['zones'] = $zones;
        }

        return $this->runModbus('set-zones', $options);
    }

    public function readRoute(?int $unitId = null): array
    {
        return $this->runModbus('read-route', [
            'unit-id' => $unitId,
        ]);
    }

    /**
     * Register helpers built on top of ModbusRegister metadata.
     */
    public function readRegisterByName(ModbusRegister $register, ?int $unitId = null): array
    {
        return $this->runModbus('read-register', [
            'address' => $register->address(),
            'count' => $register->quantity(),
            'unit-id' => $unitId,
        ]);
    }

    public function writeRegisterByName(ModbusRegister $register, array|int $value, ?int $unitId = null): array
    {
        if (!$register->writable()) {
            throw new InvalidArgumentException(sprintf('Register %s is read-only.', $register->name));
        }

        $values = is_array($value) ? array_values($value) : [$value];
        $expected = $register->quantity();
        if (count($values) !== $expected) {
            throw new InvalidArgumentException(
                sprintf(
                    'Register %s expects %d value(s), %d provided.',
                    $register->name,
                    $expected,
                    count($values)
                )
            );
        }

        $payload = [
            'address' => $register->address(),
            'unit-id' => $unitId,
        ];

        if ($expected === 1) {
            $payload['value'] = $values[0];
            return $this->runModbus('write-register', $payload);
        }

        $payload['values'] = $values;
        return $this->runModbus('write-registers', $payload);
    }

    public function readRegisterValues(ModbusRegister $register, ?int $unitId = null): ?array
    {
        return $this->extractValues($this->readRegisterByName($register, $unitId));
    }

    public function readRegisterValue(ModbusRegister $register, ?int $unitId = null): ?int
    {
        $values = $this->readRegisterValues($register, $unitId);
        if ($values === null) {
            return null;
        }
        return $values[0] ?? null;
    }

    /**
     * Convenience wrappers for frequently used registers.
     */
    public function readTxControl(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::TX_CONTROL, $unitId);
    }

    public function writeTxControl(int $value, ?int $unitId = null): array
    {
        return $this->writeRegisterByName(ModbusRegister::TX_CONTROL, $value, $unitId);
    }

    public function readRxControl(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::RX_CONTROL, $unitId);
    }

    public function writeRxControl(int $value, ?int $unitId = null): array
    {
        return $this->writeRegisterByName(ModbusRegister::RX_CONTROL, $value, $unitId);
    }

    public function readStatusRegister(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::STATUS, $unitId);
    }

    public function readErrorRegister(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::ERROR, $unitId);
    }

    public function readOggBitrate(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::OGG_BITRATE, $unitId);
    }

    public function readDestinationZones(?int $unitId = null): ?array
    {
        return $this->readRegisterValues(ModbusRegister::DESTINATION_ZONES, $unitId);
    }

    public function writeDestinationZones(array $zones, ?int $unitId = null): array
    {
        if (count($zones) > ModbusRegister::DESTINATION_ZONES->quantity()) {
            throw new InvalidArgumentException('Destination zones exceed maximum capacity.');
        }

        $padded = array_pad(array_slice(array_values($zones), 0, 5), 5, 0);
        return $this->writeRegisterByName(ModbusRegister::DESTINATION_ZONES, $padded, $unitId);
    }

    public function readRouteRam(?int $unitId = null): ?array
    {
        return $this->readRegisterValues(ModbusRegister::ROUTE_RAM, $unitId);
    }

    public function writeRouteRam(array $addresses, ?int $unitId = null): array
    {
        if (count($addresses) > ModbusRegister::ROUTE_RAM->quantity()) {
            throw new InvalidArgumentException('Route entries exceed maximum capacity.');
        }

        $padded = array_pad(array_slice(array_values($addresses), 0, 5), 5, 0);
        return $this->writeRegisterByName(ModbusRegister::ROUTE_RAM, $padded, $unitId);
    }

    public function readSerialNumberWords(?int $unitId = null): ?array
    {
        return $this->readRegisterValues(ModbusRegister::SERIAL_NUMBER, $unitId);
    }

    public function readSerialNumber(?int $unitId = null): ?string
    {
        $words = $this->readSerialNumberWords($unitId);
        if ($words === null) {
            return null;
        }

        $hex = array_map(static fn (int $word): string => sprintf('%04X', $word & 0xFFFF), $words);
        return implode('', $hex);
    }

    public function readRfAddresses(?int $unitId = null): ?array
    {
        return $this->readRegisterValues(ModbusRegister::RF_ADDRESS, $unitId);
    }

    public function writeRfAddresses(array $addresses, ?int $unitId = null): array
    {
        if (count($addresses) > ModbusRegister::RF_ADDRESS->quantity()) {
            throw new InvalidArgumentException('RF address entries exceed maximum capacity.');
        }
        $padded = array_pad(array_slice(array_values($addresses), 0, 5), 5, 0);
        return $this->writeRegisterByName(ModbusRegister::RF_ADDRESS, $padded, $unitId);
    }

    public function readRfNetId(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::RF_NET_ID, $unitId);
    }

    public function readSlaveAddress(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::SLAVE_ADDRESS, $unitId);
    }

    public function writeSlaveAddress(int $value, ?int $unitId = null): array
    {
        return $this->writeRegisterByName(ModbusRegister::SLAVE_ADDRESS, $value, $unitId);
    }

    public function writeRfNetId(int $value, ?int $unitId = null): array
    {
        return $this->writeRegisterByName(ModbusRegister::RF_NET_ID, $value, $unitId);
    }

    public function readMode(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::MODE, $unitId);
    }

    public function writeMode(int $value, ?int $unitId = null): array
    {
        return $this->writeRegisterByName(ModbusRegister::MODE, $value, $unitId);
    }

    public function readUnitNumberWords(?int $unitId = null): ?array
    {
        return $this->readRegisterValues(ModbusRegister::UNIT_NUMBER, $unitId);
    }

    public function readUnitNumber(?int $unitId = null): ?string
    {
        $words = $this->readUnitNumberWords($unitId);
        if ($words === null) {
            return null;
        }

        $hex = array_map(static fn (int $word): string => sprintf('%04X', $word & 0xFFFF), $words);
        return implode('', $hex);
    }

    public function readFirmwareVersion(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::FIRMWARE_VERSION, $unitId);
    }

    public function readFirmwareDateWords(?int $unitId = null): ?array
    {
        return $this->readRegisterValues(ModbusRegister::FIRMWARE_DATE, $unitId);
    }

    public function readFirmwareDate(?int $unitId = null): ?array
    {
        $words = $this->readFirmwareDateWords($unitId);
        if ($words === null || count($words) < 2) {
            return null;
        }

        $year = (($words[0] >> 8) & 0xFF) + 2000;
        $month = $words[0] & 0xFF;
        $day = $words[1] & 0xFF;

        return [
            'year' => $year,
            'month' => $month,
            'day' => $day,
        ];
    }

    public function readHardwareVersion(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::HARDWARE_VERSION, $unitId);
    }

    public function readInstrumentId(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::INSTRUMENT_ID, $unitId);
    }

    public function readProbe(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::PROBE, $unitId);
    }

    public function readRouteCountRam(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::ROUTE_COUNT_RAM, $unitId);
    }

    public function writeRouteCountRam(int $value, ?int $unitId = null): array
    {
        return $this->writeRegisterByName(ModbusRegister::ROUTE_COUNT_RAM, $value, $unitId);
    }

    public function readRouteCountFlash(?int $unitId = null): ?int
    {
        return $this->readRegisterValue(ModbusRegister::ROUTE_COUNT_FLASH, $unitId);
    }

    public function writeRouteCountFlash(int $value, ?int $unitId = null): array
    {
        return $this->writeRegisterByName(ModbusRegister::ROUTE_COUNT_FLASH, $value, $unitId);
    }

    public function readRouteFlash(?int $unitId = null): ?array
    {
        return $this->readRegisterValues(ModbusRegister::ROUTE_FLASH, $unitId);
    }

    public function writeRouteFlash(array $addresses, ?int $unitId = null): array
    {
        if (count($addresses) > ModbusRegister::ROUTE_FLASH->quantity()) {
            throw new InvalidArgumentException('Route entries exceed maximum capacity.');
        }
        $padded = array_pad(array_slice(array_values($addresses), 0, 5), 5, 0);
        return $this->writeRegisterByName(ModbusRegister::ROUTE_FLASH, $padded, $unitId);
    }

    public function writeOggBitrate(int $value, ?int $unitId = null): array
    {
        return $this->writeRegisterByName(ModbusRegister::OGG_BITRATE, $value, $unitId);
    }

    /**
     * -----------------------------------------------------------------
     * JSVV helpers
     * -----------------------------------------------------------------
     */
    public function listJsvvAssets(
        ?int $slot = null,
        ?string $voice = null,
        bool $includePaths = false,
        ?string $category = null,
        array $options = [],
    ): array {
        if ($slot !== null) {
            $options['slot'] = $slot;
        }
        if ($voice !== null) {
            $options['voice'] = $voice;
        }
        if ($includePaths) {
            $options['include-paths'] = true;
        }
        if ($category !== null) {
            $options['category'] = $category;
        }

        return $this->runJsvv('list-assets', $options);
    }

    public function listJsvvSirenAssets(?int $signal = null, bool $includePaths = false, array $options = []): array
    {
        return $this->listJsvvAssets(
            slot: $signal,
            includePaths: $includePaths,
            category: 'siren',
            options: $options,
        );
    }

    public function listJsvvCommands(?string $mid = null): array
    {
        $options = [];
        if ($mid !== null) {
            $options['mid'] = $mid;
        }
        return $this->runJsvv('list-commands', $options);
    }

    public function getJsvvDefaults(bool $includeAssetSummary = false): array
    {
        return $this->runJsvv('defaults', [
            'include-assets' => $includeAssetSummary,
        ]);
    }

    public function parseJsvvFrame(string $frame, bool $skipCrcValidation = false): array
    {
        return $this->runJsvv('parse-frame', [
            'frame' => $frame,
            'skip-crc' => $skipCrcValidation,
        ]);
    }

    public function buildJsvvFrame(string $mid, array $params = [], bool $includeCrc = true): array
    {
        return $this->runJsvv('build-frame', [
            'mid' => $mid,
            'params' => $params,
            'no-crc' => !$includeCrc,
        ]);
    }

    public function triggerJsvvFrame(
        string $mid,
        array $params = [],
        bool $send = false,
        bool $includeCrc = true,
    ): array {
        return $this->runJsvv('trigger', [
            'mid' => $mid,
            'params' => $params,
            'send' => $send,
            'no-crc' => !$includeCrc,
        ]);
    }

    public function listenJsvv(array $options = [], ?float $timeout = null): array
    {
        return $this->runJsvv('listen', $options, $timeout);
    }

    public function planJsvvSequence(array $sequence, array $options = [], ?float $timeout = null): array
    {
        try {
            $options['sequence-json'] = json_encode($sequence, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Unable to encode sequence for python-client', 0, $exception);
        }

        return $this->runJsvv('plan-sequence', $options, $timeout);
    }

    /**
     * -----------------------------------------------------------------
     * Generic runners
     * -----------------------------------------------------------------
     */
    public function run(string $command, array $options = [], ?float $timeout = null): array
    {
        return $this->runModbus($command, $options, $timeout);
    }

    public function runModbus(string $command, array $options = [], ?float $timeout = null): array
    {
        return $this->callScript($this->modbusScript, $command, $options, $timeout);
    }

    public function runJsvv(string $command, array $options = [], ?float $timeout = null): array
    {
        return $this->callScript($this->jsvvScript, $command, $options, $timeout);
    }

    public function callDefaultScript(array $arguments, ?float $timeout = null): array
    {
        return $this->call($this->modbusScript, $arguments, $timeout);
    }

    public function call(string $script, array $arguments = [], ?float $timeout = null): array
    {
        $scriptPath = $this->resolveScriptPath($script);
        $command = array_merge([$this->pythonBinary, $scriptPath], $arguments);

        $process = new Process($command, $this->scriptsRoot, null, null, $timeout);
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();

        return [
            'success' => $process->isSuccessful(),
            'exitCode' => $process->getExitCode(),
            'stdout' => $this->normalizeOutput($stdout),
            'stderr' => $this->normalizeOutput($stderr),
            'json' => $this->decodeJson($stdout),
        ];
    }

    /**
     * -----------------------------------------------------------------
     * Internal helpers
     * -----------------------------------------------------------------
     */
    private function callScript(string $script, string $command, array $options, ?float $timeout = null): array
    {
        return $this->call($script, $this->buildCommandArguments($command, $options), $timeout);
    }

    private function resolveScriptPath(string $script): string
    {
        if ($this->isAbsolutePath($script)) {
            $path = $script;
        } else {
            $path = $this->scriptsRoot . DIRECTORY_SEPARATOR . ltrim($script, DIRECTORY_SEPARATOR);
        }

        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Python script "%s" not found in %s', $script, $this->scriptsRoot));
        }

        return $path;
    }

    private function isAbsolutePath(string $path): bool
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        return (bool) preg_match('#^[A-Za-z]:\\\\#', $path);
    }

    private function normalizeOutput(string $output): array
    {
        $normalized = preg_split("/\r\n|\n|\r/", rtrim($output, "\r\n"));
        if ($normalized === false) {
            return [];
        }

        return array_values(array_filter($normalized, static fn (string $line): bool => $line !== ''));
    }

    private function decodeJson(string $output): ?array
    {
        $trimmed = trim($output);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function extractData(array $result): ?array
    {
        $json = $result['json'] ?? null;
        if (!is_array($json)) {
            return null;
        }

        if (($json['status'] ?? null) !== 'ok') {
            return null;
        }

        $data = $json['data'] ?? null;
        return is_array($data) ? $data : null;
    }

    private function extractValues(array $result): ?array
    {
        $data = $this->extractData($result);
        if ($data === null) {
            return null;
        }

        $values = $data['values'] ?? null;
        return is_array($values) ? array_values($values) : null;
    }

    private function buildCommandArguments(string $command, array $options): array
    {
        $arguments = [$command];

        foreach ($options as $key => $value) {
            if ($value === null) {
                continue;
            }

            $flag = '--' . str_replace('_', '-', (string) $key);

            if (is_bool($value)) {
                if ($value) {
                    $arguments[] = $flag;
                }
                continue;
            }

            if (is_array($value)) {
                $arguments[] = $flag;
                foreach ($value as $item) {
                    $arguments[] = (string) $item;
                }
                continue;
            }

            $arguments[] = $flag;
            $arguments[] = (string) $value;
        }

        return $arguments;
    }
}
