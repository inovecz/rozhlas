"""High level helper for interacting with VP_PRIJIMAC Modbus receivers/transmitters.

The module builds on top of :mod:`pymodbus` and focuses on the register layout
summarised in ``constants.py``. It intentionally keeps the surface small so the
code can run on macOS or Linux (including Raspberry Pi).
"""

from __future__ import annotations

import inspect
import os
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Callable, Iterable, Mapping, MutableMapping

from . import constants

try:  # pragma: no cover - depends on installed pymodbus variant
    from pymodbus.client import ModbusSerialClient as _SerialClient
except Exception:  # pragma: no cover - fallback for pymodbus < 3
    try:
        from pymodbus.client.sync import ModbusSerialClient as _SerialClient
    except Exception as exc:  # pragma: no cover - surfaced as runtime error
        _SerialClient = None  # type: ignore[assignment]
        _PYMODBUS_IMPORT_ERROR = exc
    else:
        _PYMODBUS_IMPORT_ERROR = None
else:
    _PYMODBUS_IMPORT_ERROR = None

try:  # pragma: no cover - pymodbus optional dependency
    from pymodbus.exceptions import ModbusIOException
except Exception:  # pragma: no cover
    ModbusIOException = Exception  # type: ignore[misc, assignment]


class ModbusAudioError(RuntimeError):
    """Generic runtime error raised by :class:`ModbusAudioClient`."""


@dataclass
class SerialSettings:
    """Settings forwarded to :class:`pymodbus.client.ModbusSerialClient`."""

    port: str = constants.DEFAULT_SERIAL_PORT
    method: str = "rtu"
    baudrate: int = constants.DEFAULT_BAUDRATE
    parity: str = constants.DEFAULT_PARITY
    stopbits: int = constants.DEFAULT_STOPBITS
    bytesize: int = constants.DEFAULT_BYTESIZE
    timeout: float = constants.DEFAULT_TIMEOUT


