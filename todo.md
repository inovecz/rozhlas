# LIST OF TASKS TO BE DONE

## MODBUS

### Start direct stream
- Backend  
  - Create controller actions `LiveBroadcastController@start/stop` delegating to a new `LiveBroadcastOrchestrator`.  
  - Orchestrator: validate request (source, route, zones), load environment config (production mixer vs dev), call `PythonClient::startStream(route?, zones?)`.  
  - Persist active session row (start time, source, operator, route, zones, process ids) and append protocol log entry.  
  - On stop, ensure Modbus stop, fade out audio if required, and mark session as completed.  
  - Expose GET `/api/live-broadcast/status` returning Modbus status registers, device info snapshot, and current session data.
- Frontend (`/live-broadcast`)  
  - Build reactive form with source dropdown, route array input, zone selector (multi-select).  
  - Poll or subscribe to status endpoint for telemetry (TxControl, Status, Error registers) and session timeline.  
  - Provide manual stop button and feedback modals on errors.  
  - Render protocol history list with timestamps.
- Service layer & infrastructure  
  - Implement health checks for Python CLI availability and serial port connectivity before starting stream.  
  - Broadcast Laravel events (e.g., via websockets) when stream starts/stops or when errors occur.  
  - Add queue job for long-running monitoring if needed (e.g., auto-retry on disconnect).

### Start stream from recordings
- Backend  
  - Update recordings DB schema to store file path, duration, codec, loudness.  
  - Create endpoint `/api/live-broadcast/playlist` accepting ordered recording ids + target zones/route.  
  - Job dispatcher: enqueue `RecordingBroadcastJob` that  
    1. Calls `PythonClient::startStream()`  
    2. Uses ffmpeg/sox to concatenate files (respecting gaps, trimming, gain) and send to configured audio output  
    3. Tracks playback progress and publishes updates  
    4. Calls `stopStream()` on completion/failure.  
  - Support resume/cancel commands; ensure partial progress is handled gracefully.  
  - Store playback logs (start/end times, operator, files played, duration).
- Frontend (`/recordings`)  
  - Playlist builder UI with drag/drop ordering, preview, and metadata display.  
  - Show real-time progress bar, elapsed time, and current file.  
  - Provide cancel button that triggers backend stop + playback abort.  
  - Display playlist history with ability to re-run previous sequences.
- Monitoring/testing  
  - Add integration tests for job pipeline (mock ffmpeg/process).  
  - Verify concurrency: block additional broadcasts while playlist active unless override is confirmed.

### Start stream via manual control panel (pending protocol)
- Python client  
  - Create module skeleton `python-client/manual_control/listener.py` exposing start/stop functions, configuration loader (port, protocol, auth).  
  - Stub message parsing with TODO for vendor-specified protocol; include logging hooks and error handling.
- Documentation & contracts  
  - Draft protocol specification placeholder (message format, commands, responses).  
  - Define event bus interface (e.g., Redis pub/sub channel) for passing decoded commands to Laravel when ready.
- Backend readiness  
  - Add service provider binding `ManualControlServiceInterface` with no-op implementation.  
  - Prepare configuration entries `.env` keys (MANUAL_CONTROL_PORT, SECRET, etc.) with comments awaiting vendor info.

### Stream triggered from GSM module
- Hardware integration  
  - Evaluate available driver/API for Waveshare GSM module; implement daemon that emits events (incoming call, accepted, rejected) and provides audio stream interface.  
  - Ensure daemon exposes REST/websocket or message queue for Laravel to consume.
- Backend  
  - Create tables: `gsm_whitelist` (numbers, labels, priority), `gsm_pin_codes` (code, expiration, attempt counter), `gsm_call_sessions`.  
  - Implement verification flow: check whitelist → if absent, prompt DTMF PIN → validate and escalate to admin if repeated failures.  
  - On successful auth: start Modbus stream with GSM audio source, monitor call state, stop on hang-up or manual termination.  
  - Capture audio metrics (duration, quality diagnostics) and store in session log.  
  - Provide admin UI for managing whitelist, PINs, viewing call/stream history, and configuring fallback numbers.
