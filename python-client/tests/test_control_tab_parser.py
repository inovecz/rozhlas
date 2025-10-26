from __future__ import annotations

import importlib.util
import sys
from pathlib import Path
import unittest

MODULE_PATH = Path(__file__).resolve().parents[1] / 'daemons' / 'control_tab_listener.py'
spec = importlib.util.spec_from_file_location('control_tab_listener', MODULE_PATH)
assert spec and spec.loader  # for type checkers
control_tab = importlib.util.module_from_spec(spec)
sys.modules['control_tab_listener'] = control_tab
spec.loader.exec_module(control_tab)  # type: ignore[attr-defined]


class ControlTabParserTest(unittest.TestCase):
    def test_parse_valid_frame(self) -> None:
        listener = control_tab.ControlTabListener(  # type: ignore[attr-defined]
            sink=control_tab.BackendSink(None, None, 1),
            transport=None,
            poll_interval=0.05,
            graceful_timeout=1.0,
            retry_backoff=0.25,
        )

        body = '1:2:2=5'
        crc = control_tab.xor_crc(body)  # type: ignore[attr-defined]
        frame = listener._parse_frame(f'<<<:{body}>>{crc}<<<\n')  # type: ignore[attr-defined]
        self.assertIsNotNone(frame)
        assert frame is not None
        self.assertTrue(frame.crc_valid)
        self.assertEqual(frame.screen, 1)
        self.assertEqual(frame.panel, 2)
        self.assertEqual(frame.event_type, 2)

    def test_crc_calculation(self) -> None:
        crc = control_tab.xor_crc('1:1:2=5')  # type: ignore[attr-defined]
        self.assertEqual(crc, '3A')


if __name__ == '__main__':  # pragma: no cover
    unittest.main()
