# Daemon Supervisor Configuration

This directory contains supervisor configuration stubs for the GSM and JSVV
listener daemons. They expect the following environment variables to be set
inside the supervisor configuration:

- `PYTHON_BINARY` – path to Python interpreter (defaults to `python3`)
- `PROJECT_ROOT` – absolute path to repository root
- `GSM_WEBHOOK` – HTTP endpoint for GSM events (defaults to `http://127.0.0.1/api/gsm/events`)
- `JSVV_WEBHOOK` – HTTP endpoint for JSVV events (defaults to `http://127.0.0.1/api/jsvv/events`)

Example supervisor snippet:

```
[include]
files = /path/to/project/supervisor/*.conf

[environment]
PYTHON_BINARY=/usr/bin/python3
PROJECT_ROOT=/path/to/project
GSM_WEBHOOK=http://127.0.0.1/api/gsm/events
JSVV_WEBHOOK=http://127.0.0.1/api/jsvv/events
```

Logs are written to `storage/logs/daemons/`.
