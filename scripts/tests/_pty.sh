#!/usr/bin/env bash
# Helper utilities for PTY-based integration tests.
set -euo pipefail

pty::require_command() {
    local binary="${1:-}"
    local label="${2:-$1}"
    if [[ -z "$binary" ]]; then
        echo "[ERROR] Missing command name for pty::require_command" >&2
        exit 1
    fi
    if ! command -v "$binary" >/dev/null 2>&1; then
        echo "[ERROR] Required command '$label' not found in PATH." >&2
        exit 1
    fi
}

pty::with_pair() {
    if [[ $# -lt 1 ]]; then
        echo "[ERROR] pty::with_pair expects a callback to execute." >&2
        return 1
    fi

    local callback="$1"
    shift

    (
        set -euo pipefail

        local tmp_log
        tmp_log="$(mktemp)"
        local socat_pid=""

        cleanup() {
            local status=$?
            if [[ -n "$socat_pid" ]]; then
                kill "$socat_pid" >/dev/null 2>&1 || true
                wait "$socat_pid" >/dev/null 2>&1 || true
            fi
            rm -f "$tmp_log"
            exit $status
        }
        trap cleanup EXIT INT TERM

        socat -d -d "pty,raw,echo=0" "pty,raw,echo=0" 2>"$tmp_log" &
        socat_pid=$!

        local app_tty=""
        local feed_tty=""
        for _ in {1..50}; do
            if [[ ! -f "$tmp_log" ]]; then
                sleep 0.05
                continue
            fi

            local matches
            matches="$(grep -E 'PTY is ' "$tmp_log" || true)"
            if [[ -z "$matches" ]]; then
                sleep 0.05
                continue
            fi
            local first second
            first="$(echo "$matches" | head -n1 | awk '{print $NF}')"
            second="$(echo "$matches" | head -n2 | tail -n1 | awk '{print $NF}')"
            if [[ -n "$first" && -n "$second" && "$first" != "$second" ]]; then
                app_tty="$first"
                feed_tty="$second"
                break
            fi
            sleep 0.05
        done

        if [[ -z "$app_tty" || -z "$feed_tty" ]]; then
            echo "[ERROR] Unable to obtain PTY pair from socat output." >&2
            echo "socat log:" >&2
            cat "$tmp_log" >&2
            exit 1
        fi

        export APP_TTY="$app_tty"
        export FEED_TTY="$feed_tty"

        "$callback" "$@"
    )
}

pty::wait_for_file() {
    local target="${1:?Provide file path}"
    local retries="${2:-20}"
    local delay="${3:-0.1}"

    for _ in $(seq 1 "$retries"); do
        if [[ -s "$target" ]]; then
            return 0
        fi
        sleep "$delay"
    done
    return 1
}