class ModbusAudioClient:
    """Convenience wrapper around ``pymodbus`` for this specific device family."""

    @classmethod
    def from_defaults(cls) -> "ModbusAudioClient":
        """Create a client using the default serial settings and unit id."""

        return cls(SerialSettings(), unit_id=constants.DEFAULT_UNIT_ID)

    def __init__(self, settings: SerialSettings, unit_id: int = 55) -> None:
        if _SerialClient is None:
            raise ModbusAudioError(
                "pymodbus is not available. Install it with 'pip install pymodbus[serial]'."
            ) from _PYMODBUS_IMPORT_ERROR

        self.settings = settings
        self.unit_id = unit_id
        serial_kwargs = self._build_serial_kwargs(settings)
        self._client = _SerialClient(**serial_kwargs)
        self._connected = False
        self._rs485_controller: _RS485Controller | None = None

    # ---------------------------------------------------------------------
    # Context manager helpers
    # ---------------------------------------------------------------------
    def __enter__(self) -> "ModbusAudioClient":
        self.connect()
        return self

    def __exit__(self, exc_type, exc, tb) -> None:
        self.close()

    # ------------------------------------------------------------------
    # Connection management
    # ------------------------------------------------------------------
    def connect(self) -> None:
        if self._connected:
            return

        if not self._client.connect():
            raise ModbusAudioError(f"Unable to open serial port {self.settings.port}")

        self._connected = True
        driver_configured = self._setup_rs485_driver()
        if not driver_configured:
            self._setup_rs485_direction_control()

    def close(self) -> None:
        if self._connected:
            self._client.close()
            self._connected = False
        if self._rs485_controller is not None:
            self._rs485_controller.close()
            self._rs485_controller = None

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------
    def get_device_info(self) -> Mapping[str, object]:
        """Collect a snapshot of the most useful device registers."""

        blocks = list(constants.DEVICE_INFO_REGISTERS.values())
        merged = constants.register_block_to_request(blocks)

        cache: MutableMapping[int, int] = {}
        for block in merged:
            for offset, value in enumerate(self._read_registers(block.start, block.quantity)):
                cache[block.start + offset] = value

        info: dict[str, object] = {}
        for name, block in constants.DEVICE_INFO_REGISTERS.items():
            words = [cache[block.start + idx] for idx in range(block.quantity)]
            info[name] = self._format_register_value(name, words)

        return info

    def read_register(self, address: int, unit: int | None = None) -> int:
        """Read a single holding register."""

        return self._read_registers(address, 1, unit=unit)[0]

    def read_registers(self, address: int, quantity: int, unit: int | None = None) -> list[int]:
        """Read multiple holding registers."""

        return self._read_registers(address, quantity, unit=unit)

    def probe(self, register: int = constants.PROBE_REGISTER, unit: int | None = None) -> int:
        """Read a register to verify the device responds."""

        return self.read_register(register, unit=unit)

    def read_serial_number(self) -> str:
        """Return the device serial number as a hexadecimal string."""

        block = constants.DEVICE_INFO_REGISTERS["serial_number"]
        words = self.read_registers(block.start, block.quantity)
        return "".join(f"{word:04X}" for word in words)

    def read_frequency(self, unit: int | None = None) -> int:
        """Return the configured RF frequency (register 0x4024)."""

        return self.read_register(constants.FREQUENCY_REGISTER, unit=unit)

    def write_frequency(self, value: int | None = None, unit: int | None = None) -> None:
        """Set the RF frequency register; defaults to the documented value."""

        target = constants.DEFAULT_FREQUENCY if value is None else value
        self.write_register(constants.FREQUENCY_REGISTER, target, unit=unit)

    def start_stream(
        self,
        hop_addresses: Iterable[int],
        zones: Iterable[int] | None = None,
        *,
        configure_route: bool = False,
    ) -> None:
        """Configure destination zones and toggle TxControl.

        Legacy behaviour configured the hop route before every start. Some
        deployments keep the route static, so configuring it can fail on units
        that expose the registers read-only. The ``configure_route`` flag lets
        callers opt into that behaviour when needed.
        """

        addr_list = list(hop_addresses)
        if configure_route and addr_list:
            self.configure_route(addr_list)

        if zones is None:
            zone_values = list(constants.DEFAULT_DESTINATION_ZONES)
        else:
            zone_values = list(zones)

        # Always push the provided zone set so empty selections clear previous
        # configuration instead of reusing stale registers.
        self.set_destination_zones(zone_values)
        self._write_tx_control(2)

    def stop_stream(self) -> None:
        """Stop audio streaming by writing ``1`` into TxControl (0x4035)."""

        self._write_tx_control(1)

    def _write_tx_control(self, value: int) -> None:
        last_error: ModbusAudioError | None = None
        for address in (constants.TX_CONTROL, constants.LEGACY_TX_CONTROL):
            try:
                self.write_registers(address, (value,))
                return
            except ModbusAudioError as exc:
                last_error = exc
        if last_error is not None:
            raise last_error

    def write_register(self, address: int, value: int, unit: int | None = None) -> None:
        """Write a single holding register."""

        try:
            response = self._call_with_unit(
                self._client.write_register,
                address=address,
                value=value,
                unit=unit,
            )
        except ModbusIOException as exc:  # pragma: no cover - depends on transport
            raise ModbusAudioError(
                f"No response while writing register 0x{address:04X}; verify wiring, port, and unit id"
            ) from exc
        if getattr(response, "isError", lambda: False)():  # pragma: no cover - depends on pymodbus
            details = _format_modbus_error_details(response)
            raise ModbusAudioError(f"Modbus error while writing register 0x{address:04X}{details}")

    def write_registers(self, address: int, values: Iterable[int], unit: int | None = None) -> None:
        """Write consecutive holding registers."""

        value_list = list(values)
        try:
            response = self._call_with_unit(
                self._client.write_registers,
                address=address,
                values=value_list,
                unit=unit,
            )
        except ModbusIOException as exc:  # pragma: no cover - depends on transport
            raise ModbusAudioError(
                f"No response while writing registers starting at 0x{address:04X}; check connection"
            ) from exc
        if getattr(response, "isError", lambda: False)():  # pragma: no cover - depends on pymodbus
            details = _format_modbus_error_details(response)
            raise ModbusAudioError(
                f"Modbus error while writing {len(value_list)} registers starting at 0x{address:04X}{details}"
            )

    def configure_route(self, addresses: Iterable[int]) -> None:
        """Populate the RAM routing table (0x0000..0x0005)."""

        addr_list = list(addresses)
        if len(addr_list) > constants.MAX_ADDR_ENTRIES:
            raise ValueError(
                f"At most {constants.MAX_ADDR_ENTRIES} hop addresses are supported; received {len(addr_list)}"
            )

        padded = addr_list + [0] * (constants.MAX_ADDR_ENTRIES - len(addr_list))
        self.write_register(constants.NUM_ADDR_RAM, len(addr_list))
        self.write_registers(constants.ADDR_RAM_BASE, padded[: constants.MAX_ADDR_ENTRIES])

    def set_destination_zones(self, zones: Iterable[int]) -> None:
        """Configure the destination zone registers (0x4030..0x4034)."""

        zone_list = list(zones)
        if len(zone_list) > constants.MAX_DEST_ZONES:
            raise ValueError(
                f"At most {constants.MAX_DEST_ZONES} destination zones are supported; received {len(zone_list)}"
            )

        padded = zone_list + [0] * (constants.MAX_DEST_ZONES - len(zone_list))
        self.write_registers(constants.RF_DEST_ZONE_BASE, padded[: constants.MAX_DEST_ZONES])

    def start_audio_stream(self, hop_addresses: Iterable[int], zones: Iterable[int] | None = None) -> None:
        """Send the sequence of writes needed to start broadcasting audio."""

        self.configure_route(hop_addresses)
        if zones is not None:
            self.set_destination_zones(zones)
        self._write_tx_control(2)

    def stop_audio_stream(self) -> None:
        """Stop the audio stream by clearing ``TxControl`` (0x4035)."""

        self._write_tx_control(1)

    def read_alarm_buffer(self) -> dict[str, object]:
        """Return latest alarm entry from the LIFO buffer (0x3000-0x3009)."""

        words = self.read_registers(constants.ALARM_BUFFER_BASE, constants.ALARM_BUFFER_WORDS)
        return {
            'nest_address': words[0],
            'repeat': words[1],
            'data': words[2:],
        }

    def read_nest_status(self, nest_address: int, *, route: Iterable[int] | None = None) -> dict[str, int]:
        """Configure hop route and read status/error registers for a specific nest."""

        route_list = list(route) if route is not None else []
        if nest_address not in route_list:
            route_list.append(nest_address)

        if route_list:
            self.configure_route(route_list)

        status_value = self.read_register(constants.STATUS_REGISTER)
        error_value = self.read_register(constants.ERROR_REGISTER)

        return {
            'status': status_value,
            'error': error_value,
            'route': route_list,
        }

    def dump_documented_registers(self) -> list[tuple[str, str, str, str]]:
        """Return a table of documented registers and their current values."""

        rows: list[tuple[str, str, str, str]] = []
        for desc in constants.DOCUMENTED_REGISTERS:
            address = f"0x{desc.block.start:04X}"
            quantity = str(desc.block.quantity)

            if not desc.readable:
                rows.append((desc.name, address, quantity, "write-only"))
                continue

            try:
                values = self.read_registers(desc.block.start, desc.block.quantity)
            except ModbusAudioError as exc:
                rows.append((desc.name, address, quantity, f"error: {exc}"))
                continue

            rendered = (
                str(values[0]) if desc.block.quantity == 1 else "[" + ", ".join(str(v) for v in values) + "]"
            )
            rows.append((desc.name, address, quantity, rendered))

        return rows

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------
    def _read_registers(self, address: int, quantity: int, unit: int | None = None) -> list[int]:
        try:
            response = self._call_with_unit(
                self._client.read_holding_registers,
                address=address,
                count=quantity,
                unit=unit,
            )
        except ModbusIOException as exc:  # pragma: no cover - depends on transport
            raise ModbusAudioError(
                f"No response while reading register(s) 0x{address:04X}-0x{address + quantity - 1:04X};"
                " verify port, wiring, baud rate, and unit id"
            ) from exc
        if getattr(response, "isError", lambda: False)():  # pragma: no cover - depends on pymodbus
            details = _format_modbus_error_details(response)
            raise ModbusAudioError(
                f"Modbus error while reading {quantity} register(s) starting at 0x{address:04X}{details}"
            )
        if not hasattr(response, "registers"):
            raise ModbusAudioError("Unexpected response payload from pymodbus")
        return list(response.registers)

    @staticmethod
    def _build_serial_kwargs(settings: SerialSettings) -> dict[str, object]:
        """Prepare keyword arguments compatible with the installed pymodbus version."""

        base_kwargs: dict[str, object] = {
            "port": settings.port,
            "baudrate": settings.baudrate,
            "parity": settings.parity,
            "stopbits": settings.stopbits,
            "bytesize": settings.bytesize,
            "timeout": settings.timeout,
        }

        init_signature = inspect.signature(_SerialClient.__init__)
        if "method" in init_signature.parameters:
            base_kwargs["method"] = settings.method
            return base_kwargs

        # pymodbus >= 3.0 removed the 'method' argument; provide the matching framer instead.
        framers = _resolve_framers()

        method_key = settings.method.lower()
        if not framers:
            if method_key == "rtu":
                # Rely on pymodbus default (RTU) if framers could not be located.
                return base_kwargs
            raise ModbusAudioError(
                "Unable to import Modbus framers from pymodbus; install a full pymodbus package"
            )

        if method_key == "rtu" and "rtu" not in framers:
            return base_kwargs

        if method_key not in framers:
            valid = ", ".join(sorted(framers)) or "rtu"
            raise ModbusAudioError(f"Unsupported Modbus method '{settings.method}'. Valid options: {valid}")

        base_kwargs["framer"] = framers[method_key]
        return base_kwargs

    @staticmethod
    def _format_register_value(name: str, words: list[int]) -> object:
        """Convert raw register values to a friendly representation."""

        if not words:
            return None

        if name in {"serial_number", "unit_number"}:
            return "".join(f"{word:04X}" for word in words)

        if name == "firmware_date" and len(words) == 2:
            # YYMM and DD?? layout according to the vendor document.
            year = (words[0] >> 8) + 2000
            month = words[0] & 0xFF
            day = words[1] & 0xFF
            return {"year": year, "month": month, "day": day}

        if name in {"rf_address", "configured_route", "destination_zones"}:
            return words

        if len(words) == 1:
            return words[0]

        return words

    def _call_with_unit(self, method, *, unit: int | None = None, **kwargs):
        """Invoke a pymodbus client method using the appropriate unit/slave keyword."""

        signature = inspect.signature(method)
        target_unit = self.unit_id if unit is None else unit
        if "unit" in signature.parameters:
            kwargs["unit"] = target_unit
        elif "slave" in signature.parameters:
            kwargs["slave"] = target_unit
        return method(**kwargs)


    def _setup_rs485_driver(self) -> bool:
        if not constants.ENABLE_RS485_DRIVER:
            return False

        serial_handle = self._resolve_serial_handle()
        if serial_handle is None:
            raise ModbusAudioError("Unable to locate the underlying serial handle for RS485 RS485Settings")

        try:
            from serial.rs485 import RS485Settings  # type: ignore[import]
        except Exception as exc:  # pragma: no cover - optional dependency
            raise ModbusAudioError("pyserial does not provide RS485 support; install a recent pyserial release") from exc

        lead = constants.RS485_DRIVER_LEAD_SECONDS if constants.RS485_DRIVER_LEAD_SECONDS > 0 else None
        tail = constants.RS485_DRIVER_TAIL_SECONDS if constants.RS485_DRIVER_TAIL_SECONDS > 0 else None

        settings = RS485Settings(
            rts_level_for_tx=constants.RS485_DRIVER_RTS_TX_HIGH,
            rts_level_for_rx=constants.RS485_DRIVER_RTS_RX_HIGH,
            delay_before_tx=lead,
            delay_before_rx=tail,
        )

        try:
            serial_handle.rs485_mode = settings  # type: ignore[attr-defined]
        except Exception as exc:
            raise ModbusAudioError(f"Unable to enable RS485 mode via serial driver: {exc}") from exc

        if constants.RS485_GPIO_DEBUG:
            print(
                "[RS485] Enabled kernel RS485 mode",
                f"(tx_level={'HIGH' if constants.RS485_DRIVER_RTS_TX_HIGH else 'LOW'}, "
                f"rx_level={'HIGH' if constants.RS485_DRIVER_RTS_RX_HIGH else 'LOW'}, "
                f"lead={lead}, tail={tail})",
                file=sys.stderr,
                flush=True,
            )
        return True

    def _setup_rs485_direction_control(self) -> None:
        if self._rs485_controller is not None:
            return

        if not (constants.ENABLE_RS485_GPIO or constants.ENABLE_RS485_PINCTRL):
            return

        serial_handle = self._resolve_serial_handle()
        if serial_handle is None:
            raise ModbusAudioError("Unable to locate the underlying serial handle for RS485 control")

        controller: _RS485Controller
        if constants.ENABLE_RS485_GPIO:
            try:
                controller = _RS485Controller(
                    chip=constants.RS485_GPIO_CHIP,
                    line_offset=constants.RS485_GPIO_LINE_OFFSET,
                    active_high=constants.RS485_GPIO_ACTIVE_HIGH,
                    consumer=constants.RS485_GPIO_CONSUMER,
                )
            except Exception as exc:
                raise ModbusAudioError(f"Unable to configure RS485 GPIO control: {exc}") from exc
        elif constants.ENABLE_RS485_PINCTRL:
            try:
                line_handle = _PinCtrlLineHandle(
                    binary=constants.RS485_PINCTRL_BINARY,
                    pin=constants.RS485_PINCTRL_PIN,
                    timeout=constants.RS485_PINCTRL_TIMEOUT,
                )
                controller = _RS485Controller(
                    chip=None,
                    line_offset=None,
                    active_high=True,
                    consumer="pinctrl",
                    line_driver=line_handle,
                )
            except Exception as exc:
                raise ModbusAudioError(f"Unable to configure RS485 pinctrl control: {exc}") from exc
        else:
            return

        try:
            controller.attach(serial_handle)
        except Exception:
            controller.close()
            raise

        self._rs485_controller = controller

    def _resolve_serial_handle(self):
        candidates = (
            "transport",
            "socket",
            "serial",
            "_serial",
            "client",
        )
        for name in candidates:
            handle = getattr(self._client, name, None)
            if handle is not None and hasattr(handle, "write") and hasattr(handle, "read"):
                return handle
        return None


