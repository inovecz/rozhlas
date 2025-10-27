#!/usr/bin/env python3

import argparse
import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone
from functools import lru_cache
from pathlib import Path
from subprocess import CalledProcessError, Popen
from typing import Any

DEFAULT_EXIT_CODES = {
    'missing_args': 1,
    'state_dir': 2,
    'missing_preset': 3,
    'exists': 4,
    'binary': 5,
}


def project_root() -> Path:
    return Path(__file__).resolve().parent.parent


def ensure_state_dir(path: Path) -> None:
    try:
        path.mkdir(mode=0o775, parents=True, exist_ok=True)
    except OSError as exc:
        print(f"Unable to create state directory: {path} ({exc})", file=sys.stderr)
        sys.exit(DEFAULT_EXIT_CODES['state_dir'])


def ensure_binary(binary: str) -> str:
    trimmed = (binary or '').strip()
    if trimmed == '':
        print('Binary path cannot be empty.', file=sys.stderr)
        sys.exit(DEFAULT_EXIT_CODES['binary'])
    return trimmed


def stream_process(command: list[str]) -> int:
    try:
        with Popen(command, stdout=sys.stdout, stderr=sys.stderr) as process:
            return process.wait() or 0
    except FileNotFoundError:
        print(f'Binary not found: {command[0]}', file=sys.stderr)
        return DEFAULT_EXIT_CODES['binary']
    except CalledProcessError as exc:
        return exc.returncode or 1


def run_command(command: list[str]) -> tuple[int, str, str]:
    result = subprocess.run(
        command,
        capture_output=True,
        text=True,
        check=False,
    )
    return result.returncode, result.stdout, result.stderr


def list_presets(state_dir: Path) -> list[str]:
    return sorted(file.stem for file in state_dir.glob('*.state'))


def parse_card_list(raw: str) -> list[dict[str, Any]]:
    devices: list[dict[str, Any]] = []
    card_pattern = re.compile(
        r"card\s+(?P<card>\d+):\s+(?P<card_name>[^ ]+)\s+\[(?P<card_desc>[^\]]+)\],\s+device\s+(?P<device>\d+):\s+(?P<device_name>[^\[]+)\[(?P<device_desc>[^\]]+)\]"
    )
    for line in raw.splitlines():
        match = card_pattern.search(line)
        if not match:
            continue
        devices.append(
            {
                'card': int(match.group('card')),
                'device': int(match.group('device')),
                'card_name': match.group('card_name'),
                'card_description': match.group('card_desc'),
                'device_name': match.group('device_name').strip(),
                'device_description': match.group('device_desc'),
            }
        )
    return devices


@lru_cache(maxsize=None)
def list_controls_for_card(card: int) -> list[str]:
    code, stdout, _ = run_command(['amixer', '-c', str(card), 'controls'])
    if code != 0:
        return []
    names: list[str] = []
    name_pattern = re.compile(r"name='([^']+)'")
    for line in stdout.splitlines():
        match = name_pattern.search(line)
        if match:
            names.append(match.group(1))
    return names


def list_pulse_devices() -> dict[str, list[dict[str, Any]]]:
    sinks: list[dict[str, Any]] = []
    sources: list[dict[str, Any]] = []

    code, stdout, _ = run_command(['pactl', 'list', 'short', 'sinks'])
    if code == 0:
        for line in stdout.splitlines():
            parts = line.split('\t')
            if len(parts) >= 5:
                sinks.append(
                    {
                        'index': int(parts[0]),
                        'name': parts[1],
                        'driver': parts[2],
                        'sample_spec': parts[3],
                        'state': parts[4],
                    }
                )

    code, stdout, _ = run_command(['pactl', 'list', 'short', 'sources'])
    if code == 0:
        for line in stdout.splitlines():
            parts = line.split('\t')
            if len(parts) >= 5:
                sources.append(
                    {
                        'index': int(parts[0]),
                        'name': parts[1],
                        'driver': parts[2],
                        'sample_spec': parts[3],
                        'state': parts[4],
                    }
                )

    return {'sinks': sinks, 'sources': sources}


