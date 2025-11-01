#!/usr/bin/env python3
"""
Jednoduchý nástroj pro naprogramování sekvence hlášení do registrů DTRX
a spuštění vysílání pro vybraná hnízda přes Modbus RTU (sériový port).

Požadavky:
    pip install pymodbus

Použití:
    ./scripts/dtrx_trigger.py --sequence 2,8,A,9 --priority P2 --nests 101,102
    ./scripts/dtrx_trigger.py --serial-port /dev/ttyUSB1 --baudrate 19200 --unit-id 5 --sequence 1,B,9
"""

from __future__ import annotations

import argparse
import sys
from typing import Iterable, List

from pymodbus.client import ModbusSerialClient

# Mapování symbolů -> sample kódy podle JsvvSequenceService::DTRX_SAMPLE_MAP
DTRX_SAMPLE_CODES = {
    "1": 1,
    "2": 2,
    "4": 3,
    "8": 5,
    "9": 6,
    "A": 7,
    "B": 8,
    "C": 9,
    "D": 10,
    "E": 11,
    "F": 12,
    "G": 13,
    "P": 14,
    "Q": 15,
    "R": 16,
    "S": 17,
    "T": 18,
    "U": 19,
    "V": 20,
    "X": 21,
    "Y": 22,
}

# Registrace (decimální adresy) – dle zadání
REGISTER_PRIORITY = 10          # priorita hlášení
REGISTER_SEQUENCE_START = 11    # SampleCode1RAM
REGISTER_SEQUENCE_END = 14      # SampleCode4RAM
REGISTER_ROUTE_COUNT = 0        # počet adres v RAM
REGISTER_ROUTE_START = 1        # první adresa v RAM
REGISTER_TX_CONTROL = 0x4035    # spuštění vysílání

# Hodnoty priority – lze přizpůsobit požadavkům ústředny
PRIORITY_MAP = {
    "P1": 1,
    "P2": 2,
    "P3": 3,
}


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Naplánuje hlášení v DTRX převodníku přes Modbus TCP.")
    parser.add_argument(
        "--serial-port",
        default="/dev/ttyUSB0",
        help="Sériový port, na kterém běží Modbus RTU (default: /dev/ttyUSB0).",
    )
    parser.add_argument("--baudrate", type=int, default=57600, help="Rychlost Modbus RTU (default: 9600).")
    parser.add_argument("--parity", default="N", choices=["N", "E", "O"], help="Parita (default: N).")
    parser.add_argument("--stopbits", type=int, default=1, choices=[1, 2], help="Stop bity (default: 1).")
    parser.add_argument("--bytesize", type=int, default=8, choices=[7, 8], help="Počet bitů (default: 8).")
    parser.add_argument("--unit-id", type=int, default=55, help="Modbus Unit ID / slave adresa (default: 1).")
    parser.add_argument(
        "--sequence",
        required=True,
        help="Sekvence symbolů (např. '2,8,A,9' podle DTRX_SAMPLE_MAP).",
    )
    parser.add_argument(
        "--priority",
        default="P2",
        choices=sorted(PRIORITY_MAP.keys()),
        help="Priorita hlášení (default: P2).",
    )
    parser.add_argument(
        "--nests",
        default="101,102",
        help="Seznam cílových hnízd oddělených čárkou (default: 101,102).",
    )
    parser.add_argument(
        "--tx-control-value",
        type=int,
        default=2,
        help="Hodnota zapsaná do registru TxControl pro spuštění vysílání (default: 2).",
    )
    return parser.parse_args()


def parse_sequence(sequence_str: str) -> List[int]:
    symbols = [item.strip().upper() for item in sequence_str.split(",") if item.strip()]
    if not symbols:
        raise ValueError("Sekvence nesmí být prázdná.")

    codes: List[int] = []
    for symbol in symbols:
        if symbol not in DTRX_SAMPLE_CODES:
            raise ValueError(f"Neznámý symbol '{symbol}'. Dostupné: {', '.join(DTRX_SAMPLE_CODES)}")
        codes.append(DTRX_SAMPLE_CODES[symbol])
    return codes


def parse_nests(nests_str: str) -> List[int]:
    values = []
    for item in nests_str.split(","):
        item = item.strip()
        if not item:
            continue
        try:
            values.append(int(item))
        except ValueError as exc:  # pragma: no cover
            raise ValueError(f"Neplatná adresa hnízda '{item}'.") from exc
    if not values:
        raise ValueError("Musí být zadán alespoň jeden cíl hnízda.")
    return values


def pad(values: Iterable[int], size: int) -> List[int]:
    result = list(values)[:size]
    while len(result) < size:
        result.append(0)
    return result


def main() -> int:
    args = parse_args()

    try:
        sample_codes = parse_sequence(args.sequence)
        nests = parse_nests(args.nests)
        priority_value = PRIORITY_MAP[args.priority]
    except ValueError as exc:
        print(f"Chyba: {exc}", file=sys.stderr)
        return 1

    client = ModbusSerialClient(
        method="rtu",
        port=args.serial_port,
        baudrate=args.baudrate,
        parity=args.parity,
        stopbits=args.stopbits,
        bytesize=args.bytesize,
    )
    if not client.connect():
        print(f"Nelze se připojit k Modbus zařízení na portu {args.serial_port}", file=sys.stderr)
        return 2

    try:
        # 1) Zápis cílových hnízd do RAM (registry 0..n)
        client.write_register(REGISTER_ROUTE_COUNT, len(nests), unit=args.unit_id)
        client.write_registers(REGISTER_ROUTE_START, pad(nests, 10), unit=args.unit_id)

        # 2) Zápis sample kódů (registr 11..14)
        sequence_registers = pad(sample_codes, REGISTER_SEQUENCE_END - REGISTER_SEQUENCE_START + 1)
        client.write_registers(REGISTER_SEQUENCE_START, sequence_registers, unit=args.unit_id)

        # 3) Priorita
        client.write_register(REGISTER_PRIORITY, priority_value, unit=args.unit_id)

        # 4) Spuštění vysílání
        client.write_register(REGISTER_TX_CONTROL, args.tx_control_value, unit=args.unit_id)

        print("Hlášení bylo naprogramováno a spuštěno.")
        print(f"  hnízda: {nests}")
        print(f"  sample codes: {sequence_registers}")
        print(f"  priorita: {args.priority} ({priority_value})")
        print(f"  TxControl hodnota: {args.tx_control_value}")
        print(f"  Modbus RTU port: {args.serial_port}, unit ID: {args.unit_id}")
    finally:
        client.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())