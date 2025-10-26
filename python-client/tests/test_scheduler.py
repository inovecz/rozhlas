from __future__ import annotations

import importlib.util
import sys
import time
import unittest
import unittest.mock
from pathlib import Path

MODULE_PATH = Path(__file__).resolve().parents[1] / 'daemons' / 'jsvv_listener.py'
SRC_PATH = Path(__file__).resolve().parents[1] / 'src'
if str(SRC_PATH) not in sys.path:
    sys.path.insert(0, str(SRC_PATH))

MODULE_NAME = 'jsvv_listener_test'
spec = importlib.util.spec_from_file_location(MODULE_NAME, MODULE_PATH)
assert spec and spec.loader  # for type checkers
jsvv_listener = importlib.util.module_from_spec(spec)
sys.modules[MODULE_NAME] = jsvv_listener
spec.loader.exec_module(jsvv_listener)  # type: ignore[attr-defined]

DispatchTask = jsvv_listener.DispatchTask
PriorityScheduler = jsvv_listener.PriorityScheduler


class PrioritySchedulerTest(unittest.TestCase):
    def test_priority_ordering(self) -> None:
        scheduler = PriorityScheduler()

        low = DispatchTask(payload={'command': 'TEST_LOW'}, raw_message='LOW', priority='P3', duplicate=False)
        high = DispatchTask(payload={'command': 'TEST_HIGH'}, raw_message='HIGH', priority='P1', duplicate=False)

        scheduler.put(low)
        scheduler.put(high)

        first = scheduler.get()
        second = scheduler.get()

        self.assertIsNotNone(first)
        self.assertIsNotNone(second)
        self.assertEqual(first.priority, 'P1')
        self.assertEqual(second.priority, 'P3')

        scheduler.stop()

    def test_schedule_retry_exponential_backoff(self) -> None:
        task = DispatchTask(payload={'command': 'RETRY'}, raw_message='R', priority='P2', duplicate=False)
        backoff = 0.25

        with unittest.mock.patch.object(jsvv_listener.time, 'monotonic', return_value=0.0):
            task.schedule_retry(backoff)
            first_delay = task.next_attempt_at

        with unittest.mock.patch.object(jsvv_listener.time, 'monotonic', return_value=0.0):
            task.schedule_retry(backoff)
            second_delay = task.next_attempt_at

        self.assertAlmostEqual(first_delay, backoff, places=2)
        self.assertAlmostEqual(second_delay, backoff * 2, places=2)

    def test_respects_retry_delay(self) -> None:
        scheduler = PriorityScheduler()
        task = DispatchTask(payload={'command': 'DELAY'}, raw_message='DELAY', priority='P2', duplicate=False)
        task.next_attempt_at = time.monotonic() + 0.2
        scheduler.put(task)

        start = time.monotonic()
        retrieved = scheduler.get()
        elapsed = time.monotonic() - start

        self.assertGreaterEqual(elapsed, 0.18)
        self.assertIs(retrieved, task)

        scheduler.stop()


if __name__ == '__main__':  # pragma: no cover
    unittest.main()