def _import_gpiod():
    """Import gpiod, optionally extending sys.path with local site-packages."""

    try:
        import gpiod  # type: ignore[import]
    except ModuleNotFoundError:
        search_paths: list[str] = []
        extra_paths = os.environ.get("MODBUS_RS485_GPIO_PYTHONPATH", "")
        if extra_paths:
            search_paths.extend(part for part in extra_paths.split(":") if part)

        project_root = Path(__file__).resolve().parents[2]
        venv_dir = project_root / ".venv"
        if venv_dir.exists():
            for pattern in ("lib/python*/site-packages", "lib64/python*/site-packages"):
                for candidate in venv_dir.glob(pattern):
                    search_paths.append(str(candidate))

        appended = False
        for path_str in search_paths:
            if not path_str:
                continue
            candidate = Path(path_str)
            if not candidate.exists():
                continue
            if path_str not in sys.path:
                sys.path.insert(0, path_str)
            appended = True
        if not appended:
            raise
        import gpiod  # type: ignore[import]  # noqa: F401
    return gpiod  # type: ignore[name-defined]


class _BaseLineDriver:
    """Common interface for RS485 direction helpers."""

    def drive(self, level: bool, *, force: bool = False) -> None:
        raise NotImplementedError

    def read(self) -> int:
        raise NotImplementedError

    def close(self) -> None:
        pass


