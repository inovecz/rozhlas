# Production Audio Setup Notes

This file tracks the knobs that must be adjusted on the production
installation so the broadcast chain behaves the same way every time we
deploy or reprovision a controller. The development `.env` is tuned for
a sandbox; production needs the items below.

## Environment variables

- `AUDIO_ROUTING_ENABLED=true` – ensures ALSA routing is applied before the
  stream starts. When set to `false`, Laravel skips the `amixer` commands and
  the stream plays through the fallback output specified by
  `AUDIO_FALLBACK_OUTPUT` (defaults to the ALSA `default` device).
- `AUDIO_MIXER_ENABLED=true` – enable the external mixer preset runner
  if production still relies on the legacy wrapper script. When disabled,
  `MixerController` will log and skip preset invocations (routing continues
  to be handled by `AudioIoService` as long as the first flag stays enabled).
- `AUDIO_MIXER_BINARY` – ponechte nevyplněné pro výchozí chování (aplikace
  použije lokální PHP interpreter a spustí `artisan audio:preset …`). Pokud je
  potřeba externí wrapper, přepište proměnnou cestou ke skriptu a upravte
  argumenty v `config/broadcast.php`.
- `AUDIO_FALLBACK_OUTPUT=default` – ALSA device that should receive
  audio when routing is disabled. Change only if the sender expects a specific
  hardware name (e.g. `hw:1,0`).
- `AUDIO_MIXER_CARD=<card index>` – production hardware usually
  enumerates as a different ALSA card than a laptop. Verify via
  `aplay -l` and update `.env`, because the volume template
  (`config/volume.php:11`) uses this value for all `amixer` calls.
- `AUDIO_LIVE_SOURCE=<source id>` – set to the actual input that
  should be selected when a live stream starts. The valid IDs and their
  ALSA controls come from `config/volume.php:180`. Example: use
  `pc_webrtc` if the sender should pick the PC line input.
- `AUDIO_LIVE_ROUTE` / `AUDIO_LIVE_ZONES` – replace the defaults
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

Předvolby definované v `config/audio.php` a `config/broadcast.php`
spouští příkaz `php artisan audio:preset <preset>`, který zajistí
přepnutí vstupu/výstupu přes ALSA (pomocí `amixer`) a zároveň aplikuje
aktuálně uložené hlasitosti (`VolumeManager`). Ověření:

```bash
php artisan audio:preset microphone
php artisan audio:preset system_audio
php artisan audio:preset fm_radio
```

Pro skriptové použití je k dispozici helper `scripts/audio/apply-preset.sh`.

To validate a preset:

```bash
sudo -u www-data php artisan audio:preset pc_webrtc
amixer -c "$AUDIO_MIXER_CARD" sget 'PC WebRTC'
```

You should see the control unmuted and set to the expected level.

## Quick health checks

1. `amixer -c $AUDIO_MIXER_CARD sget 'PC WebRTC'` – verify levels
   before and after a live stream start.
2. `speaker-test -c 2 -D plughw:$AUDIO_MIXER_CARD` – confirm the
   OS can emit audio on the card that feeds the sender.
3. `python-client/modbus_control.py status` – validates the Modbus link
   and reports the TxControl register without starting a stream.

Document any other production-only deviations here (GPIO wiring,
external amplifiers, etc.) as they come up.

### Wrapper binary

Výchozí implementace již nepotřebuje externí wrapper – jednotlivé
presety obslouží artisan příkaz zmíněný výše. Pokud by bylo nutné
použít specializovaný nástroj (např. kvůli jinému mixážnímu jádru),
zapněte vlastní wrapper přes `AUDIO_MIXER_BINARY` a přizpůsobte
argumenty v konfiguraci.
