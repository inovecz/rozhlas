import importlib.util
import logging
import sys
from pathlib import Path
from unittest import TestCase, mock

MODULE_PATH = Path(__file__).resolve().parents[1] / "daemons" / "gpio_button_listener.py"
MODULE_NAME = "gpio_button_listener"

spec = importlib.util.spec_from_file_location(MODULE_NAME, MODULE_PATH)
gpio_button_listener = importlib.util.module_from_spec(spec)
sys.modules[MODULE_NAME] = gpio_button_listener
assert spec.loader is not None
spec.loader.exec_module(gpio_button_listener)

ButtonConfig = gpio_button_listener.ButtonConfig
ButtonWatcher = gpio_button_listener.ButtonWatcher


class DummyReader(gpio_button_listener._BaseGpioReader):  # type: ignore[attr-defined]
    def __init__(self, sequence: list[int]) -> None:
        self._sequence = sequence
        self._position = 0

    def read(self) -> int:
        if self._position >= len(self._sequence):
            return self._sequence[-1]
        value = self._sequence[self._position]
        self._position += 1
        return value


class ButtonWatcherTest(TestCase):
    @mock.patch("gpio_button_listener.requests.post")
    @mock.patch("gpio_button_listener.time.monotonic")
    def test_press_triggers_webhook(self, monotonic: mock.MagicMock, post: mock.MagicMock) -> None:
        monotonic.side_effect = [0.0, 0.1, 0.2, 0.3]
        reader = DummyReader([0, 1, 1, 0])
        config = ButtonConfig(
            chip="gpiochip2",
            line_offset=0,
            active_level=1,
            debounce_seconds=0.05,
            release_debounce_seconds=0.05,
            poll_interval=0.05,
            cooldown_seconds=0.0,
            button_id=99,
            screen=1,
            panel=2,
            webhook="https://example.invalid/control-tab",
            token="test-token",
            timeout=3.0,
            session_prefix="gpio",
            backend="auto",
            consumer="test-consumer",
            require_release=False,
        )

        watcher = ButtonWatcher(reader, config, logging.getLogger("test"))

        watcher.poll()  # idle -> no trigger
        watcher.poll()  # state change to active, debounce countdown
        watcher.poll()  # active long enough -> trigger webhook

        post.assert_called_once()
        kwargs = post.call_args.kwargs
        self.assertEqual(kwargs["headers"]["Authorization"], "Bearer test-token")
        self.assertEqual(kwargs["json"]["button_id"], 99)

    @mock.patch("gpio_button_listener.requests.post")
    @mock.patch("gpio_button_listener.time.monotonic")
    def test_require_release_blocks_retrigger_until_button_is_released(self, monotonic: mock.MagicMock, post: mock.MagicMock) -> None:
        monotonic.side_effect = [0.0, 0.1, 0.3, 0.6, 0.9, 1.2]
        reader = DummyReader([0, 1, 1, 1, 0, 1])
        config = ButtonConfig(
            chip="gpiochip2",
            line_offset=0,
            active_level=1,
            debounce_seconds=0.05,
            release_debounce_seconds=0.05,
            poll_interval=0.05,
            cooldown_seconds=0.0,
            button_id=42,
            screen=0,
            panel=0,
            webhook="https://example.invalid/control-tab",
            token=None,
            timeout=3.0,
            session_prefix="gpio",
            backend="auto",
            consumer="test-consumer",
            require_release=True,
        )

        watcher = ButtonWatcher(reader, config, logging.getLogger("test"))

        watcher.poll()  # idle
        watcher.poll()  # transition -> no trigger yet
        watcher.poll()  # trigger
        watcher.poll()  # still pressed, require_release blocks new trigger
        watcher.poll()  # release detected
        watcher.poll()  # pressed again after release -> trigger second time

        self.assertEqual(post.call_count, 2)