class _GPIOLineHandle(_BaseLineDriver):
    """Wrap a GPIO line so we can drive and read it consistently across libgpiod versions."""

    def __init__(self, *, chip: str, line_offset: int, consumer: str, initial_level: bool) -> None:
        gpiod = _import_gpiod()

        self.chip_name = chip
        self.line_offset = line_offset
        self._state: bool | None = None
        self._released = False
        self._set_raw: Callable[[bool], None]
        self._get_raw: Callable[[], bool]
        self._release: Callable[[], None]

        if hasattr(gpiod, "request_lines"):
            from gpiod import line as line_mod  # type: ignore[import]

            config = {
                line_offset: gpiod.LineSettings(
                    direction=line_mod.Direction.OUTPUT,
                    output_value=line_mod.Value.ACTIVE if initial_level else line_mod.Value.INACTIVE,
                )
            }
            request = gpiod.request_lines(chip, consumer=consumer, config=config)

            def set_raw(level: bool) -> None:
                request.set_value(line_offset, line_mod.Value.ACTIVE if level else line_mod.Value.INACTIVE)

            def get_raw() -> bool:
                return request.get_value(line_offset) == line_mod.Value.ACTIVE

            self._set_raw = set_raw
            self._get_raw = get_raw
            self._release = request.release
        else:  # pragma: no cover - legacy libgpiod v1 fallback
            chip_obj = gpiod.Chip(chip)
            line_obj = chip_obj.get_line(line_offset)
            line_obj.request(consumer=consumer, type=gpiod.LINE_REQ_DIR_OUT)

            def set_raw(level: bool) -> None:
                line_obj.set_value(1 if level else 0)

            def get_raw() -> bool:
                return bool(line_obj.get_value())

            def release() -> None:
                try:
                    line_obj.release()
                finally:
                    chip_obj.close()

            self._set_raw = set_raw
            self._get_raw = get_raw
            self._release = release

        self.drive(initial_level, force=True)

    def drive(self, level: bool, *, force: bool = False) -> None:
        if not force and self._state is level:
            return
        self._set_raw(level)
        self._state = level

    def read(self) -> int:
        return int(self._get_raw())

    def close(self) -> None:
        if self._released:
            return
        try:
            self._release()
        finally:
            self._released = True


