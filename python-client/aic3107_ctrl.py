#!/usr/bin/env python3
"""Control the TLV320AIC3107 codec via I²C using smbus2."""

from __future__ import annotations

import time
from dataclasses import dataclass
from typing import Optional

from smbus2 import SMBus

AIC_ADDR = 0x18
PAGE_SEL = 0x00
REG_SOFT_RESET = 0x01
REG_BYPASS_SWITCH = 0x6C
REG_HP_ROUTE_LEFT = 0x3D
REG_HP_ROUTE_RIGHT = 0x40

LINE1_VALUE = 0x11
LINE2_VALUE = 0x44


class AICError(Exception):
    """Codec access failure."""


@dataclass(slots=True)
class _CodecState:
    volume_value: int = 0x40  # stored 7-bit volume without D7
    routing_enabled: bool = False


class AIC3107:
    """Minimal controller for TLV320AIC3107 codec."""

    def __init__(self, bus: int = 1, addr: int = AIC_ADDR) -> None:
        self._addr = addr
        self._transfer_retries = 5
        try:
            self._bus = SMBus(bus)
        except OSError as exc:
            raise AICError(f"Failed to open I²C bus {bus}: {exc}") from exc
        self._state = _CodecState()
        self._initialise()

    def _initialise(self) -> None:
        self._soft_reset()
        self.set_input("line1")
        self.set_volume(50)
        self.mute(False)

    def close(self) -> None:
        if getattr(self, "_bus", None) is not None:
            self._bus.close()
            self._bus = None  # type: ignore[assignment]

    def __write(self, reg: int, val: int) -> None:
        reg_masked = reg & 0xFF
        val_masked = val & 0xFF
        last_error: Optional[OSError] = None
        for _ in range(self._transfer_retries):
            try:
                self._bus.write_byte_data(self._addr, PAGE_SEL, 0x00)
                self._bus.write_byte_data(self._addr, reg_masked, val_masked)
                return
            except OSError as exc:
                last_error = exc
                time.sleep(0.01)
        raise AICError(f"I²C write failed at 0x{reg_masked:02X}: {last_error}") from last_error

    def __read(self, reg: int) -> int:
        reg_masked = reg & 0xFF
        last_error: Optional[OSError] = None
        for _ in range(self._transfer_retries):
            try:
                self._bus.write_byte_data(self._addr, PAGE_SEL, 0x00)
                return self._bus.read_byte_data(self._addr, reg_masked)
            except OSError as exc:
                last_error = exc
                time.sleep(0.01)
        raise AICError(f"I²C read failed at 0x{reg_masked:02X}: {last_error}") from last_error

    def _soft_reset(self) -> None:
        self.__write(REG_SOFT_RESET, 0x01)
        time.sleep(0.005)

    def set_input(self, source: str) -> None:
        if source == "line1":
            value = LINE1_VALUE
        elif source == "line2":
            value = LINE2_VALUE
        else:
            raise ValueError(f"Unsupported source: {source!r}")
        self.__write(REG_BYPASS_SWITCH, value)

    def set_volume(self, percent: int) -> None:
        clamped = max(0, min(100, int(percent)))
        vol_value = round(clamped * 127 / 100) & 0x7F
        if self._state.volume_value == vol_value and self._state.routing_enabled:
            return
        reg_value = 0x80 | vol_value
        self.__write(REG_HP_ROUTE_LEFT, reg_value)
        self.__write(REG_HP_ROUTE_RIGHT, reg_value)
        self._state.volume_value = vol_value
        self._state.routing_enabled = True

    def mute(self, enable: bool) -> None:
        payload = self._state.volume_value & 0x7F
        if enable:
            if not self._state.routing_enabled:
                return
            self.__write(REG_HP_ROUTE_LEFT, payload)
            self.__write(REG_HP_ROUTE_RIGHT, payload)
            self._state.routing_enabled = False
        else:
            reg_value = 0x80 | payload
            if self._state.routing_enabled and self._state.volume_value == payload:
                return
            self.__write(REG_HP_ROUTE_LEFT, reg_value)
            self.__write(REG_HP_ROUTE_RIGHT, reg_value)
            self._state.routing_enabled = True
            self._state.volume_value = payload


if __name__ == "__main__":
    codec: Optional[AIC3107] = None
    try:
        codec = AIC3107()
        codec.set_input("line2")
        time.sleep(0.1)
        codec.set_volume(30)
        time.sleep(0.1)
        codec.mute(True)
        time.sleep(0.5)
        codec.mute(False)
        codec.set_volume(70)
    except AICError as exc:
        print(f"AIC error: {exc}")
    finally:
        if codec is not None:
            codec.close()
