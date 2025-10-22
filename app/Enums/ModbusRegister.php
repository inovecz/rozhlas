<?php

declare(strict_types=1);

namespace App\Enums;

enum ModbusRegister: string
{
    case PROBE = 'probe';
    case ROUTE_COUNT_RAM = 'route_count_ram';
    case ROUTE_RAM = 'route_ram';
    case ROUTE_COUNT_FLASH = 'route_count_flash';
    case ROUTE_FLASH = 'route_flash';
    case DESTINATION_ZONES = 'destination_zones';
    case TX_CONTROL = 'tx_control';
    case RX_CONTROL = 'rx_control';
    case STATUS = 'status';
    case ERROR = 'error';
    case OGG_BITRATE = 'ogg_bitrate';
    case FREQUENCY = 'frequency';
    case SERIAL_NUMBER = 'serial_number';
    case SLAVE_ADDRESS = 'slave_address';
    case RF_ADDRESS = 'rf_address';
    case RF_NET_ID = 'rf_net_id';
    case MODE = 'mode';
    case UNIT_NUMBER = 'unit_number';
    case FIRMWARE_VERSION = 'firmware_version';
    case FIRMWARE_DATE = 'firmware_date';
    case HARDWARE_VERSION = 'hardware_version';
    case INSTRUMENT_ID = 'instrument_id';
    case ALARM_ADDRESS = 'alarm_address';
    case ALARM_REPEAT = 'alarm_repeat';
    case ALARM_DATA = 'alarm_data';

    public function address(): int
    {
        return match ($this) {
            self::PROBE => 0x0000,
            self::ROUTE_COUNT_RAM => 0x0000,
            self::ROUTE_RAM => 0x0001,
            self::ALARM_ADDRESS => 0x3000,
            self::ALARM_REPEAT => 0x3001,
            self::ALARM_DATA => 0x3002,
            self::ROUTE_COUNT_FLASH => 0x4025,
            self::ROUTE_FLASH => 0x4026,
            self::DESTINATION_ZONES => 0x4030,
            self::TX_CONTROL, self::RX_CONTROL => 0x4035,
            self::STATUS => 0x4036,
            self::ERROR => 0x4037,
            self::OGG_BITRATE => 0x403F,
            self::FREQUENCY => 0x4024,
            self::SERIAL_NUMBER => 0x4000,
            self::SLAVE_ADDRESS => 0x4003,
            self::RF_ADDRESS => 0x4004,
            self::RF_NET_ID => 0x4022,
            self::MODE => 0x4023,
            self::UNIT_NUMBER => 0xFFFB,
            self::FIRMWARE_VERSION => 0xFFF5,
            self::FIRMWARE_DATE => 0xFFF9,
            self::HARDWARE_VERSION => 0xFFF4,
            self::INSTRUMENT_ID => 0xFFF3,
        };
    }

    public function quantity(): int
    {
        return match ($this) {
            self::ROUTE_RAM, self::ROUTE_FLASH, self::DESTINATION_ZONES, self::RF_ADDRESS => 5,
            self::SERIAL_NUMBER => 3,
            self::FIRMWARE_DATE => 2,
            self::UNIT_NUMBER => 4,
            self::ALARM_DATA => 8,
            default => 1,
        };
    }

    public function writable(): bool
    {
        return match ($this) {
            self::PROBE => false,
            self::STATUS => false,
            self::ERROR => false,
            self::SERIAL_NUMBER => false,
            self::FIRMWARE_VERSION => false,
            self::FIRMWARE_DATE => false,
            self::HARDWARE_VERSION => false,
            self::INSTRUMENT_ID => false,
            self::ALARM_ADDRESS => false,
            self::ALARM_REPEAT => false,
            self::ALARM_DATA => false,
            default => true,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PROBE => 'Probe register used for connectivity checks',
            self::ROUTE_COUNT_RAM => 'Number of hop addresses stored in RAM',
            self::ROUTE_RAM => 'Hop route table stored in RAM',
            self::ROUTE_COUNT_FLASH => 'Number of hop addresses stored in flash',
            self::ROUTE_FLASH => 'Hop route table stored in flash',
            self::DESTINATION_ZONES => 'Destination zone configuration registers',
            self::TX_CONTROL => 'Transmitter control register (start/stop streaming)',
            self::RX_CONTROL => 'Receiver control register',
            self::STATUS => 'Device status register',
            self::ERROR => 'Device error register',
            self::OGG_BITRATE => 'OGG bitrate register',
            self::FREQUENCY => 'RF frequency register (Hz)',
            self::SERIAL_NUMBER => 'Device serial number',
            self::SLAVE_ADDRESS => 'Modbus slave address',
            self::RF_ADDRESS => 'RF address block',
            self::RF_NET_ID => 'RF network identifier',
            self::MODE => 'Operating mode register',
            self::UNIT_NUMBER => 'Unit number block',
            self::FIRMWARE_VERSION => 'Firmware version register',
            self::FIRMWARE_DATE => 'Firmware build date registers',
            self::HARDWARE_VERSION => 'Hardware version register',
            self::INSTRUMENT_ID => 'Instrument identifier register',
            self::ALARM_ADDRESS => 'Latest alarm source Modbus address (0x3000)',
            self::ALARM_REPEAT => 'Alarm repeat counter (0x3001)',
            self::ALARM_DATA => 'Alarm payload words (0x3002-0x3009)',
        };
    }
}