class _PinCtrlLineHandle(_BaseLineDriver):
    """Drive RS485 direction via external pinctrl utility."""

    def __init__(self, *, binary: str, pin: int, timeout: float) -> None:
        if not binary:
            raise ModbusAudioError("RS485 pinctrl binary is not configured")
        if pin < 0:
            raise ModbusAudioError("RS485 pinctrl pin must be non-negative")
        self._binary = binary
        self._pin = pin
        self._timeout = timeout if timeout > 0 else 2.0
        self._state: bool | None = None
        self.drive(False, force=True)

    def drive(self, level: bool, *, force: bool = False) -> None:
        if not force and self._state is level:
            return
        mode = "dh" if level else "dl"
        command = [self._binary, str(self._pin), "op", mode]
        try:
            subprocess.run(
                command,
                check=True,
                timeout=self._timeout,
                capture_output=not constants.RS485_GPIO_DEBUG,
            )
        except FileNotFoundError as exc:  # pragma: no cover - depends on system
            raise ModbusAudioError(f"pinctrl binary '{self._binary}' not found") from exc
        except subprocess.TimeoutExpired as exc:
            raise ModbusAudioError(f"pinctrl command timed out after {self._timeout}s") from exc
        except subprocess.CalledProcessError as exc:
            stderr = exc.stderr.decode("utf-8", errors="ignore") if isinstance(exc.stderr, bytes) else exc.stderr
            raise ModbusAudioError(f"pinctrl command failed with exit code {exc.returncode}: {stderr}") from exc
        self._state = level

    def read(self) -> int:
        return int(bool(self._state))

    def close(self) -> None:
        # Nothing to clean up for external pinctrl utility
        return


