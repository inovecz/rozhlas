# Production Audio Setup Notes

This file tracks the knobs that must be adjusted on the production
installation so the broadcast chain behaves the same way every time we
deploy or reprovision a controller. The development `.env` is tuned for
a sandbox; production needs the items below.

## Environment variables

- `BROADCAST_AUDIO_ROUTING_ENABLED=true` – ensures ALSA routing is applied
  before the stream starts. When set to `false`, Laravel skips the `amixer`
  commands and the stream plays through the fallback output specified by
  `BROADCAST_AUDIO_FALLBACK_OUTPUT` (defaults to the ALSA `default` device).
- `BROADCAST_MIXER_ENABLED=true` – enable the external mixer preset runner
  if production still relies on the legacy wrapper script. When disabled,
  `MixerController` will log and skip preset invocations (routing continues
  to be handled by `AudioIoService` as long as the first flag stays enabled).
- `BROADCAST_MIXER_BINARY=/path/to/scripts/alsamixer-wrapper.php` – point
  this to the helper script included in the repo (or an equivalent vendor
  tool). The stock `alsamixer` TUI is interactive-only; the wrapper shells
  out to `alsactl` so the preset arguments defined in `config/broadcast.php:70`
  work in unattended mode.
- `BROADCAST_AUDIO_FALLBACK_OUTPUT=default` – ALSA device that should receive
  audio when routing is disabled. Change only if the sender expects a specific
  hardware name (e.g. `hw:1,0`).
- `BROADCAST_MIXER_CARD=<card index>` – production hardware usually
  enumerates as a different ALSA card than a laptop. Verify via
  `aplay -l` and update `.env`, because the volume template
  (`config/volume.php:11`) uses this value for all `amixer` calls.
- `BROADCAST_LIVE_SOURCE=<source id>` – set to the actual input that
  should be selected when a live stream starts. The valid IDs and their
  ALSA controls come from `config/volume.php:180`. Example: use
  `pc_webrtc` if the sender should pick the PC line input.
- `BROADCAST_LIVE_ROUTE` / `BROADCAST_LIVE_ZONES` – replace the defaults
  with the production hop route and destination zone IDs (if we leave
  them empty, the Python client falls back to the baked-in demo values
  from `python-client/src/modbus_audio/constants.py`).
- `MODBUS_PORT=/dev/ttyUSB0` (or the real UART) – production boxes tend
  to expose a different serial path than dev. If access to the radio
  fails with “Unable to open serial port”, check this first.

After editing `.env`, always run:

```bash
php artisan config:clear
php artisan config:cache
```

## Mixer presets

The preset names in `config/broadcast.php:70` are passed straight to
the mixer binary (`app/Services/Mixer/MixerController.php:36`). If the
production device does not ship with a CLI that understands `preset
<name>`, capture the expected register changes with `alsactl store` or
`amixer` and wrap them in a custom script. Point
`BROADCAST_MIXER_BINARY` to that script so the Laravel service keeps
working unchanged.

To validate a preset:

```bash
sudo -u www-data /path/to/mixer-binary preset pc-webrtc
amixer -c "$BROADCAST_MIXER_CARD" sget 'PC WebRTC'
```

You should see the control unmuted and set to the expected level.

## Quick health checks

1. `amixer -c $BROADCAST_MIXER_CARD sget 'PC WebRTC'` – verify levels
   before and after a live stream start.
2. `speaker-test -c 2 -D plughw:$BROADCAST_MIXER_CARD` – confirm the
   OS can emit audio on the card that feeds the sender.
3. `python-client/modbus_control.py status` – validates the Modbus link
   and reports the TxControl register without starting a stream.

Document any other production-only deviations here (GPIO wiring,
external amplifiers, etc.) as they come up.

### Wrapper binary

Set `BROADCAST_MIXER_BINARY` to the project helper script:

```bash
BROADCAST_MIXER_BINARY=${PROJECT_ROOT}/scripts/alsamixer-wrapper.php
```

The wrapper expects `.state` files under `storage/mixer-presets`.
Create them on the production box with:

```bash
php scripts/alsamixer-wrapper.php save microphone
```

and edit as needed with `alsamixer` before running the `save` command.
Use `reset` (defaults to the `default.state` file) to return the mixer
into a safe idle configuration after each broadcast.
