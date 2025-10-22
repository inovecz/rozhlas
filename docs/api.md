# API Overview

## Live Broadcast

- `POST /api/live-broadcast/start` – start streaming. Payload:
  ```json
  {
    "source": "mic|recording|gsm|radio|jsvv",
    "route": [1, 2, 3],
    "locations": [1,2],
    "options": {}
  }
  ```
- `POST /api/live-broadcast/stop` – stop active stream (`{"reason":"..."}` optional).
- `GET /api/live-broadcast/status` – returns current session, Modbus status, device info.
- `POST /api/live-broadcast/playlist` – enqueue recordings (`recordings` array with ids, duration, gain, gap), plus `route`, `locations`, `options`.
- `POST /api/live-broadcast/playlist/{id}/cancel` – cancel playlist.
- `GET /api/live-broadcast/sources` – list available audio sources.

## JSVV

- `POST /api/jsvv/sequences` – plan sequence. Payload `items` array with `slot`, `category`, optional `voice`, `repeat`.
- `POST /api/jsvv/sequences/{id}/trigger` – execute planned sequence.
- `GET /api/jsvv/assets` – query assets by `category`, `slot`, `voice` query parameters.
- `POST /api/jsvv/events` – webhook endpoint for listener daemon.

## GSM

- `POST /api/gsm/events` – webhook from GSM daemon with `{state, caller, sessionId, metadata}`.
- `GET /api/gsm/whitelist` – list entries.
- `POST /api/gsm/whitelist` – create entry (`number`, `label`, `priority`).
- `PUT /api/gsm/whitelist/{id}` – update.
- `DELETE /api/gsm/whitelist/{id}` – remove.
- `POST /api/gsm/pin/verify` – verify PIN (`sessionId`, `pin`).

## Fm Radio (receiver: MINI TRAGBARER DIGITALER USB 2.0 TV STICK DVB-T + DAB + FM RTL2832U + R820T2 UNTERSTÜTZUNG SDR TUNER RECEIVER)

- `GET /api/fm/frequency` – current frequency.
- `POST /api/fm/frequency` – set new frequency (`frequency`).

## Manual Control & Telemetry

- `POST /api/manual-control/events` – captures future manual panel events.
- `GET /api/stream/telemetry` – list latest telemetry entries (`?since=ISO8601`).

## Control Tab

- `POST /api/control-tab/events` – zpracuje požadavky z Control Tabu (`panel_loaded`, `button_pressed`, `text_field_request`). Odezva obsahuje stav (`ok`, `queued`, `blocked`, ...), případně pozici ve frontě.
- `python-client/modbus_control.py read-alarms` – servisní příkaz pro vyčtení alarmového LIFO bufferu (0x3000–0x3009) ve vysílači.

See `docs/daemons.md` for daemon management and `supervisor/*.conf` for production configs.