def detect_audio_devices() -> dict[str, Any]:
    playback_devices: list[dict[str, Any]] = []
    capture_devices: list[dict[str, Any]] = []

    code, stdout, stderr = run_command(['aplay', '-l'])
    if code == 0:
        playback_devices = parse_card_list(stdout)
    else:
        playback_devices = [{'error': stderr.strip() or 'Unable to list playback devices'}]

    code, stdout, stderr = run_command(['arecord', '-l'])
    if code == 0:
        capture_devices = parse_card_list(stdout)
    else:
        capture_devices = [{'error': stderr.strip() or 'Unable to list capture devices'}]

    for device in playback_devices:
        card = device.get('card')
        if card is None:
            continue
        device['controls'] = list_controls_for_card(int(card))

    for device in capture_devices:
        card = device.get('card')
        if card is None:
            continue
        device['controls'] = list_controls_for_card(int(card))

    pulse = list_pulse_devices()

    code, stdout, _ = run_command(['amixer', '-D', 'pulse', 'scontrols'])
    simple_controls = [line.strip() for line in stdout.splitlines() if line.strip()] if code == 0 else []

    return {
        'playback_devices': playback_devices,
        'capture_devices': capture_devices,
        'pulse': pulse,
        'pulse_controls': simple_controls,
        'timestamp': datetime.now(timezone.utc).isoformat(),
    }


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        prog='alsamixer-wrapper',
        description='Helper utility to manage ALSA presets via alsactl.',
    )
    parser.add_argument(
        '--card',
        default=os.getenv('BROADCAST_MIXER_CARD', '0'),
        help='ALSA card index (default: %(default)s)',
    )
    parser.add_argument(
        '--state-dir',
        default=os.getenv(
            'BROADCAST_MIXER_STATE_DIR', str(project_root() / 'storage/mixer-presets')
        ),
        help='Directory with .state files',
    )

    subparsers = parser.add_subparsers(dest='command', required=True)

    preset_parser = subparsers.add_parser('preset', help='Apply stored ALSA state')
    preset_parser.add_argument('name', help='Preset name to restore')

    reset_parser = subparsers.add_parser('reset', help='Restore default preset')
    reset_parser.add_argument(
        'name', nargs='?', default='default', help='Preset name to restore (default: default)'
    )

    save_parser = subparsers.add_parser('save', help='Capture current ALSA state')
    save_parser.add_argument('name', help='Preset name to store')
    save_parser.add_argument(
        '--force', action='store_true', help='Overwrite existing preset if it already exists'
    )

    subparsers.add_parser('list', help='List available presets')
    subparsers.add_parser('devices', help='List available playback/capture devices and mixer controls')

    return parser.parse_args()


def main() -> None:
    args = parse_arguments()
    state_dir = Path(args.state_dir).expanduser()
    if str(state_dir).strip() == '':
        print('State directory cannot be empty.', file=sys.stderr)
        sys.exit(DEFAULT_EXIT_CODES['state_dir'])

    ensure_state_dir(state_dir)

    if args.command == 'devices':
        devices = detect_audio_devices()
        print(json.dumps(devices, ensure_ascii=False))
        sys.exit(0)

    alsactl = ensure_binary(os.getenv('ALSAMIXER_WRAPPER_ALSACTL', 'alsactl'))
    card = str(args.card)

    if args.command in {'preset', 'reset'}:
        preset_name = args.name
        state_file = state_dir / f'{preset_name}.state'
        if not state_file.is_file():
            available = list_presets(state_dir)
            print(f'Preset file not found: {state_file}', file=sys.stderr)
            if available:
                print('Available presets: ' + ', '.join(available), file=sys.stderr)
            sys.exit(DEFAULT_EXIT_CODES['missing_preset'])

        exit_code = stream_process([alsactl, '--file', str(state_file), 'restore', card])
        sys.exit(exit_code)

    if args.command == 'save':
        state_file = state_dir / f'{args.name}.state'
        if state_file.is_file() and not args.force:
            print(
                f'Preset already exists: {state_file}. Use --force to overwrite.',
                file=sys.stderr,
            )
            sys.exit(DEFAULT_EXIT_CODES['exists'])

        exit_code = stream_process([alsactl, '--file', str(state_file), 'store', card])
        sys.exit(exit_code)

    if args.command == 'list':
        presets = list_presets(state_dir)
        if not presets:
            print(f'No presets stored in {state_dir}')
        else:
            print('\n'.join(presets))
        sys.exit(0)

    print(f'Unknown command: {args.command}', file=sys.stderr)
    sys.exit(DEFAULT_EXIT_CODES['missing_args'])


if __name__ == '__main__':
    main()