class _RS485Controller:
    """Manage RS485 direction control via a dedicated GPIO line."""

    def __init__(
        self,
        *,
        chip: str | None,
        line_offset: int | None,
        active_high: bool,
        consumer: str,
        line_driver: _BaseLineDriver | None = None,
    ) -> None:
        self._debug_enabled = constants.RS485_GPIO_DEBUG
        self._serial_handle = None
        self._original_write: Callable[..., object] | None = None
        self._original_flush: Callable[..., object] | None = None
        self._is_transmitting = False

        self._tx_level = True if active_high else False
        self._rx_level = not self._tx_level

        if line_driver is not None:
            self._line: _BaseLineDriver = line_driver
            self._log_debug(
                "Configured pinctrl direction control",
                pin=getattr(line_driver, "_pin", "unknown"),
                binary=getattr(line_driver, "_binary", "pinctrl"),
                initial_level=self._format_level(self._rx_level),
            )
        else:
            if chip is None or line_offset is None:
                raise ModbusAudioError("RS485 GPIO control requires chip and line configuration")
            try:
                self._line = _GPIOLineHandle(
                    chip=chip,
                    line_offset=line_offset,
                    consumer=consumer,
                    initial_level=self._rx_level,
                )
            except ModuleNotFoundError as exc:  # pragma: no cover - optional dependency
                raise ModbusAudioError(
                    "gpiod is not installed; install it in the active interpreter or adjust MODBUS_RS485_GPIO_PYTHONPATH"
                ) from exc
            self._log_debug(
                "Configured GPIO line",
                chip=chip,
                line=line_offset,
                active_high=active_high,
                initial_level=self._format_level(self._rx_level),
            )

    def attach(self, serial_handle) -> None:
        if self._serial_handle is not None:
            return

        original_write = getattr(serial_handle, "write", None)
        if original_write is None:
            raise ModbusAudioError("Serial handle does not expose a writable interface for RS485 control")

        original_flush = getattr(serial_handle, "flush", None)

        def wrapped(data, *args, **kwargs):
            self.transmit()
            try:
                return original_write(data, *args, **kwargs)
            finally:
                if callable(original_flush):
                    try:
                        original_flush()
                    except Exception:
                        pass
                self.receive()

        serial_handle.write = wrapped  # type: ignore[attr-defined]
        self._serial_handle = serial_handle
        self._original_write = original_write
        self._original_flush = original_flush
        self._log_debug("Attached to serial handle", handle=repr(serial_handle))
        self.receive()

    def transmit(self) -> None:
        self._is_transmitting = True
        self._line.drive(self._tx_level)
        self._log_debug("Set GPIO to transmit", level=self._format_level(self._tx_level))
        if constants.RS485_GPIO_PRE_TX_DELAY > 0:
            time.sleep(constants.RS485_GPIO_PRE_TX_DELAY)

    def receive(self) -> None:
        if self._is_transmitting and constants.RS485_GPIO_POST_TX_DELAY > 0:
            time.sleep(constants.RS485_GPIO_POST_TX_DELAY)
        self._line.drive(self._rx_level)
        self._log_debug("Set GPIO to receive", level=self._format_level(self._rx_level))
        self._is_transmitting = False

    def close(self) -> None:
        if self._serial_handle is not None and self._original_write is not None:
            try:
                self._serial_handle.write = self._original_write  # type: ignore[attr-defined]
            except Exception:  # pragma: no cover - best effort cleanup
                pass
        self._serial_handle = None
        self._original_write = None
        self._original_flush = None
        self._is_transmitting = False
        self._log_debug("Detached from serial handle")

        try:
            self.receive()
        except Exception:  # pragma: no cover - GPIO access may fail during teardown
            pass

        try:
            self._line.close()
        except Exception:  # pragma: no cover - best effort cleanup
            pass

    def _log_debug(self, message: str, **kwargs) -> None:
        if not self._debug_enabled:
            return
        details = " ".join(f"{key}={value}" for key, value in kwargs.items())
        text = f"[RS485] {message}"
        if details:
            text = f"{text} ({details})"
        print(text, file=sys.stderr, flush=True)

    @staticmethod
    def _format_level(level: bool) -> str:
        return "HIGH" if level else "LOW"


