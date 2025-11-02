SHELL := /bin/bash
TEST_SCRIPTS := \
	jsvv_roundtrip.sh \
	control_tab_crc_and_events.sh \
	gsm_incoming_call_whitelist.sh \
	rf_tx_start_stop.sh \
	rf_read_buffers_lifo.sh

.PHONY: test-scripts
test-scripts:
	@set -euo pipefail; \
	cd scripts/tests; \
	for script in $(TEST_SCRIPTS); do \
		echo ">>> Running $$script"; \
		bash "$$script"; \
	done