- Security & resilience  
  - Apply rate limiting for PIN attempts, alert administrators on suspicious activity.  
  - Implement watchdog to release Modbus resources if GSM daemon disconnects unexpectedly.

## JSVV

### Trigger manual JSVV alarm
- Backend  
  - Scan asset directory on boot, cache metadata (filename, category, priority, default duration).  
  - Endpoint `/api/jsvv/sequences` to create/preview manual sequences (list of asset ids, repeat counts, pre/post delays).  
  - When sequence submitted:  
    1. Pause/stop conflicting streams depending on priority rules.  
    2. Start Modbus stream with appropriate zones/route (configurable).  
    3. Invoke `jsvv_control.py` (future) to play frames/assets in requested order.  
    4. Resume interrupted stream if policy allows.  
  - Audit log entries capturing operator, assets, outcome.
- Frontend  
  - Sequence builder with asset catalogue (search/filter by category), timeline preview, expected duration, and priority selection.  
  - Display real-time status (current frame/asset, elapsed time), allow cancel, and show post-run summary.  
  - Provide templates for common alarms to speed up manual triggering.
- Priority management  
  - Define rule set for handling conflicts between JSVV manual alarms, automated alarms, and regular broadcasts.  
  - Implement queue/stack to ensure higher priority actions pre-empt lower ones cleanly.

### Listen on KPPS device
- Listener (Python)  
  - Build `jsvv_control.py` with serial/network listener for KPPS frames, CRC validation, duplication filtering, and TLS/auth support if required.  
  - Emit structured events (JSON) including timestamp, MID, parameters, asset references, priority.
- Backend dispatcher  
  - Create queue consumer that maps events to actionable commands (e.g., start siren, stop broadcast, change frequency).  
  - Integrate with stream orchestrator to pause/resume other sources respecting priority.  
  - Provide override options (acknowledge/ignore).
- Diagnostics  
  - Persist every frame and action to `jsvv_events` table with raw payload, parsed data, handler result, and operator acknowledgements.  
  - Build dashboard showing live feed and history with filtering/search.

### Two-way alarms
- Documentation review  
  - Extract list of two-way MIDs, required responses, timeout expectations from vendor docs.  
  - Define state machine per alarm (e.g., incoming request → confirm → execute → report back).
- Implementation  
  - Add backend services to manage conversation state, send responses via KPPS link, and notify operators for manual intervention when needed.  
  - Implement UI components showing pending alarms, countdown timers, and action buttons (ack, escalate).  
  - Push notifications or SMS/email alerts for critical alarms requiring acknowledgement.
- Testing  
  - Extend simulator to generate two-way scenarios; write automated acceptance tests covering success, timeout, and error paths.

### Setup FM radio frequency via JSVV
- Backend  
  - Add command handler mapping relevant JSVV frame to FM tuning service.  
  - Implement mutex/lock to prevent overlapping frequency updates; queue requests if busy.  
  - Record change history with origin (manual/JSVV) and broadcast to subscribers.
- UI  
  - Display last known frequency, origin of change, and warnings when frequency is controlled externally.  
  - Provide manual override option with confirmation if JSVV currently controls frequency.

### Additional documented actions
- Documentation mapping  
  - Build command matrix: MID, description, parameters, priority, required backend action.  
  - For each, decide whether manual trigger, automated handler, or both are needed.
- Implementation  
  - Extend python control script to support necessary writes/reads (e.g., zone config, verbal assets).  
  - Add Laravel services/endpoints and UI components to invoke these actions.  
  - Update role-based permissions to restrict sensitive actions to authorised operators.
- Monitoring  
  - Log each command invocation and outcome; create dashboard summarising usage statistics.

## OTHER

### Set FM radio frequency (RTL-SDR Blog V3)
- Hardware control  
  - Develop script/CLI to interface with RTL-SDR Blog V3 for tuning frequency and managing gain; wrap it in Laravel service.  
  - Detect presence of Alza mixer; fetch available sources/outputs via mixer API and cache results.
- Settings page  
  - Build UI with real-time validation (allowed frequency range, step size).  
  - Show current frequency, signal strength (if available), and last-updated timestamp.  
  - Provide apply/reset buttons and error reporting.