def _format_modbus_error_details(response) -> str:
    """Render extra debugging information for pymodbus error responses."""

    parts: list[str] = []
    exc_code = getattr(response, "exception_code", None)
    if exc_code is not None:
        parts.append(f"exception code: {exc_code}")
    function_code = getattr(response, "function_code", None)
    if function_code is not None:
        parts.append(f"function code: 0x{function_code:02X}")
    data = getattr(response, "message", None)
    if data:
        parts.append(f"message: {data}")
    if not parts:
        parts.append(f"raw: {repr(response)}")
    return " (" + ", ".join(parts) + ")"


def _resolve_framers() -> dict[str, type]:
    """Locate Modbus framer implementations in the installed pymodbus package."""

    candidates = (
        (
            "pymodbus.transaction",
            "ModbusAsciiFramer",
            "ModbusBinaryFramer",
            "ModbusRtuFramer",
        ),
        (
            "pymodbus.framer.ascii_framer",
            "ModbusAsciiFramer",
            None,
            None,
        ),
        (
            "pymodbus.framer.binary_framer",
            "ModbusBinaryFramer",
            None,
            None,
        ),
        (
            "pymodbus.framer.rtu_framer",
            "ModbusRtuFramer",
            None,
            None,
        ),
        (
            "pymodbus.framer.ascii",
            "ModbusAsciiFramer",
            None,
            None,
        ),
        (
            "pymodbus.framer.binary",
            "ModbusBinaryFramer",
            None,
            None,
        ),
        (
            "pymodbus.framer.rtu",
            "ModbusRtuFramer",
            None,
            None,
        ),
    )

    framers: dict[str, type] = {}
    for module_name, attr1, attr2, attr3 in candidates:
        try:  # pragma: no cover - depends on pymodbus layout
            module = __import__(module_name, fromlist=[name for name in (attr1, attr2, attr3) if name])
        except Exception:
            continue

        if attr1 and hasattr(module, attr1):
            framers.setdefault("ascii", getattr(module, attr1))
        if attr2 and hasattr(module, attr2):
            framers.setdefault("binary", getattr(module, attr2))
        if attr3 and hasattr(module, attr3):
            framers.setdefault("rtu", getattr(module, attr3))

        if {"ascii", "binary", "rtu"}.issubset(framers.keys()):
            break

    return framers
