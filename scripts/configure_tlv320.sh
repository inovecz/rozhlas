#!/usr/bin/env bash
set -euo pipefail

if ! command -v amixer >/dev/null 2>&1; then
  echo "amixer not found; install alsa-utils first." >&2
  exit 1
fi

CARD="${1:-2}"

echo "Configuring TLV320 card ${CARD} via amixer..."

# Playback path to Line Out, HP and HPCOM
amixer -c "${CARD}" sset 'Left Line Mixer DACL1 Switch' on
amixer -c "${CARD}" sset 'Right Line Mixer DACR1 Switch' on
amixer -c "${CARD}" sset 'Line Playback Switch' on,on
amixer -c "${CARD}" sset 'Line Playback Volume' 80%

amixer -c "${CARD}" sset 'HP Playback Switch' on,on
amixer -c "${CARD}" sset 'HP Playback Volume' 70%

amixer -c "${CARD}" sset 'HPCOM Playback Switch' on,on
amixer -c "${CARD}" sset 'HPCOM Playback Volume' 70%

amixer -c "${CARD}" sset 'Mono Playback Switch' on
amixer -c "${CARD}" sset 'Mono Playback Volume' 70%

# Capture path for microphone input
amixer -c "${CARD}" sset 'PGA Capture Switch' on,on
amixer -c "${CARD}" sset 'PGA Capture Volume' 32

# Prefer microphone on MIC3 pair, disable unused line inputs by default
amixer -c "${CARD}" sset 'Left PGA Mixer Mic3L Switch' on
amixer -c "${CARD}" sset 'Right PGA Mixer Mic3R Switch' on
amixer -c "${CARD}" sset 'Left PGA Mixer Line1L Switch' off
amixer -c "${CARD}" sset 'Right PGA Mixer Line1R Switch' off
amixer -c "${CARD}" sset 'Left PGA Mixer Line2L Switch' off
amixer -c "${CARD}" sset 'Right PGA Mixer Line2R Switch' off

# Optional high-pass filter to tame DC on capture
amixer -c "${CARD}" sset 'ADC HPF Cut-off' '0.0045xFs','0.0045xFs'

if command -v alsactl >/dev/null 2>&1; then
  echo "Storing ALSA state for card ${CARD}..."
  alsactl store "${CARD}"
else
  echo "alsactl not found; mixer state not persisted." >&2
fi

echo "Done."