- Integration  
  - Ensure stream orchestrator selects correct mixer input when radio source chosen; test end-to-end (tune → start Modbus → audio path).  
  - Expose endpoint for JSVV handler to reuse (with access control).

### Stream orchestration guidelines
- Architecture  
  - Create `StreamOrchestrator` service encapsulating start/stop logic for all sources (direct mic, recordings, GSM, radio, JSVV).  
  - Use state machine pattern to ensure transitions (idle → preparing → streaming → stopping → idle) are consistent.  
  - Integrate with queue system for long-running operations and allow manual overrides.
- Telemetry  
  - Poll modbus status registers during streaming and push updates to frontend.  
  - Collect mixer statistics, audio level monitoring, and error logs into central observability stack (e.g., Laravel logging + Prometheus exporter).
- Health checks & resilience  
  - Pre-flight checks: verify python scripts executable, serial port accessible, audio source ready.  
  - Implement retries with backoff for recoverable errors; trigger alerts for unrecoverable failures.  
  - Document runbooks for operators (how to recover from common faults).

## THINGS TO DO

- `/api/live-broadcast/start` (POST)  
  Parameters: `source` (string; enum mic|recording|gsm|radio|jsvv), `route` (int[]), `zones` (int[]), `options` (object with source-specific fields).  
  Starts live broadcast orchestration using Modbus start stream and selected audio source.
- `/api/live-broadcast/stop` (POST)  
  Parameters: optional `reason` (string).  
  Stops active stream, releases Modbus control, updates session log.
- `/api/live-broadcast/status` (GET)  
  Parameters: none; query string may support `includeTelemetry` (bool).  
  Returns current session info, Modbus status/error registers, active source details.
- `/api/live-broadcast/playlist` (POST)  
  Parameters: `recordings` (array of `{id:int, gain?:float, gapMs?:int}`), `route`, `zones`, `options`.  
  Validates and enqueues a recording playback session; responds with job id.
- `/api/live-broadcast/playlist/{id}/cancel` (POST)  
  Parameters: none.  
  Cancels in-progress recording broadcast and stops stream safely.
- `/api/live-broadcast/sources` (GET)  
  Parameters: none.  
  Lists available audio inputs/outputs based on environment (dev vs production mixer).
- `/api/jsvv/sequences` (POST)  
  Parameters: `items` (array of `{slot:int, category:'verbal'|'siren', voice?:string, repeat?:int}`), `priority`, `zones`, `holdSeconds`.  
  Plans manual JSVV playback, returns resolved assets and estimated timeline.
- `/api/jsvv/sequences/{id}/trigger` (POST)  
  Parameters: none (sequence id from previous plan).  
  Executes planned sequence, coordinating Modbus and asset playback.
- `/api/jsvv/assets` (GET)  
  Parameters: optional `category`, `slot`, `voice`.  
  Returns cached siren/verbal asset metadata for UI selectors.
- `/api/jsvv/events` (POST)  
  Parameters: event payload from JSVV listener daemon (MID, params, priority, duplicate flag).  
  Backend dispatcher endpoint for incoming KPPS frames.
- `/api/gsm/events` (POST)  
  Parameters: GSM listener event `{state, caller, sessionId, metadata}`.  
  Initiates whitelist/PIN verification and triggers GSM stream orchestration.
- `/api/gsm/whitelist` (CRUD)  
  Parameters vary by method (`POST/PUT` expect `{number, label, priority}`).  
  Admin endpoints to manage GSM caller permissions.
- `/api/gsm/pin/verify` (POST)  
  Parameters: `sessionId`, `pin`.  
  Validates DTMF PIN and authorises GSM-triggered broadcast.
- `/api/fm/frequency` (GET/POST)  
  GET returns current frequency and metadata; POST accepts `{frequency:int, gain?:float}`.  
  Allows manual tuning of RTL-SDR radio source.
- `/api/manual-control/events` (POST)  
  Parameters: decoded command payload from future manual panel listener.  
  Placeholder endpoint ready for vendor protocol integration.
- `/api/stream/telemetry` (GET)  
  Parameters: optional `since` timestamp.  
  Provides aggregated stream metrics (status history, errors, mixer levels) for dashboards.
