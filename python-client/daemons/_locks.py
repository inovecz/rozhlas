from __future__ import annotations

import hashlib
import os
import tempfile
import time
from pathlib import Path
from typing import Optional

import fcntl


class PortLock:
    """Filesystem-based advisory lock to guard exclusive access to serial ports."""

    def __init__(self, port: str, *, timeout: float = 5.0, retry_delay: float = 0.1) -> None:
        if not port:
            raise ValueError("PortLock requires a non-empty port identifier.")
        self._port = port
        digest = hashlib.sha256(port.encode("utf-8")).hexdigest()
        self._lock_path = Path(tempfile.gettempdir()) / f"rozhlas-port-{digest}.lock"
        self._timeout = max(0.1, timeout)
        self._retry_delay = max(0.01, retry_delay)
        self._handle: Optional[object] = None

    def __enter__(self) -> "PortLock":
        self.acquire()
        return self

    def __exit__(self, exc_type, exc, tb) -> None:  # noqa: ANN001
        self.release()

    def acquire(self) -> None:
        deadline = time.monotonic() + self._timeout
        self._lock_path.parent.mkdir(parents=True, exist_ok=True)
        self._handle = open(self._lock_path, "w", encoding="utf-8")
        while True:
            try:
                fcntl.flock(self._handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                self._handle.truncate(0)
                self._handle.write(str(os.getpid()))
                self._handle.flush()
                return
            except BlockingIOError as exc:  # pragma: no cover - timing dependent
                if time.monotonic() >= deadline:
                    raise RuntimeError(f"Port '{self._port}' is already locked by another process.") from exc
                time.sleep(self._retry_delay)

    def release(self) -> None:
        if self._handle is None:
            return
        try:
            fcntl.flock(self._handle, fcntl.LOCK_UN)
        finally:
            self._handle.close()
            self._handle = None
            try:
                self._lock_path.unlink(missing_ok=True)  # type: ignore[arg-type]
            except TypeError:  # Python <3.8 compatibility
                if self._lock_path.exists():
                    self._lock_path.unlink()
